<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use InvalidArgumentException;
use PhpParser;
use Psalm\CodeLocation;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\AttributesAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment\InstancePropertyAssignmentAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\AssignmentAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\CallAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\ExpressionIdentifier;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\ArrayFetchAnalyzer;
use Psalm\Internal\Analyzer\Statements\ExpressionAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Codebase\ConstantTypeResolver;
use Psalm\Internal\Codebase\Functions;
use Psalm\Internal\Codebase\InternalCallMapHandler;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Stubs\Generator\StubsGenerator;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\Internal\Type\TemplateInferredTypeReplacer;
use Psalm\Internal\Type\TemplateResult;
use Psalm\Internal\Type\TemplateStandinTypeReplacer;
use Psalm\Internal\Type\TypeExpander;
use Psalm\Issue\InvalidNamedArgument;
use Psalm\Issue\InvalidPassByReference;
use Psalm\Issue\PossiblyUndefinedVariable;
use Psalm\Issue\TooFewArguments;
use Psalm\Issue\TooManyArguments;
use Psalm\IssueBuffer;
use Psalm\Node\VirtualArg;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNonEmptyArray;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;
use UnexpectedValueException;

use function array_reduce;
use function array_reverse;
use function array_slice;
use function array_values;
use function assert;
use function count;
use function in_array;
use function is_string;
use function max;
use function min;
use function reset;
use function str_contains;
use function strtolower;

/**
 * @internal
 */
final class ArgumentsAnalyzer
{
    public const ARRAY_FILTERLIKE = [
        'array_filter',
        'array_find',
        'array_find_key',
        'array_any',
        'array_all',
    ];

    /**
     * @param   list<PhpParser\Node\Arg>          $args
     * @param   array<int, FunctionLikeParameter>|null  $function_params
     * @return  false|null
     */
    public static function analyze(
        StatementsAnalyzer $statements_analyzer,
        array $args,
        ?array $function_params,
        ?string $method_id,
        bool $allow_named_args,
        Context $context,
        ?TemplateResult $template_result = null,
    ): ?bool {
        $last_param = $function_params
            ? $function_params[count($function_params) - 1]
            : null;

        // if this modifies the array type based on further args
        if (in_array($method_id, ['array_push', 'array_unshift'], true)
            && $function_params
            && isset($args[0])
            && isset($args[1])
        ) {
            if (ArrayFunctionArgumentsAnalyzer::handleAddition(
                $statements_analyzer,
                $args,
                $context,
                $method_id,
            ) === false
            ) {
                return false;
            }

            return null;
        }

        if ($method_id === 'array_splice' && $function_params && count($args) > 1) {
            if (ArrayFunctionArgumentsAnalyzer::handleSplice($statements_analyzer, $args, $context) === false) {
                return false;
            }

            return null;
        }

        if ($method_id === 'array_map') {
            $args = array_reverse($args, true);
        }

        foreach ($args as $argument_offset => $arg) {
            if ($function_params === null) {
                if (self::evaluateArbitraryParam(
                    $statements_analyzer,
                    $arg,
                    $context,
                ) === false) {
                    return false;
                }

                continue;
            }

            $param = null;

            if ($arg->name && $allow_named_args) {
                foreach ($function_params as $candidate_param) {
                    if ($candidate_param->name === $arg->name->name) {
                        $param = $candidate_param;
                        break;
                    }
                }

                if ($last_param && $last_param->is_variadic) {
                    $param = $last_param;
                }
            } elseif ($argument_offset < count($function_params)) {
                $param = $function_params[$argument_offset];
            } elseif ($last_param && $last_param->is_variadic) {
                $param = $last_param;
            }

            $by_ref = $param && $param->by_ref;

            $by_ref_type = null;

            if ($by_ref) {
                $by_ref_type = $param->type ?: Type::getMixed();
            }

            if ($by_ref
                && $by_ref_type
                && !($arg->value instanceof PhpParser\Node\Expr\Closure
                    || $arg->value instanceof PhpParser\Node\Expr\ConstFetch
                    || $arg->value instanceof PhpParser\Node\Expr\ClassConstFetch
                    || $arg->value instanceof PhpParser\Node\Expr\FuncCall
                    || $arg->value instanceof PhpParser\Node\Expr\MethodCall
                    || $arg->value instanceof PhpParser\Node\Expr\StaticCall
                    || $arg->value instanceof PhpParser\Node\Expr\New_
                    || $arg->value instanceof PhpParser\Node\Expr\Assign
                    || $arg->value instanceof PhpParser\Node\Expr\Array_
                    || $arg->value instanceof PhpParser\Node\Expr\Ternary
                    || $arg->value instanceof PhpParser\Node\Expr\BinaryOp
                )
            ) {
                if (self::handleByRefFunctionArg(
                    $statements_analyzer,
                    $method_id,
                    $argument_offset,
                    $arg,
                    $context,
                ) === false) {
                    return false;
                }

                continue;
            }

            $toggled_class_exists = false;

            if (in_array($method_id, ['class_exists', 'interface_exists', 'enum_exists', 'trait_exists'], true)
                && $argument_offset === 0
                && !$context->inside_class_exists
            ) {
                $context->inside_class_exists = true;
                $toggled_class_exists = true;
            }

            $high_order_template_result = null;
            $high_order_callable_info = $param
                ? HighOrderFunctionArgHandler::getCallableArgInfo($context, $arg->value, $statements_analyzer, $param)
                : null;

            if ($param && $high_order_callable_info) {
                $high_order_template_result = HighOrderFunctionArgHandler::remapLowerBounds(
                    $statements_analyzer,
                    $template_result ?? new TemplateResult([], []),
                    $high_order_callable_info,
                    $param->type ?? Type::getMixed(),
                );
            } elseif (($arg->value instanceof PhpParser\Node\Expr\Closure
                    || $arg->value instanceof PhpParser\Node\Expr\ArrowFunction)
                && $param
                && !$arg->value->getDocComment()
            ) {
                self::handleClosureArg(
                    $statements_analyzer,
                    $args,
                    $method_id,
                    $context,
                    $template_result ?? new TemplateResult([], []),
                    $argument_offset,
                    $arg,
                    $param,
                );
            }

            $was_inside_call = $context->inside_call;
            $context->inside_call = true;

            $was_inside_isset = $context->inside_isset;
            $context->inside_isset = false;

            if (ExpressionAnalyzer::analyze(
                $statements_analyzer,
                $arg->value,
                $context,
                false,
                null,
                null,
                $high_order_template_result,
            ) === false) {
                $context->inside_isset = $was_inside_isset;
                $context->inside_call = $was_inside_call;

                return false;
            }

            $context->inside_isset = $was_inside_isset;
            $context->inside_call = $was_inside_call;

            if ($high_order_callable_info && $high_order_template_result) {
                HighOrderFunctionArgHandler::enhanceCallableArgType(
                    $context,
                    $arg->value,
                    $statements_analyzer,
                    $high_order_callable_info,
                    $high_order_template_result,
                );
            }

            if (($argument_offset === 0 && in_array($method_id, self::ARRAY_FILTERLIKE, true) && count($args) === 2)
                || ($argument_offset > 0 && $method_id === 'array_map' && count($args) >= 2)
            ) {
                self::handleArrayMapFilterArrayArg(
                    $statements_analyzer,
                    $method_id,
                    $argument_offset,
                    $arg,
                    $context,
                    $template_result,
                );
            }

            $inferred_arg_type = $statements_analyzer->node_data->getType($arg->value);

            if (null !== $inferred_arg_type
                && null !== $template_result
                && null !== $param
                && null !== $param->type
                && !$arg->unpack
            ) {
                $codebase = $statements_analyzer->getCodebase();

                TemplateStandinTypeReplacer::fillTemplateResult(
                    $param->type,
                    $template_result,
                    $codebase,
                    $statements_analyzer,
                    $inferred_arg_type,
                    $argument_offset,
                    $context->self,
                    $context->calling_method_id ?: $context->calling_function_id,
                );
            }

            if ($toggled_class_exists) {
                $context->inside_class_exists = false;
            }
        }

        if ($method_id === "ReflectionClass::getattributes"
            || $method_id === "ReflectionClassConstant::getattributes"
            || $method_id === "ReflectionFunction::getattributes"
            || $method_id === "ReflectionMethod::getattributes"
            || $method_id === "ReflectionParameter::getattributes"
            || $method_id === "ReflectionProperty::getattributes"
        ) {
            AttributesAnalyzer::analyzeGetAttributes($statements_analyzer, $method_id, array_values($args));
        }

        return null;
    }

    private static function handleArrayMapFilterArrayArg(
        StatementsAnalyzer $statements_analyzer,
        string $method_id,
        int $argument_offset,
        PhpParser\Node\Arg $arg,
        Context $context,
        ?TemplateResult &$template_result,
    ): void {
        $codebase = $statements_analyzer->getCodebase();

        $template_types = ['ArrayValue' . $argument_offset => [$method_id => Type::getMixed()]];

        $replace_template_result = new TemplateResult(
            $template_types,
            [],
        );

        $existing_type = $statements_analyzer->node_data->getType($arg->value);

        TemplateStandinTypeReplacer::fillTemplateResult(
            new Union([
                new TArray([
                    Type::getArrayKey(),
                    new Union([
                        new TTemplateParam(
                            'ArrayValue' . $argument_offset,
                            Type::getMixed(),
                            $method_id,
                        ),
                    ]),
                ]),
            ]),
            $replace_template_result,
            $codebase,
            $statements_analyzer,
            $existing_type,
            $argument_offset,
            $context->self,
            $context->calling_method_id ?: $context->calling_function_id,
        );

        if ($replace_template_result->lower_bounds) {
            if (!$template_result) {
                $template_result = new TemplateResult([], []);
            }

            $template_result->lower_bounds += $replace_template_result->lower_bounds;
        }
    }

    /**
     * @param   array<int, PhpParser\Node\Arg>  $args
     */
    private static function handleClosureArg(
        StatementsAnalyzer $statements_analyzer,
        array $args,
        ?string $method_id,
        Context $context,
        TemplateResult $template_result,
        int $argument_offset,
        PhpParser\Node\Arg $arg,
        FunctionLikeParameter $param,
    ): void {
        if (!$param->type) {
            return;
        }

        $codebase = $statements_analyzer->getCodebase();

        if (($argument_offset === 1 && in_array($method_id, self::ARRAY_FILTERLIKE, true) && count($args) === 2)
            || ($argument_offset === 0 && $method_id === 'array_map' && count($args) >= 2)
        ) {
            $function_like_params = [];

            foreach ($template_result->lower_bounds as $template_name => $_) {
                $t = new Union([
                    new TTemplateParam(
                        $template_name,
                        Type::getMixed(),
                        $method_id,
                    ),
                ]);
                $function_like_params[] = new FunctionLikeParameter(
                    'function',
                    false,
                    $t,
                    $t,
                );
            }

            $replaced_type = new Union([
                new TCallable(
                    'callable',
                    array_reverse($function_like_params),
                ),
            ]);
        } else {
            $replaced_type = $param->type;
        }

        $new_bounds = $template_result->template_types;
        foreach ($template_result->lower_bounds as $k => $template_map) {
            $new_bounds[$k] = [];
            foreach ($template_map as $kk => $lower_bounds) {
                $new_bounds[$k][$kk] = TemplateStandinTypeReplacer::getMostSpecificTypeFromBounds(
                    $lower_bounds,
                    $codebase,
                );
            }
        }
        $replace_template_result = new TemplateResult(
            $new_bounds,
            [],
        );
        unset($new_bounds);

        $replaced_type = TemplateStandinTypeReplacer::replace(
            $replaced_type,
            $replace_template_result,
            $codebase,
            $statements_analyzer,
            null,
            null,
            null,
            $context->calling_method_id ?: $context->calling_function_id,
        );

        $replaced_type = TemplateInferredTypeReplacer::replace(
            $replaced_type,
            $replace_template_result,
            $codebase,
        );

        $closure_id = strtolower($statements_analyzer->getFilePath())
            . ':' . $arg->value->getLine()
            . ':' . (int)$arg->value->getAttribute('startFilePos')
            . ':-:closure';

        try {
            $closure_storage = $codebase->getClosureStorage(
                $statements_analyzer->getFilePath(),
                $closure_id,
            );
        } catch (UnexpectedValueException) {
            return;
        }

        foreach ($closure_storage->params as $closure_param_offset => $param_storage) {
            $param_type_inferred = $param_storage->type_inferred;

            $newly_inferred_type = null;
            $has_different_docblock_type = false;

            if ($param_storage->type && !$param_type_inferred) {
                if ($param_storage->type !== $param_storage->signature_type) {
                    $has_different_docblock_type = true;
                }
            }

            if (!$has_different_docblock_type) {
                foreach ($replaced_type->getAtomicTypes() as $replaced_type_part) {
                    if ($replaced_type_part instanceof TCallable
                        || $replaced_type_part instanceof TClosure
                    ) {
                        if (isset($replaced_type_part->params[$closure_param_offset]->type)) {
                            $replaced_param_type = $replaced_type_part->params[$closure_param_offset]->type;

                            if ($replaced_param_type->hasTemplate()) {
                                $replaced_param_type = TypeExpander::expandUnion(
                                    $codebase,
                                    $replaced_param_type,
                                    null,
                                    null,
                                    null,
                                    true,
                                    false,
                                    false,
                                    true,
                                    true,
                                );
                            }

                            if ($param_storage->type && !$param_type_inferred) {
                                $type_match_found = UnionTypeComparator::isContainedBy(
                                    $codebase,
                                    $replaced_param_type,
                                    $param_storage->type,
                                );

                                if (!$type_match_found) {
                                    continue;
                                }
                            }

                            $newly_inferred_type = Type::combineUnionTypes(
                                $newly_inferred_type,
                                $replaced_param_type,
                                $codebase,
                            );
                        }
                    }
                }
            }

            if ($newly_inferred_type) {
                $param_storage->type = $newly_inferred_type;
                $param_storage->type_inferred = true;
            }

            if ($param_storage->type
                && ($method_id === 'array_map' || in_array($method_id, self::ARRAY_FILTERLIKE, true))
            ) {
                $temp = Type::getMixed();
                ArrayFetchAnalyzer::taintArrayFetch(
                    $statements_analyzer,
                    $args[1 - $argument_offset]->value,
                    null,
                    $param_storage->type,
                    $temp,
                );
            }
        }
    }

    /**
     * @param   list<PhpParser\Node\Arg>  $args
     * @param   array<int,FunctionLikeParameter>        $function_params
     * @return  false|null
     * @psalm-suppress ComplexMethod there's just not much that can be done about this
     */
    public static function checkArgumentsMatch(
        StatementsAnalyzer $statements_analyzer,
        array $args,
        string|MethodIdentifier|null $method_id,
        array $function_params,
        ?FunctionLikeStorage $function_storage,
        ?ClassLikeStorage $class_storage,
        TemplateResult $template_result,
        CodeLocation $code_location,
        Context $context,
    ): ?bool {
        $in_call_map = $method_id ? InternalCallMapHandler::inCallMap((string) $method_id) : false;

        $cased_method_id = (string) $method_id;

        $is_variadic = false;

        $fq_class_name = null;

        $codebase = $statements_analyzer->getCodebase();

        if ($method_id) {
            if ($method_id instanceof MethodIdentifier) {
                $fq_class_name = $method_id->fq_class_name;
            }

            if ($function_storage) {
                $is_variadic = $function_storage->variadic;
            } elseif (is_string($method_id)) {
                $is_variadic = Functions::isVariadic(
                    $codebase,
                    strtolower($method_id),
                    $statements_analyzer->getRootFilePath(),
                );
            } else {
                $is_variadic = $codebase->methods->isVariadic($method_id);
            }
        }

        if ($method_id instanceof MethodIdentifier) {
            $cased_method_id = $codebase->methods->getCasedMethodId($method_id);
        } elseif ($function_storage) {
            $cased_method_id = $function_storage->cased_name;
        }

        $calling_class_storage = $class_storage;

        $static_fq_class_name = $fq_class_name;
        $self_fq_class_name = $fq_class_name;

        if ($method_id instanceof MethodIdentifier) {
            $declaring_method_id = $codebase->methods->getDeclaringMethodId($method_id);

            if ($declaring_method_id && (string)$declaring_method_id !== (string)$method_id) {
                $self_fq_class_name = $declaring_method_id->fq_class_name;
                $class_storage = $codebase->classlike_storage_provider->get($self_fq_class_name);
            }

            $appearing_method_id = $codebase->methods->getAppearingMethodId($method_id);

            if ($appearing_method_id && $declaring_method_id !== $appearing_method_id) {
                $self_fq_class_name = $appearing_method_id->fq_class_name;
            }
        }

        if ($function_params && !$is_variadic) {
            foreach ($function_params as $function_param) {
                $is_variadic = $is_variadic || $function_param->is_variadic;
            }
        }

        $has_packed_var = false;

        foreach ($args as $arg) {
            if ($arg->unpack) {
                $has_packed_var = true;
            }
        }

        $last_param = $function_params
            ? $function_params[count($function_params) - 1]
            : null;

        $class_generic_params = [];

        foreach ($template_result->lower_bounds as $template_name => $type_map) {
            foreach ($type_map as $class => $lower_bounds) {
                if (count($lower_bounds) === 1) {
                    $class_generic_params[$template_name][$class] = reset($lower_bounds)->type;
                }
            }
        }

        if ($function_storage) {
            $template_result = self::getProvisionalTemplateResultForFunctionLike(
                $statements_analyzer,
                $codebase,
                $context,
                $class_storage,
                $self_fq_class_name,
                $calling_class_storage,
                $function_storage,
                $class_generic_params,
                $template_result,
                $args,
                $function_params,
                $last_param,
            );
        }

        $function_param_count = count($function_params);

        if (count($function_params) > count($args) && !$has_packed_var) {
            for ($i = count($args), $iMax = count($function_params); $i < $iMax; $i++) {
                if ($function_params[$i]->default_type
                    && $function_params[$i]->type
                    && $function_params[$i]->type->hasTemplate()
                ) {
                    if ($function_params[$i]->default_type instanceof Union) {
                        $default_type = $function_params[$i]->default_type;
                    } else {
                        $default_type_atomic = ConstantTypeResolver::resolve(
                            $codebase->classlikes,
                            $function_params[$i]->default_type,
                            $statements_analyzer,
                        );

                        $default_type = new Union([$default_type_atomic]);
                    }

                    if ($default_type->hasLiteralValue()) {
                        ArgumentAnalyzer::checkArgumentMatches(
                            $statements_analyzer,
                            $cased_method_id,
                            $method_id instanceof MethodIdentifier ? $method_id : null,
                            $self_fq_class_name,
                            $static_fq_class_name,
                            $code_location,
                            $function_params[$i],
                            $i,
                            $i,
                            $function_storage->allow_named_arg_calls ?? true,
                            new VirtualArg(
                                StubsGenerator::getExpressionFromType($default_type),
                            ),
                            $default_type,
                            $context,
                            $class_generic_params,
                            $template_result,
                            $function_storage->specialize_call ?? true,
                            $in_call_map,
                        );
                    }
                }
            }
        }

        if (($method_id === 'preg_match_all' || $method_id === 'preg_match') && count($args) > 3) {
            $args = array_reverse($args, true);
        }

        $arg_function_params = [];
        $matched_args = [];
        $named_args_was_used = false;

        foreach ($args as $argument_offset => $arg) {
            if ($named_args_was_used && !$arg->name) {
                IssueBuffer::maybeAdd(
                    new InvalidNamedArgument(
                        'Cannot use positional argument after named argument',
                        new CodeLocation($statements_analyzer, $arg),
                        (string)$method_id,
                    ),
                    $statements_analyzer->getSuppressedIssues(),
                );
            }

            if ($arg->unpack) {
                if ($function_param_count > $argument_offset) {
                    for ($i = $argument_offset; $i < $function_param_count; $i++) {
                        $arg_function_params[$argument_offset][] = $function_params[$i];
                    }
                }

                if (($arg_value_type = $statements_analyzer->node_data->getType($arg->value))
                    && $arg_value_type->hasArray()) {
                    /**
                     * @var TArray|TKeyedArray
                     */
                    $array_type = $arg_value_type->getArray();

                    if ($array_type instanceof TKeyedArray) {
                        $array_type = $array_type->getGenericArrayType();
                        $key_types = $array_type->type_params[0]->getAtomicTypes();

                        foreach ($key_types as $key_type) {
                            if (!$key_type instanceof TLiteralString
                                || ($function_storage && !$function_storage->allow_named_arg_calls)) {
                                continue;
                            }

                            $param_found = false;

                            foreach ($function_params as $candidate_param) {
                                if ($candidate_param->name === $key_type->value || $candidate_param->is_variadic) {
                                    if ($candidate_param->name === $key_type->value) {
                                        if (isset($matched_args[$candidate_param->name])) {
                                            IssueBuffer::maybeAdd(
                                                new InvalidNamedArgument(
                                                    'Parameter $' . $key_type->value . ' has already been used in '
                                                    . ($cased_method_id ?: $method_id),
                                                    new CodeLocation($statements_analyzer, $arg),
                                                    (string)$method_id,
                                                ),
                                                $statements_analyzer->getSuppressedIssues(),
                                            );
                                        }

                                        $matched_args[$candidate_param->name] = true;
                                    }

                                    $param_found = true;
                                    break;
                                }
                            }

                            if (!$param_found) {
                                IssueBuffer::maybeAdd(
                                    new InvalidNamedArgument(
                                        'Parameter $' . $key_type->value . ' does not exist on function '
                                            . ($cased_method_id ?: $method_id),
                                        new CodeLocation($statements_analyzer, $arg),
                                        (string)$method_id,
                                    ),
                                    $statements_analyzer->getSuppressedIssues(),
                                );
                            }
                        }
                    }
                }
            } elseif ($arg->name && (!$function_storage || $function_storage->allow_named_arg_calls)) {
                $named_args_was_used = true;

                foreach ($function_params as $candidate_param) {
                    if ($candidate_param->name === $arg->name->name || $candidate_param->is_variadic) {
                        if ($candidate_param->name === $arg->name->name) {
                            if (isset($matched_args[$candidate_param->name])) {
                                IssueBuffer::maybeAdd(
                                    new InvalidNamedArgument(
                                        'Parameter $' . $arg->name->name . ' has already been used in '
                                            . ($cased_method_id ?: $method_id),
                                        new CodeLocation($statements_analyzer, $arg->name),
                                        (string) $method_id,
                                    ),
                                    $statements_analyzer->getSuppressedIssues(),
                                );
                            }

                            $matched_args[$candidate_param->name] = true;
                        }

                        $arg_function_params[$argument_offset] = [$candidate_param];
                        break;
                    }
                }

                if (!isset($arg_function_params[$argument_offset])) {
                    IssueBuffer::maybeAdd(
                        new InvalidNamedArgument(
                            'Parameter $' . $arg->name->name . ' does not exist on function '
                            . ($cased_method_id ?: $method_id),
                            new CodeLocation($statements_analyzer, $arg->name),
                            (string) $method_id,
                        ),
                        $statements_analyzer->getSuppressedIssues(),
                    );
                }
            } elseif ($function_param_count > $argument_offset) {
                $arg_function_params[$argument_offset] = [$function_params[$argument_offset]];
                $matched_args[$function_params[$argument_offset]->name] = true;
            } elseif ($last_param && $last_param->is_variadic) {
                $arg_function_params[$argument_offset] = [$last_param];
                $matched_args[$last_param->name] = true;
            }
        }

        foreach ($args as $argument_offset => $arg) {
            if (!isset($arg_function_params[$argument_offset])) {
                continue;
            }

            if ($arg_function_params[$argument_offset][0]->by_ref
                && $method_id !== 'extract'
            ) {
                if (self::handlePossiblyMatchingByRefParam(
                    $statements_analyzer,
                    $codebase,
                    (string) $method_id,
                    $cased_method_id,
                    $last_param,
                    $function_params,
                    $argument_offset,
                    $arg,
                    $context,
                    $template_result,
                ) === false) {
                    return null;
                }
            }

            $arg_value_type = $statements_analyzer->node_data->getType($arg->value);

            foreach ($arg_function_params[$argument_offset] as $i => $function_param) {
                if (ArgumentAnalyzer::checkArgumentMatches(
                    $statements_analyzer,
                    $cased_method_id,
                    $method_id instanceof MethodIdentifier ? $method_id : null,
                    $self_fq_class_name,
                    $static_fq_class_name,
                    $code_location,
                    $function_param,
                    $argument_offset + $i,
                    $i,
                    $function_storage->allow_named_arg_calls ?? true,
                    $arg,
                    $arg_value_type,
                    $context,
                    $class_generic_params,
                    $template_result,
                    $function_storage->specialize_call ?? true,
                    $in_call_map,
                ) === false) {
                    return false;
                }
            }
        }

        if ($statements_analyzer->taint_flow_graph
            && $cased_method_id
        ) {
            foreach ($args as $argument_offset => $_) {
                if (!isset($arg_function_params[$argument_offset])) {
                    continue;
                }

                foreach ($arg_function_params[$argument_offset] as $function_param) {
                    if ($function_param->sinks) {
                        if (!$function_storage || $function_storage->specialize_call) {
                            $sink = DataFlowNode::getForMethodArgument(
                                $cased_method_id,
                                $cased_method_id,
                                $argument_offset,
                                $function_param->location,
                                $code_location,
                                $function_param->sinks,
                            );
                        } else {
                            $sink = DataFlowNode::getForMethodArgument(
                                $cased_method_id,
                                $cased_method_id,
                                $argument_offset,
                                $function_param->location,
                                null,
                                $function_param->sinks,
                            );
                        }

                        $statements_analyzer->taint_flow_graph->addSink($sink);
                    }
                }
            }
        }

        $f = in_array($method_id, self::ARRAY_FILTERLIKE, true);
        if ($f || $method_id === 'array_map') {
            assert(is_string($method_id));
            if (!$f && count($args) < 2) {
                IssueBuffer::maybeAdd(
                    new TooFewArguments(
                        'Too few arguments for ' . $method_id,
                        $code_location,
                        $method_id,
                    ),
                    $statements_analyzer->getSuppressedIssues(),
                );
            } elseif ($f && count($args) < 1) {
                IssueBuffer::maybeAdd(
                    new TooFewArguments(
                        'Too few arguments for ' . $method_id,
                        $code_location,
                        $method_id,
                    ),
                    $statements_analyzer->getSuppressedIssues(),
                );
            }

            ArrayFunctionArgumentsAnalyzer::checkArgumentsMatch(
                $statements_analyzer,
                $context,
                $args,
                $method_id,
                $context->check_functions,
            );

            return null;
        }

        if ($method_id === 'get_class' && $args === []) {
            //get_class without args only works when inside a class
            if (!$context->self) {
                IssueBuffer::maybeAdd(
                    new TooFewArguments(
                        'Cannot call get_class() without argument outside of class scope',
                        $code_location,
                        $method_id,
                    ),
                    $statements_analyzer->getSuppressedIssues(),
                );

                return null;
            }
        }

        self::checkArgCount(
            $statements_analyzer,
            $codebase,
            $function_storage,
            $context,
            $template_result,
            $is_variadic,
            $args,
            $function_params,
            $in_call_map,
            $method_id,
            $cased_method_id,
            $code_location,
        );

        return null;
    }

    /**
     * @param  array<int, FunctionLikeParameter> $function_params
     * @return false|null
     */
    private static function handlePossiblyMatchingByRefParam(
        StatementsAnalyzer $statements_analyzer,
        Codebase $codebase,
        string $method_id,
        ?string $cased_method_id,
        ?FunctionLikeParameter $last_param,
        array $function_params,
        int $argument_offset,
        PhpParser\Node\Arg $arg,
        Context $context,
        ?TemplateResult $template_result,
    ): ?bool {
        if ($arg->value instanceof PhpParser\Node\Scalar
            || $arg->value instanceof PhpParser\Node\Expr\Cast
            || $arg->value instanceof PhpParser\Node\Expr\Array_
            || $arg->value instanceof PhpParser\Node\Expr\ClassConstFetch
            || $arg->value instanceof PhpParser\Node\Expr\BinaryOp
            || $arg->value instanceof PhpParser\Node\Expr\Ternary
            || (
                (
                $arg->value instanceof PhpParser\Node\Expr\ConstFetch
                    || $arg->value instanceof PhpParser\Node\Expr\FuncCall
                    || $arg->value instanceof PhpParser\Node\Expr\MethodCall
                    || $arg->value instanceof PhpParser\Node\Expr\StaticCall
                ) && (
                    !($arg_value_type = $statements_analyzer->node_data->getType($arg->value))
                    || !$arg_value_type->by_ref
                )
            )
        ) {
            IssueBuffer::maybeAdd(
                new InvalidPassByReference(
                    'Parameter ' . ($argument_offset + 1) . ' of ' . $cased_method_id . ' expects a variable',
                    new CodeLocation($statements_analyzer->getSource(), $arg->value),
                ),
                $statements_analyzer->getSuppressedIssues(),
            );

            return false;
        }

        if (!in_array(
            $method_id,
            [
                'ksort', 'asort', 'krsort', 'arsort', 'natcasesort', 'natsort',
                'reset', 'end', 'next', 'prev', 'array_pop', 'array_shift',
                'array_push', 'array_unshift', 'socket_select', 'array_splice',
            ],
            true,
        )) {
            $by_ref_type = null;
            $by_ref_out_type = null;

            $check_null_ref = true;

            if ($last_param) {
                if ($arg->name !== null) {
                    $function_param = array_reduce(
                        $function_params,
                        static function (
                            ?FunctionLikeParameter $function_param,
                            FunctionLikeParameter $param,
                        ) use (
                            $arg,
                        ) {
                            if ($param->name === $arg->name->name) {
                                return $param;
                            }
                            return $function_param;
                        },
                        null,
                    );
                    if ($function_param === null) {
                        return false;
                    }
                } elseif ($argument_offset < count($function_params)) {
                    $function_param = $function_params[$argument_offset];
                } else {
                    $function_param = $last_param;
                }

                if ($function_param->type) {
                    $by_ref_type = $function_param->type;
                }
                if ($function_param->out_type) {
                    $by_ref_out_type = $function_param->out_type;
                }

                if ($by_ref_type && $by_ref_type->isNullable()) {
                    $check_null_ref = false;
                }

                if ($template_result && $by_ref_type) {
                    $original_by_ref_type = $by_ref_type;

                    $by_ref_type = TemplateStandinTypeReplacer::replace(
                        $by_ref_type,
                        $template_result,
                        $codebase,
                        $statements_analyzer,
                        $statements_analyzer->node_data->getType($arg->value),
                        $argument_offset,
                        $context->self,
                        $context->calling_method_id ?: $context->calling_function_id,
                    );

                    if ($template_result->lower_bounds) {
                        $original_by_ref_type = TemplateInferredTypeReplacer::replace(
                            $original_by_ref_type,
                            $template_result,
                            $codebase,
                        );

                        $by_ref_type = $original_by_ref_type;
                    }
                }

                if ($template_result && $by_ref_out_type) {
                    $original_by_ref_out_type = $by_ref_out_type;

                    $by_ref_out_type = TemplateStandinTypeReplacer::replace(
                        $by_ref_out_type,
                        $template_result,
                        $codebase,
                        $statements_analyzer,
                        $statements_analyzer->node_data->getType($arg->value),
                        $argument_offset,
                        $context->self,
                        $context->calling_method_id ?: $context->calling_function_id,
                    );

                    if ($template_result->lower_bounds) {
                        $original_by_ref_out_type = TemplateInferredTypeReplacer::replace(
                            $original_by_ref_out_type,
                            $template_result,
                            $codebase,
                        );

                        $by_ref_out_type = $original_by_ref_out_type;
                    }
                }

                if ($by_ref_type && $function_param->is_variadic && $arg->unpack) {
                    $by_ref_type = new Union([
                        new TArray([
                            Type::getInt(),
                            $by_ref_type,
                        ]),
                    ]);
                }
            }

            $by_ref_type = $by_ref_type ?: Type::getMixed();

            AssignmentAnalyzer::assignByRefParam(
                $statements_analyzer,
                $arg->value,
                $by_ref_type,
                $by_ref_out_type ?: $by_ref_type,
                $context,
                $method_id && (str_contains($method_id, '::') || !InternalCallMapHandler::inCallMap($method_id)),
                $check_null_ref,
            );
        }

        return null;
    }

    /**
     * @return false|null
     */
    private static function evaluateArbitraryParam(
        StatementsAnalyzer $statements_analyzer,
        PhpParser\Node\Arg $arg,
        Context $context,
    ): ?bool {
        // there are a bunch of things we want to evaluate even when we don't
        // know what function/method is being called
        if ($arg->value instanceof PhpParser\Node\Expr\Closure
            || $arg->value instanceof PhpParser\Node\Expr\ConstFetch
            || $arg->value instanceof PhpParser\Node\Expr\ClassConstFetch
            || $arg->value instanceof PhpParser\Node\Expr\FuncCall
            || $arg->value instanceof PhpParser\Node\Expr\MethodCall
            || $arg->value instanceof PhpParser\Node\Expr\StaticCall
            || $arg->value instanceof PhpParser\Node\Expr\ArrowFunction
            || $arg->value instanceof PhpParser\Node\Expr\New_
            || $arg->value instanceof PhpParser\Node\Expr\Cast
            || $arg->value instanceof PhpParser\Node\Expr\Assign
            || $arg->value instanceof PhpParser\Node\Expr\ArrayDimFetch
            || $arg->value instanceof PhpParser\Node\Expr\PropertyFetch
            || $arg->value instanceof PhpParser\Node\Expr\Array_
            || $arg->value instanceof PhpParser\Node\Expr\BinaryOp
            || $arg->value instanceof PhpParser\Node\Expr\Ternary
            || $arg->value instanceof PhpParser\Node\Scalar\InterpolatedString
            || $arg->value instanceof PhpParser\Node\Expr\PostInc
            || $arg->value instanceof PhpParser\Node\Expr\PostDec
            || $arg->value instanceof PhpParser\Node\Expr\PreInc
            || $arg->value instanceof PhpParser\Node\Expr\PreDec
        ) {
            $was_inside_call = $context->inside_call;
            $context->inside_call = true;

            if (ExpressionAnalyzer::analyze($statements_analyzer, $arg->value, $context) === false) {
                $context->inside_call = $was_inside_call;

                return false;
            }

            $context->inside_call = $was_inside_call;
        }

        if ($arg->value instanceof PhpParser\Node\Expr\PropertyFetch
            && $arg->value->name instanceof PhpParser\Node\Identifier
        ) {
            $var_id = '$' . $arg->value->name->name;
        } else {
            $var_id = ExpressionIdentifier::getVarId(
                $arg->value,
                $statements_analyzer->getFQCLN(),
                $statements_analyzer,
            );
        }

        if ($var_id) {
            if ($arg->value instanceof PhpParser\Node\Expr\Variable) {
                $statements_analyzer->registerPossiblyUndefinedVariable($var_id, $arg->value);
            }

            if (!$context->hasVariable($var_id)
                || $context->vars_in_scope[$var_id]->isNull()
            ) {
                if (!isset($context->vars_in_scope[$var_id])
                    && $arg->value instanceof PhpParser\Node\Expr\Variable
                ) {
                    IssueBuffer::maybeAdd(
                        new PossiblyUndefinedVariable(
                            'Variable ' . $var_id
                                . ' must be defined prior to use within an unknown function or method',
                            new CodeLocation($statements_analyzer->getSource(), $arg->value),
                        ),
                        $statements_analyzer->getSuppressedIssues(),
                    );
                }

                // we don't know if it exists, assume it's passed by reference
                $context->vars_in_scope[$var_id] = Type::getMixed();
                $context->vars_possibly_in_scope[$var_id] = true;
            } else {
                $was_inside_call = $context->inside_call;
                $context->inside_call = true;
                ExpressionAnalyzer::analyze($statements_analyzer, $arg->value, $context);
                $context->inside_call = $was_inside_call;

                $context->removeVarFromConflictingClauses(
                    $var_id,
                    $context->vars_in_scope[$var_id],
                    $statements_analyzer,
                );

                $t = $context->vars_in_scope[$var_id]->getBuilder();
                foreach ($t->getAtomicTypes() as $type) {
                    if ($type instanceof TArray && $type->isEmptyArray()) {
                        $t->removeType('array');
                        $t->addType(
                            new TArray(
                                [Type::getArrayKey(), Type::getMixed()],
                            ),
                        );
                    }
                }
                $context->vars_in_scope[$var_id] = $t->freeze();
            }
        }

        return null;
    }

    private static function handleByRefReadonlyArg(
        StatementsAnalyzer $statements_analyzer,
        Context $context,
        PhpParser\Node\Expr\PropertyFetch $stmt,
        string $fq_class_name,
        string $prop_name,
    ): void {
        $property_id = $fq_class_name . '::$' . $prop_name;

        $codebase = $statements_analyzer->getCodebase();
        $declaring_property_class = (string) $codebase->properties->getDeclaringClassForProperty(
            $property_id,
            true,
            $statements_analyzer,
        );

        try {
            $declaring_class_storage = $codebase->classlike_storage_provider->get($declaring_property_class);
        } catch (InvalidArgumentException) {
            return;
        }

        if (isset($declaring_class_storage->properties[$prop_name])) {
            $property_storage = $declaring_class_storage->properties[$prop_name];

            InstancePropertyAssignmentAnalyzer::trackPropertyImpurity(
                $statements_analyzer,
                $stmt,
                $property_id,
                $property_storage,
                $declaring_class_storage,
                $context,
            );
        }
    }

    /**
     * @return false|null
     */
    private static function handleByRefFunctionArg(
        StatementsAnalyzer $statements_analyzer,
        ?string $method_id,
        int $argument_offset,
        PhpParser\Node\Arg $arg,
        Context $context,
    ): ?bool {
        $var_id = ExpressionIdentifier::getVarId(
            $arg->value,
            $statements_analyzer->getFQCLN(),
            $statements_analyzer,
        );

        $builtin_array_functions = [
            'ksort', 'asort', 'krsort', 'arsort', 'natcasesort', 'natsort',
            'reset', 'end', 'next', 'prev', 'array_pop', 'array_shift', 'extract',
        ];

        if ($arg->value instanceof PhpParser\Node\Expr\PropertyFetch
            && $arg->value->name instanceof PhpParser\Node\Identifier) {
            $prop_name = $arg->value->name->name;
            if (!empty($statements_analyzer->getFQCLN())) {
                $fq_class_name = $statements_analyzer->getFQCLN();

                self::handleByRefReadonlyArg(
                    $statements_analyzer,
                    $context,
                    $arg->value,
                    $fq_class_name,
                    $prop_name,
                );
            } else {
                // @todo atm only works for simple fetch, $a->foo, not $a->foo->bar
                // I guess there's a function to do this, but I couldn't locate it
                $var_id = ExpressionIdentifier::getVarId(
                    $arg->value->var,
                    $statements_analyzer->getFQCLN(),
                    $statements_analyzer,
                );

                if ($var_id && isset($context->vars_in_scope[$var_id])) {
                    foreach ($context->vars_in_scope[$var_id]->getAtomicTypes() as $atomic_type) {
                        if ($atomic_type instanceof TNamedObject) {
                            $fq_class_name = $atomic_type->value;

                            self::handleByRefReadonlyArg(
                                $statements_analyzer,
                                $context,
                                $arg->value,
                                $fq_class_name,
                                $prop_name,
                            );
                        }
                    }
                }
            }
        }

        if (($var_id && isset($context->vars_in_scope[$var_id]))
            || ($method_id
                && in_array(
                    $method_id,
                    $builtin_array_functions,
                    true,
                ))
        ) {
            $was_inside_assignment = $context->inside_assignment;
            $context->inside_assignment = true;

            // if the variable is in scope, get or we're in a special array function,
            // figure out its type before proceeding
            if (ExpressionAnalyzer::analyze(
                $statements_analyzer,
                $arg->value,
                $context,
            ) === false) {
                $context->inside_assignment = $was_inside_assignment;

                return false;
            }

            $context->inside_assignment = $was_inside_assignment;
        }

        // special handling for array sort
        if ($argument_offset === 0
            && $method_id
            && in_array(
                $method_id,
                $builtin_array_functions,
                true,
            )
        ) {
            if (in_array($method_id, ['array_pop', 'array_shift'], true)) {
                ArrayFunctionArgumentsAnalyzer::handleByRefArrayAdjustment(
                    $statements_analyzer,
                    $arg,
                    $context,
                    $method_id === 'array_shift',
                );

                return null;
            }

            // noops
            if (in_array($method_id, ['reset', 'end', 'next', 'prev', 'ksort'], true)) {
                return null;
            }

            if (($arg_value_type = $statements_analyzer->node_data->getType($arg->value))
                && $arg_value_type->hasArray()
            ) {
                /**
                 * @var TArray|TKeyedArray
                 */
                $array_type = $arg_value_type->getArray();

                if ($array_type instanceof TKeyedArray) {
                    $array_type = $array_type->getGenericArrayType();
                }

                $by_ref_type = new Union([$array_type]);

                AssignmentAnalyzer::assignByRefParam(
                    $statements_analyzer,
                    $arg->value,
                    $by_ref_type,
                    $by_ref_type,
                    $context,
                    false,
                );

                return null;
            }
        }

        if ($method_id === 'socket_select') {
            if (ExpressionAnalyzer::analyze(
                $statements_analyzer,
                $arg->value,
                $context,
            ) === false) {
                return false;
            }
        }

        if (!$arg->value instanceof PhpParser\Node\Expr\Variable) {
            $suppressed_issues = $statements_analyzer->getSuppressedIssues();

            if (!in_array('EmptyArrayAccess', $suppressed_issues, true)) {
                $statements_analyzer->addSuppressedIssues(['EmptyArrayAccess']);
            }

            $v = ExpressionAnalyzer::analyze($statements_analyzer, $arg->value, $context);

            if (!in_array('EmptyArrayAccess', $suppressed_issues, true)) {
                $statements_analyzer->removeSuppressedIssues(['EmptyArrayAccess']);
            }

            if ($v === false) {
                return false;
            }
        }

        return null;
    }

    /**
     * @param   list<PhpParser\Node\Arg> $args
     * @param   array<int,FunctionLikeParameter>        $function_params
     * @param   array<string, array<string, Union>>  $class_generic_params
     */
    private static function getProvisionalTemplateResultForFunctionLike(
        StatementsAnalyzer $statements_analyzer,
        Codebase $codebase,
        Context $context,
        ?ClassLikeStorage $class_storage,
        ?string $self_fq_class_name,
        ?ClassLikeStorage $calling_class_storage,
        FunctionLikeStorage $function_storage,
        array $class_generic_params,
        ?TemplateResult $template_result,
        array $args,
        array $function_params,
        ?FunctionLikeParameter $last_param,
    ): ?TemplateResult {
        $template_types = CallAnalyzer::getTemplateTypesForCall(
            $codebase,
            $class_storage,
            $self_fq_class_name,
            $calling_class_storage,
            $function_storage->template_types ?: [],
            $class_generic_params,
        );

        if (!$template_types) {
            return null;
        }

        if (!$template_result) {
            return new TemplateResult($template_types, []);
        }

        if (!$template_result->template_types) {
            $template_result->template_types = $template_types;
        }

        foreach ($args as $argument_offset => $arg) {
            $function_param = null;

            if ($arg->name && $function_storage->allow_named_arg_calls) {
                foreach ($function_params as $candidate_param) {
                    if ($candidate_param->name === $arg->name->name) {
                        $function_param = $candidate_param;
                        break;
                    }
                }
            } elseif ($argument_offset < count($function_params)) {
                $function_param = $function_params[$argument_offset];
            } elseif ($last_param && $last_param->is_variadic) {
                $function_param = $last_param;
            }

            if (!$function_param
                || !$function_param->type
            ) {
                continue;
            }

            $arg_value_type = $statements_analyzer->node_data->getType($arg->value);

            if (!$arg_value_type) {
                continue;
            }

            $fleshed_out_param_type = TypeExpander::expandUnion(
                $codebase,
                $function_param->type,
                $class_storage->name ?? null,
                $calling_class_storage->name ?? null,
                null,
                true,
                false,
                $calling_class_storage->final ?? false,
            );

            TemplateStandinTypeReplacer::fillTemplateResult(
                $fleshed_out_param_type,
                $template_result,
                $codebase,
                $statements_analyzer,
                $arg_value_type,
                $argument_offset,
                $context->self,
                $context->calling_method_id ?: $context->calling_function_id,
                false,
            );
        }

        return $template_result;
    }

    /**
     * @param   array<int, PhpParser\Node\Arg>  $args
     * @param   array<int,FunctionLikeParameter>        $function_params
     */
    private static function checkArgCount(
        StatementsAnalyzer $statements_analyzer,
        Codebase $codebase,
        ?FunctionLikeStorage $function_storage,
        Context $context,
        ?TemplateResult $template_result,
        bool $is_variadic,
        array $args,
        array $function_params,
        bool $in_call_map,
        string|MethodIdentifier|null $method_id,
        ?string $cased_method_id,
        CodeLocation $code_location,
    ): void {
        if (!$is_variadic
            && count($args) > count($function_params)
            && (!count($function_params) || $function_params[count($function_params) - 1]->name !== '...=')
            && ($in_call_map
                || !$function_storage instanceof MethodStorage
                || $function_storage->is_static
                || ($method_id instanceof MethodIdentifier
                    && $method_id->method_name === '__construct'))
        ) {
            IssueBuffer::maybeAdd(
                new TooManyArguments(
                    'Too many arguments for ' . ($cased_method_id ?: $method_id)
                    . ' - expecting ' . count($function_params) . ' but saw ' . count($args),
                    $code_location,
                    (string)$method_id,
                ),
                $statements_analyzer->getSuppressedIssues(),
            );

            return;
        }

        if (count($args) < count($function_params)) {
            //we're gonna loop over given args and unset them from the function_params.
            // If some mandatory params are left at the end, we'll throw an error
            foreach ($args as $arg) {
                // when the argument is not named, we can remove the params in order
                if ($arg->name === null) {
                    // if we're unpacking, we try to unset the exact number of params, if we can't we give up and return
                    if ($arg->unpack) {
                        $arg_value_type = $statements_analyzer->node_data->getType($arg->value);

                        if (!$arg_value_type || !$arg_value_type->hasArray()) {
                            return;
                        }

                        if ($arg_value_type->isSingle()
                            && ($atomic_arg_type = $arg_value_type->getSingleAtomic())
                            && $atomic_arg_type instanceof TKeyedArray
                            && !$atomic_arg_type->is_list
                        ) {
                            //if we have a single shape, we'll check param names
                            foreach ($atomic_arg_type->properties as $property_name => $_property_type) {
                                foreach ($function_params as $k => $param) {
                                    if ($param->name === $property_name) {
                                        unset($function_params[$k]);
                                    }
                                }
                            }
                            continue;
                        }

                        foreach ($arg_value_type->getAtomicTypes() as $atomic_arg_type) {
                            $packed_var_definite_args_tmp = [];
                            if ($atomic_arg_type instanceof TKeyedArray) {
                                if ($atomic_arg_type->fallback_params !== null) {
                                    return;
                                }

                                if (!$atomic_arg_type->allShapeKeysAlwaysDefined()) {
                                    return;
                                }

                                //we did not return. The number of packed params is the number of properties
                                $packed_var_definite_args_tmp[] = count($atomic_arg_type->properties);
                            } elseif ($atomic_arg_type instanceof TNonEmptyArray) {
                                if ($atomic_arg_type->count === null) {
                                    return;
                                }

                                $packed_var_definite_args_tmp[] = $atomic_arg_type->count;
                            } elseif ($atomic_arg_type instanceof TArray
                                && $atomic_arg_type->type_params[1]->isNever()
                            ) {
                                $packed_var_definite_args_tmp[] = 0;
                            } else {
                                return;
                            }


                            if (min($packed_var_definite_args_tmp) === max($packed_var_definite_args_tmp)) {
                                //we have a stable number of params
                                $packed_var_definite_args = $packed_var_definite_args_tmp[0];
                            } else {
                                return;
                            }
                        }
                    } else {
                        //if we're not unpacking, we remove the first param
                        $packed_var_definite_args = 1;
                    }

                    $function_params = array_slice($function_params, $packed_var_definite_args);
                    continue;
                }

                foreach ($function_params as $k => $param) {
                    if ($param->name === $arg->name->name) {
                        unset($function_params[$k]);
                        continue;
                    }
                }
            }

            //we're now left with an array of params that were not passed.
            // If they're mandatory, throw an error. Otherwise, we compute the default value
            foreach ($function_params as $i => $param) {
                if (!$param->is_optional && !$param->is_variadic) {
                    IssueBuffer::maybeAdd(
                        new TooFewArguments(
                            'Too few arguments for ' . $cased_method_id
                            . ' - expecting ' . $param->name . ' to be passed',
                            $code_location,
                            (string)$method_id,
                        ),
                        $statements_analyzer->getSuppressedIssues(),
                    );
                    continue;
                }

                if ($param->type
                    && $param->default_type
                    && !$param->is_variadic
                    && $template_result
                ) {
                    if ($param->default_type instanceof Union) {
                        $default_type = $param->default_type;
                    } else {
                        $default_type_atomic = ConstantTypeResolver::resolve(
                            $codebase->classlikes,
                            $param->default_type,
                            $statements_analyzer,
                        );

                        $default_type = new Union([$default_type_atomic]);
                    }

                    TemplateStandinTypeReplacer::fillTemplateResult(
                        $param->type,
                        $template_result,
                        $codebase,
                        $statements_analyzer,
                        $default_type,
                        $i,
                        $context->self,
                        $context->calling_method_id ?: $context->calling_function_id,
                        true,
                    );
                }
            }
        }
    }
}
