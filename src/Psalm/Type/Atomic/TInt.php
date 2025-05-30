<?php

declare(strict_types=1);

namespace Psalm\Type\Atomic;

use Override;

/**
 * Denotes the `int` type, where the exact value is unknown.
 *
 * @psalm-immutable
 */
class TInt extends Scalar
{
    #[Override]
    public function getKey(bool $include_extra = true): string
    {
        return 'int';
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
        return $analysis_php_version_id >= 7_00_00 ? 'int' : null;
    }
}
