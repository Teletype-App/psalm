<?php

declare(strict_types=1);

namespace Psalm\Plugin\Yii2;

use Override;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

final class ActiveRecordReturnTypeProvider implements MethodReturnTypeProviderInterface
{
    /**
     * @return array<string>
     * @psalm-pure
     */
    #[Override]
    public static function getClassLikeNames(): array
    {
        return [
            'yii\\db\\ActiveRecord',
            'yii\\db\\BaseActiveRecord',
        ];
    }

    #[Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $method = $event->getMethodNameLowercase();
        $called_class = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();
        $model_type = new Union([new TNamedObject($called_class)]);

        if ($method === 'find') {
            return new Union([new TGenericObject('yii\\db\\ActiveQuery', [$model_type])]);
        }

        if ($method === 'findone') {
            return new Union([new TNamedObject($called_class), new TNull()]);
        }

        if ($method === 'findall') {
            return new Union([new TArray([Type::getArrayKey(), $model_type])]);
        }

        if ($method !== 'hasone' && $method !== 'hasmany') {
            return null;
        }

        $first_arg = $event->getCallArgs()[0]->value ?? null;
        $first_arg_type = $first_arg
            ? $event->getSource()->getNodeTypeProvider()->getType($first_arg)
            : null;

        if ($first_arg_type === null) {
            return null;
        }

        $related_types = [];
        foreach ($first_arg_type->getAtomicTypes() as $atomic_type) {
            if ($atomic_type instanceof TLiteralClassString) {
                $related_types[] = new TNamedObject($atomic_type->value);
            } elseif ($atomic_type instanceof TClassString && $atomic_type->as_type !== null) {
                $related_types[] = $atomic_type->as_type;
            }
        }

        if ($related_types === []) {
            return null;
        }

        return new Union([
            new TGenericObject('yii\\db\\ActiveQuery', [new Union($related_types)]),
        ]);
    }
}
