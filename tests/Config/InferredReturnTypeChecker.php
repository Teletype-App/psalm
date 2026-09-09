<?php

declare(strict_types=1);

namespace Psalm\Tests\Config;

use Override;
use Psalm\Plugin\EventHandler\AfterFunctionLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterFunctionLikeAnalysisEvent;
use UnexpectedValueException;

final class InferredReturnTypeChecker implements AfterFunctionLikeAnalysisInterface
{
    /** @psalm-mutation-free */
    #[Override]
    public static function afterStatementAnalysis(AfterFunctionLikeAnalysisEvent $event): ?bool
    {
        $type = $event->getInferredReturnType()?->getId();

        if ($type !== 'array{base: string, extra: int}') {
            throw new UnexpectedValueException('Unexpected inferred return type: ' . ($type ?? 'null'));
        }

        return null;
    }
}
