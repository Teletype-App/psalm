<?php

declare(strict_types=1);

namespace Psalm\Plugin\EventHandler;

use Psalm\Plugin\EventHandler\Event\PropertyThrowsProviderEvent;

interface PropertyThrowsProviderInterface
{
    /**
     * @return array<string>
     * @psalm-pure
     */
    public static function getClassLikeNames(): array;

    public static function getPropertyThrows(PropertyThrowsProviderEvent $event): ?MethodThrowsProviderResult;
}
