<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer;

/**
 * @internal
 * @psalm-immutable
 */
final class ThrownExceptionOrigin
{
    public const DIRECT = 1;

    public const NARROWED_RETHROW = 2;

    public const PROPAGATED = 4;
}
