<?php

declare(strict_types=1);

namespace Psalm\Storage\Assertion;

use Override;
use Psalm\Storage\Assertion;
use Psalm\Storage\UnserializeMemoryUsageSuppressionTrait;
use Psalm\Type\Atomic;

/**
 * @psalm-immutable
 */
final class IsNotType extends Assertion
{
    use UnserializeMemoryUsageSuppressionTrait;
    public function __construct(public readonly Atomic $type)
    {
    }

    #[Override]
    public function isNegation(): bool
    {
        return true;
    }

    #[Override]
    public function getNegation(): Assertion
    {
        return new IsType($this->type);
    }

    public function __toString(): string
    {
        return '!' . $this->type->getId();
    }

    #[Override]
    public function getAtomicType(): ?Atomic
    {
        return $this->type;
    }

    /**
     * @return static
     */
    #[Override]
    public function setAtomicType(Atomic $type): self
    {
        return new static($type);
    }

    #[Override]
    public function isNegationOf(Assertion $assertion): bool
    {
        return $assertion instanceof IsType && $this->type->getId() === $assertion->type->getId();
    }
}
