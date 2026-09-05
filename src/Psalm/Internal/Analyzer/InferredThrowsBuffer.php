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

    private static ?string $analysis_file = null;

    /** @psalm-external-mutation-free */
    public static function setAnalysisFile(?string $file_path): void
    {
        self::$analysis_file = $file_path;
    }

    /** @psalm-external-mutation-free */
    public static function addDependency(string $callee_file, int $offset, string $caller_file): void
    {
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

    /** @psalm-external-mutation-free */
    public static function clear(): void
    {
        self::$inferred_throws = [];
        self::$dependencies = [];
        self::$call_targets = [];
        self::$analysis_file = null;
    }
}
