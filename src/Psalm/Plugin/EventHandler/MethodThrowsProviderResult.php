<?php

declare(strict_types=1);

namespace Psalm\Plugin\EventHandler;

use Psalm\Internal\MethodIdentifier;

/**
 * @psalm-immutable
 */
final class MethodThrowsProviderResult
{
    /**
     * @param list<MethodIdentifier> $method_ids
     */
    public function __construct(
        public readonly array $method_ids,
        public readonly bool $analysis_complete = true,
    ) {
    }
}
