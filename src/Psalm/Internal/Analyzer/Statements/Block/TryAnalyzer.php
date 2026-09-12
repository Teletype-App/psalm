<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer\Statements\Block;

use PhpParser;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\Analyzer\CatchRethrowCollector;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Analyzer\ClassLikeNameOptions;
use Psalm\Internal\Analyzer\ScopeAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Analyzer\ThrownExceptionOrigin;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Internal\Scope\FinallyScope;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\Issue\InvalidCatch;
use Psalm\IssueBuffer;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use UnexpectedValueException;

use function array_intersect_key;
use function array_key_exists;
use function array_map;
use function array_merge;
use function count;
use function in_array;
use function is_string;
use function ksort;
use function strtolower;

/**
 * @internal
 */
final class TryAnalyzer
{
    private const MAX_THROWS_CONDITIONS = 64;
    /**
     * @return  false|null
     */
    public static function analyze(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Stmt\TryCatch $stmt,
        Context $context,
    ): ?bool {
        $catch_actions = [];
        $all_catches_leave = true;

        $codebase = $statements_analyzer->getCodebase();

        /** @var int $i */
        foreach ($stmt->catches as $i => $catch) {
            $catch_actions[$i] = ScopeAnalyzer::getControlActions(
                $catch->stmts,
                $statements_analyzer->node_data,
                [],
            );
            $all_catches_leave = $all_catches_leave && !in_array(ScopeAnalyzer::ACTION_NONE, $catch_actions[$i], true);
        }

        $existing_thrown_exceptions = $context->possibly_thrown_exceptions;
        $existing_thrown_exception_origins = $context->possibly_thrown_exception_origins;
        $existing_thrown_exception_conditions = $context->possibly_thrown_exception_conditions;

        /**
         * @var array<string, array<array-key, CodeLocation>> $context->possibly_thrown_exceptions
         */
        $context->possibly_thrown_exceptions = [];
        $context->possibly_thrown_exception_origins = [];
        $context->possibly_thrown_exception_conditions = [];

        $old_context = clone $context;

        $try_context = clone $context;

        if ($codebase->alter_code && $try_context->branch_point === null) {
            $try_context->branch_point = (int) $stmt->getAttribute('startFilePos');
        }

        if ($stmt->finally) {
            $try_context->finally_scope = new FinallyScope($try_context->vars_in_scope);
        }

        $assigned_var_ids = $try_context->assigned_var_ids;
        $context->assigned_var_ids = [];

        $was_inside_try = $context->inside_try;
        $context->inside_try = true;
        if ($statements_analyzer->analyze($stmt->stmts, $context) === false) {
            return false;
        }
        $context->inside_try = $was_inside_try;

        $context->has_returned = false;

        $try_block_control_actions = ScopeAnalyzer::getControlActions(
            $stmt->stmts,
            $statements_analyzer->node_data,
            [],
        );

        /** @var array<string, int> */
        $newly_assigned_var_ids = $context->assigned_var_ids;

        $context->assigned_var_ids = array_merge(
            $assigned_var_ids,
            $newly_assigned_var_ids,
        );

        foreach ($context->vars_in_scope as $var_id => $type) {
            if (!isset($try_context->vars_in_scope[$var_id])) {
                $try_context->vars_in_scope[$var_id] = $type;

                $context->vars_in_scope[$var_id] = $type->setPossiblyUndefined(true, true);
            } else {
                $try_context->vars_in_scope[$var_id] = Type::combineUnionTypes(
                    $try_context->vars_in_scope[$var_id],
                    $type,
                );
            }
        }

        if ($try_context->finally_scope) {
            foreach ($context->vars_in_scope as $var_id => $type) {
                $try_context->finally_scope->vars_in_scope[$var_id] = Type::combineUnionTypes(
                    $try_context->finally_scope->vars_in_scope[$var_id] ?? null,
                    $type,
                    $statements_analyzer->getCodebase(),
                );
            }
        }

        $try_context->vars_possibly_in_scope = $context->vars_possibly_in_scope;
        $try_context->possibly_thrown_exceptions = $context->possibly_thrown_exceptions;
        $try_context->possibly_thrown_exception_origins = $context->possibly_thrown_exception_origins;
        $try_context->possibly_thrown_exception_conditions = $context->possibly_thrown_exception_conditions;

        $try_leaves_loop = $context->loop_scope
            && $context->loop_scope->final_actions
            && !in_array(ScopeAnalyzer::ACTION_NONE, $context->loop_scope->final_actions, true);

        if (!$all_catches_leave) {
            foreach ($newly_assigned_var_ids as $assigned_var_id => $_) {
                $context->removeVarFromConflictingClauses($assigned_var_id);
            }
        } else {
            foreach ($newly_assigned_var_ids as $assigned_var_id => $_) {
                $try_context->removeVarFromConflictingClauses($assigned_var_id);
            }
        }

        // at this point we have two contexts – $context, in which it is assumed that everything was fine,
        // and $try_context - which allows all variables to have the union of the values before and after
        // the try was applied
        $original_context = clone $try_context;

        $issues_to_suppress = [
            'RedundantCondition',
            'RedundantConditionGivenDocblockType',
            'TypeDoesNotContainNull',
            'TypeDoesNotContainType',
        ];

        $definitely_newly_assigned_var_ids = $newly_assigned_var_ids;

        /** @var int $i */
        foreach ($stmt->catches as $i => $catch) {
            $catch_context = clone $original_context;
            $catch_context->has_returned = false;
            $caught_exceptions = [];
            $caught_exception_conditions = [];

            foreach ($catch_context->vars_in_scope as $var_id => $type) {
                if (!isset($old_context->vars_in_scope[$var_id])) {
                    $catch_context->vars_in_scope[$var_id] = $type->setPossiblyUndefined(
                        $catch_context->vars_in_scope[$var_id]->possibly_undefined,
                        true,
                    );
                } else {
                    $catch_context->vars_in_scope[$var_id] = Type::combineUnionTypes(
                        $type,
                        $old_context->vars_in_scope[$var_id],
                    );
                }
            }

            $fq_catch_classes = [];

            if (!$catch->types) {
                throw new UnexpectedValueException('Very bad');
            }

            foreach ($catch->types as $catch_type) {
                $fq_catch_class = ClassLikeAnalyzer::getFQCLNFromNameObject(
                    $catch_type,
                    $statements_analyzer->getAliases(),
                );

                $fq_catch_class = $codebase->classlikes->getUnAliasedName($fq_catch_class);

                if ($codebase->alter_code && $fq_catch_class) {
                    $codebase->classlikes->handleClassLikeReferenceInMigration(
                        $codebase,
                        $statements_analyzer,
                        $catch_type,
                        $fq_catch_class,
                        $context,
                    );
                }

                if ($original_context->check_classes) {
                    ClassLikeAnalyzer::checkFullyQualifiedClassLikeName(
                        $statements_analyzer,
                        $fq_catch_class,
                        new CodeLocation($statements_analyzer->getSource(), $catch_type, $context->include_location),
                        $context,
                        $statements_analyzer->getSuppressedIssues(),
                        new ClassLikeNameOptions(true),
                    );
                }

                if (($codebase->classExists($fq_catch_class, null, $context)
                        && strtolower($fq_catch_class) !== 'exception'
                        && !($codebase->classExtends($fq_catch_class, 'Exception')
                            || $codebase->classImplements($fq_catch_class, 'Throwable')))
                    || ($codebase->interfaceExists($fq_catch_class, null, $context)
                        && strtolower($fq_catch_class) !== 'throwable'
                        && !$codebase->interfaceExtends($fq_catch_class, 'Throwable'))
                ) {
                    IssueBuffer::maybeAdd(
                        new InvalidCatch(
                            'Class/interface ' . $fq_catch_class . ' cannot be caught',
                            new CodeLocation($statements_analyzer->getSource(), $stmt),
                            $fq_catch_class,
                        ),
                        $statements_analyzer->getSuppressedIssues(),
                    );
                }

                $fq_catch_classes[] = $fq_catch_class;
            }

            if ($catch_context->collect_exceptions) {
                foreach ($fq_catch_classes as $fq_catch_class) {
                    $fq_catch_class_lower = strtolower($fq_catch_class);

                    foreach ($catch_context->possibly_thrown_exceptions as $exception_fqcln => $_) {
                        $exception_fqcln_lower = strtolower($exception_fqcln);

                        if ($exception_fqcln_lower === $fq_catch_class_lower
                            || ($codebase->classExists($exception_fqcln, null, $context)
                                && $codebase->classExtendsOrImplements($exception_fqcln, $fq_catch_class))
                            || ($codebase->interfaceExists($exception_fqcln, null, $context)
                                && $codebase->interfaceExtends($exception_fqcln, $fq_catch_class))
                        ) {
                            $caught_exceptions[$exception_fqcln] = self::getExceptionOrigins(
                                $catch_context,
                                $exception_fqcln,
                            );
                            $exception_conditions =
                                $catch_context->possibly_thrown_exception_conditions[$exception_fqcln] ?? [];
                            foreach ($exception_conditions as $conditions) {
                                foreach ($conditions as $condition) {
                                    if (!in_array(
                                        $condition,
                                        $caught_exception_conditions[$exception_fqcln] ?? [],
                                        true,
                                    )) {
                                        $caught_exception_conditions[$exception_fqcln][] = $condition;
                                    }
                                }
                            }
                            unset($original_context->possibly_thrown_exceptions[$exception_fqcln]);
                            unset($original_context->possibly_thrown_exception_origins[$exception_fqcln]);
                            unset($original_context->possibly_thrown_exception_conditions[$exception_fqcln]);
                            unset($context->possibly_thrown_exceptions[$exception_fqcln]);
                            /** @psalm-suppress EmptyArrayAccess Populated while the analyzed try block is visited */
                            unset($context->possibly_thrown_exception_origins[$exception_fqcln]);
                            /** @psalm-suppress EmptyArrayAccess Populated while the analyzed try block is visited */
                            unset($context->possibly_thrown_exception_conditions[$exception_fqcln]);
                            unset($catch_context->possibly_thrown_exceptions[$exception_fqcln]);
                            unset($catch_context->possibly_thrown_exception_origins[$exception_fqcln]);
                            unset($catch_context->possibly_thrown_exception_conditions[$exception_fqcln]);
                        }
                    }
                }

                $catch_context->possibly_thrown_exceptions = [];
                $catch_context->possibly_thrown_exception_origins = [];
                $catch_context->possibly_thrown_exception_conditions = [];
            }

            // discard all clauses because crazy stuff may have happened in try block
            $catch_context->clauses = [];

            if ($catch->var && is_string($catch->var->name)) {
                $catch_var_id = '$' . $catch->var->name;

                $catch_context->vars_in_scope[$catch_var_id] = new Union(
                    array_map(
                        static fn(string $fq_catch_class): TNamedObject => new TNamedObject(
                            $fq_catch_class,
                            false,
                            false,
                            strtolower($fq_catch_class) !== 'throwable'
                                && $codebase->interfaceExists($fq_catch_class, null, $context)
                                && !$codebase->interfaceExtends($fq_catch_class, 'Throwable')
                                    ? ['Throwable' => new TNamedObject('Throwable')]
                                    : [],
                        ),
                        $fq_catch_classes,
                    ),
                );

                // removes dependent vars from $context
                $catch_context->removeDescendents(
                    $catch_var_id,
                    $catch_context->vars_in_scope[$catch_var_id],
                    $catch_context->vars_in_scope[$catch_var_id],
                    $statements_analyzer,
                );

                $catch_context->vars_possibly_in_scope[$catch_var_id] = true;

                $location = new CodeLocation($statements_analyzer->getSource(), $catch->var);

                if (!$statements_analyzer->hasVariable($catch_var_id)) {
                    $statements_analyzer->registerVariable(
                        $catch_var_id,
                        $location,
                        $catch_context->branch_point,
                    );
                } else {
                    $statements_analyzer->registerVariableAssignment(
                        $catch_var_id,
                        $location,
                    );
                }

                if ($statements_analyzer->data_flow_graph) {
                    $catch_var_node = DataFlowNode::getForAssignment($catch_var_id, $location);

                    $catch_context->vars_in_scope[$catch_var_id] =
                        $catch_context->vars_in_scope[$catch_var_id]->addParentNodes([
                            $catch_var_node->id => $catch_var_node,
                        ])
                    ;

                        $statements_analyzer->variable_use_graph?->addPath(
                            $catch_var_node,
                            DataFlowNode::getForVariableUse(),
                            'variable-use',
                        );
                }
            }

            $suppressed_issues = $statements_analyzer->getSuppressedIssues();

            foreach ($issues_to_suppress as $issue_to_suppress) {
                if (!in_array($issue_to_suppress, $suppressed_issues, true)) {
                    $statements_analyzer->addSuppressedIssues([$issue_to_suppress]);
                }
            }

            $old_catch_assigned_var_ids = $catch_context->assigned_var_ids;

            $catch_context->assigned_var_ids = [];

            $statements_analyzer->analyze($catch->stmts, $catch_context);

            if ($catch->var && is_string($catch->var->name) && $caught_exceptions !== []) {
                self::restoreRethrownExceptions(
                    $statements_analyzer,
                    $catch,
                    $catch_context,
                    $caught_exceptions,
                    $caught_exception_conditions,
                    $fq_catch_classes,
                );
            }

            // recalculate in case there's a no-return clause
            $catch_actions[$i] = ScopeAnalyzer::getControlActions(
                $catch->stmts,
                $statements_analyzer->node_data,
                [],
            );

            foreach ($issues_to_suppress as $issue_to_suppress) {
                if (!in_array($issue_to_suppress, $suppressed_issues, true)) {
                    $statements_analyzer->removeSuppressedIssues([$issue_to_suppress]);
                }
            }

            /** @var array<string, bool> */
            $new_catch_assigned_var_ids = $catch_context->assigned_var_ids;

            $catch_context->assigned_var_ids += $old_catch_assigned_var_ids;

            if ($catch_context->collect_exceptions) {
                $context->mergeExceptions($catch_context);
            }

            $catch_doesnt_leave_parent_scope = $catch_actions[$i] !== [ScopeAnalyzer::ACTION_END]
                && $catch_actions[$i] !== [ScopeAnalyzer::ACTION_CONTINUE]
                && $catch_actions[$i] !== [ScopeAnalyzer::ACTION_BREAK];

            if ($catch_doesnt_leave_parent_scope) {
                $definitely_newly_assigned_var_ids = array_intersect_key(
                    $new_catch_assigned_var_ids,
                    $definitely_newly_assigned_var_ids,
                );

                foreach ($catch_context->vars_in_scope as $var_id => $type) {
                    if ($try_block_control_actions === [ScopeAnalyzer::ACTION_END]) {
                        $context->vars_in_scope[$var_id] = $type;
                    } elseif (isset($context->vars_in_scope[$var_id])) {
                        $context->vars_in_scope[$var_id] = Type::combineUnionTypes(
                            $context->vars_in_scope[$var_id],
                            $type,
                        );
                    }
                }

                $context->vars_possibly_in_scope = array_merge(
                    $catch_context->vars_possibly_in_scope,
                    $context->vars_possibly_in_scope,
                );
            } else {
                if ($stmt->finally) {
                    $context->vars_possibly_in_scope = array_merge(
                        $catch_context->vars_possibly_in_scope,
                        $context->vars_possibly_in_scope,
                    );
                }
            }

            if ($try_context->finally_scope) {
                foreach ($catch_context->vars_in_scope as $var_id => &$type) {
                    if (isset($try_context->finally_scope->vars_in_scope[$var_id])) {
                        if ($try_context->finally_scope->vars_in_scope[$var_id] !== $type) {
                            $try_context->finally_scope->vars_in_scope[$var_id] = Type::combineUnionTypes(
                                $try_context->finally_scope->vars_in_scope[$var_id],
                                $type,
                                $statements_analyzer->getCodebase(),
                            );
                        }
                    } else {
                        $try_context->finally_scope->vars_in_scope[$var_id] = $type->setPossiblyUndefined(
                            true,
                            true,
                        );
                    }
                }
                unset($type);
            }
        }

        if ($context->loop_scope
            && !$try_leaves_loop
            && !in_array(ScopeAnalyzer::ACTION_NONE, $context->loop_scope->final_actions, true)
        ) {
            $context->loop_scope->final_actions[] = ScopeAnalyzer::ACTION_NONE;
        }

        $finally_has_returned = false;
        if ($stmt->finally) {
            if ($try_context->finally_scope) {
                $finally_context = clone $context;

                $finally_context->assigned_var_ids = [];
                $finally_context->possibly_assigned_var_ids = [];
                $finally_context->possibly_thrown_exceptions = [];
                $finally_context->possibly_thrown_exception_origins = [];
                $finally_context->possibly_thrown_exception_conditions = [];

                $finally_context->vars_in_scope = $try_context->finally_scope->vars_in_scope;

                $statements_analyzer->analyze($stmt->finally->stmts, $finally_context);

                $finally_has_returned = $finally_context->has_returned;

                if ($finally_has_returned) {
                    // A return or throw which is guaranteed to execute in finally
                    // replaces any exception still escaping from try/catch.
                    $context->possibly_thrown_exceptions = [];
                    $context->possibly_thrown_exception_origins = [];
                    $context->possibly_thrown_exception_conditions = [];
                }
                $context->mergeExceptions($finally_context);

                /** @var string $var_id */
                foreach ($finally_context->assigned_var_ids as $var_id => $_) {
                    if (isset($context->vars_in_scope[$var_id])
                        && isset($finally_context->vars_in_scope[$var_id])
                    ) {
                        $possibly_undefined = $context->vars_in_scope[$var_id]->possibly_undefined
                            && $context->vars_in_scope[$var_id]->possibly_undefined_from_try;

                        $context->vars_in_scope[$var_id] = Type::combineUnionTypes(
                            $context->vars_in_scope[$var_id],
                            $finally_context->vars_in_scope[$var_id],
                            $codebase,
                        );
                        if ($possibly_undefined) {
                            /** @psalm-suppress InaccessibleProperty We just created this type */
                            $context->vars_in_scope[$var_id]->possibly_undefined = false;
                            /** @psalm-suppress InaccessibleProperty We just created this type */
                            $context->vars_in_scope[$var_id]->possibly_undefined_from_try = false;
                        }
                    } elseif (isset($finally_context->vars_in_scope[$var_id])) {
                        $context->vars_in_scope[$var_id] = $finally_context->vars_in_scope[$var_id];
                    }
                }
            }
        }

        foreach ($definitely_newly_assigned_var_ids as $var_id => $_) {
            if (isset($context->vars_in_scope[$var_id])) {
                if ($context->vars_in_scope[$var_id]->possibly_undefined_from_try) {
                    $context->vars_in_scope[$var_id] =
                        $context->vars_in_scope[$var_id]->setPossiblyUndefined(
                            false,
                            false,
                        );
                }
            }
        }

        foreach ($existing_thrown_exceptions as $possibly_thrown_exception => $codelocations) {
            foreach ($codelocations as $hash => $codelocation) {
                $context->possibly_thrown_exceptions[$possibly_thrown_exception][$hash] = $codelocation;
                $origin = $existing_thrown_exception_origins[$possibly_thrown_exception][$hash]
                    ?? ThrownExceptionOrigin::PROPAGATED;
                $context->possibly_thrown_exception_origins[$possibly_thrown_exception][$hash] =
                    ($context->possibly_thrown_exception_origins[$possibly_thrown_exception][$hash] ?? 0) | $origin;
                $existing_conditions =
                    $existing_thrown_exception_conditions[$possibly_thrown_exception][$hash] ?? [[]];
                foreach ($existing_conditions as $condition) {
                    $context->addThrownExceptionCondition($possibly_thrown_exception, $hash, $condition);
                }
            }
        }

        $body_has_returned = !in_array(ScopeAnalyzer::ACTION_NONE, $try_block_control_actions, true);
        $context->has_returned = ($body_has_returned && $all_catches_leave) || $finally_has_returned;

        return null;
    }

    /**
     * @param array<string, int> $caught_exceptions
     * @param array<string, list<array<int, bool|int|string|null>>> $caught_exception_conditions
     * @param non-empty-list<string> $fq_catch_classes
     */
    private static function restoreRethrownExceptions(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Stmt\Catch_ $catch,
        Context $catch_context,
        array $caught_exceptions,
        array $caught_exception_conditions,
        array $fq_catch_classes,
    ): void {
        if (!$catch->var || !is_string($catch->var->name)) {
            return;
        }

        $collector = new CatchRethrowCollector($catch->var->name);
        $traverser = new PhpParser\NodeTraverser($collector);
        $traverser->traverse($catch->stmts);
        $rethrows = $collector->getRethrows();
        if ($rethrows === null) {
            return;
        }

        foreach ($rethrows as $rethrow) {
            $codelocation = new CodeLocation($statements_analyzer->getFileAnalyzer(), $rethrow);
            $hash = $codelocation->getHash();
            $rethrow_exceptions = [];
            $rethrow_conditions = [];

            foreach ($catch_context->possibly_thrown_exceptions as $exception => $_) {
                if (isset($catch_context->possibly_thrown_exceptions[$exception][$hash])) {
                    $rethrow_exceptions[$exception] = true;
                    $rethrow_conditions[$exception] =
                        $catch_context->possibly_thrown_exception_conditions[$exception][$hash] ?? [[]];
                }

                unset($catch_context->possibly_thrown_exceptions[$exception][$hash]);
                unset($catch_context->possibly_thrown_exception_origins[$exception][$hash]);
                unset($catch_context->possibly_thrown_exception_conditions[$exception][$hash]);
                if ($catch_context->possibly_thrown_exceptions[$exception] === []) {
                    unset($catch_context->possibly_thrown_exceptions[$exception]);
                    unset($catch_context->possibly_thrown_exception_origins[$exception]);
                    unset($catch_context->possibly_thrown_exception_conditions[$exception]);
                }
            }

            if ($rethrow_exceptions === []) {
                continue;
            }

            $catch_types = array_map(strtolower(...), $fq_catch_classes);

            $is_narrowed_rethrow = false;
            foreach ($rethrow_exceptions as $rethrow_exception => $_) {
                if (!in_array(strtolower($rethrow_exception), $catch_types, true)) {
                    $is_narrowed_rethrow = true;
                    break;
                }
            }

            if ($is_narrowed_rethrow) {
                foreach ($rethrow_exceptions as $rethrow_exception => $_) {
                    $catch_context->possibly_thrown_exceptions[$rethrow_exception][$hash] = $codelocation;
                    $catch_context->possibly_thrown_exception_origins[$rethrow_exception][$hash] =
                        ThrownExceptionOrigin::NARROWED_RETHROW;
                    foreach ($rethrow_conditions[$rethrow_exception] ?? [[]] as $condition) {
                        $catch_context->addThrownExceptionCondition($rethrow_exception, $hash, $condition);
                    }
                }

                $codebase = $statements_analyzer->getCodebase();
                foreach ($caught_exceptions as $caught_exception => $origins) {
                    if (($origins & (ThrownExceptionOrigin::DIRECT | ThrownExceptionOrigin::NARROWED_RETHROW)) === 0) {
                        continue;
                    }

                    $caught_type = new Union([new TNamedObject($caught_exception)]);
                    foreach ($rethrow_exceptions as $rethrow_exception => $_) {
                        $rethrow_type = new Union([new TNamedObject($rethrow_exception)]);
                        if (!UnionTypeComparator::isContainedBy($codebase, $caught_type, $rethrow_type)) {
                            continue;
                        }

                        $combined_conditions = self::combineThrowsConditions(
                            $caught_exception_conditions[$caught_exception] ?? [[]],
                            $rethrow_conditions[$rethrow_exception] ?? [[]],
                        );
                        if ($combined_conditions === []) {
                            continue;
                        }
                        $catch_context->possibly_thrown_exceptions[$caught_exception][$hash] = $codelocation;
                        $catch_context->possibly_thrown_exception_origins[$caught_exception][$hash] = $origins;
                        foreach ($combined_conditions as $condition) {
                            $catch_context->addThrownExceptionCondition($caught_exception, $hash, $condition);
                        }
                        break;
                    }
                }

                continue;
            }

            foreach ($caught_exceptions as $caught_exception => $origins) {
                $combined_conditions = [];
                foreach ($rethrow_conditions as $conditions) {
                    foreach (self::combineThrowsConditions(
                        $caught_exception_conditions[$caught_exception] ?? [[]],
                        $conditions,
                    ) as $condition) {
                        if (!in_array($condition, $combined_conditions, true)) {
                            $combined_conditions[] = $condition;
                        }
                    }
                }
                if ($combined_conditions === []) {
                    continue;
                }
                $catch_context->possibly_thrown_exceptions[$caught_exception][$hash] = $codelocation;
                $catch_context->possibly_thrown_exception_origins[$caught_exception][$hash] = $origins;
                foreach ($combined_conditions as $condition) {
                    $catch_context->addThrownExceptionCondition($caught_exception, $hash, $condition);
                }
            }
        }
    }

    /**
     * @param list<array<int, bool|int|string|null>> $left
     * @param list<array<int, bool|int|string|null>> $right
     * @return list<array<int, bool|int|string|null>>
     * @psalm-pure
     */
    private static function combineThrowsConditions(array $left, array $right): array
    {
        $result = [];
        foreach ($left as $left_condition) {
            foreach ($right as $right_condition) {
                $combined = $left_condition;
                foreach ($right_condition as $offset => $value) {
                    if (array_key_exists($offset, $combined) && $combined[$offset] !== $value) {
                        continue 2;
                    }
                    $combined[$offset] = $value;
                }
                ksort($combined);
                if (!in_array($combined, $result, true)) {
                    $result[] = $combined;
                    if (count($result) > self::MAX_THROWS_CONDITIONS) {
                        return [[]];
                    }
                }
            }
        }
        return $result;
    }

    /** @psalm-mutation-free */
    private static function getExceptionOrigins(Context $context, string $exception): int
    {
        $origins = 0;
        foreach ($context->possibly_thrown_exception_origins[$exception] ?? [] as $origin) {
            $origins |= $origin;
        }

        return $origins ?: ThrownExceptionOrigin::PROPAGATED;
    }
}
