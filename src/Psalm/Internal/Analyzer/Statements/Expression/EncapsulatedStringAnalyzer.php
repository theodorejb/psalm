<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer\Statements\Expression;

use PhpParser;
use PhpParser\Node\Expr;
use PhpParser\Node\InterpolatedStringPart;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\ExpressionAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Codebase\VariableUseGraph;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Type;
use Psalm\Type\Atomic\TLiteralFloat;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNonEmptyNonspecificLiteralString;
use Psalm\Type\Atomic\TNonEmptyString;
use Psalm\Type\Atomic\TNonspecificLiteralInt;
use Psalm\Type\Atomic\TNonspecificLiteralString;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;

/**
 * @internal
 */
final class EncapsulatedStringAnalyzer
{
    public static function analyze(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Scalar\InterpolatedString $stmt,
        Context $context,
    ): bool {
        $parent_nodes = [];

        $non_empty = false;

        $all_literals = true;

        $literal_string = "";

        foreach ($stmt->parts as $part) {
            if ($part instanceof Expr) {
                if (ExpressionAnalyzer::analyze($statements_analyzer, $part, $context) === false) {
                    return false;
                }
            }

            if ($part instanceof InterpolatedStringPart) {
                if ($literal_string !== null) {
                    $literal_string .= $part->value;
                }
                $non_empty = $non_empty || $part->value !== "";
            } elseif ($part_type = $statements_analyzer->node_data->getType($part)) {
                $casted_part_type = CastAnalyzer::castStringAttempt(
                    $statements_analyzer,
                    $context,
                    $part_type,
                    $part,
                );

                if (!$casted_part_type->allLiterals()) {
                    $all_literals = false;
                } elseif (!$non_empty) {
                    // Check if all literals are nonempty
                    $non_empty = true;
                    foreach ($casted_part_type->getAtomicTypes() as $atomic_literal) {
                        if (!$atomic_literal instanceof TLiteralInt
                            && !$atomic_literal instanceof TNonspecificLiteralInt
                            && !$atomic_literal instanceof TLiteralFloat
                            && !$atomic_literal instanceof TNonEmptyNonspecificLiteralString
                            && !($atomic_literal instanceof TLiteralString && $atomic_literal->value !== "")
                        ) {
                            $non_empty = false;
                            break;
                        }
                    }
                }

                if ($literal_string !== null) {
                    if ($casted_part_type->isSingleLiteral()) {
                        $literal_string .= $casted_part_type->getSingleLiteral()->value;
                    } else {
                        $literal_string = null;
                    }
                }

                if ($graph = $statements_analyzer->getDataFlowGraphWithSuppressed()) {
                    $var_location = new CodeLocation($statements_analyzer, $part);

                    $new_parent_node = DataFlowNode::getForAssignment('concat', $var_location);
                    $graph->addNode($new_parent_node);

                    $parent_nodes[$new_parent_node->id] = $new_parent_node;

                    $codebase = $statements_analyzer->getCodebase();
                    $event = new AddRemoveTaintsEvent($stmt, $context, $statements_analyzer, $codebase);

                    $added_taints = $codebase->config->eventDispatcher->dispatchAddTaints($event);
                    $removed_taints = $codebase->config->eventDispatcher->dispatchRemoveTaints($event);

                    $taints = $added_taints & ~$removed_taints;
                    if ($taints !== 0 && !$graph instanceof VariableUseGraph) {
                        $taint_source = $new_parent_node->setTaints($taints);
                        $graph->addSource($taint_source);
                    }

                    if ($casted_part_type->parent_nodes) {
                        foreach ($casted_part_type->parent_nodes as $parent_node) {
                            $graph->addPath(
                                $parent_node,
                                $new_parent_node,
                                'concat',
                                $added_taints,
                                $removed_taints,
                            );
                        }
                    }
                }
            } else {
                $all_literals = false;
                $literal_string = null;
            }
        }

        if ($non_empty) {
            if ($literal_string !== null) {
                $stmt_type = new Union(
                    [Type::getAtomicStringFromLiteral($literal_string)],
                    ['parent_nodes' => $parent_nodes],
                );
            } elseif ($all_literals) {
                $stmt_type = new Union(
                    [new TNonEmptyNonspecificLiteralString()],
                    ['parent_nodes' => $parent_nodes],
                );
            } else {
                $stmt_type = new Union(
                    [new TNonEmptyString()],
                    ['parent_nodes' => $parent_nodes],
                );
            }
        } elseif ($all_literals) {
            $stmt_type = new Union(
                [new TNonspecificLiteralString()],
                ['parent_nodes' => $parent_nodes],
            );
        } else {
            $stmt_type = new Union(
                [new TString()],
                ['parent_nodes' => $parent_nodes],
            );
        }

        $statements_analyzer->node_data->setType($stmt, $stmt_type);

        return true;
    }
}
