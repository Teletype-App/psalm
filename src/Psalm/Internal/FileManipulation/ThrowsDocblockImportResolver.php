<?php

declare(strict_types=1);

namespace Psalm\Internal\FileManipulation;

use Psalm\StatementsSource;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TNamedObject;

use function array_values;
use function ksort;
use function ltrim;
use function str_contains;
use function strrpos;
use function strtolower;
use function substr;

use const SORT_FLAG_CASE;
use const SORT_NATURAL;

/**
 * @internal
 */
final class ThrowsDocblockImportResolver
{
    /**
     * @param list<Atomic> $exceptions
     * @return array{list<string>, list<string>}
     */
    public static function resolve(StatementsSource $source, array $exceptions): array
    {
        $namespace = $source->getNamespace();
        $aliased_classes_flipped = $source->getAliasedClassesFlipped();
        $reserved_aliases = [];

        foreach ($source->getAliases()->uses as $alias => $fq_class_name) {
            $reserved_aliases[$alias] = strtolower($fq_class_name);
        }

        $file_storage = $source->getCodebase()->file_storage_provider->get($source->getFilePath());
        foreach ($file_storage->classlikes_in_file as $fq_class_name) {
            if (strtolower(self::getNamespace($fq_class_name)) !== strtolower($namespace ?? '')) {
                continue;
            }

            $reserved_aliases[strtolower(self::getShortName($fq_class_name))] = strtolower($fq_class_name);
        }

        $annotation_names = [];
        $imports = [];

        foreach ($exceptions as $exception) {
            if (!$exception instanceof TNamedObject) {
                $annotation_names[] = $exception->toNamespacedString(
                    $namespace,
                    $aliased_classes_flipped,
                    $source->getFQCLN(),
                    true,
                );
                continue;
            }

            $fq_class_name = ltrim($exception->value, '\\');
            $fq_class_name_lc = strtolower($fq_class_name);

            if (isset($aliased_classes_flipped[$fq_class_name_lc])) {
                $annotation_names[] = $aliased_classes_flipped[$fq_class_name_lc];
                continue;
            }

            $short_name = self::getShortName($fq_class_name);
            $short_name_lc = strtolower($short_name);
            $class_in_current_namespace = $namespace !== null
                && strtolower($namespace . '\\' . $short_name) === $fq_class_name_lc;
            $global_class_in_global_namespace = $namespace === null && !str_contains($fq_class_name, '\\');
            $alias_is_available = !isset($reserved_aliases[$short_name_lc])
                || $reserved_aliases[$short_name_lc] === $fq_class_name_lc;

            if ($class_in_current_namespace || $global_class_in_global_namespace) {
                $annotation_names[] = $short_name;
            } elseif ($alias_is_available) {
                $annotation_names[] = $short_name;
                $imports[$fq_class_name_lc] = $fq_class_name;
                $reserved_aliases[$short_name_lc] = $fq_class_name_lc;
            } else {
                $annotation_names[] = $exception->toNamespacedString(
                    $namespace,
                    $aliased_classes_flipped,
                    $source->getFQCLN(),
                    true,
                );
            }
        }

        ksort($imports, SORT_NATURAL | SORT_FLAG_CASE);

        return [$annotation_names, array_values($imports)];
    }

    /** @psalm-pure */
    private static function getShortName(string $fq_class_name): string
    {
        $separator_position = strrpos($fq_class_name, '\\');

        return $separator_position === false ? $fq_class_name : substr($fq_class_name, $separator_position + 1);
    }

    /** @psalm-pure */
    private static function getNamespace(string $fq_class_name): string
    {
        $separator_position = strrpos($fq_class_name, '\\');

        return $separator_position === false ? '' : substr($fq_class_name, 0, $separator_position);
    }
}
