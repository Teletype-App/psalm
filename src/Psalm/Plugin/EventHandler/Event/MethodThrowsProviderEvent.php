<?php

declare(strict_types=1);

namespace Psalm\Plugin\EventHandler\Event;

use PhpParser\Node\Arg;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\StatementsSource;

/**
 * @psalm-immutable
 * @api
 */
final class MethodThrowsProviderEvent
{
    /**
     * @param list<Arg> $call_args
     * @param lowercase-string $method_name_lowercase
     * @param lowercase-string|null $called_method_name_lowercase
     * @internal
     */
    public function __construct(
        private readonly StatementsSource $source,
        private readonly string $fq_classlike_name,
        private readonly string $method_name_lowercase,
        private readonly array $call_args,
        private readonly Context $context,
        private readonly CodeLocation $code_location,
        private readonly ?string $called_fq_classlike_name = null,
        private readonly ?string $called_method_name_lowercase = null,
    ) {
    }

    public function getSource(): StatementsSource
    {
        return $this->source;
    }

    public function getFqClasslikeName(): string
    {
        return $this->fq_classlike_name;
    }

    /** @return lowercase-string */
    public function getMethodNameLowercase(): string
    {
        return $this->method_name_lowercase;
    }

    /** @return list<Arg> */
    public function getCallArgs(): array
    {
        return $this->call_args;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function getCodeLocation(): CodeLocation
    {
        return $this->code_location;
    }

    public function getCalledFqClasslikeName(): ?string
    {
        return $this->called_fq_classlike_name;
    }

    /** @return lowercase-string|null */
    public function getCalledMethodNameLowercase(): ?string
    {
        return $this->called_method_name_lowercase;
    }
}
