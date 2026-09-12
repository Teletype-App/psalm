<?php

declare(strict_types=1);

namespace Psalm\Internal\FileManipulation;

use PhpParser;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use Psalm\DocComment;
use Psalm\FileManipulation;
use Psalm\Internal\Analyzer\CommentAnalyzer;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\Scanner\ParsedDocblock;
use Psalm\Storage\Mutations;

use function array_key_exists;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function is_string;
use function ltrim;
use function natcasesort;
use function preg_match;
use function preg_split;
use function reset;
use function str_replace;
use function str_split;
use function strlen;
use function strpos;
use function strripos;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

/**
 * @internal
 */
final class FunctionDocblockManipulator
{
    /**
     * Manipulators ordered by line number
     *
     * @var array<string, array<int, FunctionDocblockManipulator>>
     */
    private static array $manipulators = [];

    private readonly int $docblock_start;

    private readonly int $docblock_end;

    private readonly int $return_typehint_area_start;

    private ?int $return_typehint_colon_start = null;

    private ?int $return_typehint_start = null;

    private ?int $return_typehint_end = null;

    private ?string $new_php_return_type = null;

    private bool $return_type_is_php_compatible = false;

    private ?string $new_phpdoc_return_type = null;

    private ?string $new_psalm_return_type = null;

    /** @var array<string, string> */
    private array $new_php_param_types = [];

    /** @var array<string, string> */
    private array $new_phpdoc_param_types = [];

    /** @var array<string, string> */
    private array $new_psalm_param_types = [];

    private string $indentation;

    private ?string $return_type_description = null;

    /** @var array<string, int> */
    private array $param_offsets = [];

    /** @var array<string, array{int, int}> */
    private array $param_typehint_offsets = [];

    /** @var ?Mutations::LEVEL_* */
    private ?int $allowed_mutations = null;

    /** @var list<string> */
    private array $throwsExceptions = [];

    /** @var list<string> */
    private array $throwsImports = [];

    /** @var list<string> */
    private array $removedThrowsExceptions = [];

    /** @var array<lowercase-string, list<string>> */
    private array $replacedThrowsExceptions = [];

    private bool $normalizeThrowsDocblock = false;

    private ?string $throwsImportGroupKey = null;

    private ?int $throwsImportPosition = null;

    private string $throwsImportIndentation = '';

    private bool $throwsImportAfterUse = false;

    /**
     * @param  Closure|Function_|ClassMethod|ArrowFunction $stmt
     */
    public static function getForFunction(
        ProjectAnalyzer $project_analyzer,
        string $file_path,
        FunctionLike $stmt,
    ): FunctionDocblockManipulator {
        if (isset(self::$manipulators[$file_path][$stmt->getLine()])) {
            return self::$manipulators[$file_path][$stmt->getLine()];
        }

        $manipulator
            = self::$manipulators[$file_path][$stmt->getLine()]
            = new self($file_path, $stmt, $project_analyzer);

        return $manipulator;
    }

    private function __construct(
        private readonly string $file_path,
        private readonly Closure|Function_|ClassMethod|ArrowFunction $stmt,
        ProjectAnalyzer $project_analyzer,
    ) {
        $docblock = $stmt->getDocComment();
        $this->docblock_start = $docblock ? $docblock->getStartFilePos() : (int)$stmt->getAttribute('startFilePos');
        $this->docblock_end = $function_start = (int)$stmt->getAttribute('startFilePos');
        $function_end = (int)$stmt->getAttribute('endFilePos');

        $attributes = $stmt->getAttrGroups();
        foreach ($attributes as $attribute) {
            // if we have attribute groups, we need to consider that the function starts after them
            if ((int) $attribute->getAttribute('endFilePos') > $function_start) {
                $function_start = (int) $attribute->getAttribute('endFilePos');
            }
        }

        foreach ($stmt->params as $param) {
            if ($param->var instanceof PhpParser\Node\Expr\Variable
                && is_string($param->var->name)
            ) {
                $this->param_offsets[$param->var->name] = (int) $param->getAttribute('startFilePos');

                if ($param->type) {
                    $this->param_typehint_offsets[$param->var->name] = [
                        (int) $param->type->getAttribute('startFilePos'),
                        (int) $param->type->getAttribute('endFilePos') + 1,
                    ];
                }
            }
        }

        $codebase = $project_analyzer->getCodebase();

        $file_contents = $codebase->getFileContents($file_path);

        $last_arg_position = $stmt->params
            ? (int) $stmt->params[count($stmt->params) - 1]->getAttribute('endFilePos') + 1
            : null;

        if ($stmt instanceof Closure && $stmt->uses) {
            $last_arg_position = (int) $stmt->uses[count($stmt->uses) - 1]->getAttribute('endFilePos') + 1;
        }

        $end_bracket_position = (int) strpos($file_contents, ')', $last_arg_position ?: $function_start);

        $this->return_typehint_area_start = $end_bracket_position + 1;

        $function_code = substr($file_contents, $function_start, $function_end - $function_start);

        $function_code_after_bracket = substr($function_code, $end_bracket_position + 1 - $function_start);

        // do a little parsing here
        $chars = str_split($function_code_after_bracket);

        $in_single_line_comment = $in_multi_line_comment = false;

        for ($i = 0, $iMax = count($chars); $i < $iMax; ++$i) {
            $char = $chars[$i];

            switch ($char) {
                case "\n":
                    $in_single_line_comment = false;
                    continue 2;

                case ':':
                    if ($in_multi_line_comment || $in_single_line_comment) {
                        continue 2;
                    }

                    $this->return_typehint_colon_start = $i + $end_bracket_position + 1;

                    continue 2;

                case '/':
                    if ($in_multi_line_comment || $in_single_line_comment) {
                        continue 2;
                    }

                    if ($chars[$i + 1] === '*') {
                        $in_multi_line_comment = true;
                        ++$i;
                    }

                    if ($chars[$i + 1] === '/') {
                        $in_single_line_comment = true;
                        ++$i;
                    }

                    continue 2;

                case '*':
                    if ($in_single_line_comment) {
                        continue 2;
                    }

                    if ($chars[$i + 1] === '/') {
                        $in_multi_line_comment = false;
                        ++$i;
                    }

                    continue 2;

                case '{':
                    if ($in_multi_line_comment || $in_single_line_comment) {
                        continue 2;
                    }

                    break 2;

                case '=':
                    if ($in_multi_line_comment || $in_single_line_comment) {
                        continue 2;
                    }
                    break 2;

                case '?':
                    if ($in_multi_line_comment || $in_single_line_comment) {
                        continue 2;
                    }

                    $this->return_typehint_start = $i + $end_bracket_position + 1;
                    break;
            }

            if ($in_multi_line_comment || $in_single_line_comment) {
                continue;
            }

            if ($chars[$i] === '\\' || preg_match('/\w/', $char)) {
                if ($this->return_typehint_start === null) {
                    $this->return_typehint_start = $i + $end_bracket_position + 1;
                }

                if (!isset($chars[$i + 1])
                    || ($chars[$i + 1] !== '\\' && !preg_match('/[\w]/', $chars[$i + 1]))
                ) {
                    $this->return_typehint_end = $i + $end_bracket_position + 2;
                    break;
                }
            }
        }

        if ($stmt->returnType !== null) {
            $this->return_typehint_start = (int) $stmt->returnType->getAttribute('startFilePos');
            $this->return_typehint_end = (int) $stmt->returnType->getAttribute('endFilePos') + 1;
        }

        $preceding_newline_pos = strrpos($file_contents, "\n", $this->docblock_end - strlen($file_contents));

        if ($preceding_newline_pos === false) {
            $this->indentation = '';

            return;
        }

        $first_line = substr($file_contents, $preceding_newline_pos + 1, $this->docblock_end - $preceding_newline_pos);

        $this->indentation = str_replace(ltrim($first_line), '', $first_line);
    }

    /**
     * Sets the new return type
     *
     * @psalm-external-mutation-free
     */
    public function setReturnType(
        ?string $php_type,
        string $new_type,
        string $phpdoc_type,
        bool $is_php_compatible,
        ?string $description,
    ): void {
        $new_type = str_replace(['<mixed, mixed>', '<array-key, mixed>'], '', $new_type);

        $this->new_php_return_type = $php_type;
        $this->new_phpdoc_return_type = $phpdoc_type;
        $this->new_psalm_return_type = $new_type;
        $this->return_type_is_php_compatible = $is_php_compatible;
        $this->return_type_description = $description;
    }

    /**
     * Sets a new param type
     *
     * @psalm-external-mutation-free
     */
    public function setParamType(
        string $param_name,
        ?string $php_type,
        string $new_type,
        string $phpdoc_type,
    ): void {
        $new_type = str_replace(['<mixed, mixed>', '<array-key, mixed>', '<never, never>'], '', $new_type);

        if ($php_type === 'static') {
            $php_type = '';
        }
        if ($php_type) {
            $this->new_php_param_types[$param_name] = $php_type;
        }

        if ($php_type !== $phpdoc_type) {
            $this->new_phpdoc_param_types[$param_name] = $phpdoc_type;
        }
        if ($php_type !== $new_type && $phpdoc_type !== $new_type) {
            $this->new_psalm_param_types[$param_name] = $new_type;
        }
    }

    /**
     * Gets a new docblock given the existing docblock, if one exists, and the updated return types
     * and/or parameters
     */
    private function getDocblock(): string
    {
        $docblock = $this->stmt->getDocComment();

        if ($docblock) {
            $parsed_docblock = DocComment::parsePreservingLength($docblock);
        } else {
            $parsed_docblock = new ParsedDocblock('', []);
        }

        $modified_docblock = false;

        foreach ($this->new_phpdoc_param_types as $param_name => $phpdoc_type) {
            $found_in_params = false;
            $new_param_block = $phpdoc_type . ' ' . '$' . $param_name;

            if (isset($parsed_docblock->tags['param'])) {
                foreach ($parsed_docblock->tags['param'] as &$param_block) {
                    $doc_parts = CommentAnalyzer::splitDocLine($param_block);

                    // If there's no type
                    if (($doc_parts[0] ?? null) === '$' . $param_name) {
                        // If the parameter has a description add that back
                        if (count($doc_parts) > 1) {
                            $new_param_block .= " ". implode(" ", array_slice($doc_parts, 1));
                        }

                        if ($param_block !== $new_param_block) {
                            $modified_docblock = true;
                        }

                        $param_block = $new_param_block;
                        $found_in_params = true;
                        break;
                    }

                    // If there is a type
                    if (($doc_parts[1] ?? null) === '$' . $param_name) {
                        // If the parameter has a description add that back
                        if (count($doc_parts) > 2) {
                            $new_param_block .= " ". implode(" ", array_slice($doc_parts, 2));
                        }

                        if ($param_block !== $new_param_block) {
                            $modified_docblock = true;
                        }

                        $param_block = $new_param_block;
                        $found_in_params = true;
                        break;
                    }
                }
                unset($param_block);
            }

            if (!$found_in_params) {
                $modified_docblock = true;
                $parsed_docblock->tags['param'][] = $new_param_block;
            }
        }

        foreach ($this->new_psalm_param_types as $param_name => $psalm_type) {
            $found_in_params = false;
            $new_param_block = $psalm_type . ' ' . '$' . $param_name;

            if (isset($parsed_docblock->tags['psalm-param'])) {
                foreach ($parsed_docblock->tags['psalm-param'] as &$param_block) {
                    $doc_parts = CommentAnalyzer::splitDocLine($param_block);

                    if (($doc_parts[1] ?? null) === '$' . $param_name) {
                        if ($param_block !== $new_param_block) {
                            $modified_docblock = true;
                        }

                        $param_block = $new_param_block;
                        $found_in_params = true;
                        break;
                    }
                }
                unset($param_block);
            }

            if (!$found_in_params) {
                $modified_docblock = true;
                $parsed_docblock->tags['psalm-param'][] = $new_param_block;
            }
        }

        $old_phpdoc_return_type = null;
        if (isset($parsed_docblock->tags['return'])) {
            $old_phpdoc_return_type = reset($parsed_docblock->tags['return']);
        }

        if ($this->allowed_mutations !== null) {
            $modified_docblock = true;
            unset($parsed_docblock->tags['psalm-pure']);
            unset($parsed_docblock->tags['psalm-mutation-free']);
            unset($parsed_docblock->tags['psalm-external-mutation-free']);
            unset($parsed_docblock->tags['psalm-impure']);
            $parsed_docblock->tags[
                Mutations::TO_ATTRIBUTE_FUNCTIONLIKE[$this->allowed_mutations]
            ] = [''];
        }
        if ($this->removedThrowsExceptions !== [] && isset($parsed_docblock->tags['throws'])) {
            $removed_throws_exceptions = [];
            foreach ($this->removedThrowsExceptions as $exception) {
                $removed_throws_exceptions[strtolower($exception)] = true;
            }

            $throws_tags = [];
            foreach ($parsed_docblock->tags['throws'] as $throws_tag) {
                $throws_parts = preg_split('/[\s]+/', $throws_tag, 2);
                if ($throws_parts === false || $throws_parts[0] === '') {
                    $throws_tags[] = $throws_tag;
                    continue;
                }

                $remaining_exceptions = [];
                foreach (explode('|', $throws_parts[0]) as $exception) {
                    $exception = trim($exception);
                    if ($exception !== '' && !isset($removed_throws_exceptions[strtolower($exception)])) {
                        $remaining_exceptions[] = $exception;
                    }
                }

                if (count($remaining_exceptions) === count(explode('|', $throws_parts[0]))) {
                    $throws_tags[] = $throws_tag;
                    continue;
                }

                $modified_docblock = true;
                if ($remaining_exceptions === []) {
                    continue;
                }

                $throws_tags[] = implode('|', $remaining_exceptions)
                    . (isset($throws_parts[1]) ? ' ' . $throws_parts[1] : '');
            }

            if ($throws_tags === []) {
                unset($parsed_docblock->tags['throws']);
            } else {
                $parsed_docblock->tags['throws'] = $throws_tags;
            }
        }

        if ($this->replacedThrowsExceptions !== [] && isset($parsed_docblock->tags['throws'])) {
            $throws_tags = [];
            foreach ($parsed_docblock->tags['throws'] as $throws_tag) {
                $throws_parts = preg_split('/[\s]+/', $throws_tag, 2);
                if ($throws_parts === false || $throws_parts[0] === '') {
                    $throws_tags[] = $throws_tag;
                    continue;
                }

                $remaining_exceptions = [];
                $remaining_exceptions_lc = [];
                $throws_tag_modified = false;
                foreach (explode('|', $throws_parts[0]) as $exception) {
                    $exception = trim($exception);
                    $exception_lc = strtolower($exception);

                    if (array_key_exists($exception_lc, $this->replacedThrowsExceptions)) {
                        foreach ($this->replacedThrowsExceptions[$exception_lc] as $replacement_exception) {
                            $replacement_exception_lc = strtolower($replacement_exception);
                            if (!isset($remaining_exceptions_lc[$replacement_exception_lc])) {
                                $remaining_exceptions[] = $replacement_exception;
                                $remaining_exceptions_lc[$replacement_exception_lc] = true;
                            }
                        }

                        $throws_tag_modified = true;
                        continue;
                    }

                    if ($exception !== '' && !isset($remaining_exceptions_lc[$exception_lc])) {
                        $remaining_exceptions[] = $exception;
                        $remaining_exceptions_lc[$exception_lc] = true;
                    }
                }

                if (!$throws_tag_modified) {
                    $throws_tags[] = $throws_tag;
                    continue;
                }

                $modified_docblock = true;
                if ($remaining_exceptions === []) {
                    continue;
                }

                $throws_tags[] = implode('|', $remaining_exceptions)
                    . (isset($throws_parts[1]) ? ' ' . $throws_parts[1] : '');
            }

            if ($throws_tags === []) {
                unset($parsed_docblock->tags['throws']);
            } else {
                $parsed_docblock->tags['throws'] = $throws_tags;
            }
        }

        if (count($this->throwsExceptions) > 0) {
            $modified_docblock = true;
            if (array_key_exists('throws', $parsed_docblock->tags)) {
                $parsed_docblock->tags['throws'] = array_merge(
                    $parsed_docblock->tags['throws'],
                    $this->throwsExceptions,
                );
            } else {
                $parsed_docblock->tags['throws'] = $this->throwsExceptions;
            }
        }

        if ($this->normalizeThrowsDocblock && isset($parsed_docblock->tags['throws'])) {
            $throws_tags = [];
            $seen_throws_clauses = [];
            $described_throws_clauses = [];
            $has_described_throws = false;

            foreach ($parsed_docblock->tags['throws'] as $throws_tag) {
                $throws_parts = preg_split('/[\s]+/', $throws_tag, 2);
                if ($throws_parts === false
                    || $throws_parts[0] === ''
                    || !isset($throws_parts[1])
                    || trim($throws_parts[1]) === ''
                ) {
                    continue;
                }

                foreach (explode('|', $throws_parts[0]) as $exception) {
                    $described_throws_clauses[strtolower(trim($exception))] = true;
                }
            }

            foreach ($parsed_docblock->tags['throws'] as $throws_tag) {
                $throws_parts = preg_split('/[\s]+/', $throws_tag, 2);
                if ($throws_parts === false || $throws_parts[0] === '') {
                    $throws_tags[] = $throws_tag;
                    continue;
                }

                $exceptions = explode('|', $throws_parts[0]);
                if (isset($throws_parts[1]) && trim($throws_parts[1]) !== '') {
                    $has_described_throws = true;
                    foreach ($exceptions as $exception) {
                        $seen_throws_clauses[strtolower(trim($exception))] = true;
                    }
                    $throws_tags[] = $throws_tag;
                    continue;
                }

                foreach ($exceptions as $exception) {
                    $exception = trim($exception);
                    $exception_lc = strtolower($exception);
                    if ($exception === ''
                        || isset($seen_throws_clauses[$exception_lc])
                        || isset($described_throws_clauses[$exception_lc])
                    ) {
                        $modified_docblock = true;
                        continue;
                    }

                    $seen_throws_clauses[$exception_lc] = true;
                    $throws_tags[] = $exception
                        . (isset($throws_parts[1]) ? ' ' . $throws_parts[1] : '');
                }

                if (count($exceptions) > 1) {
                    $modified_docblock = true;
                }
            }

            if (!$has_described_throws) {
                $unsorted_throws_tags = $throws_tags;
                natcasesort($throws_tags);
                $throws_tags = array_values($throws_tags);
                if ($throws_tags !== $unsorted_throws_tags) {
                    $modified_docblock = true;
                }
            }

            $parsed_docblock->tags['throws'] = $throws_tags;
        }


        if ($this->new_phpdoc_return_type && $this->new_phpdoc_return_type !== $old_phpdoc_return_type) {
            $modified_docblock = true;
            if ($this->new_phpdoc_return_type !== $this->new_php_return_type || $this->return_type_description) {
                //only add the type if it's different than signature or if there's a description
                $parsed_docblock->tags['return'] = [
                    $this->new_phpdoc_return_type
                    . ($this->return_type_description ? (' ' . $this->return_type_description) : ''),
                ];
            } else {
                unset($parsed_docblock->tags['return']);
            }
        }

        $old_psalm_return_type = null;
        if (isset($parsed_docblock->tags['psalm-return'])) {
            $old_psalm_return_type = reset($parsed_docblock->tags['psalm-return']);
        }

        if ($this->new_psalm_return_type
            && $this->new_phpdoc_return_type !== $this->new_psalm_return_type
            && $this->new_psalm_return_type !== $old_psalm_return_type
        ) {
            $modified_docblock = true;
            $parsed_docblock->tags['psalm-return'] = [$this->new_psalm_return_type];
        }

        if (!$parsed_docblock->tags && !$parsed_docblock->description) {
            return '';
        }

        if (!$modified_docblock) {
            return (string)$docblock . "\n" . $this->indentation;
        }

        return $parsed_docblock->render($this->indentation);
    }

    /**
     * @return array<int, FileManipulation>
     */
    public static function getManipulationsForFile(string $file_path): array
    {
        if (!isset(self::$manipulators[$file_path])) {
            return [];
        }

        $file_manipulations = [];

        /**
         * @var array<string, array{
         *     position: int,
         *     indentation: string,
         *     after_use: bool,
         *     imports: array<lowercase-string, string>,
         *     conflicting_aliases: array<lowercase-string, true>
         * }>
         */
        $throws_import_groups = [];

        foreach (self::$manipulators[$file_path] as $manipulator) {
            if ($manipulator->throwsImports !== []
                && $manipulator->throwsImportGroupKey !== null
                && $manipulator->throwsImportPosition !== null
            ) {
                $group_key = $manipulator->throwsImportGroupKey;
                $throws_import_groups[$group_key] ??= [
                    'position' => $manipulator->throwsImportPosition,
                    'indentation' => $manipulator->throwsImportIndentation,
                    'after_use' => $manipulator->throwsImportAfterUse,
                    'imports' => [],
                    'conflicting_aliases' => [],
                ];

                foreach ($manipulator->throwsImports as $import) {
                    $alias = strtolower(self::getClassShortName($import));
                    $existing_import = $throws_import_groups[$group_key]['imports'][$alias] ?? null;

                    if ($existing_import !== null && strtolower($existing_import) !== strtolower($import)) {
                        $throws_import_groups[$group_key]['conflicting_aliases'][$alias] = true;
                    } else {
                        $throws_import_groups[$group_key]['imports'][$alias] = $import;
                    }
                }
            }
        }

        foreach ($throws_import_groups as &$group) {
            foreach ($group['conflicting_aliases'] as $alias => $_) {
                unset($group['imports'][$alias]);
            }

            natcasesort($group['imports']);
        }
        unset($group);

        foreach (self::$manipulators[$file_path] as $manipulator) {
            if ($manipulator->throwsImportGroupKey !== null) {
                $group = $throws_import_groups[$manipulator->throwsImportGroupKey] ?? null;
                if ($group !== null && $group['conflicting_aliases'] !== []) {
                    $manipulator->qualifyConflictingThrowsImports($group['conflicting_aliases']);
                }
            }

            if ($manipulator->new_php_return_type) {
                if ($manipulator->return_typehint_start && $manipulator->return_typehint_end) {
                    $file_manipulations[$manipulator->return_typehint_start] = new FileManipulation(
                        $manipulator->return_typehint_start,
                        $manipulator->return_typehint_end,
                        $manipulator->new_php_return_type,
                    );
                } else {
                    $file_manipulations[$manipulator->return_typehint_area_start] = new FileManipulation(
                        $manipulator->return_typehint_area_start,
                        $manipulator->return_typehint_area_start,
                        ': ' . $manipulator->new_php_return_type,
                    );
                }
            } elseif ($manipulator->new_php_return_type === ''
                && $manipulator->return_typehint_colon_start
                && $manipulator->new_phpdoc_return_type
                && $manipulator->return_typehint_start
                && $manipulator->return_typehint_end
            ) {
                $file_manipulations[$manipulator->return_typehint_start] = new FileManipulation(
                    $manipulator->return_typehint_colon_start,
                    $manipulator->return_typehint_end,
                    '',
                );
            }

            if (!$manipulator->new_php_return_type
                || !$manipulator->return_type_is_php_compatible
                || $manipulator->docblock_start !== $manipulator->docblock_end
                || $manipulator->allowed_mutations !== null
            ) {
                $file_manipulations[$manipulator->docblock_start] = new FileManipulation(
                    $manipulator->docblock_start,
                    $manipulator->docblock_end,
                    $manipulator->getDocblock(),
                );
            }

            foreach ($manipulator->new_php_param_types as $param_name => $new_php_param_type) {
                if (!isset($manipulator->param_offsets[$param_name])) {
                    continue;
                }

                $param_offset = $manipulator->param_offsets[$param_name];

                $typehint_offsets = $manipulator->param_typehint_offsets[$param_name] ?? null;

                if ($new_php_param_type) {
                    if ($typehint_offsets) {
                        $file_manipulations[$typehint_offsets[0]] = new FileManipulation(
                            $typehint_offsets[0],
                            $typehint_offsets[1],
                            $new_php_param_type,
                        );
                    } else {
                        $file_manipulations[$param_offset] = new FileManipulation(
                            $param_offset,
                            $param_offset,
                            $new_php_param_type . ' ',
                        );
                    }
                } elseif ($new_php_param_type === ''
                    && $typehint_offsets
                ) {
                    $file_manipulations[$typehint_offsets[0]] = new FileManipulation(
                        $typehint_offsets[0],
                        $param_offset,
                        '',
                    );
                }
            }
        }

        foreach ($throws_import_groups as $group) {
            if ($group['imports'] === []) {
                continue;
            }

            $import_lines = [];
            foreach (array_values($group['imports']) as $import) {
                $import_lines[] = $group['indentation'] . 'use ' . $import . ';';
            }

            $insertion_text = implode("\n", $import_lines);
            $insertion_text = $group['after_use']
                ? "\n" . $insertion_text
                : $insertion_text . "\n\n";

            $file_manipulations[] = new FileManipulation(
                $group['position'],
                $group['position'],
                $insertion_text,
            );
        }

        return $file_manipulations;
    }

    /**
     * @param array<lowercase-string, true> $conflicting_aliases
     * @psalm-external-mutation-free
     */
    private function qualifyConflictingThrowsImports(array $conflicting_aliases): void
    {
        foreach ($this->throwsImports as $import) {
            $short_name = self::getClassShortName($import);
            if (!isset($conflicting_aliases[strtolower($short_name)])) {
                continue;
            }

            foreach ($this->throwsExceptions as $offset => $exception) {
                if ($exception === $short_name) {
                    $this->throwsExceptions[$offset] = '\\' . self::getImportedClassName($import);
                }
            }

            foreach ($this->replacedThrowsExceptions as $replaced_exception => $replacement_exceptions) {
                $qualified_replacement_exceptions = [];
                foreach ($replacement_exceptions as $exception) {
                    $qualified_replacement_exceptions[] = $exception === $short_name
                        ? '\\' . self::getImportedClassName($import)
                        : $exception;
                }

                $this->replacedThrowsExceptions[$replaced_exception] = $qualified_replacement_exceptions;
            }
        }
    }

    /**
     * @param Mutations::LEVEL_* $allowed_mutations
     * @psalm-external-mutation-free
     */
    public function setAllowedMutations(int $allowed_mutations): void
    {
        $this->allowed_mutations = $allowed_mutations;
    }

    /**
     * @param list<string> $exceptions
     * @param list<string> $imports
     */
    public function addThrowsDocblock(
        array $exceptions,
        array $imports,
        ProjectAnalyzer $project_analyzer,
    ): void {
        $this->normalizeThrowsDocblock = true;
        $this->throwsExceptions = $exceptions;
        $this->throwsImports = $imports;

        if ($imports !== []) {
            $this->initializeThrowsImportPosition($project_analyzer);
        }
    }

    /**
     * @param list<string> $exceptions
     * @psalm-external-mutation-free
     */
    public function removeThrowsDocblock(array $exceptions): void
    {
        $this->normalizeThrowsDocblock = true;
        $this->removedThrowsExceptions = $exceptions;
    }

    /**
     * @param array<string, list<string>> $replacements
     * @param list<string> $imports
     */
    public function replaceThrowsDocblock(
        array $replacements,
        array $imports,
        ProjectAnalyzer $project_analyzer,
    ): void {
        $this->normalizeThrowsDocblock = true;

        foreach ($replacements as $exception => $replacement_exceptions) {
            $this->replacedThrowsExceptions[strtolower($exception)] = $replacement_exceptions;
        }

        $this->throwsImports = array_values(array_unique(array_merge($this->throwsImports, $imports)));

        if ($imports !== []) {
            $this->initializeThrowsImportPosition($project_analyzer);
        }
    }

    private function initializeThrowsImportPosition(ProjectAnalyzer $project_analyzer): void
    {
        $codebase = $project_analyzer->getCodebase();
        $statements = $codebase->getStatementsForFile($this->file_path);
        $function_start = (int) $this->stmt->getAttribute('startFilePos');
        $scope_statements = $statements;
        $namespace_start = -1;

        foreach ($statements as $statement) {
            if ($statement instanceof PhpParser\Node\Stmt\Namespace_
                && (int) $statement->getAttribute('startFilePos') <= $function_start
                && (int) $statement->getAttribute('endFilePos') >= $function_start
            ) {
                $scope_statements = $statement->stmts;
                $namespace_start = (int) $statement->getAttribute('startFilePos');
                break;
            }
        }

        $this->throwsImportGroupKey = (string) $namespace_start;
        $last_use = null;

        foreach ($scope_statements as $statement) {
            if ($statement instanceof PhpParser\Node\Stmt\Use_
                || $statement instanceof PhpParser\Node\Stmt\GroupUse
            ) {
                $last_use = $statement;
            }
        }

        $file_contents = $codebase->getFileContents($this->file_path);

        if ($last_use !== null) {
            $use_start = (int) $last_use->getAttribute('startFilePos');
            $this->throwsImportPosition = (int) $last_use->getAttribute('endFilePos') + 1;
            $this->throwsImportIndentation = self::getLineIndentation($file_contents, $use_start);
            $this->throwsImportAfterUse = true;
            return;
        }

        foreach ($scope_statements as $statement) {
            if ($statement instanceof PhpParser\Node\Stmt\Declare_) {
                continue;
            }

            $statement_start = (int) $statement->getAttribute('startFilePos');
            $comments = $statement->getComments();
            $first_comment = reset($comments);
            if ($first_comment !== false) {
                $statement_start = $first_comment->getStartFilePos();
            }

            $line_start = strrpos($file_contents, "\n", $statement_start - strlen($file_contents));
            $line_start = $line_start === false ? 0 : $line_start + 1;

            $this->throwsImportPosition = $line_start;
            $this->throwsImportIndentation = substr($file_contents, $line_start, $statement_start - $line_start);
            return;
        }
    }

    /** @psalm-pure */
    private static function getLineIndentation(string $file_contents, int $position): string
    {
        $line_start = strrpos($file_contents, "\n", $position - strlen($file_contents));
        $line_start = $line_start === false ? 0 : $line_start + 1;

        return substr($file_contents, $line_start, $position - $line_start);
    }

    /** @psalm-pure */
    private static function getClassShortName(string $fq_class_name): string
    {
        $alias_position = strripos($fq_class_name, ' as ');
        if ($alias_position !== false) {
            return substr($fq_class_name, $alias_position + 4);
        }

        $separator_position = strrpos($fq_class_name, '\\');

        return $separator_position === false ? $fq_class_name : substr($fq_class_name, $separator_position + 1);
    }

    /** @psalm-pure */
    private static function getImportedClassName(string $import): string
    {
        $alias_position = strripos($import, ' as ');

        return $alias_position === false ? $import : substr($import, 0, $alias_position);
    }

    /**
     * @psalm-external-mutation-free
     */
    public static function clearCache(): void
    {
        self::$manipulators = [];
    }

    /**
     * @param array<string, string> $file_paths
     * @psalm-external-mutation-free
     */
    public static function clearCacheForFiles(array $file_paths): void
    {
        foreach ($file_paths as $file_path) {
            unset(self::$manipulators[$file_path]);
        }
    }

    /**
     * Keep edits for declarations that a throws convergence wave will not visit.
     *
     * @param array<string, array<int, true>|null> $targets
     * @param array<string, array<int, int>> $declarations
     */
    public static function clearCacheForTargets(array $targets, array $declarations): void
    {
        foreach ($targets as $file => $offsets) {
            if ($offsets === null || !isset($declarations[$file])) {
                unset(self::$manipulators[$file]);
                continue;
            }
            foreach (self::$manipulators[$file] ?? [] as $line => $manipulator) {
                foreach ($declarations[$file] ?? [] as $start => $end) {
                    if (isset($offsets[$start])
                        && $manipulator->stmt->getStartFilePos() >= $start
                        && $manipulator->stmt->getEndFilePos() <= $end
                    ) {
                        unset(self::$manipulators[$file][$line]);
                        break;
                    }
                }
            }
        }
    }

    /**
     * @param array<string, array<int, FunctionDocblockManipulator>> $manipulators
     * @psalm-external-mutation-free
     */
    public static function addManipulators(array $manipulators): void
    {
        foreach ($manipulators as $file => $file_manipulators) {
            self::$manipulators[$file] = (self::$manipulators[$file] ?? []) + $file_manipulators;
        }
    }

    /**
     * @return array<string, array<int, FunctionDocblockManipulator>>
     * @psalm-external-mutation-free
     */
    public static function getManipulators(): array
    {
        return self::$manipulators;
    }
}
