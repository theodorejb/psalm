<?php

declare(strict_types=1);

namespace Psalm\Storage\Assertion;

use Override;
use Psalm\Storage\Assertion;
use Psalm\Storage\UnserializeMemoryUsageSuppressionTrait;

/**
 * @psalm-immutable
 */
final class IsClassNotEqual extends Assertion
{
    use UnserializeMemoryUsageSuppressionTrait;
    public function __construct(public readonly string $type)
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
        return new IsClassEqual($this->type);
    }

    public function __toString(): string
    {
        return '!=get-class-' . $this->type;
    }

    #[Override]
    public function isNegationOf(Assertion $assertion): bool
    {
        return $assertion instanceof IsClassEqual && $this->type === $assertion->type;
    }
}
