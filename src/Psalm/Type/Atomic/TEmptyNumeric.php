<?php

declare(strict_types=1);

namespace Psalm\Type\Atomic;

use Override;

/**
 * Denotes the `numeric` type that's also empty (which can also result from an `is_numeric` and `empty` check).
 *
 * @psalm-immutable
 */
final class TEmptyNumeric extends TNumeric
{
    #[Override]
    public function getId(bool $exact = true, bool $nested = false): string
    {
        return 'empty-numeric';
    }
}
