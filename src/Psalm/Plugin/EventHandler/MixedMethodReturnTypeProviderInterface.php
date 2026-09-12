<?php

declare(strict_types=1);

namespace Psalm\Plugin\EventHandler;

use Psalm\Plugin\EventHandler\Event\MixedMethodReturnTypeProviderEvent;
use Psalm\Type\Union;

/** @psalm-mutable */
interface MixedMethodReturnTypeProviderInterface
{
    public static function getMixedMethodReturnType(MixedMethodReturnTypeProviderEvent $event): ?Union;
}
