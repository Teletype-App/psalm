<?php

declare(strict_types=1);

namespace Psalm\Plugin\EventHandler\Event;

use PhpParser\Node\Expr\MethodCall;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\StatementsSource;

/**
 * Allows opt-in plugins to provide a return type when the receiver itself is mixed.
 *
 * @psalm-immutable
 */
final class MixedMethodReturnTypeProviderEvent
{
    /**
     * @param lowercase-string $method_name_lowercase
     * @internal
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly StatementsSource $source,
        private readonly string $method_name_lowercase,
        private readonly MethodCall $stmt,
        private readonly Context $context,
        private readonly CodeLocation $code_location,
    ) {
    }

    public function getSource(): StatementsSource
    {
        return $this->source;
    }

    /** @return lowercase-string */
    public function getMethodNameLowercase(): string
    {
        return $this->method_name_lowercase;
    }

    public function getStmt(): MethodCall
    {
        return $this->stmt;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function getCodeLocation(): CodeLocation
    {
        return $this->code_location;
    }
}
