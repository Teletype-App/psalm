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
    }
}
