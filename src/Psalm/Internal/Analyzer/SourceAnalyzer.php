<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer;

use Override;
use Psalm\Aliases;
use Psalm\Codebase;
use Psalm\NodeTypeProvider;
use Psalm\StatementsSource;
use Psalm\Type\Union;

/**
 * @internal
 */
abstract class SourceAnalyzer implements StatementsSource
{
    protected SourceAnalyzer $source;

    public function __destruct()
    {
        unset($this->source);
    }

    /** @psalm-mutation-free */
    #[Override]
    public function getAliases(): Aliases
    {
        return $this->source->getAliases();
    }

    /**
     * @psalm-mutation-free
     * @return array<lowercase-string, string>
     */
    #[Override]
    public function getAliasedClassesFlipped(): array
    {
        return $this->source->getAliasedClassesFlipped();
    }

    /**
     * @psalm-mutation-free
     * @return array<string, string>
     */
    #[Override]
    public function getAliasedClassesFlippedReplaceable(): array
    {
        return $this->source->getAliasedClassesFlippedReplaceable();
    }

    /** @psalm-mutation-free */
    #[Override]
    public function getFQCLN(): ?string
    {
        return $this->source->getFQCLN();
    }

    /** @psalm-mutation-free */
    #[Override]
    public function getClassName(): ?string
    {
        return $this->source->getClassName();
    }

    /** @psalm-mutation-free */
    #[Override]
    public function getParentFQCLN(): ?string
    {
        return $this->source->getParentFQCLN();
    }

    /** @psalm-mutation-free */
    #[Override]
    public function getFileName(): string
    {
        return $this->source->getFileName();
    }

    /** @psalm-mutation-free */
    #[Override]
    public function getFilePath(): string
    {
        return $this->source->getFilePath();
    }

    /** @psalm-mutation-free */
    #[Override]
    public function getRootFileName(): string
    {
        return $this->source->getRootFileName();
    }

    /** @psalm-mutation-free */
    #[Override]
    public function getRootFilePath(): string
    {
        return $this->source->getRootFilePath();
    }

    #[Override]
    public function setRootFilePath(string $file_path, string $file_name): void
    {
        $this->source->setRootFilePath($file_path, $file_name);
    }

    /** @psalm-mutation-free */
    #[Override]
    public function hasParentFilePath(string $file_path): bool
    {
        return $this->source->hasParentFilePath($file_path);
    }

    /** @psalm-mutation-free */
    #[Override]
    public function hasAlreadyRequiredFilePath(string $file_path): bool
    {
        return $this->source->hasAlreadyRequiredFilePath($file_path);
    }

    /** @psalm-mutation-free */
    #[Override]
    public function getRequireNesting(): int
    {
        return $this->source->getRequireNesting();
    }

    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function getSource(): StatementsSource
    {
        return $this->source;
    }

    /**
     * Get a list of suppressed issues
     *
     * @psalm-mutation-free
     * @return array<string>
     */
    #[Override]
    public function getSuppressedIssues(): array
    {
        return $this->source->getSuppressedIssues();
    }

    /**
     * @param array<int, string> $new_issues
     */
    #[Override]
    public function addSuppressedIssues(array $new_issues): void
    {
        $this->source->addSuppressedIssues($new_issues);
    }

    /**
     * @param array<int, string> $new_issues
     */
    #[Override]
    public function removeSuppressedIssues(array $new_issues): void
    {
        $this->source->removeSuppressedIssues($new_issues);
    }

    /** @psalm-mutation-free */
    #[Override]
    public function getNamespace(): ?string
    {
        return $this->source->getNamespace();
    }

    /** @psalm-mutation-free */
    #[Override]
    public function isStatic(): bool
    {
        return $this->source->isStatic();
    }

    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function getCodebase(): Codebase
    {
        return $this->source->getCodebase();
    }

    /**
     * @psalm-mutation-free
     */
    public function getProjectAnalyzer(): ProjectAnalyzer
    {
        return $this->source->getProjectAnalyzer();
    }

    /**
     * @psalm-mutation-free
     */
    public function getFileAnalyzer(): FileAnalyzer
    {
        return $this->source->getFileAnalyzer();
    }

    /**
     * @psalm-mutation-free
     * @return array<string, array<string, Union>>|null
     */
    #[Override]
    public function getTemplateTypeMap(): ?array
    {
        return $this->source->getTemplateTypeMap();
    }

    /** @psalm-mutation-free */
    #[Override]
    public function getNodeTypeProvider(): NodeTypeProvider
    {
        return $this->source->getNodeTypeProvider();
    }
}
