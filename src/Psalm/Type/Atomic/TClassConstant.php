<?php

declare(strict_types=1);

namespace Psalm\Type\Atomic;

use Override;
use Psalm\Storage\UnserializeMemoryUsageSuppressionTrait;
use Psalm\Type;
use Psalm\Type\Atomic;

/**
 * Denotes a class constant whose value might not yet be known.
 *
 * @psalm-immutable
 */
final class TClassConstant extends Atomic
{
    use UnserializeMemoryUsageSuppressionTrait;
    public function __construct(
        public string $fq_classlike_name,
        public string $const_name,
        bool $from_docblock = false,
    ) {
        parent::__construct($from_docblock);
    }

    #[Override]
    public function getKey(bool $include_extra = true): string
    {
        return 'class-constant(' . $this->fq_classlike_name . '::' . $this->const_name . ')';
    }

    #[Override]
    public function getId(bool $exact = true, bool $nested = false): string
    {
        return $this->fq_classlike_name . '::' . $this->const_name;
    }

    #[Override]
    public function getAssertionString(): string
    {
        return 'class-constant(' . $this->fq_classlike_name . '::' . $this->const_name . ')';
    }

    /**
     * @param  array<lowercase-string, string> $aliased_classes
     */
    #[Override]
    public function toPhpString(
        ?string $namespace,
        array $aliased_classes,
        ?string $this_class,
        int $analysis_php_version_id,
    ): ?string {
        return null;
    }

    #[Override]
    public function canBeFullyExpressedInPhp(int $analysis_php_version_id): bool
    {
        return false;
    }

    /**
     * @param array<lowercase-string, string> $aliased_classes
     */
    #[Override]
    public function toNamespacedString(
        ?string $namespace,
        array $aliased_classes,
        ?string $this_class,
        bool $use_phpdoc_format,
    ): string {
        if ($this->fq_classlike_name === 'static') {
            return 'static::' . $this->const_name;
        }

        return Type::getStringFromFQCLN($this->fq_classlike_name, $namespace, $aliased_classes, $this_class)
            . '::'
            . $this->const_name;
    }
}
