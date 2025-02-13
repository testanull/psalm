<?php
namespace Psalm\Internal\Type;

use function array_filter;
use function count;
use function explode;
use function get_class;
use function implode;
use function is_string;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Analyzer\TraitAnalyzer;
use Psalm\Internal\Analyzer\TypeAnalyzer;
use Psalm\Issue\DocblockTypeContradiction;
use Psalm\Issue\ParadoxicalCondition;
use Psalm\Issue\PsalmInternalError;
use Psalm\Issue\RedundantCondition;
use Psalm\Issue\RedundantConditionGivenDocblockType;
use Psalm\Issue\TypeDoesNotContainNull;
use Psalm\Issue\TypeDoesNotContainType;
use Psalm\IssueBuffer;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Union;
use Psalm\Type\Atomic\ObjectLike;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TArrayKey;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TCallableArray;
use Psalm\Type\Atomic\TCallableObjectLikeArray;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TEmpty;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TList;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TNumeric;
use Psalm\Type\Atomic\TNumericString;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Atomic\TResource;
use Psalm\Type\Atomic\TScalar;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Atomic\TTrue;
use function strpos;
use function substr;
use Psalm\Issue\InvalidDocblock;

class AssertionReconciler extends \Psalm\Type\Reconciler
{
    /**
     * Reconciles types
     *
     * think of this as a set of functions e.g. empty(T), notEmpty(T), null(T), notNull(T) etc. where
     *  - empty(Object) => null,
     *  - empty(bool) => false,
     *  - notEmpty(Object|null) => Object,
     *  - notEmpty(Object|false) => Object
     *
     * @param   string[]            $suppressed_issues
     * @param   array<string, array<string, array{Type\Union}>> $template_type_map
     * @param-out   0|1|2   $failed_reconciliation
     */
    public static function reconcile(
        string $assertion,
        ?Union $existing_var_type,
        ?string $key,
        StatementsAnalyzer $statements_analyzer,
        bool $inside_loop,
        array $template_type_map,
        CodeLocation $code_location = null,
        array $suppressed_issues = [],
        ?int &$failed_reconciliation = 0
    ) : Union {
        $codebase = $statements_analyzer->getCodebase();

        $is_strict_equality = false;
        $is_loose_equality = false;
        $is_equality = false;
        $is_negation = false;
        $failed_reconciliation = 0;

        if ($assertion[0] === '!') {
            $assertion = substr($assertion, 1);
            $is_negation = true;
        }

        if ($assertion[0] === '=') {
            $assertion = substr($assertion, 1);
            $is_strict_equality = true;
            $is_equality = true;
        }

        if ($assertion[0] === '~') {
            $assertion = substr($assertion, 1);
            $is_loose_equality = true;
            $is_equality = true;
        }

        if ($existing_var_type === null
            && is_string($key)
            && $statements_analyzer->isSuperGlobal($key)
        ) {
            $existing_var_type = $statements_analyzer->getGlobalType($key);
        }

        if ($existing_var_type === null) {
            if (($assertion === 'isset' && !$is_negation)
                || ($assertion === 'empty' && $is_negation)
            ) {
                return Type::getMixed($inside_loop);
            }

            if ($assertion === 'array-key-exists' || $assertion === 'non-empty-countable') {
                return Type::getMixed();
            }

            if (!$is_negation && $assertion !== 'falsy' && $assertion !== 'empty') {
                if ($is_equality) {
                    $bracket_pos = strpos($assertion, '(');

                    if ($bracket_pos) {
                        $assertion = substr($assertion, 0, $bracket_pos);
                    }
                }

                try {
                    return Type::parseString($assertion, null, $template_type_map);
                } catch (\Exception $e) {
                    return Type::getMixed();
                }
            }

            return Type::getMixed();
        }

        $old_var_type_string = $existing_var_type->getId();

        if ($is_negation) {
            return NegatedAssertionReconciler::reconcile(
                $statements_analyzer,
                $assertion,
                $is_strict_equality,
                $is_loose_equality,
                $existing_var_type,
                $old_var_type_string,
                $key,
                $code_location,
                $suppressed_issues,
                $failed_reconciliation
            );
        }

        if ($assertion === 'mixed' && $existing_var_type->hasMixed()) {
            return $existing_var_type;
        }

        if ($assertion === 'isset') {
            $existing_var_type->removeType('null');

            if (empty($existing_var_type->getTypes())) {
                $failed_reconciliation = 2;

                // @todo - I think there's a better way to handle this, but for the moment
                // mixed will have to do.
                return Type::getMixed();
            }

            if ($existing_var_type->hasType('empty')) {
                $existing_var_type->removeType('empty');
                $existing_var_type->addType(new TMixed($inside_loop));
            }

            $existing_var_type->possibly_undefined = false;
            $existing_var_type->possibly_undefined_from_try = false;

            return $existing_var_type;
        }

        if ($assertion === 'array-key-exists') {
            $existing_var_type->possibly_undefined = false;

            return $existing_var_type;
        }

        if ($assertion === 'falsy' || $assertion === 'empty') {
            return self::reconcileFalsyOrEmpty(
                $assertion,
                $existing_var_type,
                $key,
                $code_location,
                $suppressed_issues,
                $failed_reconciliation
            );
        }

        if ($assertion === 'object' && !$existing_var_type->hasMixed()) {
            return self::reconcileObject(
                $existing_var_type,
                $key,
                $code_location,
                $suppressed_issues,
                $failed_reconciliation,
                $is_equality
            );
        }

        if ($assertion === 'callable' && !$existing_var_type->hasMixed()) {
            return self::reconcileCallable(
                $codebase,
                $existing_var_type,
                $key,
                $code_location,
                $suppressed_issues,
                $failed_reconciliation,
                $is_equality
            );
        }

        if ($assertion === 'iterable') {
            return self::reconcileIterable(
                $codebase,
                $existing_var_type,
                $key,
                $code_location,
                $suppressed_issues,
                $failed_reconciliation,
                $is_equality
            );
        }

        if ($assertion === 'array') {
            return self::reconcileArray(
                $existing_var_type,
                $key,
                $code_location,
                $suppressed_issues,
                $failed_reconciliation,
                $is_equality
            );
        }

        if ($assertion === 'Traversable') {
            return self::reconcileTraversable(
                $codebase,
                $existing_var_type,
                $key,
                $code_location,
                $suppressed_issues,
                $failed_reconciliation,
                $is_equality
            );
        }

        if ($assertion === 'countable') {
            return self::reconcileCountable(
                $codebase,
                $existing_var_type,
                $key,
                $code_location,
                $suppressed_issues,
                $failed_reconciliation,
                $is_equality
            );
        }

        if ($assertion === 'string-array-access') {
            return self::reconcileStringArrayAccess(
                $codebase,
                $existing_var_type,
                $key,
                $code_location,
                $suppressed_issues,
                $failed_reconciliation
            );
        }

        if ($assertion === 'int-or-string-array-access') {
            return self::reconcileIntArrayAccess(
                $codebase,
                $existing_var_type,
                $key,
                $code_location,
                $suppressed_issues,
                $failed_reconciliation
            );
        }

        if ($assertion === 'numeric' && !$existing_var_type->hasMixed()) {
            return self::reconcileNumeric(
                $existing_var_type,
                $key,
                $code_location,
                $suppressed_issues,
                $failed_reconciliation,
                $is_equality
            );
        }

        if ($assertion === 'scalar' && !$existing_var_type->hasMixed()) {
            return self::reconcileScalar(
                $existing_var_type,
                $key,
                $code_location,
                $suppressed_issues,
                $failed_reconciliation,
                $is_equality
            );
        }

        if ($assertion === 'bool' && !$existing_var_type->hasMixed()) {
            return self::reconcileBool(
                $existing_var_type,
                $key,
                $code_location,
                $suppressed_issues,
                $failed_reconciliation,
                $is_equality
            );
        }

        if ($assertion === 'string' && !$existing_var_type->hasMixed()) {
            return self::reconcileString(
                $existing_var_type,
                $key,
                $code_location,
                $suppressed_issues,
                $failed_reconciliation,
                $is_equality
            );
        }

        if ($assertion === 'non-empty-countable') {
            return self::reconcileNonEmptyCountable(
                $existing_var_type,
                $key,
                $code_location,
                $suppressed_issues,
                $failed_reconciliation,
                $is_equality
            );
        }

        if (substr($assertion, 0, 10) === 'hasmethod-') {
            return self::reconcileHasMethod(
                $codebase,
                substr($assertion, 10),
                $existing_var_type,
                $key,
                $code_location,
                $suppressed_issues,
                $failed_reconciliation
            );
        }

        if (($assertion === 'int' || $assertion === 'float')
            && $existing_var_type->from_calculation
            && $existing_var_type->hasInt()
        ) {
            if ($assertion === 'int') {
                return Type::getInt();
            }

            return Type::getFloat();
        }

        if (substr($assertion, 0, 4) === 'isa-') {
            $assertion = substr($assertion, 4);

            $allow_string_comparison = false;

            if (substr($assertion, 0, 7) === 'string-') {
                $assertion = substr($assertion, 7);
                $allow_string_comparison = true;
            }

            if ($existing_var_type->hasMixed()) {
                $type = new Type\Union([
                    new Type\Atomic\TNamedObject($assertion),
                ]);

                if ($allow_string_comparison) {
                    $type->addType(
                        new Type\Atomic\TClassString(
                            $assertion,
                            new Type\Atomic\TNamedObject($assertion)
                        )
                    );
                }

                return $type;
            }

            $existing_has_object = $existing_var_type->hasObjectType();
            $existing_has_string = $existing_var_type->hasString();

            if ($existing_has_object && !$existing_has_string) {
                $new_type = Type::parseString($assertion, null, $template_type_map);
            } elseif ($existing_has_string && !$existing_has_object) {
                if (!$allow_string_comparison && $code_location) {
                    if (IssueBuffer::accepts(
                        new TypeDoesNotContainType(
                            'Cannot allow string comparison to object for ' . $key,
                            $code_location
                        ),
                        $suppressed_issues
                    )) {
                        // fall through
                    }

                    $new_type = Type::getMixed();
                } else {
                    $new_type_has_interface_string = $codebase->interfaceExists($assertion);

                    $old_type_has_interface_string = false;

                    foreach ($existing_var_type->getTypes() as $existing_type_part) {
                        if ($existing_type_part instanceof TClassString
                            && $existing_type_part->as_type
                            && $codebase->interfaceExists($existing_type_part->as_type->value)
                        ) {
                            $old_type_has_interface_string = true;
                            break;
                        }
                    }

                    $new_type = Type::getClassString($assertion);

                    if ((
                        $new_type_has_interface_string
                            && !TypeAnalyzer::isContainedBy(
                                $codebase,
                                $existing_var_type,
                                $new_type
                            )
                        )
                        || (
                            $old_type_has_interface_string
                            && !TypeAnalyzer::isContainedBy(
                                $codebase,
                                $new_type,
                                $existing_var_type
                            )
                        )
                    ) {
                        $new_type_part = Atomic::create($assertion, null, $template_type_map);

                        $acceptable_atomic_types = [];

                        foreach ($existing_var_type->getTypes() as $existing_var_type_part) {
                            if (!$new_type_part instanceof TNamedObject
                                || !$existing_var_type_part instanceof TClassString
                            ) {
                                $acceptable_atomic_types = [];

                                break;
                            }

                            if (!$existing_var_type_part->as_type instanceof TNamedObject) {
                                $acceptable_atomic_types = [];

                                break;
                            }

                            $existing_var_type_part = $existing_var_type_part->as_type;

                            if (TypeAnalyzer::isAtomicContainedBy(
                                $codebase,
                                $existing_var_type_part,
                                $new_type_part
                            )) {
                                $acceptable_atomic_types[] = clone $existing_var_type_part;
                                continue;
                            }

                            if ($codebase->classExists($existing_var_type_part->value)
                                || $codebase->interfaceExists($existing_var_type_part->value)
                            ) {
                                $existing_var_type_part = clone $existing_var_type_part;
                                $existing_var_type_part->addIntersectionType($new_type_part);
                                $acceptable_atomic_types[] = $existing_var_type_part;
                            }
                        }

                        if (count($acceptable_atomic_types) === 1) {
                            return new Type\Union([
                                new TClassString('object', $acceptable_atomic_types[0]),
                            ]);
                        }
                    }
                }
            } else {
                $new_type = Type::getMixed();
            }
        } elseif (substr($assertion, 0, 9) === 'getclass-') {
            $assertion = substr($assertion, 9);
            $new_type = Type::parseString($assertion, null, $template_type_map);
        } else {
            $bracket_pos = strpos($assertion, '(');

            if ($bracket_pos) {
                return self::handleLiteralEquality(
                    $assertion,
                    $bracket_pos,
                    $is_loose_equality,
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    $code_location,
                    $suppressed_issues
                );
            }

            $new_type = Type::parseString($assertion, null, $template_type_map);
        }

        if ($existing_var_type->hasMixed()) {
            if ($is_loose_equality
                && $new_type->hasScalarType()
            ) {
                return $existing_var_type;
            }

            return $new_type;
        }

        return self::refine(
            $statements_analyzer,
            $assertion,
            $new_type,
            $existing_var_type,
            $template_type_map,
            $key,
            $code_location,
            $is_equality,
            $is_loose_equality,
            $suppressed_issues,
            $failed_reconciliation
        );
    }

    /**
     * @param 0|1|2         $failed_reconciliation
     * @param   string[]    $suppressed_issues
     * @param   array<string, array<string, array{Type\Union}>> $template_type_map
     * @param-out   0|1|2   $failed_reconciliation
     */
    private static function refine(
        StatementsAnalyzer $statements_analyzer,
        string $assertion,
        Union $new_type,
        Union $existing_var_type,
        array $template_type_map,
        ?string $key,
        ?CodeLocation $code_location,
        bool $is_equality,
        bool $is_loose_equality,
        array $suppressed_issues,
        int &$failed_reconciliation
    ) : Union {
        $codebase = $statements_analyzer->getCodebase();

        $old_var_type_string = $existing_var_type->getId();

        $new_type_has_interface = false;

        if ($new_type->hasObjectType()) {
            foreach ($new_type->getTypes() as $new_type_part) {
                if ($new_type_part instanceof TNamedObject &&
                    $codebase->interfaceExists($new_type_part->value)
                ) {
                    $new_type_has_interface = true;
                    break;
                }
            }
        }

        $old_type_has_interface = false;

        if ($existing_var_type->hasObjectType()) {
            foreach ($existing_var_type->getTypes() as $existing_type_part) {
                if ($existing_type_part instanceof TNamedObject &&
                    $codebase->interfaceExists($existing_type_part->value)
                ) {
                    $old_type_has_interface = true;
                    break;
                }
            }
        }

        try {
            if (strpos($assertion, '<') || strpos($assertion, '[')) {
                $new_type_union = Type::parseString($assertion);

                $new_type_part = \array_values($new_type_union->getTypes());
            } else {
                $new_type_part = Atomic::create($assertion, null, $template_type_map);
            }
        } catch (\Psalm\Exception\TypeParseTreeException $e) {
            $new_type_part = new TMixed();

            if ($code_location) {
                if (IssueBuffer::accepts(
                    new InvalidDocblock(
                        $assertion . ' cannot be used in an assertion',
                        $code_location
                    ),
                    $suppressed_issues
                )) {
                    // fall through
                }
            }
        }

        if ($new_type_part instanceof TNamedObject
            && ((
                $new_type_has_interface
                    && !TypeAnalyzer::isContainedBy(
                        $codebase,
                        $existing_var_type,
                        $new_type
                    )
                )
                || (
                    $old_type_has_interface
                    && !TypeAnalyzer::isContainedBy(
                        $codebase,
                        $new_type,
                        $existing_var_type
                    )
                ))
        ) {
            $acceptable_atomic_types = [];

            foreach ($existing_var_type->getTypes() as $existing_var_type_part) {
                if (TypeAnalyzer::isAtomicContainedBy(
                    $codebase,
                    $existing_var_type_part,
                    $new_type_part
                )) {
                    $acceptable_atomic_types[] = clone $existing_var_type_part;
                    continue;
                }

                if ($existing_var_type_part instanceof TNamedObject
                    && ($codebase->classExists($existing_var_type_part->value)
                        || $codebase->interfaceExists($existing_var_type_part->value))
                ) {
                    $existing_var_type_part = clone $existing_var_type_part;
                    $existing_var_type_part->addIntersectionType($new_type_part);
                    $acceptable_atomic_types[] = $existing_var_type_part;
                }
            }

            if ($acceptable_atomic_types) {
                return new Type\Union($acceptable_atomic_types);
            }
        } elseif (!$new_type->hasMixed()) {
            $has_match = true;

            if ($key
                && $code_location
                && $new_type->getId() === $existing_var_type->getId()
                && !$is_equality
                && (!($statements_analyzer->getSource()->getSource() instanceof TraitAnalyzer)
                    || ($key !== '$this'
                        && !($existing_var_type->hasLiteralClassString() && $new_type->hasLiteralClassString())))
            ) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    $assertion,
                    true,
                    $code_location,
                    $suppressed_issues
                );
            }

            $any_scalar_type_match_found = false;

            $new_type = self::filterTypeWithAnother(
                $codebase,
                $existing_var_type,
                $new_type,
                $template_type_map,
                $has_match,
                $any_scalar_type_match_found
            );

            if ($code_location
                && !$has_match
                && (!$is_loose_equality || !$any_scalar_type_match_found)
            ) {
                if ($assertion === 'null') {
                    if ($existing_var_type->from_docblock) {
                        if (IssueBuffer::accepts(
                            new DocblockTypeContradiction(
                                'Cannot resolve types for ' . $key . ' - docblock-defined type '
                                    . $existing_var_type . ' does not contain null',
                                $code_location
                            ),
                            $suppressed_issues
                        )) {
                            // fall through
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new TypeDoesNotContainNull(
                                'Cannot resolve types for ' . $key . ' - ' . $existing_var_type
                                    . ' does not contain null',
                                $code_location
                            ),
                            $suppressed_issues
                        )) {
                            // fall through
                        }
                    }
                } elseif (!($statements_analyzer->getSource()->getSource() instanceof TraitAnalyzer)
                    || ($key !== '$this'
                        && !($existing_var_type->hasLiteralClassString() && $new_type->hasLiteralClassString()))
                ) {
                    if ($existing_var_type->from_docblock) {
                        if (IssueBuffer::accepts(
                            new DocblockTypeContradiction(
                                'Cannot resolve types for ' . $key . ' - docblock-defined type '
                                    . $existing_var_type->getId() . ' does not contain ' . $new_type->getId(),
                                $code_location
                            ),
                            $suppressed_issues
                        )) {
                            // fall through
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new TypeDoesNotContainType(
                                'Cannot resolve types for ' . $key . ' - ' . $existing_var_type->getId() .
                                ' does not contain ' . $new_type->getId(),
                                $code_location
                            ),
                            $suppressed_issues
                        )) {
                            // fall through
                        }
                    }
                }

                $failed_reconciliation = 2;
            }
        }

        if ($existing_var_type->hasType($assertion)) {
            $atomic_type = clone $existing_var_type->getTypes()[$assertion];
            $atomic_type->from_docblock = false;

            return new Type\Union([$atomic_type]);
        }

        return $new_type;
    }

    /**
     * @param   string[]  $suppressed_issues
     * @param   0|1|2    $failed_reconciliation
     */
    private static function reconcileNonEmptyCountable(
        Union $existing_var_type,
        ?string $key,
        ?CodeLocation $code_location,
        array $suppressed_issues,
        int &$failed_reconciliation,
        bool $is_equality
    ) : Union {
        $old_var_type_string = $existing_var_type->getId();

        if ($existing_var_type->hasType('array')) {
            /** @psalm-suppress PossiblyUndefinedArrayOffset */
            $array_atomic_type = $existing_var_type->getTypes()['array'];
            $did_remove_type = false;

            if ($array_atomic_type instanceof TArray
                && !$array_atomic_type instanceof Type\Atomic\TNonEmptyArray
            ) {
                $did_remove_type = true;
                if ($array_atomic_type->getId() === 'array<empty, empty>') {
                    $existing_var_type->removeType('array');
                } else {
                    $existing_var_type->addType(
                        new Type\Atomic\TNonEmptyArray(
                            $array_atomic_type->type_params
                        )
                    );
                }
            } elseif ($array_atomic_type instanceof TList
                && !$array_atomic_type instanceof Type\Atomic\TNonEmptyList
            ) {
                $did_remove_type = true;
                $existing_var_type->addType(
                    new Type\Atomic\TNonEmptyList(
                        $array_atomic_type->type_param
                    )
                );
            } elseif ($array_atomic_type instanceof Type\Atomic\ObjectLike) {
                foreach ($array_atomic_type->properties as $property_type) {
                    if ($property_type->possibly_undefined) {
                        $did_remove_type = true;
                    }
                }
            }

            if (!$is_equality
                && !$existing_var_type->hasMixed()
                && (!$did_remove_type || empty($existing_var_type->getTypes()))
            ) {
                if ($key && $code_location) {
                    self::triggerIssueForImpossible(
                        $existing_var_type,
                        $old_var_type_string,
                        $key,
                        'non-empty-countable',
                        !$did_remove_type,
                        $code_location,
                        $suppressed_issues
                    );
                }
            }
        }

        return $existing_var_type;
    }

    /**
     * @param   string[]  $suppressed_issues
     * @param   0|1|2    $failed_reconciliation
     */
    private static function reconcileHasMethod(
        Codebase $codebase,
        string $method_name,
        Union $existing_var_type,
        ?string $key,
        ?CodeLocation $code_location,
        array $suppressed_issues,
        int &$failed_reconciliation
    ) : Union {
        $old_var_type_string = $existing_var_type->getId();
        $existing_var_atomic_types = $existing_var_type->getTypes();

        $object_types = [];
        $did_remove_type = false;

        foreach ($existing_var_atomic_types as $type) {
            if ($type instanceof TNamedObject
                && $codebase->classOrInterfaceExists($type->value)
            ) {
                $object_types[] = $type;

                if (!$codebase->methodExists($type->value . '::' . $method_name)) {
                    $match_found = false;

                    if ($type->extra_types) {
                        foreach ($type->extra_types as $extra_type) {
                            if ($extra_type instanceof TNamedObject
                                && $codebase->classOrInterfaceExists($extra_type->value)
                                && $codebase->methodExists($extra_type->value . '::' . $method_name)
                            ) {
                                $match_found = true;
                            } elseif ($extra_type instanceof Atomic\TObjectWithProperties) {
                                $match_found = true;

                                if (!isset($extra_type->methods[$method_name])) {
                                    $extra_type->methods[$method_name] = 'object::' . $method_name;
                                    $did_remove_type = true;
                                }
                            }
                        }
                    }

                    if (!$match_found) {
                        $obj = new Atomic\TObjectWithProperties(
                            [],
                            [$method_name => $type->value . '::' . $method_name]
                        );
                        $type->extra_types[$obj->getKey()] = $obj;
                        $did_remove_type = true;
                    }
                }
            } elseif ($type instanceof Atomic\TObjectWithProperties) {
                $object_types[] = $type;

                if (!isset($type->methods[$method_name])) {
                    $type->methods[$method_name] = 'object::' . $method_name;
                    $did_remove_type = true;
                }
            } elseif ($type instanceof TObject || $type instanceof TMixed) {
                $object_types[] = new Atomic\TObjectWithProperties(
                    [],
                    [$method_name =>  'object::' . $method_name]
                );
                $did_remove_type = true;
            } elseif ($type instanceof TString) {
                // we don’t know
                $object_types[] = $type;
                $did_remove_type = true;
            } elseif ($type instanceof TTemplateParam) {
                $object_types[] = $type;
                $did_remove_type = true;
            } else {
                $did_remove_type = true;
            }
        }

        if (!$object_types) {
            if ($key && $code_location) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    'object with method ' . $method_name,
                    !$did_remove_type,
                    $code_location,
                    $suppressed_issues
                );
            }
        }

        if ($object_types) {
            return new Type\Union($object_types);
        }

        $failed_reconciliation = 2;

        return Type::getMixed();
    }

    /**
     * @param   string[]  $suppressed_issues
     * @param   0|1|2    $failed_reconciliation
     */
    private static function reconcileString(
        Union $existing_var_type,
        ?string $key,
        ?CodeLocation $code_location,
        array $suppressed_issues,
        int &$failed_reconciliation,
        bool $is_equality
    ) : Union {
        $old_var_type_string = $existing_var_type->getId();
        $existing_var_atomic_types = $existing_var_type->getTypes();

        $string_types = [];
        $did_remove_type = false;

        foreach ($existing_var_atomic_types as $type) {
            if ($type instanceof TString) {
                $string_types[] = $type;

                if (get_class($type) === TString::class) {
                    $type->from_docblock = false;
                }
            } elseif ($type instanceof TCallable) {
                $string_types[] = new Type\Atomic\TCallableString;
                $did_remove_type = true;
            } elseif ($type instanceof TNumeric) {
                $string_types[] = new TNumericString;
                $did_remove_type = true;
            } elseif ($type instanceof TScalar || $type instanceof TArrayKey) {
                $string_types[] = new TString;
                $did_remove_type = true;
            } elseif ($type instanceof TTemplateParam) {
                if ($type->as->hasString() || $type->as->hasMixed()) {
                    $string_types[] = $type;
                }

                $did_remove_type = true;
            } else {
                $did_remove_type = true;
            }
        }

        if ((!$did_remove_type || !$string_types) && !$is_equality) {
            if ($key && $code_location) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    'string',
                    !$did_remove_type,
                    $code_location,
                    $suppressed_issues
                );
            }
        }

        if ($string_types) {
            return new Type\Union($string_types);
        }

        $failed_reconciliation = 2;

        return Type::getMixed();
    }

    /**
     * @param   string[]  $suppressed_issues
     * @param   0|1|2    $failed_reconciliation
     */
    private static function reconcileBool(
        Union $existing_var_type,
        ?string $key,
        ?CodeLocation $code_location,
        array $suppressed_issues,
        int &$failed_reconciliation,
        bool $is_equality
    ) : Union {
        $bool_types = [];
        $did_remove_type = false;

        $old_var_type_string = $existing_var_type->getId();
        $existing_var_atomic_types = $existing_var_type->getTypes();

        foreach ($existing_var_atomic_types as $type) {
            if ($type instanceof TBool) {
                $bool_types[] = $type;
                $type->from_docblock = false;
            } elseif ($type instanceof TScalar) {
                $bool_types[] = new TBool;
                $did_remove_type = true;
            } elseif ($type instanceof TTemplateParam) {
                if ($type->as->hasBool() || $type->as->hasMixed()) {
                    $bool_types[] = $type;
                }

                $did_remove_type = true;
            } else {
                $did_remove_type = true;
            }
        }

        if ((!$did_remove_type || !$bool_types) && !$is_equality) {
            if ($key && $code_location) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    'bool',
                    !$did_remove_type,
                    $code_location,
                    $suppressed_issues
                );
            }
        }

        if ($bool_types) {
            return new Type\Union($bool_types);
        }

        $failed_reconciliation = 2;

        return Type::getMixed();
    }

    /**
     * @param   string[]  $suppressed_issues
     * @param   0|1|2    $failed_reconciliation
     */
    private static function reconcileScalar(
        Union $existing_var_type,
        ?string $key,
        ?CodeLocation $code_location,
        array $suppressed_issues,
        int &$failed_reconciliation,
        bool $is_equality
    ) : Union {
        $scalar_types = [];
        $did_remove_type = false;

        $old_var_type_string = $existing_var_type->getId();
        $existing_var_atomic_types = $existing_var_type->getTypes();

        foreach ($existing_var_atomic_types as $type) {
            if ($type instanceof Scalar) {
                $scalar_types[] = $type;
            } elseif ($type instanceof TTemplateParam) {
                if ($type->as->hasScalarType() || $type->as->hasMixed()) {
                    $scalar_types[] = $type;
                }

                $did_remove_type = true;
            } else {
                $did_remove_type = true;
            }
        }

        if ((!$did_remove_type || !$scalar_types) && !$is_equality) {
            if ($key && $code_location) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    'scalar',
                    !$did_remove_type,
                    $code_location,
                    $suppressed_issues
                );
            }
        }

        if ($scalar_types) {
            return new Type\Union($scalar_types);
        }

        $failed_reconciliation = 2;

        return Type::getMixed();
    }

    /**
     * @param   string[]  $suppressed_issues
     * @param   0|1|2    $failed_reconciliation
     */
    private static function reconcileNumeric(
        Union $existing_var_type,
        ?string $key,
        ?CodeLocation $code_location,
        array $suppressed_issues,
        int &$failed_reconciliation,
        bool $is_equality
    ) : Union {
        $old_var_type_string = $existing_var_type->getId();

        $numeric_types = [];
        $did_remove_type = false;

        if ($existing_var_type->hasString()) {
            $did_remove_type = true;
            $existing_var_type->removeType('string');
            $existing_var_type->addType(new TNumericString);
        }

        foreach ($existing_var_type->getTypes() as $type) {
            if ($type instanceof TNumeric || $type instanceof TNumericString) {
                // this is a workaround for a possible issue running
                // is_numeric($a) && is_string($a)
                $did_remove_type = true;
                $numeric_types[] = $type;
            } elseif ($type->isNumericType()) {
                $numeric_types[] = $type;
            } elseif ($type instanceof TScalar) {
                $did_remove_type = true;
                $numeric_types[] = new TNumeric();
            } elseif ($type instanceof TTemplateParam) {
                $numeric_types[] = $type;
                $did_remove_type = true;
            } else {
                $did_remove_type = true;
            }
        }

        if ((!$did_remove_type || !$numeric_types) && !$is_equality) {
            if ($key && $code_location) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    'numeric',
                    !$did_remove_type,
                    $code_location,
                    $suppressed_issues
                );
            }
        }

        if ($numeric_types) {
            return new Type\Union($numeric_types);
        }

        $failed_reconciliation = 2;

        return Type::getMixed();
    }

    /**
     * @param   string[]  $suppressed_issues
     * @param   0|1|2    $failed_reconciliation
     */
    private static function reconcileObject(
        Union $existing_var_type,
        ?string $key,
        ?CodeLocation $code_location,
        array $suppressed_issues,
        int &$failed_reconciliation,
        bool $is_equality
    ) : Union {
        $old_var_type_string = $existing_var_type->getId();
        $existing_var_atomic_types = $existing_var_type->getTypes();

        $object_types = [];
        $did_remove_type = false;

        foreach ($existing_var_atomic_types as $type) {
            if ($type->isObjectType()) {
                $object_types[] = $type;
            } elseif ($type instanceof TCallable) {
                $object_types[] = new Type\Atomic\TCallableObject();
                $did_remove_type = true;
            } elseif ($type instanceof TTemplateParam) {
                $object_types[] = $type;
                $did_remove_type = true;
            } else {
                $did_remove_type = true;
            }
        }

        if ((!$object_types || !$did_remove_type) && !$is_equality) {
            if ($key && $code_location) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    'object',
                    !$did_remove_type,
                    $code_location,
                    $suppressed_issues
                );
            }
        }

        if ($object_types) {
            return new Type\Union($object_types);
        }

        $failed_reconciliation = 2;

        return Type::getMixed();
    }

    /**
     * @param   string[]  $suppressed_issues
     * @param   0|1|2    $failed_reconciliation
     */
    private static function reconcileCountable(
        Codebase $codebase,
        Union $existing_var_type,
        ?string $key,
        ?CodeLocation $code_location,
        array $suppressed_issues,
        int &$failed_reconciliation,
        bool $is_equality
    ) : Union {
        $old_var_type_string = $existing_var_type->getId();

        $existing_var_atomic_types = $existing_var_type->getTypes();


        if ($existing_var_type->hasMixed() || $existing_var_type->hasTemplate()) {
            return new Type\Union([
                new Type\Atomic\TArray([Type::getArrayKey(), Type::getMixed()]),
                new Type\Atomic\TNamedObject('Countable'),
            ]);
        }

        $iterable_types = [];
        $did_remove_type = false;

        foreach ($existing_var_atomic_types as $type) {
            if ($type->isCountable($codebase)) {
                $iterable_types[] = $type;
            } elseif ($type instanceof TObject) {
                $iterable_types[] = new TNamedObject('Countable');
                $did_remove_type = true;
            } elseif ($type instanceof TNamedObject || $type instanceof Type\Atomic\TIterable) {
                $countable = new TNamedObject('Countable');
                $type->extra_types[$countable->getKey()] = $countable;
                $iterable_types[] = $type;
                $did_remove_type = true;
            } else {
                $did_remove_type = true;
            }
        }

        if ((!$iterable_types || !$did_remove_type) && !$is_equality) {
            if ($key && $code_location) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    'countable',
                    !$did_remove_type,
                    $code_location,
                    $suppressed_issues
                );
            }
        }

        if ($iterable_types) {
            return new Type\Union($iterable_types);
        }

        $failed_reconciliation = 2;

        return Type::getMixed();
    }

    /**
     * @param   string[]  $suppressed_issues
     * @param   0|1|2    $failed_reconciliation
     */
    private static function reconcileIterable(
        Codebase $codebase,
        Union $existing_var_type,
        ?string $key,
        ?CodeLocation $code_location,
        array $suppressed_issues,
        int &$failed_reconciliation,
        bool $is_equality
    ) : Union {
        $old_var_type_string = $existing_var_type->getId();

        $existing_var_atomic_types = $existing_var_type->getTypes();

        if ($existing_var_type->hasMixed() || $existing_var_type->hasTemplate()) {
            return new Type\Union([new Type\Atomic\TIterable]);
        }

        $iterable_types = [];
        $did_remove_type = false;

        foreach ($existing_var_atomic_types as $type) {
            if ($type->isIterable($codebase)) {
                $iterable_types[] = $type;
            } elseif ($type instanceof TObject) {
                $iterable_types[] = new Type\Atomic\TNamedObject('Traversable');
                $did_remove_type = true;
            } else {
                $did_remove_type = true;
            }
        }

        if ((!$iterable_types || !$did_remove_type) && !$is_equality) {
            if ($key && $code_location) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    'iterable',
                    !$did_remove_type,
                    $code_location,
                    $suppressed_issues
                );
            }
        }

        if ($iterable_types) {
            return new Type\Union($iterable_types);
        }

        $failed_reconciliation = 2;

        return Type::getMixed();
    }

    /**
     * @param   string[]  $suppressed_issues
     * @param   0|1|2    $failed_reconciliation
     */
    private static function reconcileTraversable(
        Codebase $codebase,
        Union $existing_var_type,
        ?string $key,
        ?CodeLocation $code_location,
        array $suppressed_issues,
        int &$failed_reconciliation,
        bool $is_equality
    ) : Union {
        $old_var_type_string = $existing_var_type->getId();

        $existing_var_atomic_types = $existing_var_type->getTypes();

        if ($existing_var_type->hasMixed() || $existing_var_type->hasTemplate()) {
            return new Type\Union([new Type\Atomic\TNamedObject('Traversable')]);
        }

        $traversable_types = [];
        $did_remove_type = false;

        foreach ($existing_var_atomic_types as $type) {
            if ($type->hasTraversableInterface($codebase)) {
                $traversable_types[] = $type;
            } elseif ($type instanceof Atomic\TIterable) {
                if ($type->type_params) {
                    $clone_type = clone $type;
                    $traversable_types[] = new Atomic\TGenericObject('Traversable', $clone_type->type_params);
                } else {
                    $traversable_types[] = new Atomic\TNamedObject('Traversable');
                }
                $did_remove_type = true;
            } elseif ($type instanceof TObject) {
                $traversable_types[] = new TNamedObject('Traversable');
                $did_remove_type = true;
            } elseif ($type instanceof TNamedObject) {
                $traversable = new TNamedObject('Traversable');
                $type->extra_types[$traversable->getKey()] = $traversable;
                $traversable_types[] = $type;
                $did_remove_type = true;
            } else {
                $did_remove_type = true;
            }
        }

        if ((!$traversable_types || !$did_remove_type) && !$is_equality) {
            if ($key && $code_location) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    'Traversable',
                    !$did_remove_type,
                    $code_location,
                    $suppressed_issues
                );
            }
        }

        if ($traversable_types) {
            return new Type\Union($traversable_types);
        }

        $failed_reconciliation = 2;

        return Type::getMixed();
    }

    /**
     * @param   string[]  $suppressed_issues
     * @param   0|1|2    $failed_reconciliation
     */
    private static function reconcileArray(
        Union $existing_var_type,
        ?string $key,
        ?CodeLocation $code_location,
        array $suppressed_issues,
        int &$failed_reconciliation,
        bool $is_equality
    ) : Union {
        $old_var_type_string = $existing_var_type->getId();

        $existing_var_atomic_types = $existing_var_type->getTypes();

        if ($existing_var_type->hasMixed() || $existing_var_type->hasTemplate()) {
            return Type::getArray();
        }

        $array_types = [];
        $did_remove_type = false;

        foreach ($existing_var_atomic_types as $type) {
            if ($type instanceof TArray || $type instanceof ObjectLike) {
                $array_types[] = $type;
            } elseif ($type instanceof TCallable) {
                $array_types[] = new TCallableObjectLikeArray([
                    new Union([new TString, new TObject]),
                    Type::getString()
                ]);

                $did_remove_type = true;
            } elseif ($type instanceof Atomic\TIterable) {
                if ($type->type_params) {
                    $clone_type = clone $type;
                    $array_types[] = new TArray($clone_type->type_params);
                } else {
                    $array_types[] = new TArray([Type::getArrayKey(), Type::getMixed()]);
                }

                $did_remove_type = true;
            } else {
                $did_remove_type = true;
            }
        }

        if ((!$array_types || !$did_remove_type) && !$is_equality) {
            if ($key && $code_location) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    'array',
                    !$did_remove_type,
                    $code_location,
                    $suppressed_issues
                );

                if (!$did_remove_type) {
                    $failed_reconciliation = 1;
                }
            }
        }

        if ($array_types) {
            return new Type\Union($array_types);
        }

        $failed_reconciliation = 2;

        return Type::getMixed();
    }

    /**
     * @param   string[]  $suppressed_issues
     * @param   0|1|2    $failed_reconciliation
     */
    private static function reconcileStringArrayAccess(
        Codebase $codebase,
        Union $existing_var_type,
        ?string $key,
        ?CodeLocation $code_location,
        array $suppressed_issues,
        int &$failed_reconciliation
    ) : Union {
        $old_var_type_string = $existing_var_type->getId();

        $existing_var_atomic_types = $existing_var_type->getTypes();

        if ($existing_var_type->hasMixed() || $existing_var_type->hasTemplate()) {
            return new Union([
                new Atomic\TNonEmptyArray([Type::getArrayKey(), Type::getMixed()]),
                new TNamedObject('ArrayAccess'),
            ]);
        }

        $array_types = [];

        foreach ($existing_var_atomic_types as $type) {
            if ($type->isArrayAccessibleWithStringKey($codebase)) {
                if (get_class($type) === TArray::class) {
                    $array_types[] = new Atomic\TNonEmptyArray($type->type_params);
                } elseif (get_class($type) === TList::class) {
                    $array_types[] = new Atomic\TNonEmptyList($type->type_param);
                } else {
                    $array_types[] = $type;
                }
            } elseif ($type instanceof TTemplateParam) {
                $array_types[] = $type;
            }
        }

        if (!$array_types) {
            if ($key && $code_location) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    'string-array-access',
                    true,
                    $code_location,
                    $suppressed_issues
                );
            }
        }

        if ($array_types) {
            return new Type\Union($array_types);
        }

        $failed_reconciliation = 2;

        return Type::getMixed();
    }

    /**
     * @param   string[]  $suppressed_issues
     * @param   0|1|2    $failed_reconciliation
     */
    private static function reconcileIntArrayAccess(
        Codebase $codebase,
        Union $existing_var_type,
        ?string $key,
        ?CodeLocation $code_location,
        array $suppressed_issues,
        int &$failed_reconciliation
    ) : Union {
        $old_var_type_string = $existing_var_type->getId();

        $existing_var_atomic_types = $existing_var_type->getTypes();

        if ($existing_var_type->hasMixed()) {
            return Type::getMixed();
        }

        $array_types = [];

        foreach ($existing_var_atomic_types as $type) {
            if ($type->isArrayAccessibleWithIntOrStringKey($codebase)) {
                if (get_class($type) === TArray::class) {
                    $array_types[] = new Atomic\TNonEmptyArray($type->type_params);
                } else {
                    $array_types[] = $type;
                }
            } elseif ($type instanceof TTemplateParam) {
                $array_types[] = $type;
            }
        }

        if (!$array_types) {
            if ($key && $code_location) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    'int-or-string-array-access',
                    true,
                    $code_location,
                    $suppressed_issues
                );
            }
        }

        if ($array_types) {
            return new Type\Union($array_types);
        }

        $failed_reconciliation = 2;

        return Type::getMixed();
    }

    /**
     * @param   string[]  $suppressed_issues
     * @param   0|1|2    $failed_reconciliation
     */
    private static function reconcileCallable(
        Codebase $codebase,
        Union $existing_var_type,
        ?string $key,
        ?CodeLocation $code_location,
        array $suppressed_issues,
        int &$failed_reconciliation,
        bool $is_equality
    ) : Union {
        $old_var_type_string = $existing_var_type->getId();

        $existing_var_atomic_types = $existing_var_type->getTypes();

        $callable_types = [];
        $did_remove_type = false;

        foreach ($existing_var_atomic_types as $type) {
            if ($type->isCallableType()) {
                $callable_types[] = $type;
            } elseif ($type instanceof TObject) {
                $callable_types[] = new Type\Atomic\TCallableObject();
                $did_remove_type = true;
            } elseif ($type instanceof TNamedObject
                && $codebase->classExists($type->value)
                && $codebase->methodExists($type->value . '::__invoke')
            ) {
                $callable_types[] = $type;
            } elseif (get_class($type) === TString::class) {
                $callable_types[] = new Type\Atomic\TCallableString();
                $did_remove_type = true;
            } elseif (get_class($type) === Type\Atomic\TLiteralString::class
                && \Psalm\Internal\Codebase\CallMap::inCallMap($type->value)
            ) {
                $callable_types[] = $type;
                $did_remove_type = true;
            } elseif ($type instanceof TArray) {
                $type = clone $type;
                $type = new TCallableArray($type->type_params);
                $callable_types[] = $type;
                $did_remove_type = true;
            } elseif ($type instanceof ObjectLike) {
                $type = clone $type;
                $type = new TCallableObjectLikeArray($type->properties);
                $callable_types[] = $type;
                $did_remove_type = true;
            } elseif ($type instanceof TTemplateParam) {
                $callable_types[] = $type;
                $did_remove_type = true;
            } else {
                $did_remove_type = true;
            }
        }

        if ((!$callable_types || !$did_remove_type) && !$is_equality) {
            if ($key && $code_location) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    'callable',
                    !$did_remove_type,
                    $code_location,
                    $suppressed_issues
                );
            }
        }

        if ($callable_types) {
            return new Type\Union($callable_types);
        }

        $failed_reconciliation = 2;

        return Type::getMixed();
    }

    /**
     * @param   string[]  $suppressed_issues
     * @param   0|1|2    $failed_reconciliation
     */
    private static function reconcileFalsyOrEmpty(
        string $assertion,
        Union $existing_var_type,
        ?string $key,
        ?CodeLocation $code_location,
        array $suppressed_issues,
        int &$failed_reconciliation
    ) : Union {
        $old_var_type_string = $existing_var_type->getId();

        $existing_var_atomic_types = $existing_var_type->getTypes();

        $did_remove_type = $existing_var_type->hasDefinitelyNumericType(false)
            || $existing_var_type->hasType('iterable');


        if ($existing_var_type->hasMixed()) {
            if ($existing_var_type->isMixed()
                && $existing_var_atomic_types['mixed'] instanceof Type\Atomic\TNonEmptyMixed
            ) {
                if ($code_location
                    && $key
                    && IssueBuffer::accepts(
                        new ParadoxicalCondition(
                            'Found a paradox when evaluating ' . $key
                                . ' of type ' . $existing_var_type->getId()
                                . ' and trying to reconcile it with a ' . $assertion . ' assertion',
                            $code_location
                        ),
                        $suppressed_issues
                    )
                ) {
                    // fall through
                }

                return Type::getMixed();
            }

            if (!$existing_var_atomic_types['mixed'] instanceof Type\Atomic\TEmptyMixed) {
                $did_remove_type = true;
                $existing_var_type->removeType('mixed');

                if (!$existing_var_atomic_types['mixed'] instanceof Type\Atomic\TNonEmptyMixed) {
                    $existing_var_type->addType(new Type\Atomic\TEmptyMixed);
                }
            } elseif ($existing_var_type->isMixed()) {
                if ($code_location
                    && $key
                    && IssueBuffer::accepts(
                        new RedundantCondition(
                            'Found a redundant condition when evaluating ' . $key
                                . ' of type ' . $existing_var_type->getId()
                                . ' and trying to reconcile it with a ' . $assertion . ' assertion',
                            $code_location
                        ),
                        $suppressed_issues
                    )
                ) {
                    // fall through
                }
            }

            if ($existing_var_type->isMixed()) {
                return $existing_var_type;
            }
        }

        if ($existing_var_type->hasType('bool')) {
            $did_remove_type = true;
            $existing_var_type->removeType('bool');
            $existing_var_type->addType(new TFalse);
        }

        if ($existing_var_type->hasType('true')) {
            $did_remove_type = true;
            $existing_var_type->removeType('true');
        }

        if ($existing_var_type->hasString()) {
            $existing_string_types = $existing_var_type->getLiteralStrings();

            if ($existing_string_types) {
                foreach ($existing_string_types as $string_key => $literal_type) {
                    if ($literal_type->value) {
                        $existing_var_type->removeType($string_key);
                        $did_remove_type = true;
                    }
                }
            } else {
                $did_remove_type = true;
                if ($existing_var_type->hasType('class-string')) {
                    $existing_var_type->removeType('class-string');
                }

                if ($existing_var_type->hasType('callable-string')) {
                    $existing_var_type->removeType('callable-string');
                }

                if ($existing_var_type->hasType('string')) {
                    $existing_var_type->removeType('string');
                    $existing_var_type->addType(new Type\Atomic\TLiteralString(''));
                    $existing_var_type->addType(new Type\Atomic\TLiteralString('0'));
                }
            }
        }

        if ($existing_var_type->hasInt()) {
            $existing_int_types = $existing_var_type->getLiteralInts();

            if ($existing_int_types) {
                foreach ($existing_int_types as $int_key => $literal_type) {
                    if ($literal_type->value) {
                        $existing_var_type->removeType($int_key);
                        $did_remove_type = true;
                    }
                }
            } else {
                $did_remove_type = true;
                $existing_var_type->removeType('int');
                $existing_var_type->addType(new Type\Atomic\TLiteralInt(0));
            }
        }

        if ($existing_var_type->hasFloat()) {
            $existing_float_types = $existing_var_type->getLiteralFloats();

            if ($existing_float_types) {
                foreach ($existing_float_types as $float_key => $literal_type) {
                    if ($literal_type->value) {
                        $existing_var_type->removeType($float_key);
                        $did_remove_type = true;
                    }
                }
            } else {
                $did_remove_type = true;
                $existing_var_type->removeType('float');
                $existing_var_type->addType(new Type\Atomic\TLiteralFloat(0));
            }
        }

        if (isset($existing_var_atomic_types['array'])) {
            $array_atomic_type = $existing_var_atomic_types['array'];

            if ($array_atomic_type instanceof Type\Atomic\TNonEmptyArray
                || $array_atomic_type instanceof Type\Atomic\TNonEmptyList
                || ($array_atomic_type instanceof Type\Atomic\ObjectLike
                    && array_filter(
                        $array_atomic_type->properties,
                        function (Type\Union $t) {
                            return !$t->possibly_undefined;
                        }
                    ))
            ) {
                $did_remove_type = true;

                $existing_var_type->removeType('array');
            } elseif ($array_atomic_type->getId() !== 'array<empty, empty>') {
                $did_remove_type = true;

                $existing_var_type->addType(new TArray(
                    [
                        new Type\Union([new TEmpty]),
                        new Type\Union([new TEmpty]),
                    ]
                ));
            }
        }

        if (isset($existing_var_atomic_types['scalar'])
            && $existing_var_atomic_types['scalar']->getId() !== 'empty-scalar'
        ) {
            $did_remove_type = true;
            $existing_var_type->addType(new Type\Atomic\TEmptyScalar);
        }

        foreach ($existing_var_atomic_types as $type_key => $type) {
            if ($type instanceof TNamedObject
                || $type instanceof TObject
                || $type instanceof TResource
                || $type instanceof TCallable
                || $type instanceof TClassString
            ) {
                $did_remove_type = true;

                $existing_var_type->removeType($type_key);
            }

            if ($type instanceof TTemplateParam) {
                $did_remove_type = true;
            }
        }

        if (!$did_remove_type || empty($existing_var_type->getTypes())) {
            if ($key && $code_location) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    $assertion,
                    !$did_remove_type,
                    $code_location,
                    $suppressed_issues
                );
            }
        }

        if ($existing_var_type->getTypes()) {
            return $existing_var_type;
        }

        $failed_reconciliation = 2;

        return Type::getMixed();
    }

    /**
     * @param array<string, array<string, array{0:Type\Union, 1?: int}>> $template_type_map
     */
    private static function filterTypeWithAnother(
        Codebase $codebase,
        Type\Union $existing_type,
        Type\Union $new_type,
        array $template_type_map,
        bool &$has_match,
        bool &$any_scalar_type_match_found
    ) : Type\Union {
        $matching_atomic_types = [];

        foreach ($new_type->getTypes() as $new_type_part) {
            $has_local_match = false;

            foreach ($existing_type->getTypes() as $existing_type_part) {
                // special workaround because PHP allows floats to contain ints, but we don’t want this
                // behaviour here
                if ($existing_type_part instanceof Type\Atomic\TFloat
                    && $new_type_part instanceof Type\Atomic\TInt
                ) {
                    $any_scalar_type_match_found = true;
                    continue;
                }

                $atomic_comparison_results = new \Psalm\Internal\Analyzer\TypeComparisonResult();

                $atomic_contained_by = TypeAnalyzer::isAtomicContainedBy(
                    $codebase,
                    $new_type_part,
                    $existing_type_part,
                    true,
                    false,
                    $atomic_comparison_results
                );

                if ($atomic_contained_by || $atomic_comparison_results->type_coerced) {
                    $has_local_match = true;
                    if ($atomic_comparison_results->type_coerced) {
                        $matching_atomic_types[] = $existing_type_part;
                    }
                }

                if (($new_type_part instanceof Type\Atomic\TGenericObject
                        || $new_type_part instanceof Type\Atomic\TArray
                        || $new_type_part instanceof Type\Atomic\TIterable)
                    && ($existing_type_part instanceof Type\Atomic\TGenericObject
                        || $existing_type_part instanceof Type\Atomic\TArray
                        || $existing_type_part instanceof Type\Atomic\TIterable)
                    && count($new_type_part->type_params) === count($existing_type_part->type_params)
                ) {
                    $has_any_param_match = false;

                    foreach ($new_type_part->type_params as $i => $new_param) {
                        $existing_param = $existing_type_part->type_params[$i];

                        $has_param_match = true;

                        $new_param = self::filterTypeWithAnother(
                            $codebase,
                            $existing_param,
                            $new_param,
                            $template_type_map,
                            $has_param_match,
                            $any_scalar_type_match_found
                        );

                        if ($template_type_map) {
                            $new_param->replaceTemplateTypesWithArgTypes(
                                $template_type_map,
                                $codebase
                            );
                        }

                        if ($has_param_match
                            && $existing_type_part->type_params[$i]->getId() !== $new_param->getId()
                        ) {
                            $existing_type_part->type_params[$i] = $new_param;

                            if (!$has_local_match) {
                                $has_any_param_match = true;
                            }
                        }
                    }

                    if ($has_any_param_match) {
                        $has_local_match = true;
                        $matching_atomic_types[] = $existing_type_part;
                        $atomic_comparison_results->type_coerced = true;
                    }
                }

                if ($atomic_contained_by || $atomic_comparison_results->type_coerced) {
                    continue;
                }

                if ($atomic_comparison_results->scalar_type_match_found) {
                    $any_scalar_type_match_found = true;
                }
            }

            if (!$has_local_match) {
                $has_match = false;
                break;
            }
        }

        if ($matching_atomic_types) {
            return new Type\Union($matching_atomic_types);
        }

        return $new_type;
    }

    /**
* @param  string[]   $suppressed_issues
     */
    private static function handleLiteralEquality(
        string $assertion,
        int $bracket_pos,
        bool $is_loose_equality,
        Type\Union $existing_var_type,
        string $old_var_type_string,
        ?string $var_id,
        ?CodeLocation $code_location,
        array $suppressed_issues
    ) : Type\Union {
        $value = substr($assertion, $bracket_pos + 1, -1);

        $scalar_type = substr($assertion, 0, $bracket_pos);

        $existing_var_atomic_types = $existing_var_type->getTypes();

        if ($scalar_type === 'int') {
            $value = (int) $value;

            if ($existing_var_type->hasMixed() || $existing_var_type->hasScalar() || $existing_var_type->hasNumeric()) {
                if ($is_loose_equality) {
                    return $existing_var_type;
                }

                return new Type\Union([new Type\Atomic\TLiteralInt($value)]);
            }

            if ($existing_var_type->hasInt()) {
                $existing_int_types = $existing_var_type->getLiteralInts();

                if ($existing_int_types) {
                    $can_be_equal = false;
                    $did_remove_type = false;

                    foreach ($existing_var_atomic_types as $atomic_key => $_) {
                        if ($atomic_key !== $assertion) {
                            $existing_var_type->removeType($atomic_key);
                            $did_remove_type = true;
                        } else {
                            $can_be_equal = true;
                        }
                    }

                    if ($var_id
                        && $code_location
                        && (!$can_be_equal || (!$did_remove_type && count($existing_var_atomic_types) === 1))
                    ) {
                        self::triggerIssueForImpossible(
                            $existing_var_type,
                            $old_var_type_string,
                            $var_id,
                            $assertion,
                            $can_be_equal,
                            $code_location,
                            $suppressed_issues
                        );
                    }
                } else {
                    $existing_var_type = new Type\Union([new Type\Atomic\TLiteralInt($value)]);
                }
            } elseif ($var_id && $code_location && !$is_loose_equality) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $var_id,
                    $assertion,
                    false,
                    $code_location,
                    $suppressed_issues
                );
            } elseif ($is_loose_equality && $existing_var_type->hasFloat()) {
                // convert floats to ints
                $existing_float_types = $existing_var_type->getLiteralFloats();

                if ($existing_float_types) {
                    $can_be_equal = false;
                    $did_remove_type = false;

                    foreach ($existing_var_atomic_types as $atomic_key => $_) {
                        if (substr($atomic_key, 0, 6) === 'float(') {
                            $atomic_key = 'int(' . substr($atomic_key, 6);
                        }
                        if ($atomic_key !== $assertion) {
                            $existing_var_type->removeType($atomic_key);
                            $did_remove_type = true;
                        } else {
                            $can_be_equal = true;
                        }
                    }

                    if ($var_id
                        && $code_location
                        && (!$can_be_equal || (!$did_remove_type && count($existing_var_atomic_types) === 1))
                    ) {
                        self::triggerIssueForImpossible(
                            $existing_var_type,
                            $old_var_type_string,
                            $var_id,
                            $assertion,
                            $can_be_equal,
                            $code_location,
                            $suppressed_issues
                        );
                    }
                }
            }
        } elseif ($scalar_type === 'string'
            || $scalar_type === 'class-string'
            || $scalar_type === 'interface-string'
            || $scalar_type === 'callable-string'
            || $scalar_type === 'trait-string'
        ) {
            if ($existing_var_type->hasMixed() || $existing_var_type->hasScalar()) {
                if ($is_loose_equality) {
                    return $existing_var_type;
                }

                if ($scalar_type === 'class-string'
                    || $scalar_type === 'interface-string'
                    || $scalar_type === 'trait-string'
                ) {
                    return new Type\Union([new Type\Atomic\TLiteralClassString($value)]);
                }

                return new Type\Union([new Type\Atomic\TLiteralString($value)]);
            }

            if ($existing_var_type->hasString()) {
                $existing_string_types = $existing_var_type->getLiteralStrings();

                if ($existing_string_types) {
                    $can_be_equal = false;
                    $did_remove_type = false;

                    foreach ($existing_var_atomic_types as $atomic_key => $_) {
                        if ($atomic_key !== $assertion) {
                            $existing_var_type->removeType($atomic_key);
                            $did_remove_type = true;
                        } else {
                            $can_be_equal = true;
                        }
                    }

                    if ($var_id
                        && $code_location
                        && (!$can_be_equal || (!$did_remove_type && count($existing_var_atomic_types) === 1))
                    ) {
                        self::triggerIssueForImpossible(
                            $existing_var_type,
                            $old_var_type_string,
                            $var_id,
                            $assertion,
                            $can_be_equal,
                            $code_location,
                            $suppressed_issues
                        );
                    }
                } else {
                    if ($scalar_type === 'class-string'
                        || $scalar_type === 'interface-string'
                        || $scalar_type === 'trait-string'
                    ) {
                        $existing_var_type = new Type\Union([new Type\Atomic\TLiteralClassString($value)]);
                    } else {
                        $existing_var_type = new Type\Union([new Type\Atomic\TLiteralString($value)]);
                    }
                }
            } elseif ($var_id && $code_location && !$is_loose_equality) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $var_id,
                    $assertion,
                    false,
                    $code_location,
                    $suppressed_issues
                );
            }
        } elseif ($scalar_type === 'float') {
            $value = (float) $value;

            if ($existing_var_type->hasMixed() || $existing_var_type->hasScalar() || $existing_var_type->hasNumeric()) {
                if ($is_loose_equality) {
                    return $existing_var_type;
                }

                return new Type\Union([new Type\Atomic\TLiteralFloat($value)]);
            }

            if ($existing_var_type->hasFloat()) {
                $existing_float_types = $existing_var_type->getLiteralFloats();

                if ($existing_float_types) {
                    $can_be_equal = false;
                    $did_remove_type = false;

                    foreach ($existing_var_atomic_types as $atomic_key => $_) {
                        if ($atomic_key !== $assertion) {
                            $existing_var_type->removeType($atomic_key);
                            $did_remove_type = true;
                        } else {
                            $can_be_equal = true;
                        }
                    }

                    if ($var_id
                        && $code_location
                        && (!$can_be_equal || (!$did_remove_type && count($existing_var_atomic_types) === 1))
                    ) {
                        self::triggerIssueForImpossible(
                            $existing_var_type,
                            $old_var_type_string,
                            $var_id,
                            $assertion,
                            $can_be_equal,
                            $code_location,
                            $suppressed_issues
                        );
                    }
                } else {
                    $existing_var_type = new Type\Union([new Type\Atomic\TLiteralFloat($value)]);
                }
            } elseif ($var_id && $code_location && !$is_loose_equality) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $var_id,
                    $assertion,
                    false,
                    $code_location,
                    $suppressed_issues
                );
            } elseif ($is_loose_equality && $existing_var_type->hasInt()) {
                // convert ints to floats
                $existing_float_types = $existing_var_type->getLiteralInts();

                if ($existing_float_types) {
                    $can_be_equal = false;
                    $did_remove_type = false;

                    foreach ($existing_var_atomic_types as $atomic_key => $_) {
                        if (substr($atomic_key, 0, 4) === 'int(') {
                            $atomic_key = 'float(' . substr($atomic_key, 4);
                        }
                        if ($atomic_key !== $assertion) {
                            $existing_var_type->removeType($atomic_key);
                            $did_remove_type = true;
                        } else {
                            $can_be_equal = true;
                        }
                    }

                    if ($var_id
                        && $code_location
                        && (!$can_be_equal || (!$did_remove_type && count($existing_var_atomic_types) === 1))
                    ) {
                        self::triggerIssueForImpossible(
                            $existing_var_type,
                            $old_var_type_string,
                            $var_id,
                            $assertion,
                            $can_be_equal,
                            $code_location,
                            $suppressed_issues
                        );
                    }
                }
            }
        }

        return $existing_var_type;
    }
}
