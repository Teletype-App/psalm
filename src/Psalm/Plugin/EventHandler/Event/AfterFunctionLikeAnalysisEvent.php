<?php

declare(strict_types=1);

namespace Psalm\Plugin\EventHandler\Event;

use PhpParser\Node;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\FileManipulation;
use Psalm\NodeTypeProvider;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Type\Union;

/**
 * @psalm-external-mutation-free
 */
final class AfterFunctionLikeAnalysisEvent
{
    /**
     * Called after a statement has been checked
     *
     * @param FileManipulation[]   $file_replacements
     * @internal
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly Node\FunctionLike $stmt,
        private readonly FunctionLikeStorage $functionlike_storage,
        private readonly StatementsSource $statements_source,
        private readonly Codebase $codebase,
        private array $file_replacements,
        private readonly NodeTypeProvider $node_type_provider,
        private readonly Context $context,
        private readonly ?Union $inferred_return_type = null,
    ) {
    }

    /**
     * @psalm-mutation-free
     */
    public function getStmt(): Node\FunctionLike
    {
        return $this->stmt;
    }

    /**
     * @psalm-mutation-free
     */
    public function getFunctionlikeStorage(): FunctionLikeStorage
    {
        return $this->functionlike_storage;
    }

    /**
     * @psalm-mutation-free
     */
    public function getStatementsSource(): StatementsSource
    {
        return $this->statements_source;
    }

    /**
     * @psalm-mutation-free
     */
    public function getCodebase(): Codebase
    {
        return $this->codebase;
    }

    /**
     * @return FileManipulation[]
     * @psalm-mutation-free
     */
    public function getFileReplacements(): array
    {
        return $this->file_replacements;
    }

    /**
     * @param FileManipulation[] $file_replacements
     * @psalm-external-mutation-free
     */
    public function setFileReplacements(array $file_replacements): void
    {
        $this->file_replacements = $file_replacements;
    }

    /**
     * @psalm-mutation-free
     */
    public function getNodeTypeProvider(): NodeTypeProvider
    {
        return $this->node_type_provider;
    }

    /**
     * @psalm-mutation-free
     */
    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * Returns the type inferred from the analyzed function body.
     *
     * The type is unavailable during initialization and mutation collection passes.
     *
     * @psalm-mutation-free
     */
    public function getInferredReturnType(): ?Union
    {
        return $this->inferred_return_type;
    }
}
