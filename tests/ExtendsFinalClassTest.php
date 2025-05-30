<?php

declare(strict_types=1);

namespace Psalm\Tests;

use Override;
use Psalm\Tests\Traits\InvalidCodeAnalysisTestTrait;
use Psalm\Tests\Traits\ValidCodeAnalysisTestTrait;

final class ExtendsFinalClassTest extends TestCase
{
    use InvalidCodeAnalysisTestTrait;
    use ValidCodeAnalysisTestTrait;

    #[Override]
    public function providerValidCodeParse(): iterable
    {
        return [
            'suppressingIssueWhenUsedWithKeyword' => [
                'code' => '<?php

                final class A {}

                /**
                * @psalm-suppress InvalidExtendClass
                */
                class B extends A {}',
            ],
            'suppressingIssueWhenUsedWithAnnotation' => [
                'code' => '<?php

                /**
                * @final
                */
                class A {}

                /**
                * @psalm-suppress InvalidExtendClass
                */
                class B extends A {}',
            ],
        ];
    }

    #[Override]
    public function providerInvalidCodeParse(): iterable
    {
        return [
            'invalidExtendsFinalClass' => [
                'code' => '<?php

                final class A {}

                class B extends A {}',

                'error_message' => 'InvalidExtendClass',
            ],

            'invalidExtendsAnnotatedFinalClass' => [
                'code' => '<?php

                /**
                * @final
                */
                class DoctrineA {}

                class DoctrineB extends DoctrineA {}',

                'error_message' => 'InvalidExtendClass',
            ],

            'invalidExtendsFinalClassAndOtherAnnotation' => [
                'code' => '<?php

                /**
                * @something-else-no-final annotation
                */
                final class DoctrineA {}

                class DoctrineB extends DoctrineA {}',

                'error_message' => 'InvalidExtendClass',
            ],
        ];
    }
}
