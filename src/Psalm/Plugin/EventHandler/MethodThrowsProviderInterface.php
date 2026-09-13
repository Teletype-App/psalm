<?php

declare(strict_types=1);

namespace Psalm\Plugin\EventHandler;

use Psalm\Plugin\EventHandler\Event\MethodThrowsProviderEvent;
use Psalm\Plugin\EventHandler\MethodThrowsProviderResult;

interface MethodThrowsProviderInterface
{
    /**
     * @return array<string>
     */
    public static function getClassLikeNames(): array;

    /**
     * Returns additional project methods invoked by a framework method.
     */
    public static function getMethodThrows(MethodThrowsProviderEvent $event): ?MethodThrowsProviderResult;
}
