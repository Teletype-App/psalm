<?php

declare(strict_types=1);

namespace Psalm\Internal\PhpVisitor;

use Override;
use PhpParser;

use function in_array;
use function is_string;
use function strtolower;

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

        if ($node instanceof PhpParser\Node\Expr\FuncCall
            && $node->name instanceof PhpParser\Node\Name
            && in_array(strtolower($node->name->toString()), ['call_user_func', 'call_user_func_array'], true)
            && isset($node->args[0])
            && $node->args[0] instanceof PhpParser\Node\Arg
            && $node->args[0]->value instanceof PhpParser\Node\Expr\Variable
            && is_string($node->args[0]->value->name)
            && isset($this->parameter_names[$node->args[0]->value->name])
        ) {
            $this->invoked_parameters[$node->args[0]->value->name] = true;
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
