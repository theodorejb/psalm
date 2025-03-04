<?php

declare(strict_types=1);

namespace Psalm\Internal\LanguageServer;

use Amp\ByteStream\WritableResourceStream;
use Override;

/**
 * @internal
 */
final class ProtocolStreamWriter implements ProtocolWriter
{
    private readonly WritableResourceStream $output;

    /**
     * @param resource $output
     */
    public function __construct($output)
    {
        $this->output = new WritableResourceStream($output);
    }

    /**
     * {@inheritdoc}
     */
    #[Override]
    public function write(Message $msg): void
    {
        $this->output->write((string)$msg);
    }
}
