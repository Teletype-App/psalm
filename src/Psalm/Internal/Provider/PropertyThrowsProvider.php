<?php

declare(strict_types=1);

namespace Psalm\Internal\Provider;

use Closure;
use PhpParser\Node\Arg;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\Event\PropertyThrowsProviderEvent;
use Psalm\Plugin\EventHandler\MethodThrowsProviderResult;
use Psalm\Plugin\EventHandler\PropertyThrowsProviderInterface;
use Psalm\StatementsSource;

use function is_subclass_of;
use function strtolower;

/** @internal */
final class PropertyThrowsProvider
{
    /**
     * @var array<lowercase-string, array<Closure(PropertyThrowsProviderEvent): ?MethodThrowsProviderResult>>
     */
    private static array $handlers = [];

    /** @psalm-mutation-free */
    public function __construct()
    {
        self::$handlers = [];
    }

    /**
     * @param class-string $class
     * @psalm-external-mutation-free
     */
    public function registerClass(string $class): void
    {
        if (!is_subclass_of($class, PropertyThrowsProviderInterface::class, true)) {
            return;
        }

        $callable = $class::getPropertyThrows(...);
        foreach ($class::getClassLikeNames() as $fq_classlike_name) {
            $this->registerClosure($fq_classlike_name, $callable);
        }
    }

    /**
     * @param Closure(PropertyThrowsProviderEvent): ?MethodThrowsProviderResult $handler
     * @psalm-external-mutation-free
     */
    public function registerClosure(string $fq_classlike_name, Closure $handler): void
    {
        self::$handlers[strtolower($fq_classlike_name)][] = $handler;
    }

    /**
     * @param list<Arg> $args
     */
    public function mergePropertyThrows(
        StatementsSource $source,
        string $fq_classlike_name,
        string $property_name,
        bool $read_mode,
        Context $context,
        CodeLocation $code_location,
        array $args = [],
    ): void {
        foreach (self::$handlers[strtolower($fq_classlike_name)] ?? [] as $handler) {
            $result = $handler(new PropertyThrowsProviderEvent(
                $source,
                $fq_classlike_name,
                $property_name,
                $read_mode,
                $context,
                $code_location,
            ));
            if ($result === null) {
                continue;
            }

            $context->throws_analysis_complete = $context->throws_analysis_complete && $result->analysis_complete;
            $seen = [];
            foreach ($result->method_ids as $method_id) {
                $declaring_method_id = $source->getCodebase()->methods->getDeclaringMethodId($method_id);
                if ($declaring_method_id === null) {
                    continue;
                }
                $key = strtolower((string) $declaring_method_id);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $context->mergeFunctionExceptions(
                    $source->getCodebase()->methods->getStorage($declaring_method_id),
                    $code_location,
                    $args,
                    $source instanceof StatementsAnalyzer ? $source : null,
                );
            }

            return;
        }
    }
}
