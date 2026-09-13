<?php

declare(strict_types=1);

namespace Psalm\Plugin\Yii2;

use Override;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use Psalm\Internal\MethodIdentifier;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\MethodThrowsProviderEvent;
use Psalm\Plugin\EventHandler\MethodThrowsProviderInterface;
use Psalm\Plugin\EventHandler\MethodThrowsProviderResult;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TLiteralClassString;

use function array_keys;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function ltrim;
use function str_contains;
use function strrpos;
use function strtolower;
use function substr;

/**
 * Connects Yii's dynamic dispatch entry points to project method throws summaries.
 */
final class ThrowsProvider implements MethodThrowsProviderInterface, AfterClassLikeVisitInterface
{
    /**
     * @return array<string>
     * @psalm-pure
     */
    #[Override]
    public static function getClassLikeNames(): array
    {
        return [
            'yii\\BaseYii',
            'yii\\base\\BaseObject',
            'yii\\base\\Component',
            'yii\\base\\Model',
            'yii\\db\\BaseActiveRecord',
            'yii\\db\\ActiveRecord',
            'yii\\db\\ActiveQuery',
            'yii\\db\\Query',
        ];
    }

    #[Override]
    public static function getMethodThrows(MethodThrowsProviderEvent $event): ?MethodThrowsProviderResult
    {
        $method = $event->getCalledMethodNameLowercase() ?? $event->getMethodNameLowercase();
        $called_class = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();

        if ($method === 'trigger') {
            return self::getTriggerTargets($event, $called_class);
        }

        if ($method === 'createobject') {
            $created_class = self::getCreatedClass($event);
            if ($created_class === null) {
                return new MethodThrowsProviderResult([]);
            }

            return new MethodThrowsProviderResult(
                self::resolveProjectMethods($event, $created_class, ['__construct', 'init']),
            );
        }

        $hooks = match ($method) {
            '__construct' => ['init'],
            'validate' => [
                'beforeValidate',
                ...self::validatorMethods($event, $called_class),
                'afterValidate',
            ],
            'save', 'insert', 'update' => [
                ...self::validationMethods($event, $called_class),
                'beforeSave',
                'afterSave',
            ],
            'delete' => ['beforeDelete', 'afterDelete'],
            'findone', 'findall', 'populaterecord' => ['afterFind'],
            'refresh' => ['afterRefresh'],
            default => null,
        };
        if ($hooks === null) {
            $query_target = self::databaseExecutionTarget($event, $method);
            return $query_target === null ? null : new MethodThrowsProviderResult([$query_target]);
        }

        $targets = self::resolveProjectMethods($event, $called_class, $hooks);
        $query_target = self::databaseExecutionTarget($event, $method);
        if ($query_target !== null) {
            $targets[] = $query_target;
        }

        return new MethodThrowsProviderResult($targets);
    }

    /** @return list<string> */
    private static function validationMethods(MethodThrowsProviderEvent $event, string $called_class): array
    {
        $first_arg = $event->getCallArgs()[0]->value ?? null;
        if ($first_arg instanceof Node\Expr\ConstFetch
            && strtolower($first_arg->name->toString()) === 'false'
        ) {
            return [];
        }

        return ['beforeValidate', ...self::validatorMethods($event, $called_class), 'afterValidate'];
    }

    private static function databaseExecutionTarget(
        MethodThrowsProviderEvent $event,
        string $method,
    ): ?MethodIdentifier {
        $command_method = match ($method) {
            'all', 'findall' => 'queryall',
            'one', 'findone', 'refresh' => 'queryone',
            'column' => 'querycolumn',
            'scalar', 'count', 'sum', 'average', 'min', 'max', 'exists' => 'queryscalar',
            'updateall', 'updateallcounters', 'deleteall' => 'execute',
            default => null,
        };

        if ($command_method === null
            || !$event->getSource()->getCodebase()->classOrInterfaceExists('yii\\db\\Command')
        ) {
            return null;
        }

        return new MethodIdentifier('yii\\db\\Command', $command_method);
    }

    /** @return list<string> */
    private static function validatorMethods(MethodThrowsProviderEvent $event, string $called_class): array
    {
        $codebase = $event->getSource()->getCodebase();
        if (!$codebase->classOrInterfaceExists($called_class)) {
            return [];
        }

        $storage = $codebase->classlike_storage_provider->get($called_class);
        $classes = [$storage->name, ...array_keys($storage->parent_classes)];
        $methods = [];
        foreach ($classes as $class) {
            $class_storage = $codebase->classlike_storage_provider->get($class);
            $candidates = $class_storage->custom_metadata['yii_validator_methods'] ?? [];
            if (!is_array($candidates)) {
                continue;
            }
            foreach ($candidates as $candidate => $_) {
                if (is_string($candidate)) {
                    $methods[strtolower($candidate)] = strtolower($candidate);
                }
            }
        }

        return array_values($methods);
    }

    /**
     * @param list<string> $methods
     * @return list<MethodIdentifier>
     */
    private static function resolveProjectMethods(
        MethodThrowsProviderEvent $event,
        string $class,
        array $methods,
    ): array {
        $codebase = $event->getSource()->getCodebase();
        if (!$codebase->classOrInterfaceExists($class)) {
            return [];
        }

        $storage = $codebase->classlike_storage_provider->get($class);
        $result = [];
        $seen = [];
        foreach ($methods as $method) {
            $method_id = $storage->declaring_method_ids[strtolower($method)] ?? null;
            if ($method_id === null) {
                continue;
            }
            $method_storage = $codebase->methods->getStorage($method_id);
            if ($method_storage->stmt_location === null
                || !$codebase->config->isInProjectDirs($method_storage->stmt_location->file_path)
            ) {
                continue;
            }
            $key = strtolower((string) $method_id);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $method_id;
            }
        }

        return $result;
    }

    private static function getCreatedClass(MethodThrowsProviderEvent $event): ?string
    {
        $arg = $event->getCallArgs()[0]->value ?? null;
        if ($arg === null) {
            return null;
        }

        $type = $event->getSource()->getNodeTypeProvider()->getType($arg);
        if ($type !== null) {
            foreach ($type->getAtomicTypes() as $atomic) {
                if ($atomic instanceof TLiteralClassString) {
                    return $atomic->value;
                }
            }
        }

        $literal_class = self::classNameFromExpression($arg, null);
        if ($literal_class !== null) {
            return $literal_class;
        }

        if ($arg instanceof Node\Expr\Array_) {
            foreach ($arg->items as $item) {
                if ($item === null || !$item->key instanceof Node\Scalar\String_) {
                    continue;
                }
                if (strtolower($item->key->value) === 'class') {
                    return self::classNameFromExpression($item->value, null);
                }
            }
        }

        return null;
    }

    private static function getTriggerTargets(
        MethodThrowsProviderEvent $event,
        string $called_class,
    ): MethodThrowsProviderResult {
        $codebase = $event->getSource()->getCodebase();
        if (!$codebase->classOrInterfaceExists($called_class)) {
            return new MethodThrowsProviderResult([]);
        }

        $storage = $codebase->classlike_storage_provider->get($called_class);
        $event_arg = $event->getCallArgs()[0]->value ?? null;
        $event_key = $event_arg ? self::eventKey($event_arg, $storage) : null;
        if ($event_key === null) {
            return new MethodThrowsProviderResult([]);
        }

        $classes = [$storage->name, ...array_keys($storage->parent_classes)];
        $targets = [];
        $seen = [];
        foreach ($classes as $class) {
            $class_storage = $codebase->classlike_storage_provider->get($class);
            $handlers = $class_storage->custom_metadata['yii_event_handlers'][$event_key] ?? [];
            if (!is_array($handlers)) {
                continue;
            }
            foreach ($handlers as $handler) {
                if (!is_array($handler)
                    || !isset($handler['class'], $handler['method'])
                    || !is_string($handler['class'])
                    || !is_string($handler['method'])
                ) {
                    continue;
                }
                $method_id = new MethodIdentifier($handler['class'], strtolower($handler['method']));
                $key = strtolower((string) $method_id);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $targets[] = $method_id;
                }
            }
        }

        return new MethodThrowsProviderResult($targets);
    }

    #[Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $storage = $event->getStorage();
        self::collectValidatorMethods($event, $storage);
        $calls = (new NodeFinder())->find(
            $event->getStmt()->stmts ?? [],
            static fn(Node $node): bool => $node instanceof Node\Expr\MethodCall
                && $node->var instanceof Node\Expr\Variable
                && $node->var->name === 'this'
                && $node->name instanceof Node\Identifier
                && strtolower($node->name->name) === 'on',
        );
        foreach ($calls as $call) {
            if (!$call instanceof Node\Expr\MethodCall) {
                continue;
            }

            $event_expr = $call->getArgs()[0]->value ?? null;
            $callback_expr = $call->getArgs()[1]->value ?? null;
            $event_key = $event_expr ? self::eventKey($event_expr, $storage) : null;
            $callback = $callback_expr ? self::callbackMethod($callback_expr, $storage) : null;
            if ($event_key === null || $callback === null) {
                continue;
            }

            $storage->custom_metadata['yii_event_handlers'][$event_key][strtolower((string) $callback)] = [
                'class' => $callback->fq_class_name,
                'method' => $callback->method_name,
            ];
        }
    }

    private static function collectValidatorMethods(
        AfterClassLikeVisitEvent $event,
        ClassLikeStorage $storage,
    ): void {
        foreach ($event->getStmt()->stmts ?? [] as $stmt) {
            if (!$stmt instanceof ClassMethod
                || !in_array(strtolower($stmt->name->name), ['rules', 'ruleslist'], true)
            ) {
                continue;
            }

            $arrays = (new NodeFinder())->findInstanceOf($stmt->stmts ?? [], Node\Expr\Array_::class);
            foreach ($arrays as $array) {
                $position = 0;
                foreach ($array->items as $item) {
                    if ($item === null || $item->key !== null) {
                        continue;
                    }
                    if (($position === 0 || $position === 1)
                        && $item->value instanceof Node\Scalar\String_
                        && $item->value->value !== ''
                    ) {
                        $method = strtolower($item->value->value);
                        if (isset($storage->declaring_method_ids[$method])) {
                            $storage->custom_metadata['yii_validator_methods'][$method] = true;
                        }
                    }
                    ++$position;
                }
            }
        }
    }

    private static function eventKey(Node\Expr $expr, ?ClassLikeStorage $storage): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return 'string:' . $expr->value;
        }
        if (!$expr instanceof Node\Expr\ClassConstFetch || !$expr->name instanceof Node\Identifier) {
            return null;
        }

        $class = self::classNameFromExpression($expr, $storage);
        return $class === null ? null : 'const:' . strtolower($class) . '::' . strtolower($expr->name->name);
    }

    private static function callbackMethod(Node\Expr $expr, ClassLikeStorage $storage): ?MethodIdentifier
    {
        if (!$expr instanceof Node\Expr\Array_
            || count($expr->items) !== 2
        ) {
            return null;
        }

        $class_item = $expr->items[0] ?? null;
        $method_item = $expr->items[1] ?? null;
        if (!$class_item instanceof Node\ArrayItem
            || !$method_item instanceof Node\ArrayItem
            || !$method_item->value instanceof Node\Scalar\String_
            || $method_item->value->value === ''
        ) {
            return null;
        }

        $class_expr = $class_item->value;
        $class = $class_expr instanceof Node\Expr\Variable && $class_expr->name === 'this'
            ? $storage->name
            : self::classNameFromExpression($class_expr, $storage);
        if ($class === null) {
            return null;
        }

        return new MethodIdentifier($class, strtolower($method_item->value->value));
    }

    private static function classNameFromExpression(
        ?Node\Expr $expr,
        ?ClassLikeStorage $storage,
    ): ?string {
        if ($expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
        ) {
            $name = strtolower($expr->class->toString());
            if (($name === 'self' || $name === 'static') && $storage !== null) {
                return $storage->name;
            }
            if ($name === 'parent' && $storage !== null) {
                return $storage->parent_class;
            }
            /** @var Node\Name|string|null $resolved */
            $resolved = $expr->class->getAttribute('resolvedName');
            if ($resolved instanceof Node\Name) {
                return $resolved->toString();
            }
            if (is_string($resolved) && strtolower($resolved) !== 'self' && strtolower($resolved) !== 'static') {
                return $resolved;
            }

            $class = $expr->class->toString();
            if ($storage !== null
                && !$expr->class instanceof Node\Name\FullyQualified
                && !str_contains($class, '\\')
            ) {
                $separator = strrpos($storage->name, '\\');
                if ($separator !== false) {
                    return substr($storage->name, 0, $separator + 1) . $class;
                }
            }
            return $class;
        }

        if ($expr instanceof Node\Scalar\String_) {
            $class = ltrim($expr->value, '\\');
            if ($storage !== null && !str_contains($class, '\\')) {
                $separator = strrpos($storage->name, '\\');
                if ($separator !== false) {
                    $class = substr($storage->name, 0, $separator + 1) . $class;
                }
            }
            return $class;
        }

        return null;
    }
}
