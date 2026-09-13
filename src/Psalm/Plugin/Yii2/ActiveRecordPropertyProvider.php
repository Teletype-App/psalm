<?php

declare(strict_types=1);

namespace Psalm\Plugin\Yii2;

use Override;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyThrowsProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyVisibilityProviderEvent;
use Psalm\Plugin\EventHandler\MethodThrowsProviderResult;
use Psalm\Plugin\EventHandler\PropertyExistenceProviderInterface;
use Psalm\Plugin\EventHandler\PropertyThrowsProviderInterface;
use Psalm\Plugin\EventHandler\PropertyTypeProviderInterface;
use Psalm\Plugin\EventHandler\PropertyVisibilityProviderInterface;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

use function array_key_exists;
use function count;
use function str_contains;
use function strtolower;
use function substr;

final class ActiveRecordPropertyProvider implements
    AfterCodebasePopulatedInterface,
    PropertyExistenceProviderInterface,
    PropertyTypeProviderInterface,
    PropertyThrowsProviderInterface,
    PropertyVisibilityProviderInterface
{
    /** @var array<lowercase-string, true> */
    private static array $registered_classes = [];

    /** @var array<string, array<int, array{bool, string}|null>> */
    private static array $relations_by_method = [];

    /** @psalm-external-mutation-free */
    public static function reset(): void
    {
        self::$registered_classes = [];
        self::$relations_by_method = [];
    }

    /**
     * @return array<string>
     * @psalm-pure
     */
    #[Override]
    public static function getClassLikeNames(): array
    {
        // Providers are attached to concrete ActiveRecord descendants after population.
        return [];
    }

    /** @psalm-external-mutation-free */
    #[Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        foreach (ClassLikeStorageProvider::getAll() as $storage) {
            $class_name_lc = strtolower($storage->name);
            if (isset(self::$registered_classes[$class_name_lc]) || !self::isActiveRecord($storage)) {
                continue;
            }

            self::$registered_classes[$class_name_lc] = true;
            $codebase->properties->property_existence_provider->registerClosure(
                $storage->name,
                self::doesPropertyExist(...),
            );
            $codebase->properties->property_type_provider->registerClosure(
                $storage->name,
                self::getPropertyType(...),
            );
            $codebase->properties->property_throws_provider->registerClosure(
                $storage->name,
                self::getPropertyThrows(...),
            );
            $codebase->properties->property_visibility_provider->registerClosure(
                $storage->name,
                self::isPropertyVisible(...),
            );
        }
    }

    #[Override]
    public static function doesPropertyExist(PropertyExistenceProviderEvent $event): ?bool
    {
        return self::getMagicPropertyType(
            $event->getSource()?->getCodebase(),
            $event->getFqClasslikeName(),
            $event->getPropertyName(),
            $event->isReadMode(),
        ) !== null ?: null;
    }

    #[Override]
    public static function getPropertyType(PropertyTypeProviderEvent $event): ?Union
    {
        return self::getMagicPropertyType(
            $event->getSource()?->getCodebase(),
            $event->getFqClasslikeName(),
            $event->getPropertyName(),
            $event->isReadMode(),
        );
    }

    #[Override]
    public static function getPropertyThrows(PropertyThrowsProviderEvent $event): ?MethodThrowsProviderResult
    {
        $codebase = $event->getSource()->getCodebase();
        $method = self::getMagicPropertyMethod(
            $codebase,
            $event->getFqClasslikeName(),
            $event->getPropertyName(),
            $event->isReadMode(),
        );
        if ($method === null) {
            return null;
        }

        [$method_id, $method_storage, $declaring_class_storage] = $method;
        $targets = [$method_id];
        if ($event->isReadMode()) {
            $relation = self::getRelationDefinition($codebase, $declaring_class_storage, $method_storage);
            if ($relation !== null) {
                $targets[] = new MethodIdentifier(
                    'yii\\db\\Command',
                    $relation[0] ? 'queryall' : 'queryone',
                );
            }
        }

        return new MethodThrowsProviderResult($targets);
    }

    #[Override]
    public static function isPropertyVisible(PropertyVisibilityProviderEvent $event): ?bool
    {
        $codebase = $event->getSource()->getCodebase();
        return (self::getMagicPropertyMethod(
            $codebase,
            $event->getFqClasslikeName(),
            $event->getPropertyName(),
            $event->isReadMode(),
        ) ?? self::getMagicPropertyMethod(
            $codebase,
            $event->getFqClasslikeName(),
            $event->getPropertyName(),
            !$event->isReadMode(),
        )) !== null ?: null;
    }

    private static function getMagicPropertyType(
        ?Codebase $codebase,
        string $fq_classlike_name,
        string $property_name,
        bool $read_mode,
    ): ?Union {
        if ($codebase === null) {
            return null;
        }

        $method = self::getMagicPropertyMethod($codebase, $fq_classlike_name, $property_name, $read_mode);
        if ($method === null) {
            return null;
        }

        [$method_id, $method_storage, $declaring_class_storage] = $method;

        if (!$read_mode) {
            return $method_storage->params[0]->type ?? Type::getMixed();
        }

        $relation = self::getRelationDefinition($codebase, $declaring_class_storage, $method_storage);
        if ($relation !== null) {
            [$multiple, $related_class] = $relation;
            $related_type = new Union([new TNamedObject($related_class)]);

            return $multiple
                ? new Union([new TArray([Type::getArrayKey(), $related_type])])
                : new Union([new TNamedObject($related_class), new TNull()]);
        }

        $self_class = $method_id->fq_class_name;
        return $codebase->getMethodReturnType($method_id, $self_class);
    }

    /**
     * @return array{MethodIdentifier, MethodStorage, ClassLikeStorage}|null
     * @psalm-mutation-free
     */
    private static function getMagicPropertyMethod(
        Codebase $codebase,
        string $fq_classlike_name,
        string $property_name,
        bool $read_mode,
    ): ?array {

        $class_storage = $codebase->classlike_storage_provider->get($fq_classlike_name);
        if (isset($class_storage->declaring_property_ids[$property_name])
            || isset(($read_mode
                ? $class_storage->pseudo_property_get_types
                : $class_storage->pseudo_property_set_types)['$' . $property_name])
        ) {
            return null;
        }

        $method_id = new MethodIdentifier(
            $fq_classlike_name,
            strtolower(($read_mode ? 'get' : 'set') . $property_name),
        );
        $declaring_method_id = $codebase->methods->getDeclaringMethodId($method_id);
        if ($declaring_method_id === null) {
            return null;
        }

        $method_storage = $codebase->methods->getStorage($declaring_method_id);
        if ($method_storage->is_static
            || $method_storage->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC
            || ($read_mode && ($method_storage->required_param_count ?? 0) > 0)
            || (!$read_mode
                && (count($method_storage->params) < 1 || ($method_storage->required_param_count ?? 0) > 1))
        ) {
            return null;
        }

        $declaring_class_storage = $codebase->classlike_storage_provider->get(
            $declaring_method_id->fq_class_name,
        );

        return [$method_id, $method_storage, $declaring_class_storage];
    }

    /**
     * @return array{bool, string}|null
     */
    private static function getRelationDefinition(
        Codebase $codebase,
        ClassLikeStorage $class_storage,
        MethodStorage $storage,
    ): ?array {
        $location = $storage->stmt_location;
        if ($location === null) {
            return null;
        }

        $contents = $codebase->file_provider->getContents($location->file_path);
        $method_source = substr(
            $contents,
            $location->raw_file_start,
            $location->raw_file_end - $location->raw_file_start + 1,
        );
        if (!str_contains($method_source, 'hasOne') && !str_contains($method_source, 'hasMany')) {
            return null;
        }

        if (array_key_exists(
            $location->raw_file_start,
            self::$relations_by_method[$location->file_path] ?? [],
        )) {
            return self::$relations_by_method[$location->file_path][$location->raw_file_start];
        }

        $statements = $codebase->getStatementsForFile($location->file_path);
        $method = (new NodeFinder())->findFirst(
            $statements,
            static fn(Node $node): bool => $node instanceof ClassMethod
                && $node->getStartFilePos() === $location->raw_file_start,
        );

        $relation_call = $method instanceof ClassMethod ? (new NodeFinder())->findFirst(
            $method->stmts ?? [],
            static fn(Node $node): bool => $node instanceof MethodCall
                && $node->name instanceof Identifier
                && ($node->name->toLowerString() === 'hasone' || $node->name->toLowerString() === 'hasmany'),
        ) : null;
        if (!$relation_call instanceof MethodCall || !$relation_call->name instanceof Identifier) {
            return self::$relations_by_method[$location->file_path][$location->raw_file_start] = null;
        }

        $class_arg = $relation_call->getArgs()[0]->value ?? null;
        if (!$class_arg instanceof ClassConstFetch
            || !$class_arg->class instanceof Name
            || !$class_arg->name instanceof Identifier
            || strtolower($class_arg->name->name) !== 'class'
        ) {
            return self::$relations_by_method[$location->file_path][$location->raw_file_start] = null;
        }

        if ($class_storage->aliases === null) {
            return self::$relations_by_method[$location->file_path][$location->raw_file_start] = null;
        }

        $related_class = ClassLikeAnalyzer::getFQCLNFromNameObject($class_arg->class, $class_storage->aliases);

        if ($related_class === 'self' || $related_class === 'static') {
            $related_class = $class_storage->name;
        } elseif ($related_class === 'parent') {
            $related_class = $class_storage->parent_class ?? $related_class;
        }

        return self::$relations_by_method[$location->file_path][$location->raw_file_start] = [
            $relation_call->name->toLowerString() === 'hasmany',
            $related_class,
        ];
    }

    /** @psalm-mutation-free */
    private static function isActiveRecord(ClassLikeStorage $storage): bool
    {
        $name_lc = strtolower($storage->name);
        return $name_lc === 'yii\\db\\activerecord'
            || isset($storage->parent_classes['yii\\db\\activerecord']);
    }
}
