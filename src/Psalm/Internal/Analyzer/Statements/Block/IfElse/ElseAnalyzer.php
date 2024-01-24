<?php

namespace Psalm\Internal\Analyzer\Statements\Block\IfElse;

use PhpParser;
use Psalm\Context;
use Psalm\Internal\Algebra;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Scope\IfScope;

use function array_diff_key;
use function array_merge;

/**
 * @internal
 */
final class ElseAnalyzer
{
    /**
     * @return false|null
     */
    public static function analyze(
        StatementsAnalyzer $statements_analyzer,
        ?PhpParser\Node\Stmt\Else_ $else,
        IfScope $if_scope,
        Context $else_context,
        Context $outer_context
    ): ?bool {
        $codebase = $statements_analyzer->getCodebase();
        $assigned_in_conditional_var_ids = [];

        if (!$else) {
            $else = new PhpParser\Node\Stmt\Else_();
        }

        $else_context->clauses = Algebra::simplifyCNF(
            [...$else_context->clauses, ...$if_scope->negated_clauses],
        );

        $else_types = Algebra::getTruthsFromFormula($else_context->clauses);

        $original_context = clone $else_context;

        IfAnalyzer::setVarsInScope(
            $else_types,
            $statements_analyzer,
            $else_context,
            $outer_context,
            [],
            [],
            $else,
            $if_scope,
        );

        $pre_stmts_assigned_var_ids = $else_context->assigned_var_ids;
        $else_context->assigned_var_ids = [];

        $pre_possibly_assigned_var_ids = $else_context->possibly_assigned_var_ids;
        $else_context->possibly_assigned_var_ids = [];

        if ($statements_analyzer->analyze($else->stmts, $else_context) === false) {
            return false;
        }

        [
            $final_actions,
            $new_assigned_var_ids,
            $new_possibly_assigned_var_ids,
            $has_ending_statements,
            $has_leaving_statements,
            $has_break_statement,
            $has_continue_statement,
        ] = IfAnalyzer::determineActions(
            $statements_analyzer,
            $else,
            $else_context,
            $outer_context,
            $pre_stmts_assigned_var_ids,
            $pre_possibly_assigned_var_ids,
        );

        $if_scope->final_actions = array_merge($final_actions, $if_scope->final_actions);

        // if it doesn't end in a return
        if (!$has_leaving_statements) {
            IfAnalyzer::updateIfScope(
                $codebase,
                $if_scope,
                $else_context,
                $original_context,
                array_merge($new_assigned_var_ids, $assigned_in_conditional_var_ids),
                $new_possibly_assigned_var_ids,
                $if_scope->if_cond_changed_var_ids,
            );

            $if_scope->reasonable_clauses = [];
        }

        if (!$has_ending_statements) {
            $vars_possibly_in_scope = array_diff_key(
                $else_context->vars_possibly_in_scope,
                $outer_context->vars_possibly_in_scope,
            );

            $possibly_assigned_var_ids = $new_possibly_assigned_var_ids;

            if (!$has_leaving_statements ||
                $else_context->loop_scope && !$has_continue_statement && !$has_break_statement
            ) {
                $if_scope->new_vars_possibly_in_scope = array_merge(
                    $vars_possibly_in_scope,
                    $if_scope->new_vars_possibly_in_scope,
                );

                $if_scope->possibly_assigned_var_ids = array_merge(
                    $possibly_assigned_var_ids,
                    $if_scope->possibly_assigned_var_ids,
                );
            }

            if ($has_leaving_statements && $else_context->loop_scope) {
                $else_context->loop_scope->vars_possibly_in_scope = array_merge(
                    $vars_possibly_in_scope,
                    $else_context->loop_scope->vars_possibly_in_scope,
                );
            }
        }

        if ($outer_context->collect_exceptions) {
            $outer_context->mergeExceptions($else_context);
        }

        // Track references set in the else to make sure they aren't reused later
        $outer_context->updateReferencesPossiblyFromConfusingScope(
            $else_context,
            $statements_analyzer,
        );

        return null;
    }
}
