<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer;

use function strtolower;

/**
 * @internal
 * @psalm-external-mutation-free
 */
final class InferredThrowsBuffer
{
    /**
     * @var array<lowercase-string, array<string, true>>
     */
    private static array $inferred_throws = [];

    /** @var array<string, array<string, true>> Callee file => caller files. */
    private static array $dependencies = [];

    /** @var array<string, array<int, true>> Callee file => declaration offsets. */
    private static array $call_targets = [];

    /** @var array<string, array<int, array<string, array<int, true>>>> Caller file/position => callee file/offsets. */
    private static array $call_edges = [];

    /** @var array<lowercase-string, array<string, array<string, true>>> */
    private static array $context_summaries = [];

    private static ?string $analysis_file = null;

    /** @psalm-external-mutation-free */
    public static function setAnalysisFile(?string $file_path): void
    {
        self::$analysis_file = $file_path;
    }

    /** @psalm-external-mutation-free */
    public static function addDependency(
        string $callee_file,
        int $offset,
        string $caller_file,
        int $caller_offset = -1,
    ): void {
        $analysis_file = self::$analysis_file ?? $caller_file;
        if ($analysis_file !== $caller_file) {
            $caller_offset = -1;
        }
        self::$call_edges[$analysis_file][$caller_offset][$callee_file][$offset] = true;
        self::$dependencies[$callee_file][self::$analysis_file ?? $caller_file] = true;
        self::$call_targets[$callee_file][$offset] = true;
    }

    /**
     * @param array<string, array<int, true>> $targets
     * @psalm-external-mutation-free
     */
    public static function addCallTargets(array $targets): void
    {
        foreach ($targets as $file => $offsets) {
            self::$call_targets[$file] = $offsets + (self::$call_targets[$file] ?? []);
        }
    }

    /**
     * @return array<string, array<int, true>>
     * @psalm-external-mutation-free
     */
    public static function getCallTargets(): array
    {
        return self::$call_targets;
    }

    /** @return array<string, array<int, array<string, array<int, true>>>> */
    public static function getCallEdges(): array
    {
        return self::$call_edges;
    }

    /** @param array<string, array<int, array<string, array<int, true>>>> $edges */
    public static function addCallEdges(array $edges): void
    {
        foreach ($edges as $caller => $positions) {
            foreach ($positions as $position => $callees) {
                foreach ($callees as $callee => $offsets) {
                    self::$call_edges[$caller][$position][$callee] =
                        $offsets + (self::$call_edges[$caller][$position][$callee] ?? []);
                }
            }
        }
    }

    /**
     * @param array<string, array<string, true>> $dependencies
     * @psalm-external-mutation-free
     */
    public static function addDependencies(array $dependencies): void
    {
        foreach ($dependencies as $callee_file => $callers) {
            self::$dependencies[$callee_file] = $callers + (self::$dependencies[$callee_file] ?? []);
        }
    }

    /**
     * @return array<string, array<string, true>>
     * @psalm-external-mutation-free
     */
    public static function getDependencies(): array
    {
        return self::$dependencies;
    }

    /**
     * @param array<string, true> $throws
     * @psalm-external-mutation-free
     */
    public static function set(string $function_id, array $throws): void
    {
        $function_id = strtolower($function_id);
        self::$inferred_throws[$function_id] = $throws + (self::$inferred_throws[$function_id] ?? []);
        $file = self::$analysis_file ?? '';
        self::$context_summaries[$function_id][$file] = $throws + (self::$context_summaries[$function_id][$file] ?? []);
    }

    /**
     * @param array<lowercase-string, array<string, true>> $inferred_throws
     * @psalm-external-mutation-free
     */
    public static function add(array $inferred_throws): void
    {
        foreach ($inferred_throws as $function_id => $throws) {
            self::$inferred_throws[$function_id] = $throws + (self::$inferred_throws[$function_id] ?? []);
        }
    }

    /**
     * @return array<lowercase-string, array<string, true>>
     * @psalm-external-mutation-free
     */
    public static function getAll(): array
    {
        return self::$inferred_throws;
    }

    /** @return array<lowercase-string, array<string, array<string, true>>> */
    public static function getContextSummaries(): array
    {
        return self::$context_summaries;
    }

    /** @param array<lowercase-string, array<string, array<string, true>>> $summaries */
    public static function addContextSummaries(array $summaries): void
    {
        foreach ($summaries as $id => $contexts) {
            foreach ($contexts as $file => $throws) {
                self::$context_summaries[$id][$file] = $throws + (self::$context_summaries[$id][$file] ?? []);
            }
        }
    }

    /** @psalm-external-mutation-free */
    public static function clear(): void
    {
        self::$inferred_throws = [];
        self::$context_summaries = [];
        self::$dependencies = [];
        self::$call_targets = [];
        self::$call_edges = [];
        self::$analysis_file = null;
    }
}
