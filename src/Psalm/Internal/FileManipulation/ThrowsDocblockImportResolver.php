<?php

declare(strict_types=1);

namespace Psalm\Internal\FileManipulation;

use PhpParser\Comment\Doc;
use Psalm\DocComment;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TNamedObject;

use function array_values;
use function explode;
use function ksort;
use function ltrim;
use function preg_match;
use function preg_split;
use function str_contains;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

use const SORT_FLAG_CASE;
use const SORT_NATURAL;

/**
 * @internal
 */
final class ThrowsDocblockImportResolver
{
    /**
     * @psalm-pure
     */
    public static function isValidClassLikeName(string $class_name): bool
    {
        return preg_match(
            '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/D',
            ltrim($class_name, '\\'),
        ) === 1;
    }

    /**
     * @return array{
     *     documented_throws: array<string, true>,
     *     documented_throw_names: array<string, non-empty-list<non-empty-string>>,
     *     has_duplicates: bool,
     * }
     */
    public static function analyzeDocumentedThrows(StatementsSource $source, ?Doc $doc_comment): array
    {
        if ($doc_comment === null) {
            return [
                'documented_throws' => [],
                'documented_throw_names' => [],
                'has_duplicates' => false,
            ];
        }

        $parsed_docblock = DocComment::parsePreservingLength($doc_comment, true);
        $documented_throws = [];
        $documented_throw_names = [];
        $throws_clauses = [];
        $has_duplicates = false;

        foreach ($parsed_docblock->tags['throws'] ?? [] as $throws_entry) {
            $throws_parts = preg_split('/[\s]+/', $throws_entry);
            if ($throws_parts === false || $throws_parts[0] === '') {
                continue;
            }

            $throws_clause = $throws_parts[0];
            $normalized_throws_clause = strtolower($throws_clause);
            if (isset($throws_clauses[$normalized_throws_clause])) {
                $has_duplicates = true;
            } else {
                $throws_clauses[$normalized_throws_clause] = true;
            }

            foreach (explode('|', $throws_clause) as $throw_class) {
                $throw_class = trim($throw_class);
                if ($throw_class === '' || !self::isValidClassLikeName($throw_class)) {
                    continue;
                }

                $exception_fqcln = $throw_class === 'self'
                    || $throw_class === 'static'
                    || $throw_class === 'parent'
                    ? $throw_class
                    : Type::getFQCLNFromString($throw_class, $source->getAliases());
                $documented_throws[$exception_fqcln] = true;
                $documented_throw_names[$exception_fqcln][] = $throw_class;
            }
        }

        return [
            'documented_throws' => $documented_throws,
            'documented_throw_names' => $documented_throw_names,
            'has_duplicates' => $has_duplicates,
        ];
    }

    /**
     * @param list<Atomic> $exceptions
     * @return array{list<string>, list<string>}
     */
    public static function resolve(StatementsSource $source, array $exceptions): array
    {
        $namespace = $source->getNamespace();
        $aliased_classes_flipped = $source->getAliasedClassesFlipped();
        $aliases = $source->getAliases();
        $reserved_aliases = [];

        foreach ($aliases->uses as $alias => $fq_class_name) {
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

            $existing_alias = $aliased_classes_flipped[$fq_class_name_lc]
                ?? $aliases->uses_flipped[$fq_class_name_lc]
                ?? null;

            if ($existing_alias !== null) {
                $annotation_names[] = $existing_alias;
                continue;
            }

            $short_name = self::getShortName($fq_class_name);
            $short_name_lc = strtolower($short_name);
            $preferred_alias = $source->getCodebase()->config->throws_import_aliases[$fq_class_name_lc] ?? null;
            $preferred_alias_lc = strtolower($preferred_alias ?? '');
            $class_in_current_namespace = $namespace !== null
                && strtolower($namespace . '\\' . $short_name) === $fq_class_name_lc;
            $global_class_in_global_namespace = $namespace === null && !str_contains($fq_class_name, '\\');
            $alias_is_available = !isset($reserved_aliases[$short_name_lc]);

            if ($class_in_current_namespace || $global_class_in_global_namespace) {
                $annotation_names[] = $short_name;
            } elseif ($preferred_alias !== null
                && $preferred_alias_lc !== ''
                && !isset($reserved_aliases[$preferred_alias_lc])
            ) {
                $annotation_names[] = $preferred_alias;
                $imports[$fq_class_name_lc] = $fq_class_name . ' as ' . $preferred_alias;
                $reserved_aliases[$preferred_alias_lc] = $fq_class_name_lc;
            } elseif ($alias_is_available) {
                $annotation_names[] = $short_name;
                $imports[$fq_class_name_lc] = $fq_class_name;
                $reserved_aliases[$short_name_lc] = $fq_class_name_lc;
            } else {
                $annotation_names[] = '\\' . $fq_class_name;
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
