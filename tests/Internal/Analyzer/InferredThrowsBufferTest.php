<?php

declare(strict_types=1);

namespace Psalm\Tests\Internal\Analyzer;

use Override;
use PHPUnit\Framework\TestCase;
use Psalm\Internal\Analyzer\InferredThrowsBuffer;

final class InferredThrowsBufferTest extends TestCase
{
    /** @psalm-external-mutation-free */
    #[Override]
    protected function tearDown(): void
    {
        InferredThrowsBuffer::clear();
    }

    public function testPreservesOnlyRequestedAnalysisContexts(): void
    {
        InferredThrowsBuffer::setAnalysisFile('/project/Consumer.php');
        InferredThrowsBuffer::set(
            'regular',
            ['RuntimeException' => true],
            ['RuntimeException' => [[0 => true]]],
        );
        InferredThrowsBuffer::set(
            'someTrait::run',
            ['DomainException' => true],
            ['DomainException' => [[1 => false]]],
            true,
        );

        self::assertSame([], InferredThrowsBuffer::getContextSummaries()['regular'] ?? []);
        self::assertSame([], InferredThrowsBuffer::getContextConditions()['regular'] ?? []);
        self::assertSame(
            ['DomainException' => true],
            InferredThrowsBuffer::getContextSummaries()['sometrait::run']['/project/Consumer.php'],
        );
        self::assertSame(
            ['DomainException' => [[1 => false]]],
            InferredThrowsBuffer::getContextConditions()['sometrait::run']['/project/Consumer.php'],
        );
    }
}
