<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer;

use Override;
use PhpParser;

use function array_count_values;
use function assert;
use function is_string;

/** @internal */
final class CatchRethrowCollector extends PhpParser\NodeVisitorAbstract
{
    /** @var list<array{'assign', string, ?string}|array{'throw', string, PhpParser\Node\Expr\Throw_}> */
    private array $events = [];

    /** @psalm-mutation-free */
    public function __construct(private readonly string $catch_var_name)
    {
    }

    /** @psalm-external-mutation-free */
    #[Override]
    public function enterNode(PhpParser\Node $node): ?int
    {
        if ($node instanceof PhpParser\Node\FunctionLike || $node instanceof PhpParser\Node\Stmt\ClassLike) {
            return PhpParser\NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if (($node instanceof PhpParser\Node\Expr\Assign
                || $node instanceof PhpParser\Node\Expr\AssignRef
                || $node instanceof PhpParser\Node\Expr\AssignOp)
            && $node->var instanceof PhpParser\Node\Expr\Variable
            && is_string($node->var->name)
        ) {
            $source = $node instanceof PhpParser\Node\Expr\Assign
                && $node->expr instanceof PhpParser\Node\Expr\Variable
                && is_string($node->expr->name)
                    ? $node->expr->name
                    : null;
            $this->events[] = ['assign', $node->var->name, $source];
        } elseif ($node instanceof PhpParser\Node\Expr\Throw_
            && $node->expr instanceof PhpParser\Node\Expr\Variable
            && is_string($node->expr->name)
        ) {
            $this->events[] = ['throw', $node->expr->name, $node];
        }

        return null;
    }

    /**
     * @return list<PhpParser\Node\Expr\Throw_>|null Null means the catch variable was reassigned.
     * @psalm-mutation-free
     */
    public function getRethrows(): ?array
    {
        $assigned_names = [];
        foreach ($this->events as $event) {
            if ($event[0] === 'assign') {
                $assigned_names[] = $event[1];
            }
        }
        $assignment_counts = array_count_values($assigned_names);
        if (isset($assignment_counts[$this->catch_var_name])) {
            return null;
        }

        $aliases = [$this->catch_var_name => true];
        $rethrows = [];
        foreach ($this->events as $event) {
            if ($event[0] === 'assign') {
                [, $target, $source] = $event;
                if ($assignment_counts[$target] === 1 && $source !== null && isset($aliases[$source])) {
                    $aliases[$target] = true;
                } else {
                    unset($aliases[$target]);
                }
                continue;
            }
            [, $name, $throw] = $event;
            if (isset($aliases[$name])) {
                assert($throw instanceof PhpParser\Node\Expr\Throw_);
                $rethrows[] = $throw;
            }
        }
        return $rethrows;
    }
}
