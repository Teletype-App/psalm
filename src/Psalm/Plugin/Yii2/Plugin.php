<?php

declare(strict_types=1);

namespace Psalm\Plugin\Yii2;

use Override;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;

/**
 * Yii 2 ActiveRecord type support.
 *
 * Enable with `<pluginClass class="Psalm\Plugin\Yii2\Plugin"/>` in Psalm's `plugins` config section.
 */
final class Plugin implements PluginEntryPointInterface
{
    #[Override]
    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null): void
    {
        require_once __DIR__ . '/ActiveRecordReturnTypeProvider.php';
        require_once __DIR__ . '/ActiveRecordPropertyProvider.php';

        ActiveRecordPropertyProvider::reset();

        $registration->registerHooksFromClass(ActiveRecordReturnTypeProvider::class);
        $registration->registerHooksFromClass(ActiveRecordPropertyProvider::class);
    }
}
