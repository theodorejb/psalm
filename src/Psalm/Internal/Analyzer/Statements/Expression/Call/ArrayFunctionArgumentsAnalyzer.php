<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use AssertionError;
use PhpParser;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment\ArrayAssignmentAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\AssignmentAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\CallAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\ExpressionIdentifier;
use Psalm\Internal\Analyzer\Statements\ExpressionAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Codebase\InternalCallMapHandler;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\ArrayType;
use Psalm\Internal\Type\Comparator\TypeComparisonResult;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\Internal\Type\TemplateResult;
use Psalm\Internal\Type\TemplateStandinTypeReplacer;
use Psalm\Internal\Type\TypeCombiner;
use Psalm\Internal\Type\TypeExpander;
use Psalm\Issue\ArgumentTypeCoercion;
use Psalm\Issue\InvalidArgument;
use Psalm\Issue\InvalidScalarArgument;
use Psalm\Issue\MixedArgumentTypeCoercion;
use Psalm\Issue\PossiblyInvalidArgument;
use Psalm\Issue\TooFewArguments;
use Psalm\Issue\TooManyArguments;
use Psalm\IssueBuffer;
use Psalm\Node\Expr\VirtualArrayDimFetch;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNonEmptyArray;
use Psalm\Type\Union;
use UnexpectedValueException;

use function array_filter;
use function array_pop;
use function array_shift;
use function array_unshift;
use function assert;
use function count;
use function explode;
use function in_array;
use function is_numeric;
use function str_contains;
use function strtolower;
use function substr;

/**
 * @internal
 */
final class ArrayFunctionArgumentsAnalyzer
{
    /**
     * @param   array<int, PhpParser\Node\Arg> $args
     */
    public static function checkArgumentsMatch(
        StatementsAnalyzer $statements_analyzer,
        Context $context,
        array $args,
        string $method_id,
        bool $check_functions,
    ): void {
        $closure_index = $method_id === 'array_map' ? 0 : 1;

        $array_arg_types = [];

        foreach ($args as $i => $arg) {
            if ($i === 0 && $method_id === 'array_map') {
                continue;
            }

            if ($i === 1 && in_array($method_id, ArgumentsAnalyzer::ARRAY_FILTERLIKE, true)) {
                break;
            }

            /**
             * @var TKeyedArray|TArray|null
             */
            $array_arg_type = ($arg_value_type = $statements_analyzer->node_data->getType($arg->value))
                    && $arg_value_type->hasArray()
                ? $arg_value_type->getArray()
                : null;

            if ($array_arg_type instanceof TKeyedArray) {
                $array_arg_type = $array_arg_type->getGenericArrayType();
            }

            $array_arg_types[] = $array_arg_type;
        }

        $closure_arg = $args[$closure_index] ?? null;

        $closure_arg_type = null;

        if ($closure_arg) {
            $closure_arg_type = $statements_analyzer->node_data->getType($closure_arg->value);
        }

        if ($closure_arg && $closure_arg_type) {
            $min_closure_param_count = $max_closure_param_count = count($array_arg_types);

            if ($method_id === 'array_filter') {
                $max_closure_param_count = count($args) > 2 ? 2 : 1;
            } elseif (in_array($method_id, ArgumentsAnalyzer::ARRAY_FILTERLIKE, true)) {
                $max_closure_param_count = 2;
            }

            $new = [];
            foreach ($closure_arg_type->getAtomicTypes() as $closure_type) {
                self::checkClosureType(
                    $statements_analyzer,
                    $context,
                    $method_id,
                    $closure_type,
                    $closure_arg,
                    $min_closure_param_count,
                    $max_closure_param_count,
                    $array_arg_types,
                    $check_functions,
                );
                $new []= $closure_type;
            }

            $statements_analyzer->node_data->setType(
                $closure_arg->value,
                $closure_arg_type->getBuilder()->setTypes($new)->freeze(),
            );
        }
    }

    /**
     * @param   list<PhpParser\Node\Arg>          $args
     * @return  false|null
     */
    public static function handleAddition(
        StatementsAnalyzer $statements_analyzer,
        array $args,
        Context $context,
        string $method_id,
    ): ?bool {
        $array_arg = $args[0]->value;
        $nb_args = count($args);

        $unpacked_args = array_filter(
            $args,
            static fn(PhpParser\Node\Arg $arg): bool => $arg->unpack,
        );

        if ($method_id === 'array_push' && !$unpacked_args) {
            for ($i = 1; $i < $nb_args; $i++) {
                $was_inside_assignment = $context->inside_assignment;

                $context->inside_assignment = true;

                if (ExpressionAnalyzer::analyze(
                    $statements_analyzer,
                    $args[$i]->value,
                    $context,
                ) === false) {
                    $context->inside_assignment = $was_inside_assignment;

                    return false;
                }

                $context->inside_assignment = $was_inside_assignment;

                $old_node_data = $statements_analyzer->node_data;

                $statements_analyzer->node_data = clone $statements_analyzer->node_data;

                ArrayAssignmentAnalyzer::analyze(
                    $statements_analyzer,
                    new VirtualArrayDimFetch(
                        $args[0]->value,
                        null,
                        $args[$i]->value->getAttributes(),
                    ),
                    $context,
                    $args[$i]->value,
                    $statements_analyzer->node_data->getType($args[$i]->value) ?? Type::getMixed(),
                );

                $statements_analyzer->node_data = $old_node_data;
            }

            return null;
        }

        $context->inside_call = true;

        if (ExpressionAnalyzer::analyze(
            $statements_analyzer,
            $array_arg,
            $context,
        ) === false) {
            return false;
        }

        for ($i = 1; $i < $nb_args; $i++) {
            if (ExpressionAnalyzer::analyze(
                $statements_analyzer,
                $args[$i]->value,
                $context,
            ) === false) {
                return false;
            }
        }

        if (($array_arg_type = $statements_analyzer->node_data->getType($array_arg))
            && $array_arg_type->hasArray()
        ) {
            $array_type = $array_arg_type->getArray();

            $objectlike_list = null;

            if ($array_type instanceof TKeyedArray) {
                if ($array_type->is_list) {
                    $objectlike_list = $array_type;
                }
            }

            $by_ref_type = new Union([$array_type]);

            foreach ($args as $argument_offset => $arg) {
                if ($argument_offset === 0) {
                    continue;
                }

                if (ExpressionAnalyzer::analyze(
                    $statements_analyzer,
                    $arg->value,
                    $context,
                ) === false) {
                    return false;
                }

                if ($method_id === 'array_unshift' && $nb_args === 2 && !$unpacked_args) {
                    $new_offset_type = Type::getInt(false, 0);
                } else {
                    $new_offset_type = Type::getInt();
                }

                if (!($arg_value_type = $statements_analyzer->node_data->getType($arg->value))
                    || $arg_value_type->hasMixed()
                ) {
                    $by_ref_type = Type::combineUnionTypes(
                        $by_ref_type,
                        new Union([new TArray([$new_offset_type, Type::getMixed()])]),
                    );
                } elseif ($arg->unpack) {
                    $arg_value_type = $arg_value_type->getBuilder();

                    foreach ($arg_value_type->getAtomicTypes() as $arg_value_atomic_type) {
                        if ($arg_value_atomic_type instanceof TKeyedArray) {
                            $was_list = $arg_value_atomic_type->is_list;

                            $arg_value_atomic_type = $arg_value_atomic_type->getGenericArrayType();

                            if ($was_list) {
                                if ($arg_value_atomic_type instanceof TNonEmptyArray) {
                                    $arg_value_atomic_type = Type::getNonEmptyListAtomic(
                                        $arg_value_atomic_type->type_params[1],
                                    );
                                } else {
                                    $arg_value_atomic_type = Type::getListAtomic(
                                        $arg_value_atomic_type->type_params[1],
                                    );
                                }
                            }

                            $arg_value_type->addType($arg_value_atomic_type);
                        }
                    }
                    $arg_value_type = $arg_value_type->freeze();

                    $by_ref_type = Type::combineUnionTypes(
                        $by_ref_type,
                        $arg_value_type,
                    );
                } else {
                    if ($objectlike_list) {
                        $properties = $objectlike_list->properties;
                        array_unshift($properties, $arg_value_type);

                        $by_ref_type = new Union([$objectlike_list->setProperties($properties)]);
                    } elseif ($array_type instanceof TArray && $array_type->isEmptyArray()) {
                        $by_ref_type = new Union([TKeyedArray::make([
                            $arg_value_type,
                        ], null, null, true)]);
                    } else {
                        $by_ref_type = Type::combineUnionTypes(
                            $by_ref_type,
                            new Union(
                                [
                                    new TNonEmptyArray(
                                        [
                                            $new_offset_type,
                                            $arg_value_type,
                                        ],
                                    ),
                                ],
                            ),
                            null,
                            true,
                        );
                    }
                }
            }

            AssignmentAnalyzer::assignByRefParam(
                $statements_analyzer,
                $array_arg,
                $by_ref_type,
                $by_ref_type,
                $context,
                false,
            );
        }

        $context->inside_call = false;

        return null;
    }

    /**
     * @param   list<PhpParser\Node\Arg>          $args
     * @return  false|null
     */
    public static function handleSplice(
        StatementsAnalyzer $statements_analyzer,
        array $args,
        Context $context,
    ): ?bool {
        $context->inside_call = true;
        $array_arg = $args[0]->value;

        if (ExpressionAnalyzer::analyze(
            $statements_analyzer,
            $array_arg,
            $context,
        ) === false) {
            return false;
        }

        $array_type = null;
        $array_size = null;

        if (($array_arg_type = $statements_analyzer->node_data->getType($array_arg))
            && $array_arg_type->hasArray()
        ) {
            $array_type = $array_arg_type->getArray();
            if ($generic_array_type = ArrayType::infer($array_type)) {
                $array_size = $generic_array_type->count;
            }

            if ($array_type instanceof TKeyedArray) {
                if ($array_type->is_list && isset($args[3])) {
                    $array_type = Type::getNonEmptyListAtomic($array_type->getGenericValueType());
                } else {
                    $array_type = $array_type->getGenericArrayType();
                }
            }

            if ($array_type instanceof TArray
                && $array_type->type_params[0]->hasInt()
                && !$array_type->type_params[0]->hasString()
            ) {
                if ($array_type instanceof TNonEmptyArray && isset($args[3])) {
                    $array_type = Type::getNonEmptyListAtomic($array_type->type_params[1]);
                } else {
                    $array_type = Type::getListAtomic($array_type->type_params[1]);
                }
            }
        }

        $offset_arg = $args[1]->value;

        if (ExpressionAnalyzer::analyze(
            $statements_analyzer,
            $offset_arg,
            $context,
        ) === false) {
            return false;
        }

        $offset_arg_is_zero = false;

        if (($offset_arg_type = $statements_analyzer->node_data->getType($offset_arg))
            && $offset_arg_type->hasLiteralValue() && $offset_arg_type->isSingleLiteral()
        ) {
            $offset_literal_value = $offset_arg_type->getSingleLiteral()->value;
            $offset_arg_is_zero = is_numeric($offset_literal_value) && ((int) $offset_literal_value)===0;
        }

        if (!isset($args[2])) {
            if ($offset_arg_is_zero) {
                $array_type = Type::getEmptyArray();
                AssignmentAnalyzer::assignByRefParam(
                    $statements_analyzer,
                    $array_arg,
                    $array_type,
                    $array_type,
                    $context,
                    false,
                );
            } elseif ($array_type) {
                AssignmentAnalyzer::assignByRefParam(
                    $statements_analyzer,
                    $array_arg,
                    new Union([$array_type]),
                    new Union([$array_type]),
                    $context,
                    false,
                );
            } else {
                $default_array_type = Type::getArray();
                AssignmentAnalyzer::assignByRefParam(
                    $statements_analyzer,
                    $array_arg,
                    $default_array_type,
                    $default_array_type,
                    $context,
                    false,
                );
            }

            return null;
        }

        $length_arg = $args[2]->value;

        if (ExpressionAnalyzer::analyze(
            $statements_analyzer,
            $length_arg,
            $context,
        ) === false) {
            return false;
        }

        $cover_whole_arr = false;
        if ($offset_arg_is_zero && is_numeric($array_size)) {
            if (($length_arg_type = $statements_analyzer->node_data->getType($length_arg))
                && $length_arg_type->hasLiteralValue()
            ) {
                $length_min = null;
                if ($length_arg_type->isSingleLiteral()) {
                    $length_literal =  $length_arg_type->getSingleLiteral();
                    if ($length_literal->isNumericType()) {
                        $length_min = (int) $length_literal->value;
                    }
                } else {
                    foreach ([
                        ...$length_arg_type->getLiteralStrings(),
                        ...$length_arg_type->getLiteralInts(),
                        ...$length_arg_type->getLiteralFloats(),
                    ] as $literal) {
                        if ($literal->isNumericType()
                            && ($literal_val = (int) $literal->value)
                            && ((isset($length_min) && $length_min> $literal_val) || !isset($length_min))) {
                            $length_min = $literal_val;
                        }
                    }
                }
                $cover_whole_arr = isset($length_min) && $length_min>= $array_size;
            } elseif ($length_arg_type&& $length_arg_type->isNull()) {
                $cover_whole_arr = true;
            }
        }

        if (!isset($args[3])) {
            if ($cover_whole_arr) {
                $array_type = Type::getEmptyArray();
                AssignmentAnalyzer::assignByRefParam(
                    $statements_analyzer,
                    $array_arg,
                    $array_type,
                    $array_type,
                    $context,
                    false,
                );
            } elseif ($array_type) {
                AssignmentAnalyzer::assignByRefParam(
                    $statements_analyzer,
                    $array_arg,
                    new Union([$array_type]),
                    new Union([$array_type]),
                    $context,
                    false,
                );
            } else {
                $default_array_type = Type::getArray();
                AssignmentAnalyzer::assignByRefParam(
                    $statements_analyzer,
                    $array_arg,
                    $default_array_type,
                    $default_array_type,
                    $context,
                    false,
                );
            }

            return null;
        }

        $replacement_arg = $args[3]->value;

        if (ExpressionAnalyzer::analyze(
            $statements_analyzer,
            $replacement_arg,
            $context,
        ) === false) {
            return false;
        }

        $context->inside_call = false;

        $replacement_arg_type = $statements_analyzer->node_data->getType($replacement_arg);

        if ($replacement_arg_type
            && !$replacement_arg_type->hasArray()
            && $replacement_arg_type->hasString()
            && $replacement_arg_type->isSingle()
        ) {
            $replacement_arg_type = new Union([
                new TArray([Type::getInt(), $replacement_arg_type]),
            ]);

            $statements_analyzer->node_data->setType($replacement_arg, $replacement_arg_type);
        }

        if ($array_type
            && $replacement_arg_type
            && $replacement_arg_type->hasArray()
        ) {
            /**
             * @var TArray|TKeyedArray
             */
            $replacement_array_type = $replacement_arg_type->getArray();

            if (($replacement_array_type_generic = ArrayType::infer($replacement_array_type))
                && $replacement_array_type_generic->count === 0
                && $cover_whole_arr) {
                $empty_array_type = Type::getEmptyArray();
                AssignmentAnalyzer::assignByRefParam(
                    $statements_analyzer,
                    $array_arg,
                    $empty_array_type,
                    $empty_array_type,
                    $context,
                    false,
                );

                return null;
            }

            if ($replacement_array_type instanceof TKeyedArray) {
                $was_list = $replacement_array_type->is_list;

                $replacement_array_type = $replacement_array_type->getGenericArrayType();

                if ($was_list) {
                    if ($replacement_array_type instanceof TNonEmptyArray) {
                        $replacement_array_type = Type::getNonEmptyListAtomic($replacement_array_type->type_params[1]);
                    } else {
                        $replacement_array_type = Type::getListAtomic($replacement_array_type->type_params[1]);
                    }
                }
            }

            $by_ref_type = TypeCombiner::combine([$array_type, $replacement_array_type]);

            AssignmentAnalyzer::assignByRefParam(
                $statements_analyzer,
                $array_arg,
                $by_ref_type,
                $by_ref_type,
                $context,
                false,
            );

            return null;
        }

        if ($array_type) {
            AssignmentAnalyzer::assignByRefParam(
                $statements_analyzer,
                $array_arg,
                new Union([$array_type]),
                new Union([$array_type]),
                $context,
                false,
            );
        } else {
            $default_array_type = Type::getArray();
            AssignmentAnalyzer::assignByRefParam(
                $statements_analyzer,
                $array_arg,
                $default_array_type,
                $default_array_type,
                $context,
                false,
            );
        }

        return null;
    }

    public static function handleByRefArrayAdjustment(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Arg $arg,
        Context $context,
        bool $is_array_shift,
    ): void {
        $var_id = ExpressionIdentifier::getVarId(
            $arg->value,
            $statements_analyzer->getFQCLN(),
            $statements_analyzer,
        );

        if ($var_id) {
            $context->removeVarFromConflictingClauses($var_id, null, $statements_analyzer);

            if (isset($context->vars_in_scope[$var_id])) {
                $array_atomic_types = [];

                foreach ($context->vars_in_scope[$var_id]->getAtomicTypes() as $array_atomic_type) {
                    if ($array_atomic_type instanceof TKeyedArray) {
                        if ($is_array_shift && $array_atomic_type->is_list
                            && !$context->inside_loop
                        ) {
                            $array_properties = $array_atomic_type->properties;

                            array_shift($array_properties);

                            if (!$array_properties) {
                                $array_atomic_types []= $array_atomic_type->fallback_params
                                    ? Type::getListAtomic($array_atomic_type->fallback_params[1])
                                    : Type::getEmptyArrayAtomic();
                            } else {
                                $array_atomic_types []= $array_atomic_type->setProperties($array_properties);
                            }
                            continue;
                        } elseif (!$is_array_shift && $array_atomic_type->is_list
                            && !$array_atomic_type->fallback_params
                            && !$context->inside_loop
                        ) {
                            $array_properties = $array_atomic_type->properties;

                            array_pop($array_properties);

                            if (!$array_properties) {
                                $array_atomic_types []= Type::getEmptyArrayAtomic();
                            } else {
                                $array_atomic_types []= $array_atomic_type->setProperties($array_properties);
                            }
                            continue;
                        }

                        $array_atomic_type = $array_atomic_type->is_list
                            ? Type::getListAtomic($array_atomic_type->getGenericValueType())
                            : $array_atomic_type->getGenericArrayType();
                    }

                    if ($array_atomic_type instanceof TNonEmptyArray) {
                        if (!$context->inside_loop && $array_atomic_type->count !== null) {
                            if ($array_atomic_type->count === 1) {
                                $array_atomic_type = new TArray(
                                    [
                                        Type::getNever(),
                                        Type::getNever(),
                                    ],
                                );
                            } else {
                                $array_atomic_type = $array_atomic_type->setCount($array_atomic_type->count-1);
                            }
                        } else {
                            $array_atomic_type = new TArray($array_atomic_type->type_params);
                        }

                        $array_atomic_types[] = $array_atomic_type;
                    } elseif ($array_atomic_type instanceof TKeyedArray && $array_atomic_type->is_list) {
                        if (!$context->inside_loop
                            && ($prop_count = $array_atomic_type->getMaxCount())
                            && $prop_count === $array_atomic_type->getMinCount()
                        ) {
                            if ($prop_count === 1) {
                                $array_atomic_type = new TArray(
                                    [
                                        Type::getNever(),
                                        Type::getNever(),
                                    ],
                                );
                            } else {
                                $properties = $array_atomic_type->properties;
                                unset($properties[$prop_count-1]);
                                assert($properties !== []);
                                $array_atomic_type = $array_atomic_type->setProperties($properties);
                            }
                        } else {
                            $array_atomic_type = Type::getListAtomic($array_atomic_type->getGenericValueType());
                        }

                        $array_atomic_types[] = $array_atomic_type;
                    } else {
                        $array_atomic_types[] = $array_atomic_type;
                    }
                }

                if (!$array_atomic_types) {
                    throw new AssertionError("We must have some types here!");
                }
                $array_type = new Union($array_atomic_types);
                $context->removeDescendents($var_id, $array_type);
                $context->vars_in_scope[$var_id] = $array_type;
            }
        }
    }

    /**
     * @param  (TArray|null)[] $array_arg_types
     */
    private static function checkClosureType(
        StatementsAnalyzer $statements_analyzer,
        Context $context,
        string $method_id,
        Atomic &$closure_type,
        PhpParser\Node\Arg $closure_arg,
        int $min_closure_param_count,
        int $max_closure_param_count,
        array $array_arg_types,
        bool $check_functions,
    ): void {
        $codebase = $statements_analyzer->getCodebase();

        if (!$closure_type instanceof TClosure) {
            if ($method_id === 'array_map') {
                return;
            }

            if (!$closure_arg->value instanceof PhpParser\Node\Scalar\String_
                && !$closure_arg->value instanceof PhpParser\Node\Expr\Array_
                && !$closure_arg->value instanceof PhpParser\Node\Expr\BinaryOp\Concat
            ) {
                return;
            }

            $function_ids = CallAnalyzer::getFunctionIdsFromCallableArg(
                $statements_analyzer,
                $closure_arg->value,
            );

            $closure_types = [];

            foreach ($function_ids as $function_id) {
                $function_id = strtolower($function_id);

                if (str_contains($function_id, '::')) {
                    if ($function_id[0] === '$') {
                        $function_id = substr($function_id, 1);
                    }

                    $function_id_parts = explode('&', $function_id);

                    foreach ($function_id_parts as $function_id_part) {
                        [$callable_fq_class_name, $method_name] = explode('::', $function_id_part);

                        switch ($callable_fq_class_name) {
                            case 'self':
                            case 'static':
                            case 'parent':
                                $container_class = $statements_analyzer->getFQCLN();

                                if ($callable_fq_class_name === 'parent') {
                                    $container_class = $statements_analyzer->getParentFQCLN();
                                }

                                if (!$container_class) {
                                    continue 2;
                                }

                                $callable_fq_class_name = $container_class;
                        }

                        if (!$codebase->classOrInterfaceExists($callable_fq_class_name)) {
                            return;
                        }

                        $function_id_part = new MethodIdentifier(
                            $callable_fq_class_name,
                            strtolower($method_name),
                        );

                        try {
                            $method_storage = $codebase->methods->getStorage($function_id_part);
                        } catch (UnexpectedValueException) {
                            // the method may not exist, but we're suppressing that issue
                            continue;
                        }

                        $closure_types[] = new TClosure(
                            'Closure',
                            $method_storage->params,
                            $method_storage->return_type ?: Type::getMixed(),
                        );
                    }
                } else {
                    if (!$check_functions) {
                        continue;
                    }

                    if (!$codebase->functions->functionExists($statements_analyzer, $function_id)) {
                        continue;
                    }

                    $function_storage = $codebase->functions->getStorage(
                        $statements_analyzer,
                        $function_id,
                    );

                    if (InternalCallMapHandler::inCallMap($function_id)) {
                        $callmap_callables = InternalCallMapHandler::getCallablesFromCallMap($function_id);

                        if ($callmap_callables === null) {
                            throw new UnexpectedValueException('This should not happen');
                        }

                        $passing_callmap_callables = [];

                        foreach ($callmap_callables as $callmap_callable) {
                            $required_param_count = 0;

                            assert($callmap_callable->params !== null);

                            foreach ($callmap_callable->params as $i => $param) {
                                if (!$param->is_optional && !$param->is_variadic) {
                                    $required_param_count = $i + 1;
                                }
                            }

                            if ($required_param_count <= $max_closure_param_count) {
                                $passing_callmap_callables[] = $callmap_callable;
                            }
                        }

                        if ($passing_callmap_callables) {
                            foreach ($passing_callmap_callables as $passing_callmap_callable) {
                                $closure_types[] = $passing_callmap_callable;
                            }
                        } else {
                            $closure_types[] = $callmap_callables[0];
                        }
                    } else {
                        $closure_types[] = new TClosure(
                            'Closure',
                            $function_storage->params,
                            $function_storage->return_type ?: Type::getMixed(),
                        );
                    }
                }
            }
        } else {
            $closure_types = [&$closure_type];
        }

        foreach ($closure_types as &$closure_type) {
            if ($closure_type->params === null) {
                continue;
            }

            self::checkClosureTypeArgs(
                $statements_analyzer,
                $context,
                $method_id,
                $closure_type,
                $closure_arg,
                $min_closure_param_count,
                $max_closure_param_count,
                $array_arg_types,
            );
        }
        unset($closure_type);
    }

    /**
     * @param  TClosure|TCallable $closure_type
     * @param  (TArray|null)[] $array_arg_types
     */
    private static function checkClosureTypeArgs(
        StatementsAnalyzer $statements_analyzer,
        Context $context,
        string $method_id,
        Atomic &$closure_type,
        PhpParser\Node\Arg $closure_arg,
        int $min_closure_param_count,
        int $max_closure_param_count,
        array $array_arg_types,
    ): void {
        $codebase = $statements_analyzer->getCodebase();

        $closure_params = $closure_type->params;

        if ($closure_params === null) {
            throw new UnexpectedValueException('Closure params should not be null here');
        }

        $required_param_count = 0;

        foreach ($closure_params as $i => $param) {
            if (!$param->is_optional && !$param->is_variadic) {
                $required_param_count = $i + 1;
            }
        }

        if (count($closure_params) < $min_closure_param_count) {
            $argument_text = $min_closure_param_count === 1 ? 'one argument' : $min_closure_param_count . ' arguments';

            IssueBuffer::maybeAdd(
                new TooManyArguments(
                    'The callable passed to ' . $method_id . ' will be called with ' . $argument_text . ', expecting '
                        . $required_param_count,
                    new CodeLocation($statements_analyzer->getSource(), $closure_arg),
                    $method_id,
                ),
                $statements_analyzer->getSuppressedIssues(),
            );

            return;
        }

        if ($required_param_count > $max_closure_param_count) {
            $argument_text = $max_closure_param_count === 1 ? 'one argument' : $max_closure_param_count . ' arguments';

            IssueBuffer::maybeAdd(
                new TooFewArguments(
                    'The callable passed to ' . $method_id . ' will be called with ' . $argument_text . ', expecting '
                        . $required_param_count,
                    new CodeLocation($statements_analyzer->getSource(), $closure_arg),
                    $method_id,
                ),
                $statements_analyzer->getSuppressedIssues(),
            );

            return;
        }

        // abandon attempt to validate closure params if we have an extra arg for ARRAY_FILTER
        if ($method_id === 'array_filter' && $max_closure_param_count > 1) {
            return;
        }

        foreach ($closure_params as $i => $closure_param) {
            if (!isset($array_arg_types[$i])) {
                continue;
            }

            $array_arg_type = $array_arg_types[$i];

            $input_type = $array_arg_type->type_params[1];

            if ($input_type->hasMixed()) {
                continue;
            }

            $closure_param_type = $closure_param->type;

            if (!$closure_param_type) {
                continue;
            }

            if ($method_id === 'array_map'
                && $i === 0
                && $closure_type->return_type
                && $closure_param_type->hasTemplate()
            ) {
                $template_result = new TemplateResult(
                    [],
                    [],
                );

                foreach ($closure_param_type->getTemplateTypes() as $template_type) {
                    $template_result->template_types[$template_type->param_name] = [
                        ($template_type->defining_class) => $template_type->as,
                    ];
                }

                $closure_param_type = TemplateStandinTypeReplacer::replace(
                    $closure_param_type,
                    $template_result,
                    $codebase,
                    $statements_analyzer,
                    $input_type,
                    $i,
                    $context->self,
                    $context->calling_method_id ?: $context->calling_function_id,
                );

                $closure_type = $closure_type->replaceTemplateTypesWithArgTypes(
                    $template_result,
                    $codebase,
                );
            }

            $closure_param_type = TypeExpander::expandUnion(
                $codebase,
                $closure_param_type,
                $context->self,
                null,
                $statements_analyzer->getParentFQCLN(),
            );

            $union_comparison_results = new TypeComparisonResult();

            $type_match_found = UnionTypeComparator::isContainedBy(
                $codebase,
                $input_type,
                $closure_param_type,
                $input_type->ignore_nullable_issues,
                $input_type->ignore_falsable_issues,
                $union_comparison_results,
            );

            if ($union_comparison_results->type_coerced) {
                if ($union_comparison_results->type_coerced_from_mixed) {
                    IssueBuffer::maybeAdd(
                        new MixedArgumentTypeCoercion(
                            'Parameter ' . ($i + 1) . ' of closure passed to function ' . $method_id . ' expects ' .
                                $closure_param_type->getId() .
                                ', but parent type ' . $input_type->getId() . ' provided',
                            new CodeLocation($statements_analyzer->getSource(), $closure_arg),
                            $method_id,
                        ),
                        $statements_analyzer->getSuppressedIssues(),
                    );
                } else {
                    IssueBuffer::maybeAdd(
                        new ArgumentTypeCoercion(
                            'Parameter ' . ($i + 1) . ' of closure passed to function ' . $method_id . ' expects ' .
                                $closure_param_type->getId() .
                                ', but parent type ' . $input_type->getId() . ' provided',
                            new CodeLocation($statements_analyzer->getSource(), $closure_arg),
                            $method_id,
                        ),
                        $statements_analyzer->getSuppressedIssues(),
                    );
                }
            }

            if (!$union_comparison_results->type_coerced && !$type_match_found) {
                $types_can_be_identical = UnionTypeComparator::canExpressionTypesBeIdentical(
                    $codebase,
                    $input_type,
                    $closure_param_type,
                );

                if ($union_comparison_results->scalar_type_match_found) {
                    IssueBuffer::maybeAdd(
                        new InvalidScalarArgument(
                            'Parameter ' . ($i + 1) . ' of closure passed to function ' . $method_id . ' expects ' .
                                $closure_param_type->getId() . ', but ' . $input_type->getId() . ' provided',
                            new CodeLocation($statements_analyzer->getSource(), $closure_arg),
                            $method_id,
                        ),
                        $statements_analyzer->getSuppressedIssues(),
                    );
                } elseif ($types_can_be_identical) {
                    IssueBuffer::maybeAdd(
                        new PossiblyInvalidArgument(
                            'Parameter ' . ($i + 1) . ' of closure passed to function ' . $method_id . ' expects '
                                . $closure_param_type->getId() . ', but possibly different type '
                                . $input_type->getId() . ' provided',
                            new CodeLocation($statements_analyzer->getSource(), $closure_arg),
                            $method_id,
                        ),
                        $statements_analyzer->getSuppressedIssues(),
                    );
                } else {
                    IssueBuffer::maybeAdd(
                        new InvalidArgument(
                            'Parameter ' . ($i + 1) . ' of closure passed to function ' . $method_id . ' expects ' .
                            $closure_param_type->getId() . ', but ' . $input_type->getId() . ' provided',
                            new CodeLocation($statements_analyzer->getSource(), $closure_arg),
                            $method_id,
                        ),
                        $statements_analyzer->getSuppressedIssues(),
                    );
                }
            }
        }
    }
}
