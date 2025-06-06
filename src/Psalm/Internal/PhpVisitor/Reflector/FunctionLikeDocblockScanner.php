<?php

declare(strict_types=1);

namespace Psalm\Internal\PhpVisitor\Reflector;

use AssertionError;
use PhpParser;
use Psalm\Aliases;
use Psalm\CodeLocation;
use Psalm\CodeLocation\DocblockTypeLocation;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Exception\DocblockParseException;
use Psalm\Exception\InvalidMethodOverrideException;
use Psalm\Exception\TypeParseTreeException;
use Psalm\Internal\Analyzer\CommentAnalyzer;
use Psalm\Internal\Analyzer\NamespaceAnalyzer;
use Psalm\Internal\Scanner\FileScanner;
use Psalm\Internal\Scanner\FunctionDocblockComment;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\Internal\Type\TypeAlias;
use Psalm\Internal\Type\TypeParser;
use Psalm\Internal\Type\TypeTokenizer;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\PossiblyInvalidDocblockTag;
use Psalm\Storage\Assertion;
use Psalm\Storage\Assertion\Empty_;
use Psalm\Storage\Assertion\Falsy;
use Psalm\Storage\Assertion\IsIdentical;
use Psalm\Storage\Assertion\IsLooselyEqual;
use Psalm\Storage\Assertion\IsNotIdentical;
use Psalm\Storage\Assertion\IsNotLooselyEqual;
use Psalm\Storage\Assertion\IsNotType;
use Psalm\Storage\Assertion\IsType;
use Psalm\Storage\Assertion\NonEmpty;
use Psalm\Storage\Assertion\Truthy;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FileStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Storage\Possibilities;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TConditional;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

use function array_any;
use function array_filter;
use function array_merge;
use function array_values;
use function count;
use function explode;
use function in_array;
use function preg_last_error_msg;
use function preg_match;
use function preg_replace;
use function preg_split;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function substr_replace;
use function trim;

/**
 * @internal
 */
final class FunctionLikeDocblockScanner
{
    /**
     * @param array<string, non-empty-array<string, Union>> $existing_function_template_types
     * @param array<string, TypeAlias> $type_aliases
     */
    public static function addDocblockInfo(
        Codebase $codebase,
        FileScanner $file_scanner,
        FileStorage $file_storage,
        Aliases $aliases,
        array $type_aliases,
        ?ClassLikeStorage $classlike_storage,
        array $existing_function_template_types,
        FunctionLikeStorage $storage,
        PhpParser\Node\FunctionLike $stmt,
        FunctionDocblockComment $docblock_info,
        bool $is_functionlike_override,
        bool $fake_method,
        string $cased_function_id,
    ): void {
        self::handleUnexpectedTags($docblock_info, $storage, $stmt, $file_scanner, $cased_function_id);

        $config = Config::getInstance();

        if ($docblock_info->mutation_free) {
            $storage->mutation_free = true;

            if ($storage instanceof MethodStorage) {
                $storage->external_mutation_free = true;
                $storage->mutation_free_inferred = false;
            }
        }

        if ($storage instanceof MethodStorage && $docblock_info->external_mutation_free) {
            $storage->external_mutation_free = true;
        }

        if ($docblock_info->deprecated) {
            $storage->deprecated = true;
        }

        if (count($docblock_info->psalm_internal) !== 0) {
            $storage->internal = $docblock_info->psalm_internal;
        } elseif ($docblock_info->internal && $aliases->namespace) {
            $storage->internal = [NamespaceAnalyzer::getNameSpaceRoot($aliases->namespace)];
        }

        if (($storage->internal || ($classlike_storage && $classlike_storage->internal))
            && !$config->allow_internal_named_arg_calls
        ) {
            $storage->allow_named_arg_calls = false;
        } elseif ($docblock_info->no_named_args) {
            $storage->allow_named_arg_calls = false;
        }

        if ($docblock_info->variadic) {
            $storage->variadic = true;
        }

        if ($docblock_info->pure) {
            $storage->pure = true;
            $storage->specialize_call = true;
            $storage->mutation_free = true;
            if ($storage instanceof MethodStorage) {
                $storage->external_mutation_free = true;
            }
        }

        if ($docblock_info->specialize_call) {
            $storage->specialize_call = true;
        }

        // we make sure we only add ignore flag for internal stubs if the config is set to true
        if ($docblock_info->ignore_nullable_return
            && $storage->return_type
            && ($codebase->config->ignore_internal_nullable_issues
                || !in_array($file_storage->file_path, $codebase->config->internal_stubs)
            )
        ) {
            /** @psalm-suppress InaccessibleProperty We just created this type */
            $storage->return_type->ignore_nullable_issues = true;
        }

        // we make sure we only add ignore flag for internal stubs if the config is set to true
        if ($docblock_info->ignore_falsable_return
            && $storage->return_type
            && ($codebase->config->ignore_internal_falsable_issues
                || !in_array($file_storage->file_path, $codebase->config->internal_stubs)
            )
        ) {
            /** @psalm-suppress InaccessibleProperty We just created this type */
            $storage->return_type->ignore_falsable_issues = true;
        }

        if ($docblock_info->stub_override && !$is_functionlike_override) {
            throw new InvalidMethodOverrideException(
                'Method ' . $cased_function_id . ' is marked as stub override,'
                . ' but no original counterpart found',
            );
        }

        $storage->suppressed_issues = $docblock_info->suppressed_issues;

        foreach ($docblock_info->throws as [$throw, $offset, $line]) {
            $throw_location = new DocblockTypeLocation(
                $file_scanner,
                $offset,
                $offset + strlen($throw),
                $line,
            );

            foreach (explode('|', $throw) as $throw_class) {
                $throw_class = trim($throw_class);

                if ($throw_class === '') {
                    continue;
                }

                if ($throw_class !== 'self' && $throw_class !== 'static' && $throw_class !== 'parent') {
                    $exception_fqcln = Type::getFQCLNFromString(
                        $throw_class,
                        $aliases,
                    );
                } else {
                    $exception_fqcln = $throw_class;
                }

                $codebase->scanner->queueClassLikeForScanning($exception_fqcln);
                $file_storage->referenced_classlikes[strtolower($exception_fqcln)] = $exception_fqcln;
                $storage->throws[$exception_fqcln] = true;
                $storage->throw_locations[$exception_fqcln] = $throw_location;
            }
        }

        if (!$config->use_docblock_types) {
            return;
        }

        if ($storage instanceof MethodStorage && $docblock_info->inheritdoc) {
            $storage->inheritdoc = true;
        }

        $template_types = $classlike_storage && $classlike_storage->template_types
            ? $classlike_storage->template_types
            : null;

        $function_template_types = $existing_function_template_types;
        $class_template_types = $classlike_storage ? ($classlike_storage->template_types ?: []) : [];

        if ($docblock_info->templates) {
            $function_template_types = self::handleTemplates(
                $storage,
                $docblock_info,
                $aliases,
                $template_types,
                $type_aliases,
                $file_scanner,
                $stmt,
                $cased_function_id,
            );
        }

        self::handleAssertions(
            $docblock_info,
            $storage,
            $codebase,
            $file_scanner,
            $file_storage,
            $aliases,
            $stmt,
            $class_template_types,
            $function_template_types,
            $type_aliases,
            $classlike_storage,
        );

        foreach ($docblock_info->globals as $global) {
            try {
                $storage->global_types[$global['name']] = TypeParser::parseTokens(
                    TypeTokenizer::getFullyQualifiedTokens(
                        $global['type'],
                        $aliases,
                        null,
                        $type_aliases,
                    ),
                    null,
                );
            } catch (TypeParseTreeException $e) {
                $storage->docblock_issues[] = new InvalidDocblock(
                    $e->getMessage() . ' in docblock for ' . $cased_function_id,
                    new CodeLocation($file_scanner, $stmt, null, true),
                );

                continue;
            }
        }

        if ($docblock_info->params) {
            self::improveParamsFromDocblock(
                $codebase,
                $file_scanner,
                $file_storage,
                $aliases,
                $type_aliases,
                $classlike_storage,
                $storage,
                $function_template_types,
                $class_template_types,
                $docblock_info->params,
                $stmt,
                $fake_method,
                $classlike_storage && !$classlike_storage->is_trait ? $classlike_storage->name : null,
            );
        }

        if ($storage instanceof MethodStorage) {
            $storage->has_docblock_param_types = array_any(
                $storage->params,
                static fn(FunctionLikeParameter $p): bool => $p->type !== null && $p->has_docblock_type,
            );
        }

        foreach ($docblock_info->params_out as $docblock_param_out) {
            self::handleParamOut(
                $docblock_param_out,
                $aliases,
                $function_template_types,
                $class_template_types,
                $type_aliases,
                $cased_function_id,
                $file_scanner,
                $stmt,
                $storage,
                $codebase,
                $file_storage,
            );
        }

        if ($docblock_info->self_out
            && $storage instanceof MethodStorage) {
            $out_type = TypeParser::parseTokens(
                TypeTokenizer::getFullyQualifiedTokens(
                    $docblock_info->self_out['type'],
                    $aliases,
                    $function_template_types + $class_template_types,
                    $type_aliases,
                    $classlike_storage ? $classlike_storage->name : null,
                ),
                null,
                $function_template_types + $class_template_types,
                $type_aliases,
            );
            $storage->self_out_type = $out_type;
        }

        if ($docblock_info->if_this_is
            && $storage instanceof MethodStorage) {
            $out_type = TypeParser::parseTokens(
                TypeTokenizer::getFullyQualifiedTokens(
                    $docblock_info->if_this_is['type'],
                    $aliases,
                    $function_template_types + $class_template_types,
                    $type_aliases,
                    $classlike_storage ? $classlike_storage->name : null,
                ),
                null,
                $function_template_types + $class_template_types,
                $type_aliases,
            );
            $storage->if_this_is_type = $out_type;
        }

        foreach ($docblock_info->taint_sink_params as $taint_sink_param) {
            $param_name = substr($taint_sink_param['name'], 1);

            foreach ($storage->params as $param_storage) {
                if ($param_storage->name === $param_name) {
                    $param_storage->sinks |= $taint_sink_param['taint'];
                }
            }
        }

        // merge taints from doc block to storage, enforce uniqueness and having consecutive index keys
        $storage->taint_source_types |= $docblock_info->taint_source_types;
        $location = new CodeLocation($file_scanner, $stmt, null, true);

        foreach ($docblock_info->added_taints as $taint) {
            $t = $codebase->getOrRegisterTaint($taint, $location);
            if ($t !== null) {
                $storage->added_taints |= $t;
            }
        }

        foreach ($docblock_info->removed_taints as $removed_taint) {
            if ($removed_taint[0] === '(') {
                self::handleRemovedTaint(
                    $codebase,
                    $stmt,
                    $aliases,
                    $removed_taint,
                    $function_template_types,
                    $class_template_types,
                    $type_aliases,
                    $storage,
                    $classlike_storage,
                    $cased_function_id,
                    $file_storage,
                    $file_scanner,
                );
            } else {
                $t = $codebase->getOrRegisterTaint($removed_taint, $location);
                if ($t !== null) {
                    $storage->removed_taints |= $t;
                }
            }
        }

        self::handleTaintFlow($docblock_info, $storage);

        foreach ($docblock_info->assert_untainted_params as $untainted_assert_param) {
            $param_name = substr($untainted_assert_param['name'], 1);

            foreach ($storage->params as $param_storage) {
                if ($param_storage->name === $param_name) {
                    $param_storage->assert_untainted = true;
                }
            }
        }

        if ($docblock_info->return_type !== null) {
            self::handleReturn(
                $codebase,
                $docblock_info,
                $docblock_info->return_type,
                $fake_method,
                $file_scanner,
                $storage,
                $stmt,
                $aliases,
                $function_template_types,
                $class_template_types,
                $type_aliases,
                $classlike_storage,
                $cased_function_id,
                $file_storage,
            );
        }

        if ($docblock_info->description) {
            $storage->description = $docblock_info->description;
        }

        $storage->public_api = $docblock_info->public_api;
    }

    /**
     * @param  array<string, array<string, Union>> $template_types
     * @param  array<string, TypeAlias>|null   $type_aliases
     * @param  array<string, array<string, Union>> $function_template_types
     * @return array{
     *     array<int, array{0: string, 1: int, 2?: string}>,
     *     array<string, array<string, Union>>
     * }
     */
    private static function getConditionalSanitizedTypeTokens(
        string $docblock_return_type,
        Aliases $aliases,
        array $template_types,
        ?array $type_aliases,
        FunctionLikeStorage $storage,
        ?ClassLikeStorage $classlike_storage,
        string $cased_function_id,
        array $function_template_types,
    ): array {
        $fixed_type_tokens = TypeTokenizer::getFullyQualifiedTokens(
            $docblock_return_type,
            $aliases,
            $template_types,
            $type_aliases,
            $classlike_storage && !$classlike_storage->is_trait ? $classlike_storage->name : null,
        );

        $param_type_mapping = [];
        $template_function_id = 'fn-' . strtolower($cased_function_id);

        // This checks for param references in the return type tokens
        // If found, the param is replaced with a generated template param
        foreach ($fixed_type_tokens as $i => $type_token) {
            $token_body = $type_token[0];

            if ($token_body[0] === '$') {
                foreach ($storage->params as $j => $param_storage) {
                    if ('$' . $param_storage->name === $token_body) {
                        if (!isset($param_type_mapping[$token_body])) {
                            $template_name = 'TGeneratedFromParam' . $j;
                            if (isset($storage->template_types[$template_name])) {
                                $function_template_types[$template_name]
                                    = $storage->template_types[$template_name];
                                $param_type_mapping[$token_body] = $template_name;
                            } else {
                                $template_as_type = $param_storage->type ?: Type::getMixed();

                                $storage->template_types[$template_name] = [
                                    $template_function_id => $template_as_type,
                                ];

                                $function_template_types[$template_name]
                                    = $storage->template_types[$template_name];

                                $param_type_mapping[$token_body] = $template_name;

                                $param_storage->type = new Union([
                                    new TTemplateParam(
                                        $template_name,
                                        $template_as_type,
                                        $template_function_id,
                                    ),
                                ]);
                            }
                        }

                        // spaces are allowed before $foo in get(string $foo) magic method
                        // definitions, but we want to remove them in this instance
                        if ($i > 0
                            && isset($fixed_type_tokens[$i - 1])
                            && $fixed_type_tokens[$i - 1][0][0] === ' '
                        ) {
                            unset($fixed_type_tokens[$i - 1]);
                        }

                        $fixed_type_tokens[$i][0] = $param_type_mapping[$token_body];

                        continue 2;
                    }
                }
            }

            if ($token_body === 'func_num_args()') {
                $template_name = 'TFunctionArgCount';
                $storage->template_types[$template_name] = [
                    'fn-' . strtolower($storage->cased_name ?? '') => Type::getInt(),
                ];

                $function_template_types[$template_name]
                    = $storage->template_types[$template_name];

                $fixed_type_tokens[$i][0] = $template_name;
            }

            if ($token_body === 'PHP_MAJOR_VERSION') {
                $template_name = 'TPhpMajorVersion';

                $storage->template_types[$template_name] = [
                    'fn-' . strtolower($storage->cased_name ?? '') => Type::getInt(),
                ];

                $function_template_types[$template_name]
                    = $storage->template_types[$template_name];

                $fixed_type_tokens[$i][0] = $template_name;
            }

            if ($token_body === 'PHP_VERSION_ID') {
                $template_name = 'TPhpVersionId';

                $storage->template_types[$template_name] = [
                    'fn-' . strtolower($storage->cased_name ?? '') => Type::getInt(),
                ];

                $function_template_types[$template_name]
                    = $storage->template_types[$template_name];

                $fixed_type_tokens[$i][0] = $template_name;
            }
        }

        return [$fixed_type_tokens, $function_template_types];
    }

    /**
     * @param array<string, array<string, Union>> $class_template_types
     * @param array<string, array<string, Union>> $function_template_types
     * @param array<string, TypeAlias> $type_aliases
     * @return non-empty-list<Assertion>|null
     */
    private static function getAssertionParts(
        Codebase $codebase,
        FileScanner $file_scanner,
        FileStorage $file_storage,
        Aliases $aliases,
        PhpParser\Node\FunctionLike $stmt,
        FunctionLikeStorage $storage,
        string $assertion_type,
        array $class_template_types,
        array $function_template_types,
        array $type_aliases,
        ?string $self_fqcln,
    ): ?array {
        $is_negation = false;
        $is_loose_equality = false;
        $is_strict_equality = false;

        if ($assertion_type[0] === '!') {
            $is_negation = true;
            $assertion_type = substr($assertion_type, 1);
        }

        if ($assertion_type[0] === '~') {
            $is_loose_equality = true;
            $assertion_type = substr($assertion_type, 1);
        }

        if ($assertion_type[0] === '=') {
            $is_strict_equality = true;
            $assertion_type = substr($assertion_type, 1);
        }

        $class_template_types = !$stmt instanceof PhpParser\Node\Stmt\ClassMethod || !$stmt->isStatic()
            ? $class_template_types
            : [];

        if ($assertion_type === 'falsy') {
            return [$is_negation ? new Truthy() : new Falsy()];
        }

        if ($assertion_type === 'truthy') {
            return [$is_negation ? new Falsy() : new Truthy()];
        }

        if ($assertion_type === 'empty') {
            return [$is_negation ? new NonEmpty() : new Empty_()];
        }

        $template_types = $function_template_types + $class_template_types;

        try {
            $namespaced_type = TypeParser::parseTokens(
                TypeTokenizer::getFullyQualifiedTokens(
                    $assertion_type,
                    $aliases,
                    $template_types,
                    $type_aliases,
                    $self_fqcln,
                    null,
                    true,
                ),
                null,
                $template_types,
                $type_aliases,
            );
        } catch (TypeParseTreeException $e) {
            $storage->docblock_issues[] = new InvalidDocblock(
                'Invalid @psalm-assert union type: ' . $e->getMessage(),
                new CodeLocation($file_scanner, $stmt, null, true),
            );

            return null;
        }

        if (($is_negation || $is_loose_equality || $is_strict_equality)
            && count($namespaced_type->getAtomicTypes()) > 1
        ) {
            $storage->docblock_issues[] = new InvalidDocblock(
                'Docblock assertions cannot contain | characters together with a prefix',
                new CodeLocation($file_scanner, $stmt, null, true),
            );

            return null;
        }

        /** @psalm-suppress UnusedMethodCall */
        $namespaced_type->queueClassLikesForScanning(
            $codebase,
            $file_storage,
            $function_template_types + $class_template_types,
        );

        $assertion_type_parts = [];

        foreach ($namespaced_type->getAtomicTypes() as $namespaced_type_part) {
            if ($is_negation) {
                if ($is_strict_equality) {
                    $assertion_type_parts[] = new IsNotIdentical($namespaced_type_part);
                } elseif ($is_loose_equality) {
                    $assertion_type_parts[] = new IsNotLooselyEqual($namespaced_type_part);
                } else {
                    $assertion_type_parts[] = new IsNotType($namespaced_type_part);
                }
            } else {
                if ($is_strict_equality) {
                    $assertion_type_parts[] = new IsIdentical($namespaced_type_part);
                } elseif ($is_loose_equality) {
                    $assertion_type_parts[] = new IsLooselyEqual($namespaced_type_part);
                } else {
                    $assertion_type_parts[] = new IsType($namespaced_type_part);
                }
            }
        }

        return $assertion_type_parts;
    }

    /**
     * @param array<string, array<string, Union>> $class_template_types
     * @param array<string, non-empty-array<string, Union>> $function_template_types
     * @param array<string, TypeAlias> $type_aliases
     * @param array<
     *     int,
     *     array{
     *         type:string,
     *         name:string,
     *         line_number:int,
     *         start:int,
     *         end:int,
     *         description?:string
     *     }
     * > $docblock_params
     */
    private static function improveParamsFromDocblock(
        Codebase $codebase,
        FileScanner $file_scanner,
        FileStorage $file_storage,
        Aliases $aliases,
        array $type_aliases,
        ?ClassLikeStorage $classlike_storage,
        FunctionLikeStorage $storage,
        array &$function_template_types,
        array $class_template_types,
        array $docblock_params,
        PhpParser\Node\FunctionLike $function,
        bool $fake_method,
        ?string $fq_classlike_name,
    ): void {
        $base = $classlike_storage ? $classlike_storage->name . '::' : '';

        $cased_method_id = $base . $storage->cased_name;

        $unused_docblock_params = [];

        $class_template_types = !$function instanceof PhpParser\Node\Stmt\ClassMethod || !$function->isStatic()
            ? $class_template_types
            : [];

        foreach ($docblock_params as $docblock_param) {
            $param_name = $docblock_param['name'];
            $docblock_param_variadic = false;

            if (str_starts_with($param_name, '...')) {
                $docblock_param_variadic = true;
                $param_name = substr($param_name, 3);
            }

            $param_name = substr($param_name, 1);

            $storage_param = null;

            foreach ($storage->params as $function_signature_param) {
                if ($function_signature_param->name === $param_name) {
                    $storage_param = $function_signature_param;
                    break;
                }
            }

            if (!$fake_method) {
                $docblock_type_location = new DocblockTypeLocation(
                    $file_scanner,
                    $docblock_param['start'],
                    $docblock_param['end'],
                    $docblock_param['line_number'],
                );
            } else {
                $docblock_type_location = new CodeLocation(
                    $file_scanner,
                    $function,
                    null,
                    false,
                    CodeLocation::FUNCTION_PHPDOC_METHOD,
                    null,
                );
            }

            if ($storage_param === null) {
                $param_location = new CodeLocation(
                    $file_scanner,
                    $function,
                    null,
                    true,
                    CodeLocation::FUNCTION_PARAM_VAR,
                    null,
                    $docblock_param['line_number'],
                );

                $unused_docblock_params[$param_name] = $param_location;

                if (!$docblock_param_variadic || $storage->params || $file_scanner->will_analyze) {
                    continue;
                }

                $storage_param = new FunctionLikeParameter(
                    $param_name,
                    false,
                    null,
                    null,
                    null,
                    null,
                    false,
                    false,
                    true,
                    null,
                );

                $storage->addParam($storage_param);
            }

            try {
                $new_param_type = TypeParser::parseTokens(
                    TypeTokenizer::getFullyQualifiedTokens(
                        $docblock_param['type'],
                        $aliases,
                        $function_template_types + $class_template_types,
                        $type_aliases,
                        $fq_classlike_name,
                    ),
                    null,
                    $function_template_types + $class_template_types,
                    $type_aliases,
                    true,
                );
            } catch (TypeParseTreeException $e) {
                $storage->docblock_issues[] = new InvalidDocblock(
                    $e->getMessage() . ' in docblock for ' . $cased_method_id,
                    $docblock_type_location,
                );

                continue;
            }

            $storage_param->has_docblock_type = true;

            /** @psalm-suppress UnusedMethodCall */
            $new_param_type->queueClassLikesForScanning(
                $codebase,
                $file_storage,
                $storage->template_types ?: [],
            );

            if ($storage->template_types) {
                foreach ($storage->template_types as $t => $type_map) {
                    foreach ($type_map as $obj => $type) {
                        if ($type->isMixed() && $docblock_param['type'] === 'class-string<' . $t . '>') {
                            $storage->template_types[$t][$obj] = Type::getObject();

                            if (isset($function_template_types[$t])) {
                                $function_template_types[$t][$obj] = $storage->template_types[$t][$obj];
                            }
                        }
                    }
                }
            }

            if (!$docblock_param_variadic && $storage_param->is_variadic && $new_param_type->hasArray()) {
                /**
                 * @var TArray|TKeyedArray
                 */
                $array_type = $new_param_type->getArray();

                if ($array_type instanceof TKeyedArray) {
                    $new_param_type = $array_type->getGenericValueType();
                } else {
                    $new_param_type = $array_type->type_params[1];
                }
            }

            $existing_param_type_nullable = $storage_param->is_nullable;

            if (isset($docblock_param['description'])) {
                $storage_param->description = $docblock_param['description'];
            }

            if (!$storage_param->type || $storage_param->type->hasMixed() || $storage->template_types) {
                if ($existing_param_type_nullable
                    && !$new_param_type->isNullable()
                    && !$new_param_type->hasTemplate()
                ) {
                    $new_param_type = $new_param_type->getBuilder()->addType(new TNull())->freeze();
                }

                $config = Config::getInstance();

                if ($config->add_param_default_to_docblock_type
                    && $storage_param->default_type instanceof Union
                    && !$storage_param->default_type->hasMixed()
                    && (!$storage_param->type || !$storage_param->type->hasMixed())
                ) {
                    $new_param_type = Type::combineUnionTypes($new_param_type, $storage_param->default_type);
                }

                $storage_param->type = $new_param_type;
                $storage_param->type_location = $docblock_type_location;
                continue;
            }

            $storage_param_atomic_types = $storage_param->type->getAtomicTypes();

            $all_typehint_types_match = true;

            foreach ($new_param_type->getAtomicTypes() as $key => $type) {
                if (isset($storage_param_atomic_types[$key])) {
                    /** @psalm-suppress InaccessibleProperty We just created this type */
                    $type->from_docblock = false;

                    if ($storage_param_atomic_types[$key] instanceof TArray
                        && $type instanceof TArray
                        && $type->type_params[0]->hasArrayKey()
                    ) {
                        /** @psalm-suppress InaccessibleProperty We just created this type */
                        $type->type_params[0]->from_docblock = false;
                    }
                } else {
                    $all_typehint_types_match = false;
                }
            }

            if ($all_typehint_types_match) {
                /** @psalm-suppress InaccessibleProperty We just created this type */
                $new_param_type->from_docblock = false;
            }

            if ($existing_param_type_nullable && !$new_param_type->isNullable()) {
                $new_param_type = $new_param_type->getBuilder()->addType(new TNull())->freeze();
            }

            $storage_param->type = $new_param_type;
            $storage_param->type_location = $docblock_type_location;
        }

        $params_without_docblock_type = array_filter(
            $storage->params,
            static fn(FunctionLikeParameter $p): bool => !$p->has_docblock_type && (!$p->type || $p->type->hasArray()),
        );

        $storage->has_undertyped_native_parameters = $params_without_docblock_type !== [];
        $storage->unused_docblock_parameters = $unused_docblock_params;
    }

    /**
     * @param array<string, TypeAlias> $type_aliases
     * @param array<string, non-empty-array<string, Union>> $function_template_types
     * @param array<string, non-empty-array<string, Union>> $class_template_types
     */
    private static function handleReturn(
        Codebase $codebase,
        FunctionDocblockComment $docblock_info,
        string $docblock_return_type,
        bool $fake_method,
        FileScanner $file_scanner,
        FunctionLikeStorage $storage,
        PhpParser\Node\FunctionLike $stmt,
        Aliases $aliases,
        array $function_template_types,
        array $class_template_types,
        array $type_aliases,
        ?ClassLikeStorage $classlike_storage,
        string $cased_function_id,
        FileStorage $file_storage,
    ): void {
        if (!$fake_method
            && $docblock_info->return_type_line_number
            && $docblock_info->return_type_start
            && $docblock_info->return_type_end
        ) {
            $storage->return_type_location = new DocblockTypeLocation(
                $file_scanner,
                $docblock_info->return_type_start,
                $docblock_info->return_type_end,
                $docblock_info->return_type_line_number,
            );
        } else {
            $storage->return_type_location = new CodeLocation(
                $file_scanner,
                $stmt,
                null,
                false,
                !$fake_method
                    ? CodeLocation::FUNCTION_PHPDOC_RETURN_TYPE
                    : CodeLocation::FUNCTION_PHPDOC_METHOD,
                $docblock_info->return_type,
                $docblock_info->return_type_line_number && !$fake_method
                    ? $docblock_info->return_type_line_number
                    : null,
            );
        }

        try {
            [$fixed_type_tokens, $function_template_types] = self::getConditionalSanitizedTypeTokens(
                $docblock_return_type,
                $aliases,
                $function_template_types + $class_template_types,
                $type_aliases,
                $storage,
                $classlike_storage,
                $cased_function_id,
                $function_template_types,
            );

            $storage->return_type = TypeParser::parseTokens(
                array_values($fixed_type_tokens),
                null,
                $function_template_types + $class_template_types,
                $type_aliases,
                true,
            );

            if ($storage instanceof MethodStorage) {
                $storage->has_docblock_return_type = true;
            }

            if ($storage->signature_return_type) {
                $all_typehint_types_match = true;
                $signature_return_atomic_types = $storage->signature_return_type->getAtomicTypes();

                foreach ($storage->return_type->getAtomicTypes() as $key => $type) {
                    if (isset($signature_return_atomic_types[$key])) {
                        /** @psalm-suppress InaccessibleProperty We just created this atomic type */
                        $type->from_docblock = false;
                    } else {
                        $all_typehint_types_match = false;
                    }
                }

                if ($all_typehint_types_match) {
                    /** @psalm-suppress InaccessibleProperty We just created this type */
                    $storage->return_type->from_docblock = false;

                    if ($storage instanceof MethodStorage) {
                        $storage->has_docblock_return_type = true;
                    }
                }

                // if the signature type contains null, we add null into the final return type too
                if ($storage->signature_return_type->isNullable()
                    && !$storage->return_type->isNullable()
                    && !$storage->return_type->hasTemplate()
                    && !$storage->return_type->hasConditional()
                ) {
                    //don't add null to final type if signature type don't match the docblock type
                    // however, we can't check for object types at this point (#6931), so we'll assume it's ok
                    if ($storage->return_type->hasObjectType() ||
                        UnionTypeComparator::isContainedBy(
                            $codebase,
                            $storage->return_type,
                            $storage->signature_return_type,
                        )
                    ) {
                        $storage->return_type = $storage->return_type->getBuilder()->addType(new TNull())->freeze();
                    }
                }
            }

            /** @psalm-suppress UnusedMethodCall */
            $storage->return_type->queueClassLikesForScanning($codebase, $file_storage);
        } catch (TypeParseTreeException $e) {
            $storage->docblock_issues[] = new InvalidDocblock(
                $e->getMessage() . ' in docblock for ' . $cased_function_id,
                new CodeLocation($file_scanner, $stmt, null, true),
            );
        }

        // we make sure we only add ignore flag for internal stubs if the config is set to true
        if ($docblock_info->ignore_nullable_return
            && $storage->return_type
            && ($codebase->config->ignore_internal_nullable_issues
                || !in_array($file_storage->file_path, $codebase->config->internal_stubs)
            )
        ) {
            /** @psalm-suppress InaccessibleProperty We just created this type */
            $storage->return_type->ignore_nullable_issues = true;
        }

        // we make sure we only add ignore flag for internal stubs if the config is set to true
        if ($docblock_info->ignore_falsable_return
            && $storage->return_type
            && ($codebase->config->ignore_internal_falsable_issues
                || !in_array($file_storage->file_path, $codebase->config->internal_stubs)
            )
        ) {
            /** @psalm-suppress InaccessibleProperty We just created this type */
            $storage->return_type->ignore_falsable_issues = true;
        }

        if ($stmt->returnsByRef() && $storage->return_type) {
            /** @psalm-suppress InaccessibleProperty We just created this type */
            $storage->return_type->by_ref = true;
        }

        $storage->return_type_description = $docblock_info->return_type_description;
    }

    private static function handleTaintFlow(
        FunctionDocblockComment $docblock_info,
        FunctionLikeStorage $storage,
    ): void {
        if ($docblock_info->flows) {
            foreach ($docblock_info->flows as $flow) {
                $path_type = 'arg';

                $fancy_path_regex = '/-\(([a-z\-]+)\)->/';

                if (preg_match($fancy_path_regex, $flow, $matches)) {
                    if (isset($matches[1])) {
                        $path_type = $matches[1];
                    }

                    $flow = (string) preg_replace($fancy_path_regex, '->', $flow);
                }

                $flow_parts = explode('->', $flow);

                if (isset($flow_parts[1]) && trim($flow_parts[1]) === 'return') {
                    $source_param_string = trim($flow_parts[0]);

                    if ($source_param_string[0] === '(' && str_ends_with($source_param_string, ')')) {
                        $source_params = preg_split('/, ?/', substr($source_param_string, 1, -1));
                        if ($source_params === false) {
                            throw new AssertionError(preg_last_error_msg());
                        }

                        foreach ($source_params as $source_param) {
                            $source_param = substr($source_param, 1);

                            foreach ($storage->params as $i => $param_storage) {
                                if ($param_storage->name === $source_param) {
                                    $storage->return_source_params[$i] = $path_type;
                                }
                            }
                        }
                    }
                }

                if (isset($flow_parts[0]) && str_starts_with(trim($flow_parts[0]), 'proxy')) {
                    $proxy_call = trim(substr($flow_parts[0], strlen('proxy')));
                    [$fully_qualified_name, $source_param_string] = explode('(', $proxy_call, 2);

                    if (!empty($fully_qualified_name) && !empty($source_param_string)) {
                        $source_params = preg_split('/, ?/', substr($source_param_string, 0, -1)) ?: [];
                        $call_params = [];
                        foreach ($source_params as $source_param) {
                            $source_param = substr($source_param, 1);

                            foreach ($storage->params as $i => $param_storage) {
                                if ($param_storage->name === $source_param) {
                                    $call_params[] = $i;
                                }
                            }
                        }

                        if ($storage->proxy_calls === null) {
                            $storage->proxy_calls = [];
                        }

                        $storage->proxy_calls[] = [
                            'fqn' => $fully_qualified_name,
                            'params' => $call_params,
                            'return' => isset($flow_parts[1]) && trim($flow_parts[1]) === 'return',
                        ];
                    }
                }
            }
        }
    }

    /**
     * @param array<string, TypeAlias> $type_aliases
     * @param array<string, non-empty-array<string, Union>> $function_template_types
     * @param array<string, non-empty-array<string, Union>> $class_template_types
     */
    private static function handleRemovedTaint(
        Codebase $codebase,
        PhpParser\Node\FunctionLike $stmt,
        Aliases $aliases,
        string $removed_taint,
        array $function_template_types,
        array $class_template_types,
        array $type_aliases,
        FunctionLikeStorage $storage,
        ?ClassLikeStorage $classlike_storage,
        string $cased_function_id,
        FileStorage $file_storage,
        FileScanner $file_scanner,
    ): void {
        try {
            [$fixed_type_tokens, $function_template_types] = self::getConditionalSanitizedTypeTokens(
                $removed_taint,
                $aliases,
                $function_template_types + $class_template_types,
                $type_aliases,
                $storage,
                $classlike_storage,
                $cased_function_id,
                $function_template_types,
            );

            $removed_taint = TypeParser::parseTokens(
                array_values($fixed_type_tokens),
                null,
                $function_template_types + $class_template_types,
                $type_aliases,
            );

            /** @psalm-suppress UnusedMethodCall */
            $removed_taint->queueClassLikesForScanning($codebase, $file_storage);

            $removed_taint_single = $removed_taint->getSingleAtomic();

            if (!$removed_taint_single instanceof TConditional) {
                throw new TypeParseTreeException('Escaped taint must be a conditional');
            }

            $storage->conditionally_removed_taints[] = $removed_taint;
        } catch (TypeParseTreeException $e) {
            $storage->docblock_issues[] = new InvalidDocblock(
                $e->getMessage() . ' in docblock for ' . $cased_function_id,
                new CodeLocation($file_scanner, $stmt, null, true),
            );
        }
    }

    /**
     * @param array<string, TypeAlias> $type_aliases
     * @param array<string, non-empty-array<string, Union>> $function_template_types
     * @param array<string, non-empty-array<string, Union>> $class_template_types
     */
    private static function handleAssertions(
        FunctionDocblockComment $docblock_info,
        FunctionLikeStorage $storage,
        Codebase $codebase,
        FileScanner $file_scanner,
        FileStorage $file_storage,
        Aliases $aliases,
        PhpParser\Node\FunctionLike $stmt,
        array $class_template_types,
        array $function_template_types,
        array $type_aliases,
        ?ClassLikeStorage $classlike_storage,
    ): void {
        if ($docblock_info->assertions) {
            $storage->assertions = [];

            foreach ($docblock_info->assertions as $assertion) {
                $assertion_type_parts = self::getAssertionParts(
                    $codebase,
                    $file_scanner,
                    $file_storage,
                    $aliases,
                    $stmt,
                    $storage,
                    $assertion['type'],
                    $class_template_types,
                    $function_template_types,
                    $type_aliases,
                    $classlike_storage && !$classlike_storage->is_trait ? $classlike_storage->name : null,
                );

                if (!$assertion_type_parts) {
                    continue;
                }

                foreach ($storage->params as $i => $param) {
                    if ($param->name === $assertion['param_name']) {
                        $storage->assertions[] = new Possibilities(
                            $i,
                            $assertion_type_parts,
                        );
                        continue 2;
                    }

                    if (str_starts_with($assertion['param_name'], $param->name.'->')) {
                        $storage->assertions[] = new Possibilities(
                            substr_replace($assertion['param_name'], (string) $i, 0, strlen($param->name)),
                            $assertion_type_parts,
                        );
                        continue 2;
                    }
                }

                $storage->assertions[] = new Possibilities(
                    (!str_contains($assertion['param_name'], '$') ? '$' : '') . $assertion['param_name'],
                    $assertion_type_parts,
                );
            }
        }

        if ($docblock_info->if_true_assertions) {
            $storage->if_true_assertions = [];

            foreach ($docblock_info->if_true_assertions as $assertion) {
                $assertion_type_parts = self::getAssertionParts(
                    $codebase,
                    $file_scanner,
                    $file_storage,
                    $aliases,
                    $stmt,
                    $storage,
                    $assertion['type'],
                    $class_template_types,
                    $function_template_types,
                    $type_aliases,
                    $classlike_storage && !$classlike_storage->is_trait ? $classlike_storage->name : null,
                );

                if (!$assertion_type_parts) {
                    continue;
                }

                foreach ($storage->params as $i => $param) {
                    if ($param->name === $assertion['param_name']) {
                        $storage->if_true_assertions[] = new Possibilities(
                            $i,
                            $assertion_type_parts,
                        );
                        continue 2;
                    }

                    if (str_starts_with($assertion['param_name'], $param->name.'->')) {
                        $storage->if_true_assertions[] = new Possibilities(
                            str_replace($param->name, (string) $i, $assertion['param_name']),
                            $assertion_type_parts,
                        );
                        continue 2;
                    }
                }

                $storage->if_true_assertions[] = new Possibilities(
                    (!str_contains($assertion['param_name'], '$') ? '$' : '') . $assertion['param_name'],
                    $assertion_type_parts,
                );
            }
        }

        if ($docblock_info->if_false_assertions) {
            $storage->if_false_assertions = [];

            foreach ($docblock_info->if_false_assertions as $assertion) {
                $assertion_type_parts = self::getAssertionParts(
                    $codebase,
                    $file_scanner,
                    $file_storage,
                    $aliases,
                    $stmt,
                    $storage,
                    $assertion['type'],
                    $class_template_types,
                    $function_template_types,
                    $type_aliases,
                    $classlike_storage && !$classlike_storage->is_trait ? $classlike_storage->name : null,
                );

                if (!$assertion_type_parts) {
                    continue;
                }

                foreach ($storage->params as $i => $param) {
                    if ($param->name === $assertion['param_name']) {
                        $storage->if_false_assertions[] = new Possibilities(
                            $i,
                            $assertion_type_parts,
                        );
                        continue 2;
                    }

                    if (str_starts_with($assertion['param_name'], $param->name.'->')) {
                        $storage->if_false_assertions[] = new Possibilities(
                            str_replace($param->name, (string) $i, $assertion['param_name']),
                            $assertion_type_parts,
                        );
                        continue 2;
                    }
                }

                $storage->if_false_assertions[] = new Possibilities(
                    (!str_contains($assertion['param_name'], '$') ? '$' : '') . $assertion['param_name'],
                    $assertion_type_parts,
                );
            }
        }
    }

    /**
     * @param array<string, TypeAlias> $type_aliases
     * @param array<string, array<string, Union>> $function_template_types
     * @param array<string, non-empty-array<string, Union>> $class_template_types
     * @param  array{name:string, type:string, line_number: int} $docblock_param_out
     */
    private static function handleParamOut(
        array $docblock_param_out,
        Aliases $aliases,
        array $function_template_types,
        array $class_template_types,
        array $type_aliases,
        string $cased_function_id,
        FileScanner $file_scanner,
        PhpParser\Node\FunctionLike $stmt,
        FunctionLikeStorage $storage,
        Codebase $codebase,
        FileStorage $file_storage,
    ): void {
        $param_name = substr($docblock_param_out['name'], 1);

        try {
            $out_type = TypeParser::parseTokens(
                TypeTokenizer::getFullyQualifiedTokens(
                    $docblock_param_out['type'],
                    $aliases,
                    $function_template_types + $class_template_types,
                    $type_aliases,
                ),
                null,
                $function_template_types + $class_template_types,
                $type_aliases,
            );
        } catch (TypeParseTreeException $e) {
            $storage->docblock_issues[] = new InvalidDocblock(
                $e->getMessage() . ' in docblock for ' . $cased_function_id,
                new CodeLocation($file_scanner, $stmt, null, true),
            );

            return;
        }

        /** @psalm-suppress UnusedMethodCall */
        $out_type->queueClassLikesForScanning(
            $codebase,
            $file_storage,
            $storage->template_types ?: [],
        );

        foreach ($storage->params as $param_storage) {
            if ($param_storage->name === $param_name) {
                $param_storage->out_type = $out_type;
            }
        }
    }

    /**
     * @param ?array<string, non-empty-array<string, Union>> $template_types
     * @param array<string, TypeAlias> $type_aliases
     * @return array<string, non-empty-array<string, Union>>
     */
    private static function handleTemplates(
        FunctionLikeStorage $storage,
        FunctionDocblockComment $docblock_info,
        Aliases $aliases,
        ?array $template_types,
        array $type_aliases,
        FileScanner $file_scanner,
        PhpParser\Node\FunctionLike $stmt,
        string $cased_function_id,
    ): array {
        $storage->template_types = [];

        foreach ($docblock_info->templates as $template_map) {
            $template_name = $template_map[0];

            if ($template_map[1] !== null && $template_map[2] !== null) {
                if (trim($template_map[2])) {
                    $type_string = $template_map[2];
                    try {
                        $type_string = CommentAnalyzer::splitDocLine($type_string)[0];
                    } catch (DocblockParseException $e) {
                        throw new DocblockParseException($type_string . ' is not a valid type: '.$e->getMessage());
                    }
                    $type_string = CommentAnalyzer::sanitizeDocblockType($type_string);
                    try {
                        $template_type = TypeParser::parseTokens(
                            TypeTokenizer::getFullyQualifiedTokens(
                                $type_string,
                                $aliases,
                                $storage->template_types + ($template_types ?: []),
                                $type_aliases,
                            ),
                            null,
                            $storage->template_types + ($template_types ?: []),
                            $type_aliases,
                        );
                    } catch (TypeParseTreeException $e) {
                        $storage->docblock_issues[] = new InvalidDocblock(
                            'Template ' . $template_name . ' has invalid as type - ' . $e->getMessage(),
                            new CodeLocation($file_scanner, $stmt, null, true),
                        );

                        $template_type = Type::getMixed();
                    }
                } else {
                    $storage->docblock_issues[] = new InvalidDocblock(
                        'Template ' . $template_name . ' missing as type',
                        new CodeLocation($file_scanner, $stmt, null, true),
                    );

                    $template_type = Type::getMixed();
                }
            } else {
                $template_type = Type::getMixed();
            }

            if (isset($template_types[$template_name])) {
                $storage->docblock_issues[] = new InvalidDocblock(
                    'Duplicate template param ' . $template_name . ' in docblock for '
                    . $cased_function_id,
                    new CodeLocation($file_scanner, $stmt, null, true),
                );
            } else {
                $storage->template_types[$template_name] = [
                    'fn-' . strtolower($cased_function_id) => $template_type,
                ];
            }
        }

        return array_merge($template_types ?: [], $storage->template_types);
    }

    private static function handleUnexpectedTags(
        FunctionDocblockComment $docblock_info,
        FunctionLikeStorage $storage,
        PhpParser\Node\FunctionLike $stmt,
        FileScanner $file_scanner,
        string $cased_function_id,
    ): void {
        foreach ($docblock_info->unexpected_tags as $tag => $details) {
            foreach ($details['lines'] as $line) {
                $tag_location = new CodeLocation(
                    $file_scanner,
                    $stmt,
                    null,
                    true,
                    null,
                    null,
                    $line,
                );

                $message = 'Docblock tag @' . $tag . ' is not recognized in the function docblock '
                    . 'for ' . $cased_function_id;

                if (isset($details['suggested_replacement'])) {
                    $message .= ', did you mean to use @' . $details['suggested_replacement'] . '?';
                }

                $storage->docblock_issues[] = new PossiblyInvalidDocblockTag($message, $tag_location);
            }
        }
    }
}
