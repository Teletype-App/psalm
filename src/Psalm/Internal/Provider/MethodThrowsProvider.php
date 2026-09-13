<?php

declare(strict_types=1);

namespace Psalm\Internal\Provider;

use Closure;
use PhpParser\Node\Arg;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Plugin\EventHandler\Event\MethodThrowsProviderEvent;
use Psalm\Plugin\EventHandler\MethodThrowsProviderInterface;
use Psalm\Plugin\EventHandler\MethodThrowsProviderResult;
use Psalm\StatementsSource;

use function is_subclass_of;
use function strtolower;

/** @internal */
final class MethodThrowsProvider
{
    /**
     * @var array<lowercase-string, array<Closure(MethodThrowsProviderEvent): ?MethodThrowsProviderResult>>
     */
    private static array $handlers = [];

    /** @psalm-mutation-free */
    public function __construct()
    {
        self::$handlers = [];
    }

    /** @param class-string $class */
    public function registerClass(string $class): void
    {
        if (!is_subclass_of($class, MethodThrowsProviderInterface::class, true)) {
            return;
        }

        $callable = $class::getMethodThrows(...);
        foreach ($class::getClassLikeNames() as $fq_classlike_name) {
            self::$handlers[strtolower($fq_classlike_name)][] = $callable;
        }
    }

    /** @psalm-external-mutation-free */
    public function has(string $fq_classlike_name): bool
    {
        return isset(self::$handlers[strtolower($fq_classlike_name)]);
    }

    /**
     * @param list<Arg> $args
     * @param lowercase-string $method_name
     * @param lowercase-string|null $called_method_name
     */
    public function getMethodThrows(
        StatementsSource $statements_source,
        string $fq_classlike_name,
        string $method_name,
        array $args,
        Context $context,
        CodeLocation $code_location,
        ?string $called_fq_classlike_name = null,
        ?string $called_method_name = null,
    ): ?MethodThrowsProviderResult {
        foreach (self::$handlers[strtolower($fq_classlike_name)] ?? [] as $handler) {
            $result = $handler(new MethodThrowsProviderEvent(
                $statements_source,
                $fq_classlike_name,
                $method_name,
                $args,
                $context,
                $code_location,
                $called_fq_classlike_name,
                $called_method_name,
            ));
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }
}
