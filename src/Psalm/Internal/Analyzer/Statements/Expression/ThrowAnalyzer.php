<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer\Statements\Expression;

use PhpParser;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\ExpressionAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Analyzer\ThrownExceptionOrigin;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\Issue\InvalidThrow;
use Psalm\IssueBuffer;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

use function count;
use function reset;
use function strtolower;

/**
 * @internal
 */
final class ThrowAnalyzer
{
    public static function analyze(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Expr\Throw_ $stmt,
        Context $context,
    ): bool {
        $context->inside_throw = true;
        if (ExpressionAnalyzer::analyze($statements_analyzer, $stmt->expr, $context) === false) {
            $context->has_returned = true;
            return false;
        }
        $context->inside_throw = false;
        $context->has_returned = true;

        if ($context->finally_scope) {
            foreach ($context->vars_in_scope as $var_id => &$type) {
                if (isset($context->finally_scope->vars_in_scope[$var_id])) {
                    $context->finally_scope->vars_in_scope[$var_id] = Type::combineUnionTypes(
                        $context->finally_scope->vars_in_scope[$var_id],
                        $type,
                        $statements_analyzer->getCodebase(),
                    );
                } else {
                    $type = $type->setPossiblyUndefined(true, true);
                    $context->finally_scope->vars_in_scope[$var_id] = $type;
                }
            }
        }

        if ($context->check_classes
            && ($throw_type = $statements_analyzer->node_data->getType($stmt->expr))
            && !$throw_type->hasMixed()
        ) {
            $exception_type = new Union([new TNamedObject('Exception'), new TNamedObject('Throwable')]);

            $file_analyzer = $statements_analyzer->getFileAnalyzer();
            $codebase = $statements_analyzer->getCodebase();

            foreach ($throw_type->getAtomicTypes() as $throw_type_part) {
                $throw_type_candidate = new Union([$throw_type_part]);

                if (!UnionTypeComparator::isContainedBy($codebase, $throw_type_candidate, $exception_type)) {
                    if (IssueBuffer::accepts(
                        new InvalidThrow(
                            'Cannot throw ' . $throw_type_part
                                . ' as it does not extend Exception or implement Throwable',
                            new CodeLocation($file_analyzer, $stmt),
                            (string) $throw_type_part,
                        ),
                        $statements_analyzer->getSuppressedIssues(),
                    )) {
                        return false;
                    }
                } elseif (!$context->isSuppressingExceptions($statements_analyzer)) {
                    $codelocation = new CodeLocation($file_analyzer, $stmt);
                    $hash = $codelocation->getHash();
                    foreach ($throw_type->getAtomicTypes() as $throw_atomic_type) {
                        if ($throw_atomic_type instanceof TNamedObject) {
                            $context->possibly_thrown_exceptions[$throw_atomic_type->value][$hash] = $codelocation;
                            $context->possibly_thrown_exception_origins[$throw_atomic_type->value][$hash] =
                                self::isDirectThrow($statements_analyzer, $stmt->expr, $throw_atomic_type)
                                    ? ThrownExceptionOrigin::DIRECT
                                    : ThrownExceptionOrigin::PROPAGATED;
                        }
                    }
                }
            }
        }

        $statements_analyzer->node_data->setType($stmt, Type::getNever());

        return true;
    }

    private static function isDirectThrow(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Expr $throw_expression,
        TNamedObject $throw_type,
    ): bool {
        while ($throw_expression instanceof PhpParser\Node\Expr\MethodCall) {
            $throw_expression = $throw_expression->var;
        }

        if (!$throw_expression instanceof PhpParser\Node\Expr\New_) {
            return false;
        }

        $new_type = $statements_analyzer->node_data->getType($throw_expression);
        if ($new_type === null || $new_type->hasMixed()) {
            return false;
        }

        $new_atomic_types = $new_type->getAtomicTypes();
        if (count($new_atomic_types) !== 1) {
            return false;
        }

        $new_atomic_type = reset($new_atomic_types);

        return $new_atomic_type instanceof TNamedObject
            && strtolower($new_atomic_type->value) === strtolower($throw_type->value);
    }
}
