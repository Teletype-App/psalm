<?php

declare(strict_types=1);

namespace Psalm\Internal\Codebase;

use Amp\Future;
use Generator;
use InvalidArgumentException;
use PhpParser;
use Psalm\CodeLocation;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\FileManipulation;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\Analyzer\InferredThrowsBuffer;
use Psalm\Internal\Analyzer\IssueData;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\FileManipulation\ClassDocblockManipulator;
use Psalm\Internal\FileManipulation\FileManipulationBuffer;
use Psalm\Internal\FileManipulation\FunctionDocblockManipulator;
use Psalm\Internal\FileManipulation\PropertyDocblockManipulator;
use Psalm\Internal\Fork\AnalyzerTask;
use Psalm\Internal\Fork\InitAnalyzerTask;
use Psalm\Internal\Fork\Pool;
use Psalm\Internal\Fork\ShutdownAnalyzerTask;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\Internal\Provider\FileProvider;
use Psalm\Internal\Provider\FileStorageProvider;
use Psalm\Internal\Provider\InferredThrowsCache;
use Psalm\Internal\Provider\StatementsProvider;
use Psalm\IssueBuffer;
use Psalm\Progress\Phase;
use Psalm\Progress\Progress;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Storage\Mutations;
use Psalm\Type;
use Psalm\Type\Union;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\StrictUnifiedDiffOutputBuilder;
use UnexpectedValueException;

use function Amp\Future\await;
use function array_fill_keys;
use function array_filter;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function ksort;
use function max;
use function number_format;
use function pathinfo;
use function preg_replace;
use function sort;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function substr_count;
use function usort;
use function var_export;

use const PATHINFO_EXTENSION;
use const PHP_INT_MAX;

/**
 * @psalm-type  TaggedCodeType = array<int, array{0: int, 1: non-empty-string}>
 *
 * @psalm-type  FileMapType = array{
 *      0: TaggedCodeType,
 *      1: TaggedCodeType,
 *      2: array<int, array{0: int, 1: non-empty-string, 2: int}>
 * }
 *
 * @psalm-type  WorkerData = array{
 *      issues: array<string, list<IssueData>>,
 *      fixable_issue_counts: array<string, int>,
 *      nonmethod_references_to_classes: array<string, array<string,bool>>,
 *      method_references_to_classes: array<string, array<string,bool>>,
 *      file_references_to_class_members: array<string, array<string,bool>>,
 *      file_references_to_class_properties: array<string, array<string,bool>>,
 *      file_references_to_method_returns: array<string, array<string,bool>>,
 *      file_references_to_missing_class_members: array<string, array<string,bool>>,
 *      mixed_counts: array<string, array{0: int, 1: int}>,
 *      mixed_member_names: array<string, array<string, bool>>,
 *      function_timings: array<string, float>,
 *      file_manipulations: array<string, FileManipulation[]>,
 *      method_references_to_class_members: array<string, array<string,bool>>,
 *      method_dependencies: array<string, array<string,bool>>,
 *      method_references_to_method_returns: array<string, array<string,bool>>,
 *      method_references_to_class_properties: array<string, array<string,bool>>,
 *      method_references_to_missing_class_members: array<string, array<string,bool>>,
 *      method_param_uses: array<string, array<int, array<string, bool>>>,
 *      analyzed_methods: array<string, array<string, int>>,
 *      file_maps: array<string, FileMapType>,
 *      class_locations: array<string, array<int, CodeLocation>>,
 *      class_method_locations: array<string, array<int, CodeLocation>>,
 *      class_property_locations: array<string, array<int, CodeLocation>>,
 *      possible_method_param_types: array<string, array<int, Union>>,
 *      taint_data: ?TaintFlowGraph,
 *      unused_suppressions: array<string, array<int, int>>,
 *      used_suppressions: array<string, array<int, bool>>,
 *      function_docblock_manipulators: array<string, array<int, FunctionDocblockManipulator>>,
 *      inferred_throws: array<lowercase-string, array<string, true>>,
 *      throws_conditions: array<lowercase-string, array<string, list<array<int, bool|int|string|null>>>>,
 *      throws_context_summaries: array<lowercase-string, array<string, array<string, true>>>,
 *      throws_context_conditions: array<
 *          lowercase-string,
 *          array<string, array<string, list<array<int, bool|int|string|null>>>>
 *      >,
 *      throws_dependencies: array<string, array<string, true>>,
 *      throws_call_targets: array<string, array<int, true>>,
 *      throws_call_edges: array<string, array<int, array<string, array<int, true>>>>,
 *      mutable_classes: array<string, Mutations::LEVEL_*>,
 *      issue_handlers: array{type: string, index: int, count: int}[],
 * }
 */

/**
 * @internal
 * @psalm-import-type Entry from InferredThrowsCache
 */
final class Analyzer
{
    private const MAX_THROWS_CONDITIONS = 64;
    /** @var array<string, array<int, true>|null>|null Null file entries select every declaration. */
    private ?array $throws_analysis_targets = null;

    private ?InferredThrowsCache $throws_cache = null;
    /** @var array<lowercase-string, array<string, array<string, true>>> */
    private array $throws_context_summaries = [];
    /** @var array<lowercase-string, array<string, array<string, list<array<int, bool|int|string|null>>>>> */
    private array $throws_context_conditions = [];
    /** @var array<string, array<string, true>> */
    private array $throws_dependencies = [];

    /** @var array<string, array<int, array<string, array<int, true>>>> */
    private array $throws_call_edges = [];

    /** @psalm-mutation-free */
    public function shouldAnalyzeThrowsTarget(string $file_path, int $offset): bool
    {
        return $this->throws_analysis_targets === null
            || (array_key_exists($file_path, $this->throws_analysis_targets)
                && ($this->throws_analysis_targets[$file_path] === null
                    || isset($this->throws_analysis_targets[$file_path][$offset])));
    }

    /**
     * Used to store counts of mixed vs non-mixed variables
     *
     * @var array<string, list{int, int}>
     */
    private array $mixed_counts = [];

    /**
     * Used to store member names of mixed property/method access
     *
     * @var array<string, array<string, bool>>
     */
    private array $mixed_member_names = [];

    private bool $count_mixed = true;

    /**
     * Used to store debug performance data
     *
     * @var array<string, float>
     */
    private array $function_timings = [];

    /**
     * We analyze more files than we necessarily report errors in
     *
     * @var array<string, string>
     */
    private array $files_to_analyze = [];

    /**
     * We can show analysis results on more files than we analyze
     * because the results can be cached
     *
     * @var array<string, string>
     */
    private array $files_with_analysis_results = [];

    /**
     * We may update fewer files than we analyse (i.e. for dead code detection)
     *
     * @var array<string>|null
     */
    private ?array $files_to_update = null;

    /**
     * @var array<string, array<string, int>>
     */
    private array $analyzed_methods = [];

    /**
     * @var array<string, array<int, IssueData>>
     */
    private array $existing_issues = [];

    /**
     * @var array<string, array<int, array{0: int, 1: non-empty-string}>>
     */
    private array $reference_map = [];

    /**
     * @var array<string, array<int, array{0: int, 1: non-empty-string}>>
     */
    private array $type_map = [];

    /**
     * @var array<string, array<int, array{0: int, 1: non-empty-string, 2: int}>>
     */
    private array $argument_map = [];

    /**
     * @var array<string, array<int, Union>>
     */
    public array $possible_method_param_types = [];

    /**
     * @var array<string, Mutations::LEVEL_*>
     */
    public array $mutable_classes = [];

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly Config $config,
        private readonly FileProvider $file_provider,
        private readonly FileStorageProvider $file_storage_provider,
        private readonly Progress $progress,
    ) {
    }

    /**
     * @param array<string, string> $files_to_analyze
     * @psalm-external-mutation-free
     */
    public function addFilesToAnalyze(array $files_to_analyze): void
    {
        $this->files_to_analyze += $files_to_analyze;
        $this->files_with_analysis_results += $files_to_analyze;
    }

    /**
     * @param array<string, string> $files_to_analyze
     * @psalm-external-mutation-free
     */
    public function addFilesToShowResults(array $files_to_analyze): void
    {
        $this->files_with_analysis_results += $files_to_analyze;
    }

    /**
     * @param array<string> $files_to_update
     * @psalm-external-mutation-free
     */
    public function setFilesToUpdate(array $files_to_update): void
    {
        $this->files_to_update = $files_to_update;
    }

    /**
     * @psalm-mutation-free
     */
    public function canReportIssues(string $file_path): bool
    {
        return isset($this->files_with_analysis_results[$file_path]);
    }

    public function analyzeFiles(
        ProjectAnalyzer $project_analyzer,
        int $pool_size,
        bool $alter_code,
        bool $consolidate_analyzed_data = false,
    ): void {
        $this->throws_cache = null;
        $this->throws_analysis_targets = null;
        $this->throws_context_summaries = [];
        $this->throws_context_conditions = [];
        $this->throws_dependencies = [];
        $this->throws_call_edges = [];
        $this->loadCachedResults($project_analyzer);

        $codebase = $project_analyzer->getCodebase();

        if ($alter_code) {
            $project_analyzer->interpretRefactors();
        }

        $this->files_to_analyze = array_filter(
            $this->files_to_analyze,
            $this->file_provider->fileExists(...),
        );

        if ($codebase->config->check_for_throws_docblock && !$codebase->language_server
            && $this->file_provider::class === FileProvider::class) {
            $this->throws_cache = new InferredThrowsCache(
                $this->config,
                $this->files_to_analyze,
                $codebase->analysis_php_version_id,
            );
            foreach ($this->throws_cache->entries() as $file => $entry) {
                if ($entry['summaries'] !== []) {
                    $this->progress->debug("Reusing inferred throws: " . $file . "\n");
                    foreach ($entry['summaries'] as $id => $_) {
                        $this->progress->debug("Reusing inferred throws method: " . $id . "\n");
                    }
                }
                foreach ($entry['dependencies'] as $callee => $_) {
                    $this->throws_dependencies[$callee][$file] = true;
                }
            }
        }
        if ($alter_code && $project_analyzer->changed_file_scope !== null
            && $codebase->config->check_for_throws_docblock) {
            $this->throws_analysis_targets = [];
            foreach ($this->files_to_analyze as $file => $_) {
                $this->throws_analysis_targets[$file] = [];
                $functions = (new PhpParser\NodeFinder())->find(
                    $codebase->getStatementsForFile($file),
                    static fn(PhpParser\Node $node): bool => $node instanceof PhpParser\Node\Stmt\ClassMethod
                        || $node instanceof PhpParser\Node\Stmt\Function_,
                );
                foreach ($functions as $function) {
                    if ($project_analyzer->canFixFunctionLike(
                        $file,
                        $function->getDocComment()?->getStartLine() ?? $function->getStartLine(),
                        $function->getEndLine(),
                    )) {
                        $this->throws_analysis_targets[$file][$function->getStartFilePos()] = true;
                    }
                }
            }
        }
        $this->resetInferredThrows();
        InferredThrowsBuffer::clear();
        InferredThrowsBuffer::useCodeOnlySummaries(
            $alter_code && $codebase->config->check_for_throws_docblock,
        );
        $this->doAnalysis($project_analyzer, $pool_size);

        if ($codebase->config->check_for_throws_docblock
            && (!$alter_code
                || isset($project_analyzer->getIssuesToFix()['MissingThrowsDocblock']))
        ) {
            $selected_files = $this->files_to_analyze;
            $this->analyzeThrowsDependencies($project_analyzer, $pool_size);
            $this->convergeInferredThrows($project_analyzer, $pool_size);
            $this->saveThrowsCache();
            $this->files_to_analyze = $selected_files;
            $this->throws_analysis_targets = null;

            if (!$alter_code) {
                IssueBuffer::clearCache();
                InferredThrowsBuffer::clear();
                $this->doAnalysis($project_analyzer, $pool_size);
            }
        }

        InferredThrowsBuffer::addDependencies($this->throws_dependencies);
        $scanned_files = $codebase->scanner->getScannedFiles();

        if ($codebase->taint_flow_graph) {
            $codebase->taint_flow_graph->connectSinksAndSources($codebase->progress);
        }

        $this->progress->finish();

        if ($consolidate_analyzed_data) {
            $project_analyzer->consolidateAnalyzedData();
        }

        foreach (IssueBuffer::getIssuesData() as $file_path => $file_issues) {
            $codebase->file_reference_provider->clearExistingIssuesForFile($file_path);

            foreach ($file_issues as $issue_data) {
                $codebase->file_reference_provider->addIssue($file_path, $issue_data);
            }
        }

        $codebase->file_reference_provider->updateReferenceCache($codebase, $scanned_files);

        if ($codebase->track_unused_suppressions) {
            IssueBuffer::processUnusedSuppressions($codebase->file_provider);
        }

        $codebase->file_reference_provider->setAnalyzedMethods($this->analyzed_methods);
        $codebase->file_reference_provider->setFileMaps($this->getFileMaps());
        $codebase->file_reference_provider->setTypeCoverage($this->mixed_counts);
        $codebase->file_reference_provider->updateReferenceCache($codebase, $scanned_files);

        if ($codebase->diff_methods) {
            $codebase->statements_provider->resetDiffs();
        }

        if ($alter_code) {
            $this->progress->startPhase(Phase::ALTERING);

            $project_analyzer->prepareMigration();

            $files_to_update = $this->files_to_update ?? $this->files_to_analyze;

            foreach ($files_to_update as $file_path) {
                $this->updateFile($file_path, $project_analyzer->dry_run);
            }

            $project_analyzer->migrateCode();
        }
    }

    private function resetInferredThrows(): void
    {
        foreach (ClassLikeStorageProvider::getAll() as $classlike_storage) {
            foreach ($classlike_storage->methods as $method_storage) {
                $method_storage->inferred_throws = !$classlike_storage->is_interface
                    && !$method_storage->abstract
                    && $method_storage->location !== null
                    && isset($this->files_to_analyze[$method_storage->location->file_path])
                        ? []
                        : null;
                $method_storage->inferred_throws_conditions = $method_storage->inferred_throws === null ? null : [];
            }
        }

        foreach (FileStorageProvider::getAll() as $file_storage) {
            foreach ($file_storage->functions as $function_storage) {
                $function_storage->inferred_throws = $function_storage->location !== null
                    && isset($this->files_to_analyze[$function_storage->location->file_path]) ? [] : null;
                $function_storage->inferred_throws_conditions =
                    $function_storage->inferred_throws === null ? null : [];
            }
        }
    }

    private function doAnalysis(ProjectAnalyzer $project_analyzer, int $pool_size): void
    {
        $this->restoreThrowsCache();
        $this->doUncachedAnalysis($project_analyzer, $pool_size);
        // A shared trait body is analyzed in several using classes. A wave may
        // revisit only one of them; retain the other contexts instead of making
        // their exceptions disappear and reappear in alternating waves.
        foreach (InferredThrowsBuffer::getContextSummaries() as $id => $contexts) {
            $this->throws_context_summaries[$id] = $contexts + ($this->throws_context_summaries[$id] ?? []);
            $combined = [];
            foreach ($this->throws_context_summaries[$id] as $throws) {
                $combined += $throws;
            }
            InferredThrowsBuffer::add([$id => $combined]);
        }
        foreach (InferredThrowsBuffer::getContextConditions() as $id => $contexts) {
            foreach ($contexts as $file => $conditions) {
                $this->throws_context_conditions[$id][$file] = self::mergeThrowsConditions(
                    $this->throws_context_conditions[$id][$file] ?? [],
                    $conditions,
                );
            }
            $combined = [];
            foreach ($this->throws_context_conditions[$id] as $conditions) {
                $combined = self::mergeThrowsConditions($combined, $conditions);
            }
            InferredThrowsBuffer::addConditions([$id => $combined]);
        }
        foreach (InferredThrowsBuffer::getDependencies() as $callee => $callers) {
            $this->throws_dependencies[$callee] = $callers + ($this->throws_dependencies[$callee] ?? []);
        }
        foreach (InferredThrowsBuffer::getCallEdges() as $caller => $positions) {
            foreach ($positions as $position => $callees) {
                foreach ($callees as $callee => $offsets) {
                    $this->throws_call_edges[$caller][$position][$callee] =
                        $offsets + ($this->throws_call_edges[$caller][$position][$callee] ?? []);
                }
            }
        }
    }

    private function restoreThrowsCache(): void
    {
        $cached = $this->throws_cache?->entries() ?? [];
        foreach ($this->throwsStorages() as $id => $storage) {
            $file = $storage->location?->file_path;
            if ($file !== null && isset($cached[$file]['summaries'][$id])) {
                $storage->inferred_throws = $cached[$file]['summaries'][$id];
                $storage->inferred_throws_conditions = $cached[$file]['conditions'][$id] ?? [];
            }
        }
    }

    /**
     * @return Generator<lowercase-string, FunctionLikeStorage>
     * @psalm-external-mutation-free
     */
    private function throwsStorages(): Generator
    {
        $trait_files = [];
        foreach (ClassLikeStorageProvider::getAll() as $class) {
            if ($class->is_trait && $class->location !== null) {
                $trait_files[$class->location->file_path] = true;
            }
        }
        foreach (ClassLikeStorageProvider::getAll() as $class) {
            if ($class->is_interface) {
                continue;
            }
            foreach ($class->methods as $name => $storage) {
                if (!$storage->abstract && !isset($trait_files[$storage->location?->file_path ?? ''])) {
                    yield strtolower($class->name . '::' . $name) => $storage;
                }
            }
        }
        foreach (FileStorageProvider::getAll() as $file) {
            foreach ($file->functions as $name => $storage) {
                yield strtolower($name) => $storage;
            }
        }
    }

    /**
     * Explain the final inferred exception set without changing normal report
     * formats. Paths are reconstructed from the converged per-declaration
     * summaries and the exact call edges collected during analysis.
     */
    public function getInferredThrowsReport(): string
    {
        $storages = [];
        $targets = [];
        foreach ($this->throwsStorages() as $id => $storage) {
            $storages[$id] = $storage;
            if ($storage->stmt_location !== null) {
                $targets[$storage->stmt_location->file_path][$storage->stmt_location->raw_file_start] = $id;
            }
        }

        $lines = ['Inferred throws:'];
        $reported = false;
        foreach ($storages as $id => $storage) {
            $location = $storage->location;
            if ($location === null
                || !isset($this->files_to_analyze[$location->file_path])
                || $storage->inferred_throws === null
                || $storage->inferred_throws === []
            ) {
                continue;
            }

            $reported = true;
            $lines[] = $id;
            $exceptions = array_keys($storage->inferred_throws);
            sort($exceptions);
            foreach ($exceptions as $exception) {
                $condition = self::formatThrowsConditions(
                    $storage->inferred_throws_conditions[$exception] ?? [[]],
                );
                $lines[] = '  ' . $exception . $condition;
                $paths = $this->findThrowsPaths($id, $exception, $storages, $targets, [], 0);
                foreach ($paths as $path) {
                    $lines[] = '    ' . implode(' -> ', $path);
                }
            }
        }

        if (!$reported) {
            $lines[] = '  (none)';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<lowercase-string, FunctionLikeStorage> $storages
     * @param array<string, array<int, lowercase-string>> $targets
     * @param array<lowercase-string, true> $visited
     * @param lowercase-string $id
     * @return non-empty-list<non-empty-list<string>>
     */
    private function findThrowsPaths(
        string $id,
        string $exception,
        array $storages,
        array $targets,
        array $visited,
        int $depth,
    ): array {
        $storage = $storages[$id];
        $location = $storage->stmt_location ?? $storage->location;
        if ($location === null || $depth >= 16 || isset($visited[$id])) {
            return [['cycle or unavailable body']];
        }
        $visited[$id] = true;

        $paths = [];
        foreach ($this->throws_call_edges[$location->file_path] ?? [] as $position => $callees) {
            if ($position < $location->raw_file_start || $position > $location->raw_file_end) {
                continue;
            }
            foreach ($callees as $callee_file => $offsets) {
                foreach ($offsets as $offset => $_) {
                    $callee_id = $targets[$callee_file][$offset] ?? null;
                    if ($callee_id === null
                        || !isset($storages[$callee_id]->inferred_throws[$exception])
                    ) {
                        continue;
                    }
                    $call = 'call ' . $callee_id . ' at ' . $this->formatThrowsLocation(
                        $location->file_path,
                        $position,
                    );
                    foreach ($this->findThrowsPaths(
                        $callee_id,
                        $exception,
                        $storages,
                        $targets,
                        $visited,
                        $depth + 1,
                    ) as $suffix) {
                        $paths[] = [$call, ...$suffix];
                        if (count($paths) >= 8) {
                            return $paths;
                        }
                    }
                }
            }
        }

        if ($paths !== []) {
            return $paths;
        }

        return [[
            'throw/rethrow in ' . $id . ' at '
                . $this->config->shortenFileName($location->file_path) . ':' . $location->raw_line_number,
        ]];
    }

    private function formatThrowsLocation(string $file, int $offset): string
    {
        $contents = $this->file_provider->getContents($file);
        $line = substr_count(substr($contents, 0, max(0, $offset)), "\n") + 1;
        return $this->config->shortenFileName($file) . ':' . $line;
    }

    /**
     * @param list<array<int, bool|int|string|null>> $conditions
     * @psalm-pure
     */
    private static function formatThrowsConditions(array $conditions): string
    {
        if ($conditions === [] || in_array([], $conditions, true)) {
            return '';
        }
        $formatted = [];
        foreach ($conditions as $condition) {
            $parts = [];
            foreach ($condition as $offset => $value) {
                $parts[] = '#' . $offset . '=' . var_export($value, true);
            }
            $formatted[] = implode(' & ', $parts);
        }
        return ' when ' . implode(' or ', $formatted);
    }

    private function saveThrowsCache(): void
    {
        foreach (FileStorageProvider::getAll() as $file) {
            if ($file->has_visitor_issues && (isset($this->files_to_analyze[$file->file_path])
                || isset($this->throws_dependencies[$file->file_path]))) {
                $this->progress->debug("Throws cache blocked by " . $file->file_path . "\n");
                return;
            }
        }
        /** @var array<string, Entry> $entries */
        $entries = [];
        foreach ($this->files_to_analyze as $file => $_) {
            $entries[$file] = ['summaries' => [], 'offsets' => [], 'dependencies' => []];
        }
        // Trait summaries depend on their using contexts. Keep graph-only nodes
        // so changes in a using class invalidate consumers of the shared body.
        foreach (ClassLikeStorageProvider::getAll() as $class) {
            if (!$class->is_trait || $class->location === null) {
                continue;
            }
            $file = $class->location->file_path;
            if (!isset($entries[$file])) {
                continue;
            }
            $entries[$file]['contextual'] = true;
            foreach ($class->methods as $name => $_) {
                $id = strtolower($class->name . '::' . $name);
                foreach ($this->throws_context_summaries[$id] ?? [] as $context_file => $_) {
                    if ($context_file !== '') {
                        $entries[$file]['dependencies'][$context_file] = true;
                    }
                }
            }
        }
        foreach ($this->throwsStorages() as $id => $storage) {
            $file = $storage->location?->file_path;
            $offset = $storage->stmt_location?->raw_file_start;
            if ($file === null || $offset === null || !isset($this->files_to_analyze[$file])
                || !$this->shouldAnalyzeThrowsTarget($file, $offset) || $storage->inferred_throws === null) {
                continue;
            }
            $entries[$file]['summaries'][$id] = $storage->inferred_throws;
            $entries[$file]['conditions'][$id] = $storage->inferred_throws_conditions ?? [];
            $entries[$file]['offsets'][$offset] = true;
            $entries[$file]['method_offsets'][$id] = $offset;
            $entries[$file]['dependencies'] ??= [];
        }
        foreach ($this->throws_dependencies as $callee => $callers) {
            foreach ($callers as $caller => $_) {
                if (isset($entries[$caller])) {
                    $entries[$caller]['dependencies'][$callee] = true;
                }
            }
        }
        $this->throws_cache?->save($entries, $this->throws_call_edges);
    }

    private function doUncachedAnalysis(ProjectAnalyzer $project_analyzer, int $pool_size): void
    {
        $this->progress->expand(count($this->files_to_analyze));

        ksort($this->files_to_analyze);

        $codebase = $project_analyzer->getCodebase();

        $task_done_closure = $this->progress->taskDone(...);

        if ($pool_size > 1 && count($this->files_to_analyze) > $pool_size) {
            // Run analysis one file at a time, splitting the set of
            // files up among a given number of child processes.
            $pool = new Pool(
                $pool_size,
                $codebase->config->long_scan_warning,
                $project_analyzer->progress,
            );

            $this->progress->debug('Forking analysis' . "\n");

            // Wait for all tasks to complete and collect the results.
            await($pool->runAll(new InitAnalyzerTask));
            $pool->run($this->files_to_analyze, AnalyzerTask::class, $task_done_closure);
            $forked_pool_data = $pool->runAll(new ShutdownAnalyzerTask);

            $this->progress->debug('Collecting forked analysis results' . "\n");
            $this->progress->startPhase(Phase::MERGING_THREAD_RESULTS);
            $this->progress->expand(count($forked_pool_data));

            foreach (Future::iterate($forked_pool_data) as $pool_data) {
                $pool_data = $pool_data->await();

                IssueBuffer::addIssues($pool_data['issues']);
                IssueBuffer::addFixableIssues($pool_data['fixable_issue_counts']);

                if ($codebase->track_unused_suppressions) {
                    IssueBuffer::addUnusedSuppressions($pool_data['unused_suppressions']);
                    IssueBuffer::addUsedSuppressions($pool_data['used_suppressions']);
                }

                if ($codebase->config->find_unused_issue_handler_suppression) {
                    $codebase->config->combineIssueHandlerSuppressions($pool_data['issue_handlers']);
                }

                if ($codebase->taint_flow_graph && $pool_data['taint_data']) {
                    $codebase->taint_flow_graph->addGraph($pool_data['taint_data']);
                }

                $codebase->file_reference_provider->addNonMethodReferencesToClasses(
                    $pool_data['nonmethod_references_to_classes'],
                );
                $codebase->file_reference_provider->addMethodReferencesToClasses(
                    $pool_data['method_references_to_classes'],
                );
                $codebase->file_reference_provider->addFileReferencesToClassMembers(
                    $pool_data['file_references_to_class_members'],
                );
                $codebase->file_reference_provider->addFileReferencesToClassProperties(
                    $pool_data['file_references_to_class_properties'],
                );
                $codebase->file_reference_provider->addFileReferencesToMethodReturns(
                    $pool_data['file_references_to_method_returns'],
                );
                $codebase->file_reference_provider->addMethodReferencesToClassMembers(
                    $pool_data['method_references_to_class_members'],
                );
                $codebase->file_reference_provider->addMethodDependencies(
                    $pool_data['method_dependencies'],
                );
                $codebase->file_reference_provider->addMethodReferencesToClassProperties(
                    $pool_data['method_references_to_class_properties'],
                );
                $codebase->file_reference_provider->addMethodReferencesToMethodReturns(
                    $pool_data['method_references_to_method_returns'],
                );
                $codebase->file_reference_provider->addFileReferencesToMissingClassMembers(
                    $pool_data['file_references_to_missing_class_members'],
                );
                $codebase->file_reference_provider->addMethodReferencesToMissingClassMembers(
                    $pool_data['method_references_to_missing_class_members'],
                );
                $codebase->file_reference_provider->addMethodParamUses(
                    $pool_data['method_param_uses'],
                );
                $this->addMixedMemberNames(
                    $pool_data['mixed_member_names'],
                );
                $this->function_timings += $pool_data['function_timings'];
                $codebase->file_reference_provider->addClassLocations(
                    $pool_data['class_locations'],
                );
                $codebase->file_reference_provider->addClassMethodLocations(
                    $pool_data['class_method_locations'],
                );
                $codebase->file_reference_provider->addClassPropertyLocations(
                    $pool_data['class_property_locations'],
                );

                foreach ($pool_data['mutable_classes'] as $class => $level) {
                    if (array_key_exists($class, $this->mutable_classes)) {
                        $this->mutable_classes[$class] = max(
                            $this->mutable_classes[$class],
                            $level,
                        );
                    } else {
                        $this->mutable_classes[$class] = $level;
                    }
                }

                FunctionDocblockManipulator::addManipulators($pool_data['function_docblock_manipulators']);
                InferredThrowsBuffer::add($pool_data['inferred_throws']);
                InferredThrowsBuffer::addConditions($pool_data['throws_conditions']);
                InferredThrowsBuffer::addContextSummaries($pool_data['throws_context_summaries']);
                InferredThrowsBuffer::addContextConditions($pool_data['throws_context_conditions']);
                InferredThrowsBuffer::addDependencies($pool_data['throws_dependencies']);
                InferredThrowsBuffer::addCallTargets($pool_data['throws_call_targets']);
                InferredThrowsBuffer::addCallEdges($pool_data['throws_call_edges']);

                $this->analyzed_methods = array_merge($pool_data['analyzed_methods'], $this->analyzed_methods);

                foreach ($pool_data['mixed_counts'] as $file_path => [$mixed_count, $nonmixed_count]) {
                    if (!isset($this->mixed_counts[$file_path])) {
                        $this->mixed_counts[$file_path] = [$mixed_count, $nonmixed_count];
                    } else {
                        $this->mixed_counts[$file_path][0] += $mixed_count;
                        $this->mixed_counts[$file_path][1] += $nonmixed_count;
                    }
                }

                foreach ($pool_data['possible_method_param_types'] as $declaring_method_id => $possible_param_types) {
                    if (!isset($this->possible_method_param_types[$declaring_method_id])) {
                        $this->possible_method_param_types[$declaring_method_id] = $possible_param_types;
                    } else {
                        foreach ($possible_param_types as $offset => $possible_param_type) {
                            $this->possible_method_param_types[$declaring_method_id][$offset]
                                = Type::combineUnionTypes(
                                    $this->possible_method_param_types[$declaring_method_id][$offset] ?? null,
                                    $possible_param_type,
                                    $codebase,
                                );
                        }
                    }
                }

                foreach ($pool_data['file_manipulations'] as $file_path => $manipulations) {
                    FileManipulationBuffer::add($file_path, $manipulations);
                }

                foreach ($pool_data['file_maps'] as $file_path => $file_maps) {
                    [$reference_map, $type_map, $argument_map] = $file_maps;
                    $this->reference_map[$file_path] = $reference_map;
                    $this->type_map[$file_path] = $type_map;
                    $this->argument_map[$file_path] = $argument_map;
                }

                $this->progress->taskDone(0);
            }
        } else {
            foreach ($this->files_to_analyze as $file_path => $_) {
                $task_done_closure(self::analysisWorker($this->config, $this->progress, $file_path));
            }
        }
    }

    /**
     * Discover the transitive call dependencies of selected files without making
     * those dependencies eligible for edits or diagnostics. Scan caches may be
     * reused, but body summaries are rebuilt from current source on every run.
     */
    private function analyzeThrowsDependencies(ProjectAnalyzer $project_analyzer, int $pool_size): void
    {
        $codebase = $project_analyzer->getCodebase();
        $selected_files = $this->files_to_analyze;
        $all_files = $selected_files;
        $targets = InferredThrowsBuffer::getCallTargets();
        $this->throws_analysis_targets ??= array_fill_keys(array_keys($selected_files), null);
        $expanded = false;

        while (true) {
            $new_files = [];
            foreach ($targets as $file_path => $offsets) {
                if ((array_key_exists($file_path, $this->throws_analysis_targets)
                        && $this->throws_analysis_targets[$file_path] === null)
                    || !$this->config->isInProjectDirs($file_path)
                    || !$this->file_provider->fileExists($file_path)
                ) {
                    continue;
                }
                foreach ($offsets as $offset => $_) {
                    if ($this->throws_cache?->covers($file_path, $offset)) {
                        continue;
                    }
                    if (!isset($this->throws_analysis_targets[$file_path][$offset])) {
                        $this->throws_analysis_targets[$file_path][$offset] = true;
                        $new_files[$file_path] = $file_path;
                    }
                }
            }
            if ($new_files === []) {
                break;
            }

            $expanded = true;
            $all_files += $new_files;
            $codebase->scanner->addFilesToDeepScan($new_files);
            $codebase->scanFiles($project_analyzer->scanThreads);
            $this->files_to_analyze = $new_files;
            InferredThrowsBuffer::clear();
            $this->doAnalysis($project_analyzer, $pool_size);
            $targets = InferredThrowsBuffer::getCallTargets();
        }

        $this->files_to_analyze = $all_files;
        if ($expanded) {
            // Deep scanning can replace previously shallow storage. Start the
            // fixed point from empty summaries only after discovery is complete.
            $this->resetInferredThrows();
            $this->throws_context_summaries = [];
            $this->throws_context_conditions = [];
            InferredThrowsBuffer::clear();
            FunctionDocblockManipulator::clearCacheForFiles($all_files);
            $this->doAnalysis($project_analyzer, $pool_size);
        }
    }

    private function convergeInferredThrows(ProjectAnalyzer $project_analyzer, int $pool_size): void
    {
        $codebase = $project_analyzer->getCodebase();
        $dependencies = InferredThrowsBuffer::getDependencies();
        $summaries = InferredThrowsBuffer::getAll();
        $conditions = InferredThrowsBuffer::getConditions();
        $functions = [];
        $caller_declarations = [];
        foreach ($this->throwsStorages() as $storage) {
            if ($storage->stmt_location !== null) {
                $location = $storage->stmt_location;
                $caller_declarations[$location->file_path][$location->raw_file_start] = $location->raw_file_end;
            }
        }
        foreach (FileStorageProvider::getAll() as $file_storage) {
            $functions += $file_storage->functions;
        }

        while ($summaries !== []) {
            $files_to_reanalyze = [];
            $wave_targets = [];

            foreach ($summaries as $function_id => $new_throws) {
                $new_conditions = $conditions[$function_id] ?? [];
                if (MethodIdentifier::isValidMethodIdReference($function_id)) {
                    try {
                        $method_id = MethodIdentifier::fromMethodIdReference($function_id);
                        $storage = $codebase->methods->getStorage($method_id);
                        $classlike_storage = $codebase->methods->getClassLikeStorageForMethod($method_id);
                    } catch (UnexpectedValueException) {
                        continue;
                    }
                    if ($classlike_storage->is_interface || $storage->abstract) {
                        continue;
                    }
                } else {
                    $storage = $functions[$function_id] ?? null;
                    if ($storage === null) {
                        continue;
                    }
                }

                // These are sets; worker completion order must not cause another wave.
                if ($new_throws == $storage->inferred_throws
                    && $new_conditions == $storage->inferred_throws_conditions
                ) {
                    continue;
                }
                $storage->inferred_throws = $new_throws;
                $storage->inferred_throws_conditions = $new_conditions;
                if ($storage->location === null) {
                    continue;
                }

                foreach ($dependencies[$storage->location->file_path] ?? [] as $caller_file => $_) {
                    if (isset($this->files_to_analyze[$caller_file])) {
                        $targets = $this->throwsCallerTargets($caller_file, $storage, $caller_declarations);
                        if ($targets === []) {
                            continue;
                        }
                        $files_to_reanalyze[$caller_file] = $caller_file;
                        if ($targets === null) {
                            $wave_targets[$caller_file] = null;
                        } elseif (!array_key_exists($caller_file, $wave_targets)) {
                            $wave_targets[$caller_file] = $targets;
                        } elseif ($wave_targets[$caller_file] !== null) {
                            $wave_targets[$caller_file] += $targets;
                        }
                    }
                }
            }

            if ($files_to_reanalyze === []) {
                break;
            }

            $analysis_targets = $this->throws_analysis_targets;
            foreach ($wave_targets as $file => &$targets) {
                $allowed = $analysis_targets[$file] ?? null;
                if ($allowed !== null) {
                    $targets = $targets === null ? $allowed : array_intersect_key($targets, $allowed);
                }
                if ($targets === []) {
                    unset($files_to_reanalyze[$file]);
                }
            }
            unset($targets);
            if ($files_to_reanalyze === []) {
                break;
            }
            InferredThrowsBuffer::clear();
            // Execution files and physical declaration files can differ: a
            // selected class can execute an already allowed trait body. Keep
            // those permissions outside the wave's files without scheduling
            // those files or widening targets within the selected callers.
            $this->throws_analysis_targets = $wave_targets + ($analysis_targets ?? []);
            FunctionDocblockManipulator::clearCacheForTargets($wave_targets, $caller_declarations);
            $files_to_analyze = $this->files_to_analyze;
            $this->files_to_analyze = $files_to_reanalyze;
            $this->doAnalysis($project_analyzer, $pool_size);
            $this->files_to_analyze = $files_to_analyze;
            $this->throws_analysis_targets = $analysis_targets;
            $summaries = InferredThrowsBuffer::getAll();
            $conditions = InferredThrowsBuffer::getConditions();
            foreach (InferredThrowsBuffer::getDependencies() as $file_path => $callers) {
                $dependencies[$file_path] = $callers + ($dependencies[$file_path] ?? []);
            }
        }
    }

    /**
     * @param array<string, list<array<int, bool|int|string|null>>> $target
     * @param array<string, list<array<int, bool|int|string|null>>> $source
     * @return array<string, list<array<int, bool|int|string|null>>>
     * @psalm-pure
     */
    private static function mergeThrowsConditions(array $target, array $source): array
    {
        foreach ($source as $exception => $exception_conditions) {
            if (($target[$exception][0] ?? null) === []) {
                continue;
            }
            foreach ($exception_conditions as $condition) {
                if ($condition === []) {
                    $target[$exception] = [[]];
                    break;
                }
                if (!in_array($condition, $target[$exception] ?? [], true)) {
                    $target[$exception][] = $condition;
                    if (count($target[$exception]) > self::MAX_THROWS_CONDITIONS) {
                        $target[$exception] = [[]];
                        break;
                    }
                }
            }
        }
        return $target;
    }

    /**
     * Use only call edges observed during this analysis. Missing positions (in
     * particular calls through a shared trait) retain the file-level fallback.
     * A position inside a closure selects its enclosing named declaration too.
     *
     * @return array<int, true>|null Null means the existing file scope.
     * @param array<string, array<int, int>> $caller_declarations
     * @psalm-mutation-free
     */
    private function throwsCallerTargets(
        string $caller_file,
        FunctionLikeStorage $callee,
        array $caller_declarations,
    ): ?array {
        $callee_file = $callee->location?->file_path;
        $callee_offset = $callee->stmt_location?->raw_file_start;
        if ($callee_file === null || $callee_offset === null
            || !isset($this->throws_call_edges[$caller_file])) {
            return null;
        }
        $declarations = $caller_declarations[$caller_file] ?? [];
        $targets = [];
        $known_file = false;
        foreach ($this->throws_call_edges[$caller_file] as $position => $callees) {
            if (!isset($callees[$callee_file])) {
                continue;
            }
            $known_file = true;
            if ($position === -1 || isset($callees[$callee_file][-1])) {
                return null;
            }
            if (!isset($callees[$callee_file][$callee_offset])) {
                continue;
            }
            $found = false;
            foreach ($declarations as $start => $end) {
                if ($position >= $start && $position <= $end) {
                    $targets[$start] = true;
                    $found = true;
                }
            }
            if (!$found) {
                return null;
            }
        }
        return $known_file ? $targets : null;
    }

    /**
     * @psalm-suppress ComplexMethod
     */
    public function loadCachedResults(ProjectAnalyzer $project_analyzer): void
    {
        $codebase = $project_analyzer->getCodebase();

        $statements_provider = $codebase->statements_provider;
        $file_reference_provider = $codebase->file_reference_provider;

        // Load cached data from disk
        if ($codebase->diff_methods) {
            $this->analyzed_methods = $file_reference_provider->getAnalyzedMethods();
            $this->existing_issues = $file_reference_provider->getExistingIssues();
            $file_maps = $file_reference_provider->getFileMaps();

            foreach ($file_maps as $file_path => [$reference_map, $type_map, $argument_map]) {
                $this->reference_map[$file_path] = $reference_map;
                $this->type_map[$file_path] = $type_map;
                $this->argument_map[$file_path] = $argument_map;
            }
        }

        $method_references_to_class_members = $file_reference_provider->getAllMethodReferencesToClassMembers();

        $method_dependencies = $file_reference_provider->getAllMethodDependencies();

        $method_references_to_class_properties = $file_reference_provider->getAllMethodReferencesToClassProperties();

        $method_references_to_method_returns = $file_reference_provider->getAllMethodReferencesToMethodReturns();

        $method_references_to_missing_class_members =
            $file_reference_provider->getAllMethodReferencesToMissingClassMembers();

        $all_referencing_methods = $method_references_to_class_members
            + $method_references_to_missing_class_members
            + $method_dependencies;

        $nonmethod_references_to_classes = $file_reference_provider->getAllNonMethodReferencesToClasses();

        $method_references_to_classes = $file_reference_provider->getAllMethodReferencesToClasses();

        $method_param_uses = $file_reference_provider->getAllMethodParamUses();

        $file_references_to_class_members = $file_reference_provider->getAllFileReferencesToClassMembers();

        $file_references_to_class_properties = $file_reference_provider->getAllFileReferencesToClassProperties();

        $file_references_to_method_returns = $file_reference_provider->getAllFileReferencesToMethodReturns();

        $file_references_to_missing_class_members
            = $file_reference_provider->getAllFileReferencesToMissingClassMembers();

        $references_to_mixed_member_names = $file_reference_provider->getAllReferencesToMixedMemberNames();

        $this->mixed_counts = $file_reference_provider->getTypeCoverage();
        // Finish loading cached data from disk

        $changed_members = $statements_provider->getChangedMembers();

        foreach ($changed_members as $file_path => $members_by_file) {
            foreach ($members_by_file as $changed_member => $_) {
                if (!strpos($changed_member, '&')) {
                    continue;
                }

                [$base_class, $trait] = explode('&', $changed_member);

                foreach ($all_referencing_methods as $member_id => $_) {
                    if (!str_starts_with($member_id, $base_class . '::')) {
                        continue;
                    }

                    $member_bit = substr($member_id, strlen($base_class) + 2);

                    if (isset($all_referencing_methods[$trait . '::' . $member_bit])) {
                        $changed_members[$file_path][$member_id] = true;
                    }
                }
            }
        }

        $newly_invalidated_methods = [];

        foreach ($statements_provider->getUnchangedSignatureMembers() as $file_unchanged_signature_members) {
            $newly_invalidated_methods = array_merge($newly_invalidated_methods, $file_unchanged_signature_members);

            foreach ($file_unchanged_signature_members as $unchanged_signature_member_id => $_) {
                // also check for things that might invalidate constructor property initialisation
                if (isset($all_referencing_methods[$unchanged_signature_member_id])) {
                    foreach ($all_referencing_methods[$unchanged_signature_member_id] as $referencing_method_id => $_) {
                        if (str_ends_with($referencing_method_id, '::__construct')) {
                            $referencing_base_classlike = explode('::', $referencing_method_id)[0];
                            $unchanged_signature_classlike = explode('::', $unchanged_signature_member_id)[0];

                            if ($referencing_base_classlike === $unchanged_signature_classlike) {
                                $newly_invalidated_methods[$referencing_method_id] = true;
                            } else {
                                try {
                                    $referencing_storage = $codebase->classlike_storage_provider->get(
                                        $referencing_base_classlike,
                                    );
                                } catch (InvalidArgumentException) {
                                    // Workaround for #3671
                                    $newly_invalidated_methods[$referencing_method_id] = true;
                                    $referencing_storage = null;
                                }

                                if (isset($referencing_storage->used_traits[$unchanged_signature_classlike])
                                    || isset($referencing_storage->parent_classes[$unchanged_signature_classlike])
                                ) {
                                    $newly_invalidated_methods[$referencing_method_id] = true;
                                }
                            }
                        }
                    }
                }
            }
        }

        foreach ($changed_members as $file_changed_members) {
            foreach ($file_changed_members as $member_id => $_) {
                $newly_invalidated_methods[$member_id] = true;

                if (isset($all_referencing_methods[$member_id])) {
                    $newly_invalidated_methods = array_merge(
                        $all_referencing_methods[$member_id],
                        $newly_invalidated_methods,
                    );
                }

                unset(
                    $method_references_to_class_members[$member_id],
                    $method_dependencies[$member_id],
                    $method_references_to_class_properties[$member_id],
                    $method_references_to_method_returns[$member_id],
                    $file_references_to_class_members[$member_id],
                    $file_references_to_class_properties[$member_id],
                    $file_references_to_method_returns[$member_id],
                    $method_references_to_missing_class_members[$member_id],
                    $file_references_to_missing_class_members[$member_id],
                    $references_to_mixed_member_names[$member_id],
                    $method_param_uses[$member_id],
                );

                $member_stub = (string) preg_replace('/::.*$/', '::*', $member_id, 1);

                if (isset($all_referencing_methods[$member_stub])) {
                    $newly_invalidated_methods = array_merge(
                        $all_referencing_methods[$member_stub],
                        $newly_invalidated_methods,
                    );
                }
            }
        }

        // This could be optimized by storing method references to files
        foreach ($file_reference_provider->getDeletedReferencedFiles() as $deleted_file) {
            foreach ($file_reference_provider->getFilesReferencingFile($deleted_file) as $file_referencing_deleted) {
                $methods_referencing_deleted = $this->analyzed_methods[$file_referencing_deleted] ?? [];
                foreach ($methods_referencing_deleted as $method_referencing_deleted => $_) {
                    $newly_invalidated_methods[$method_referencing_deleted] = true;
                }
            }
        }

        foreach ($newly_invalidated_methods as $method_id => $_) {
            foreach ($method_references_to_class_members as $i => $_) {
                unset($method_references_to_class_members[$i][$method_id]);
            }

            foreach ($method_dependencies as $i => $_) {
                unset($method_dependencies[$i][$method_id]);
            }

            foreach ($method_references_to_class_properties as $i => $_) {
                unset($method_references_to_class_properties[$i][$method_id]);
            }

            foreach ($method_references_to_method_returns as $i => $_) {
                unset($method_references_to_method_returns[$i][$method_id]);
            }

            foreach ($method_references_to_classes as $i => $_) {
                unset($method_references_to_classes[$i][$method_id]);
            }

            foreach ($method_references_to_missing_class_members as $i => $_) {
                unset($method_references_to_missing_class_members[$i][$method_id]);
            }

            foreach ($references_to_mixed_member_names as $i => $_) {
                unset($references_to_mixed_member_names[$i][$method_id]);
            }

            foreach ($method_param_uses as $i => $_) {
                foreach ($method_param_uses[$i] as $j => $_) {
                    unset($method_param_uses[$i][$j][$method_id]);
                }
            }
        }

        foreach ($statements_provider->getErrors() as $file_path => $_) {
            unset($this->analyzed_methods[$file_path]);
            unset($this->existing_issues[$file_path]);
        }

        foreach ($this->analyzed_methods as $file_path => $analyzed_methods) {
            foreach ($analyzed_methods as $correct_method_id => $_) {
                $trait_safe_method_id = $correct_method_id;

                $correct_method_ids = explode('&', $correct_method_id);

                $correct_method_id = $correct_method_ids[0];

                if (isset($newly_invalidated_methods[$correct_method_id])
                    || (isset($correct_method_ids[1])
                        && isset($newly_invalidated_methods[$correct_method_ids[1]]))
                ) {
                    unset($this->analyzed_methods[$file_path][$trait_safe_method_id]);
                }
            }
        }

        $this->shiftFileOffsets($statements_provider);

        foreach ($this->files_to_analyze as $file_path) {
            $file_reference_provider->clearExistingIssuesForFile($file_path);
            $file_reference_provider->clearExistingFileMapsForFile($file_path);

            $this->setMixedCountsForFile($file_path, [0, 0]);

            foreach ($file_references_to_class_members as $i => $_) {
                unset($file_references_to_class_members[$i][$file_path]);
            }

            foreach ($file_references_to_class_properties as $i => $_) {
                unset($file_references_to_class_properties[$i][$file_path]);
            }

            foreach ($file_references_to_method_returns as $i => $_) {
                unset($file_references_to_method_returns[$i][$file_path]);
            }

            foreach ($nonmethod_references_to_classes as $i => $_) {
                unset($nonmethod_references_to_classes[$i][$file_path]);
            }

            foreach ($references_to_mixed_member_names as $i => $_) {
                unset($references_to_mixed_member_names[$i][$file_path]);
            }

            foreach ($file_references_to_missing_class_members as $i => $_) {
                unset($file_references_to_missing_class_members[$i][$file_path]);
            }
        }

        foreach ($this->existing_issues as $file_path => $issues) {
            if (!isset($this->files_to_analyze[$file_path])) {
                unset($this->existing_issues[$file_path]);

                if ($this->file_provider->fileExists($file_path)) {
                    IssueBuffer::addIssues([$file_path => array_values($issues)]);
                }
            }
        }

        $method_references_to_class_members = array_filter(
            $method_references_to_class_members,
        );

        $method_dependencies = array_filter(
            $method_dependencies,
        );

        $method_references_to_class_properties = array_filter(
            $method_references_to_class_properties,
        );

        $method_references_to_method_returns = array_filter(
            $method_references_to_method_returns,
        );

        $method_references_to_missing_class_members = array_filter(
            $method_references_to_missing_class_members,
        );

        $file_references_to_class_members = array_filter(
            $file_references_to_class_members,
        );

        $file_references_to_class_properties = array_filter(
            $file_references_to_class_properties,
        );

        $file_references_to_method_returns = array_filter(
            $file_references_to_method_returns,
        );

        $file_references_to_missing_class_members = array_filter(
            $file_references_to_missing_class_members,
        );

        $references_to_mixed_member_names = array_filter(
            $references_to_mixed_member_names,
        );

        $nonmethod_references_to_classes = array_filter(
            $nonmethod_references_to_classes,
        );

        $method_references_to_classes = array_filter(
            $method_references_to_classes,
        );

        $method_param_uses = array_filter(
            $method_param_uses,
        );

        $file_reference_provider->setCallingMethodReferencesToClassMembers(
            $method_references_to_class_members,
        );

        $file_reference_provider->setMethodDependencies(
            $method_dependencies,
        );

        $file_reference_provider->setCallingMethodReferencesToClassProperties(
            $method_references_to_class_properties,
        );

        $file_reference_provider->setCallingMethodReferencesToMethodReturns(
            $method_references_to_method_returns,
        );

        $file_reference_provider->setFileReferencesToClassMembers(
            $file_references_to_class_members,
        );

        $file_reference_provider->setFileReferencesToClassProperties(
            $file_references_to_class_properties,
        );

        $file_reference_provider->setFileReferencesToMethodReturns(
            $file_references_to_method_returns,
        );

        $file_reference_provider->setCallingMethodReferencesToMissingClassMembers(
            $method_references_to_missing_class_members,
        );

        $file_reference_provider->setFileReferencesToMissingClassMembers(
            $file_references_to_missing_class_members,
        );

        $file_reference_provider->setReferencesToMixedMemberNames(
            $references_to_mixed_member_names,
        );

        $file_reference_provider->setCallingMethodReferencesToClasses(
            $method_references_to_classes,
        );

        $file_reference_provider->setNonMethodReferencesToClasses(
            $nonmethod_references_to_classes,
        );

        $file_reference_provider->setMethodParamUses(
            $method_param_uses,
        );
    }

    public function shiftFileOffsets(StatementsProvider $statements_provider): void
    {
        $diff_map = $statements_provider->getDiffMap();
        $deletion_ranges = $statements_provider->getDeletionRanges();

        foreach ($this->existing_issues as $file_path => $file_issues) {
            if (!isset($this->analyzed_methods[$file_path])) {
                continue;
            }

            $file_diff_map = $diff_map[$file_path] ?? [];
            $file_deletion_ranges = $deletion_ranges[$file_path] ?? [];

            if ($file_deletion_ranges) {
                foreach ($file_issues as $i => $issue_data) {
                    foreach ($file_deletion_ranges as [$from, $to]) {
                        if ($issue_data->from >= $from
                            && $issue_data->from <= $to
                        ) {
                            unset($this->existing_issues[$file_path][$i]);
                            break;
                        }
                    }
                }
            }

            if ($file_diff_map) {
                foreach ($file_issues as $issue_data) {
                    foreach ($file_diff_map as [$from, $to, $file_offset, $line_offset]) {
                        if ($issue_data->from >= $from
                            && $issue_data->from <= $to
                        ) {
                            $issue_data->from += $file_offset;
                            $issue_data->to += $file_offset;
                            $issue_data->snippet_from += $file_offset;
                            $issue_data->snippet_to += $file_offset;
                            $issue_data->line_from += $line_offset;
                            $issue_data->line_to += $line_offset;
                            break;
                        }
                    }
                }
            }
        }

        foreach ($this->reference_map as $file_path => $reference_map) {
            if (!isset($this->analyzed_methods[$file_path])) {
                unset($this->reference_map[$file_path]);
                continue;
            }

            $file_diff_map = $diff_map[$file_path] ?? [];
            $file_deletion_ranges = $deletion_ranges[$file_path] ?? [];

            if ($file_deletion_ranges) {
                foreach ($reference_map as $reference_from => $_) {
                    foreach ($file_deletion_ranges as [$from, $to]) {
                        if ($reference_from >= $from && $reference_from <= $to) {
                            unset($this->reference_map[$file_path][$reference_from]);
                            break;
                        }
                    }
                }
            }

            if ($file_diff_map) {
                foreach ($reference_map as $reference_from => [$reference_to, $tag]) {
                    foreach ($file_diff_map as [$from, $to, $file_offset]) {
                        if ($reference_from >= $from && $reference_from <= $to) {
                            unset($this->reference_map[$file_path][$reference_from]);
                            $this->reference_map[$file_path][$reference_from + $file_offset] = [
                                $reference_to + $file_offset,
                                $tag,
                            ];
                            break;
                        }
                    }
                }
            }
        }

        foreach ($this->type_map as $file_path => $type_map) {
            if (!isset($this->analyzed_methods[$file_path])) {
                unset($this->type_map[$file_path]);
                continue;
            }

            $file_diff_map = $diff_map[$file_path] ?? [];
            $file_deletion_ranges = $deletion_ranges[$file_path] ?? [];

            if ($file_deletion_ranges) {
                foreach ($type_map as $type_from => $_) {
                    foreach ($file_deletion_ranges as [$from, $to]) {
                        if ($type_from >= $from && $type_from <= $to) {
                            unset($this->type_map[$file_path][$type_from]);
                            break;
                        }
                    }
                }
            }

            if ($file_diff_map) {
                foreach ($type_map as $type_from => [$type_to, $tag]) {
                    foreach ($file_diff_map as [$from, $to, $file_offset]) {
                        if ($type_from >= $from && $type_from <= $to) {
                            unset($this->type_map[$file_path][$type_from]);
                            $this->type_map[$file_path][$type_from + $file_offset] = [
                                $type_to + $file_offset,
                                $tag,
                            ];
                            break;
                        }
                    }
                }
            }
        }

        foreach ($this->argument_map as $file_path => $argument_map) {
            if (!isset($this->analyzed_methods[$file_path])) {
                unset($this->argument_map[$file_path]);
                continue;
            }

            $file_diff_map = $diff_map[$file_path] ?? [];
            $file_deletion_ranges = $deletion_ranges[$file_path] ?? [];

            if ($file_deletion_ranges) {
                foreach ($argument_map as $argument_from => $_) {
                    foreach ($file_deletion_ranges as [$from, $to]) {
                        if ($argument_from >= $from && $argument_from <= $to) {
                            unset($argument_map[$argument_from]);
                            break;
                        }
                    }
                }
            }

            if ($file_diff_map) {
                foreach ($argument_map as $argument_from => [$argument_to, $method_id, $argument_number]) {
                    foreach ($file_diff_map as [$from, $to, $file_offset]) {
                        if ($argument_from >= $from && $argument_from <= $to) {
                            unset($this->argument_map[$file_path][$argument_from]);
                            $this->argument_map[$file_path][$argument_from + $file_offset] = [
                                $argument_to + $file_offset,
                                $method_id,
                                $argument_number,
                            ];
                            break;
                        }
                    }
                }
            }
        }
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function getMixedMemberNames(): array
    {
        return $this->mixed_member_names;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function addMixedMemberName(string $member_id, string $reference): void
    {
        $this->mixed_member_names[$member_id][$reference] = true;
    }

    /**
     * @psalm-mutation-free
     */
    public function hasMixedMemberName(string $member_id): bool
    {
        return isset($this->mixed_member_names[$member_id]);
    }

    /**
     * @param array<string, array<string, bool>> $names
     * @psalm-external-mutation-free
     */
    public function addMixedMemberNames(array $names): void
    {
        foreach ($names as $key => $name) {
            if (isset($this->mixed_member_names[$key])) {
                $this->mixed_member_names[$key] = array_merge(
                    $this->mixed_member_names[$key],
                    $name,
                );
            } else {
                $this->mixed_member_names[$key] = $name;
            }
        }
    }

    /**
     * @return list{int, int}
     * @psalm-external-mutation-free
     */
    public function getMixedCountsForFile(string $file_path): array
    {
        if (!isset($this->mixed_counts[$file_path])) {
            $this->mixed_counts[$file_path] = [0, 0];
        }

        return $this->mixed_counts[$file_path];
    }

    /**
     * @param list{int, int} $mixed_counts
     * @psalm-external-mutation-free
     */
    public function setMixedCountsForFile(string $file_path, array $mixed_counts): void
    {
        $this->mixed_counts[$file_path] = $mixed_counts;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function incrementMixedCount(string $file_path): void
    {
        if (!$this->count_mixed) {
            return;
        }

        if (!isset($this->mixed_counts[$file_path])) {
            $this->mixed_counts[$file_path] = [0, 0];
        }

        ++$this->mixed_counts[$file_path][0];
    }

    /**
     * @psalm-external-mutation-free
     */
    public function decrementMixedCount(string $file_path): void
    {
        if (!$this->count_mixed) {
            return;
        }

        if (!isset($this->mixed_counts[$file_path])) {
            return;
        }

        if ($this->mixed_counts[$file_path][0] === 0) {
            return;
        }

        --$this->mixed_counts[$file_path][0];
    }

    /**
     * @psalm-external-mutation-free
     */
    public function incrementNonMixedCount(string $file_path): void
    {
        if (!$this->count_mixed) {
            return;
        }

        if (!isset($this->mixed_counts[$file_path])) {
            $this->mixed_counts[$file_path] = [0, 0];
        }

        ++$this->mixed_counts[$file_path][1];
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     * @psalm-mutation-free
     */
    public function getMixedCounts(): array
    {
        $all_deep_scanned_files = [];

        foreach ($this->files_to_analyze as $file_path => $_) {
            $all_deep_scanned_files[$file_path] = true;
        }

        return array_intersect_key($this->mixed_counts, $all_deep_scanned_files);
    }

    /**
     * @return array<string, float>
     */
    public function getFunctionTimings(): array
    {
        return $this->function_timings;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function addFunctionTiming(string $function_id, float $time_per_node): void
    {
        $this->function_timings[$function_id] = $time_per_node;
    }

    public function addNodeType(
        string $file_path,
        PhpParser\Node $node,
        string $node_type,
        ?PhpParser\Node $parent_node = null,
    ): void {
        if ($node_type === '') {
            throw new UnexpectedValueException('non-empty node_type expected');
        }

        $this->type_map[$file_path][(int)$node->getAttribute('startFilePos')] = [
            ($parent_node ? (int)$parent_node->getAttribute('endFilePos') : (int)$node->getAttribute('endFilePos')) + 1,
            $node_type,
        ];
    }

    /**
     * @psalm-external-mutation-free
     */
    public function addNodeArgument(
        string $file_path,
        int $start_position,
        int $end_position,
        string $reference,
        int $argument_number,
    ): void {
        if ($reference === '') {
            throw new UnexpectedValueException('non-empty reference expected');
        }

        $this->argument_map[$file_path][$start_position] = [
            $end_position,
            $reference,
            $argument_number,
        ];
    }

    /**
     * @param string $reference The symbol name for the reference.
     *                          Prepend with an asterisk (*) to signify a reference that doesn't exist.
     */
    public function addNodeReference(string $file_path, PhpParser\Node $node, string $reference): void
    {
        if (!$reference) {
            throw new UnexpectedValueException('non-empty node_type expected');
        }

        $this->reference_map[$file_path][(int)$node->getAttribute('startFilePos')] = [
            (int)$node->getAttribute('endFilePos') + 1,
            $reference,
        ];
    }

    /**
     * @psalm-external-mutation-free
     */
    public function addOffsetReference(string $file_path, int $start, int $end, string $reference): void
    {
        if (!$reference) {
            throw new UnexpectedValueException('non-empty node_type expected');
        }

        $this->reference_map[$file_path][$start] = [
            $end,
            $reference,
        ];
    }

    /**
     * @return array{int, int}
     * @psalm-external-mutation-free
     */
    public function getTotalTypeCoverage(Codebase $codebase): array
    {
        $mixed_count = 0;
        $nonmixed_count = 0;

        foreach ($codebase->file_reference_provider->getTypeCoverage() as $file_path => $counts) {
            if (!$this->config->reportTypeStatsForFile($file_path)) {
                continue;
            }

            [$path_mixed_count, $path_nonmixed_count] = $counts;

            if (isset($this->mixed_counts[$file_path])) {
                $mixed_count += $path_mixed_count;
                $nonmixed_count += $path_nonmixed_count;
            }
        }

        return [$mixed_count, $nonmixed_count];
    }

    /**
     * @psalm-external-mutation-free
     */
    public function getTypeInferenceSummary(Codebase $codebase): string
    {
        $all_deep_scanned_files = [];

        foreach ($this->files_to_analyze as $file_path => $_) {
            $all_deep_scanned_files[$file_path] = true;

            foreach ($this->file_storage_provider->get($file_path)->required_file_paths as $required_file_path) {
                $all_deep_scanned_files[$required_file_path] = true;
            }
        }

        [$mixed_count, $nonmixed_count] = $this->getTotalTypeCoverage($codebase);

        $total = $mixed_count + $nonmixed_count;

        $total_files = count($all_deep_scanned_files);

        $lines = [];

        if (!$total_files) {
            $lines[] = 'No files analyzed';
        }

        if (!$total) {
            $lines[] = 'Psalm was unable to infer types in the codebase';
        } else {
            $percentage = $nonmixed_count === $total ? '100' : number_format(100 * $nonmixed_count / $total, 4);
            $lines[] = 'Psalm was able to infer types for ' . $percentage . '%'
                . ' of the codebase';
        }

        return implode("\n", $lines);
    }

    public function getNonMixedStats(): string
    {
        $stats = '';

        $all_deep_scanned_files = [];

        foreach ($this->files_to_analyze as $file_path => $_) {
            $all_deep_scanned_files[$file_path] = true;

            if (!$this->config->reportTypeStatsForFile($file_path)) {
                continue;
            }

            foreach ($this->file_storage_provider->get($file_path)->required_file_paths as $required_file_path) {
                $all_deep_scanned_files[$required_file_path] = true;
            }
        }

        foreach ($all_deep_scanned_files as $file_path => $_) {
            if (isset($this->mixed_counts[$file_path])) {
                [$path_mixed_count, $path_nonmixed_count] = $this->mixed_counts[$file_path];

                if ($path_mixed_count + $path_nonmixed_count) {
                    $stats .= number_format(100 * $path_nonmixed_count / ($path_mixed_count + $path_nonmixed_count), 3)
                        . '% ' . $this->config->shortenFileName($file_path)
                        . ' (' . $path_mixed_count . ' mixed)' . "\n";
                }
            }
        }

        return $stats;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function disableMixedCounts(): void
    {
        $this->count_mixed = false;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function enableMixedCounts(): void
    {
        $this->count_mixed = true;
    }

    public function updateFile(string $file_path, bool $dry_run): void
    {
        FileManipulationBuffer::add(
            $file_path,
            FunctionDocblockManipulator::getManipulationsForFile($file_path),
        );

        FileManipulationBuffer::add(
            $file_path,
            PropertyDocblockManipulator::getManipulationsForFile($file_path),
        );

        FileManipulationBuffer::add(
            $file_path,
            ClassDocblockManipulator::getManipulationsForFile($file_path),
        );

        $file_manipulations = FileManipulationBuffer::getManipulationsForFile($file_path);

        if (!$file_manipulations) {
            return;
        }

        usort(
            $file_manipulations,
            static function (FileManipulation $a, FileManipulation $b): int {
                if ($b->end === $a->end) {
                    if ($a->start === $b->start) {
                        return $b->insertion_text > $a->insertion_text ? 1 : -1;
                    }

                    return $b->start > $a->start ? 1 : -1;
                }

                return $b->end > $a->end ? 1 : -1;
            },
        );

        $last_start = PHP_INT_MAX;
        $existing_contents = $this->file_provider->getContents($file_path);
        $original_contents = $existing_contents;
        $applied_manipulations = [];
        $changed_file_scope = ProjectAnalyzer::getInstance()->changed_file_scope;

        foreach ($file_manipulations as $manipulation) {
            if ($manipulation->start <= $last_start) {
                $existing_contents = $manipulation->transform($existing_contents);
                if ($changed_file_scope !== null) {
                    $applied_manipulations[] = clone $manipulation;
                }
                $last_start = $manipulation->start;
            }
        }

        if ($dry_run) {
            echo $file_path . ':' . "\n";

            $differ = new Differ(
                new StrictUnifiedDiffOutputBuilder([
                    'fromFile' => $file_path,
                    'toFile' => $file_path,
                ]),
            );

            echo $differ->diff($this->file_provider->getContents($file_path), $existing_contents);

            return;
        }

        $this->progress->alterFileDone($file_path);

        $changed_file_scope?->recordUpdate(
            $file_path,
            $original_contents,
            $existing_contents,
            $applied_manipulations,
        );
        $this->file_provider->setContents($file_path, $existing_contents);
    }

    /**
     * @return list<IssueData>
     * @psalm-mutation-free
     */
    public function getExistingIssuesForFile(string $file_path, int $start, int $end, ?string $issue_type = null): array
    {
        if (!isset($this->existing_issues[$file_path])) {
            return [];
        }

        $applicable_issues = [];

        foreach ($this->existing_issues[$file_path] as $issue_data) {
            if ($issue_data->from >= $start && $issue_data->from <= $end) {
                if ($issue_type === null || $issue_type === $issue_data->type) {
                    $applicable_issues[] = $issue_data;
                }
            }
        }

        return $applicable_issues;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function removeExistingDataForFile(string $file_path, int $start, int $end, ?string $issue_type = null): void
    {
        if (isset($this->existing_issues[$file_path])) {
            foreach ($this->existing_issues[$file_path] as $i => $issue_data) {
                if ($issue_data->from >= $start && $issue_data->from <= $end) {
                    if ($issue_type === null || $issue_type === $issue_data->type) {
                        unset($this->existing_issues[$file_path][$i]);
                    }
                }
            }
        }

        if (isset($this->type_map[$file_path])) {
            foreach ($this->type_map[$file_path] as $map_start => $_) {
                if ($map_start >= $start && $map_start <= $end) {
                    unset($this->type_map[$file_path][$map_start]);
                }
            }
        }

        if (isset($this->reference_map[$file_path])) {
            foreach ($this->reference_map[$file_path] as $map_start => $_) {
                if ($map_start >= $start && $map_start <= $end) {
                    unset($this->reference_map[$file_path][$map_start]);
                }
            }
        }

        if (isset($this->argument_map[$file_path])) {
            foreach ($this->argument_map[$file_path] as $map_start => $_) {
                if ($map_start >= $start && $map_start <= $end) {
                    unset($this->argument_map[$file_path][$map_start]);
                }
            }
        }
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function getAnalyzedMethods(): array
    {
        return $this->analyzed_methods;
    }

    /**
     * @return array<string, FileMapType>
     * @psalm-mutation-free
     */
    public function getFileMaps(): array
    {
        $file_maps = [];

        foreach ($this->reference_map as $file_path => $reference_map) {
            $file_maps[$file_path] = [$reference_map, [], []];
        }

        foreach ($this->type_map as $file_path => $type_map) {
            if (isset($file_maps[$file_path])) {
                $file_maps[$file_path][1] = $type_map;
            } else {
                $file_maps[$file_path] = [[], $type_map, []];
            }
        }

        foreach ($this->argument_map as $file_path => $argument_map) {
            if (isset($file_maps[$file_path])) {
                $file_maps[$file_path][2] = $argument_map;
            } else {
                $file_maps[$file_path] = [[], [], $argument_map];
            }
        }

        return $file_maps;
    }

    /**
     * @return FileMapType
     * @psalm-mutation-free
     */
    public function getMapsForFile(string $file_path): array
    {
        return [
            $this->reference_map[$file_path] ?? [],
            $this->type_map[$file_path] ?? [],
            $this->argument_map[$file_path] ?? [],
        ];
    }

    /**
     * @return array<string, array<int, Union>>
     */
    public function getPossibleMethodParamTypes(): array
    {
        return $this->possible_method_param_types;
    }

    /**
     * @param Mutations::LEVEL_* $allowed_mutations
     * @psalm-external-mutation-free
     */
    public function addMutableClass(string $fqcln, int $allowed_mutations): void
    {
        $fqcln = strtolower($fqcln);
        if (array_key_exists($fqcln, $this->mutable_classes)) {
            $this->mutable_classes[$fqcln] = max(
                $this->mutable_classes[$fqcln],
                $allowed_mutations,
            );
        } else {
            $this->mutable_classes[$fqcln] = $allowed_mutations;
        }
    }

    /**
     * @psalm-external-mutation-free
     */
    public function setAnalyzedMethod(string $file_path, string $method_id, bool $is_constructor = false): void
    {
        $this->analyzed_methods[$file_path][$method_id] = $is_constructor ? 2 : 1;
    }

    /**
     * @psalm-mutation-free
     */
    public function isMethodAlreadyAnalyzed(string $file_path, string $method_id, bool $is_constructor = false): bool
    {
        if ($is_constructor) {
            return isset($this->analyzed_methods[$file_path][$method_id])
                && $this->analyzed_methods[$file_path][$method_id] === 2;
        }

        return isset($this->analyzed_methods[$file_path][$method_id]);
    }

    /**
     * @internal
     */
    public static function analysisWorker(Config $config, Progress $progress, string $file_path): int
    {
        $extension = pathinfo($file_path, PATHINFO_EXTENSION);

        $file_name = $config->shortenFileName($file_path);

        $filetype_analyzers = $config->getFiletypeAnalyzers();
        if (isset($filetype_analyzers[$extension])) {
            $file_analyzer = new $filetype_analyzers[$extension](
                ProjectAnalyzer::getInstance(),
                $file_path,
                $file_name
            );
        } else {
            $file_analyzer = new FileAnalyzer(ProjectAnalyzer::getInstance(), $file_path, $file_name);
        }

        $progress->debug('Analyzing ' . $file_analyzer->getFilePath() . "\n");

        InferredThrowsBuffer::setAnalysisFile($file_path);
        $file_analyzer->analyze();
        InferredThrowsBuffer::setAnalysisFile(null);
        $file_analyzer->context = null;
        $file_analyzer->clearSourceBeforeDestruction();
        unset($file_analyzer);

        $has_error = false;
        $has_info = false;

        foreach (IssueBuffer::getIssuesDataForFile($file_path) as $issue) {
            switch ($issue->severity) {
                case IssueData::SEVERITY_INFO:
                    $has_info = true;
                    break;
                default:
                    $has_error = true;
                    break;
            }
        }

        return $has_error ? 2 : ($has_info ? 1 : 0);
    }
}
