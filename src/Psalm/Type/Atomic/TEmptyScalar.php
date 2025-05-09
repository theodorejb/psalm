<?php

declare(strict_types=1);

namespace Psalm\Type\Atomic;

use Override;

/**
 * Denotes a `scalar` type that is also empty.
 *
 * @psalm-immutable
 */
final class TEmptyScalar extends TScalar
{
    #[Override]
    public function getId(bool $exact = true, bool $nested = false): string
    {
        return 'empty-scalar';
    }
}
