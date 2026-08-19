<?php

declare(strict_types=1);

namespace Psalm\Internal\PhpVisitor;

use Override;
use PhpParser;

use function is_string;

/**
 * Finds callable parameters invoked directly in the current function body.
 *
 * @internal
 */
final class ImmediatelyInvokedCallableVisitor extends PhpParser\NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $invoked_parameters = [];

    private bool $has_yield = false;

    /**
     * @param array<string, true> $parameter_names
     * @psalm-mutation-free
     */
    public function __construct(private readonly array $parameter_names)
    {
    }

    /** @psalm-external-mutation-free */
    #[Override]
    public function enterNode(PhpParser\Node $node): ?int
    {
        if ($node instanceof PhpParser\Node\FunctionLike || $node instanceof PhpParser\Node\Stmt\ClassLike) {
            return PhpParser\NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof PhpParser\Node\Expr\Yield_ || $node instanceof PhpParser\Node\Expr\YieldFrom) {
            $this->has_yield = true;
        }

        if ($node instanceof PhpParser\Node\Expr\FuncCall
            && $node->name instanceof PhpParser\Node\Expr\Variable
            && is_string($node->name->name)
            && isset($this->parameter_names[$node->name->name])
        ) {
            $this->invoked_parameters[$node->name->name] = true;
        }

        return null;
    }

    /** @return array<string, true> */
    public function getInvokedParameters(): array
    {
        return $this->invoked_parameters;
    }

    public function hasYield(): bool
    {
        return $this->has_yield;
    }
}
