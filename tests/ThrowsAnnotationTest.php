<?php

declare(strict_types=1);

namespace Psalm\Tests;

use Psalm\Config;
use Psalm\Context;
use Psalm\Exception\CodeException;
use Psalm\Internal\Analyzer\InferredThrowsBuffer;

final class ThrowsAnnotationTest extends TestCase
{
    public function testUndefinedClassAsThrows(): void
    {
        $this->expectExceptionMessage('UndefinedDocblockClass - somefile.php:3:28');
        $this->expectException(CodeException::class);

        $this->addFile(
            'somefile.php',
            '<?php
                /**
                 * @throws Foo
                 * @psalm-mutation-free
                 */
                function bar() : void {}',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testNonThrowableClassAsThrows(): void
    {
        $this->expectExceptionMessage('InvalidThrow');
        $this->expectException(CodeException::class);

        $this->addFile(
            'somefile.php',
            '<?php
                class Foo {}

                /**
                 * @throws Foo
                 * @psalm-mutation-free
                 */
                function bar() : void {}',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testInheritedThrowableClassAsThrows(): void
    {
        $this->addFile(
            'somefile.php',
            '<?php
                class MyException extends Exception {}

                class Foo {
                    /**
                     * @throws MyException|Throwable
                     * @psalm-mutation-free
                     */
                    public function bar() : void {}
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testUndocumentedThrow(): void
    {
        $this->expectExceptionMessage('MissingThrowsDocblock');
        $this->expectException(CodeException::class);
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                /**
                 * @psalm-pure
                 */
                function foo(int $x, int $y) : int {
                    if ($y === 0) {
                        throw new \RangeException("Cannot divide by zero");
                    }

                    if ($y < 0) {
                        throw new \InvalidArgumentException("This is also bad");
                    }

                    return intdiv($x, $y);
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testDocumentedThrow(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                /**
                 * @throws RangeException
                 * @throws InvalidArgumentException
                 * @psalm-pure
                 */
                function foo(int $x, int $y) : int {
                    if ($y === 0) {
                        throw new \RangeException("Cannot divide by zero");
                    }

                    if ($y < 0) {
                        throw new \InvalidArgumentException("This is also bad");
                    }

                    return intdiv($x, $y);
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testUnusedThrowsDocblock(): void
    {
        $this->expectExceptionMessage('UnusedThrowsDocblock');
        $this->expectException(CodeException::class);
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                /**
                 * @throws RuntimeException
                 */
                function foo(): void {}',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testOverlyBroadThrowsDocblock(): void
    {
        $this->expectExceptionMessage('OverlyBroadThrowsDocblock');
        $this->expectException(CodeException::class);
        Config::getInstance()->check_for_throws_docblock = true;
        Config::getInstance()->setCustomErrorLevel('OverlyBroadThrowsDocblock', Config::REPORT_ERROR);

        $this->addFile(
            'somefile.php',
            '<?php
                class ApplicationException extends Exception {}
                class InvalidApplicationState extends ApplicationException {}

                /** @throws ApplicationException */
                function foo(): void {
                    throw new InvalidApplicationState();
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testReportsDescribedOverlyBroadThrowsDocblock(): void
    {
        $this->expectExceptionMessage('OverlyBroadThrowsDocblock');
        $this->expectException(CodeException::class);
        Config::getInstance()->check_for_throws_docblock = true;
        Config::getInstance()->setCustomErrorLevel('OverlyBroadThrowsDocblock', Config::REPORT_ERROR);

        $this->addFile(
            'somefile.php',
            '<?php
                class ApplicationException extends Exception {}
                class InvalidApplicationState extends ApplicationException {}

                /** @throws ApplicationException when application state is invalid */
                function foo(): void {
                    throw new InvalidApplicationState();
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testDoesNotReportOverlyBroadThrowsDocblockWhenParentCanBeThrown(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;
        Config::getInstance()->setCustomErrorLevel('OverlyBroadThrowsDocblock', Config::REPORT_ERROR);

        $this->addFile(
            'somefile.php',
            '<?php
                class ApplicationException extends Exception {}
                class InvalidApplicationState extends ApplicationException {}

                /** @throws ApplicationException */
                function foo(bool $specific): void {
                    if ($specific) {
                        throw new InvalidApplicationState();
                    }

                    throw new ApplicationException();
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testReportsBroadRethrownThrowable(): void
    {
        $this->expectExceptionMessage('OverlyBroadThrowsDocblock');
        $this->expectException(CodeException::class);
        Config::getInstance()->check_for_throws_docblock = true;
        Config::getInstance()->setCustomErrorLevel('OverlyBroadThrowsDocblock', Config::REPORT_ERROR);

        $this->addFile(
            'somefile.php',
            '<?php
                /** @throws Throwable */
                function foo(): void {
                    try {
                        throw new RuntimeException();
                    } catch (Throwable $throwable) {
                        throw $throwable;
                    }
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testPreservesSpecificRethrownExceptionCoveredByThrowable(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                interface Service {
                    /** @throws Throwable */
                    public function execute(): void;
                }

                /** @throws Throwable */
                function foo(Service $service): void {
                    try {
                        $service->execute();
                        throw new RuntimeException();
                    } catch (Throwable $throwable) {
                        error_log($throwable->getMessage());
                        throw $throwable;
                    }
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);

        $inferred_throws = InferredThrowsBuffer::getAll();
        $this->assertArrayHasKey('RuntimeException', $inferred_throws['foo']);
        $this->assertArrayHasKey('Throwable', $inferred_throws['foo']);
    }

    /**
     * @dataProvider providerIgnoredDocumentedExceptions
     */
    public function testIgnoredDocumentedExceptions(
        string $ignored_exception,
        bool $include_descendants,
        bool $only_global_scope,
        bool $expect_unused,
    ): void {
        $config = Config::getInstance();
        $config->check_for_throws_docblock = true;
        if ($include_descendants) {
            $config->ignored_exceptions_and_descendants_in_global_scope = [$ignored_exception => true];
            if (!$only_global_scope) {
                $config->ignored_exceptions_and_descendants = [$ignored_exception => true];
            }
        } else {
            $config->ignored_exceptions_in_global_scope = [$ignored_exception => true];
            if (!$only_global_scope) {
                $config->ignored_exceptions = [$ignored_exception => true];
            }
        }

        if ($expect_unused) {
            $this->expectException(CodeException::class);
            $this->expectExceptionMessage('UnusedThrowsDocblock');
        }

        $this->addFile(
            'somefile.php',
            '<?php
                /** @throws RuntimeException */
                function foo(): void {}',
        );
        $this->analyzeFile('somefile.php', new Context());
    }

    /**
     * @return array<string, array{string, bool, bool, bool}>
     * @psalm-pure
     */
    public function providerIgnoredDocumentedExceptions(): array
    {
        return [
            'exactClass' => ['RuntimeException', false, false, false],
            'caseInsensitiveClass' => ['rUnTiMeExCePtIoN', false, false, false],
            'descendant' => ['Exception', true, false, false],
            'exactClassDoesNotIgnoreDescendants' => ['Exception', false, false, true],
            'globalClassDoesNotIgnoreFunction' => ['RuntimeException', false, true, true],
            'globalDescendantsDoNotIgnoreFunction' => ['Exception', true, true, true],
            'unrelatedClass' => ['LogicException', true, false, true],
        ];
    }

    public function testIgnoredThrownExceptionKeepsCoveringAnnotationUsed(): void
    {
        $config = Config::getInstance();
        $config->check_for_throws_docblock = true;
        $config->ignored_exceptions = ['RuntimeException' => true];

        $this->addFile(
            'somefile.php',
            '<?php
                /** @throws Exception */
                function foo(): void {
                    throw new RuntimeException();
                }',
        );
        $this->analyzeFile('somefile.php', new Context());
    }

    public function testDoesNotNarrowIgnoredThrowsAnnotation(): void
    {
        $config = Config::getInstance();
        $config->check_for_throws_docblock = true;
        $config->ignored_exceptions = ['Exception' => true];
        $config->setCustomErrorLevel('OverlyBroadThrowsDocblock', Config::REPORT_ERROR);

        $this->addFile(
            'somefile.php',
            '<?php
                /** @throws Exception */
                function foo(): void {
                    throw new RuntimeException();
                }',
        );
        $this->analyzeFile('somefile.php', new Context());
    }

    public function testDoesNotRestoreCaughtExceptionAfterCatchVariableReassignment(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                /** @throws LogicException */
                function foo(): void {
                    try {
                        throw new RuntimeException();
                    } catch (Throwable $throwable) {
                        $throwable = new LogicException();
                        throw $throwable;
                    }
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testDoesNotRestoreCaughtExceptionFromNestedClosure(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                function foo(): Closure {
                    try {
                        throw new RuntimeException();
                    } catch (Throwable $throwable) {
                        /** @throws Throwable */
                        return static function () use ($throwable): void {
                            throw $throwable;
                        };
                    }
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testRandomIntThrowsRandomException(): void
    {
        $this->expectExceptionMessage('RandomException');
        $this->expectException(CodeException::class);
        Config::getInstance()->check_for_throws_docblock = true;
        Config::getInstance()->setCustomErrorLevel('OverlyBroadThrowsDocblock', Config::REPORT_ERROR);
        $this->project_analyzer->setPhpVersion('8.5', 'tests');

        $this->addFile(
            'somefile.php',
            '<?php
                /** @throws Exception|ValueError */
                function foo(): int {
                    return random_int(1, 10);
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testFirstClassRandomIntCallableDoesNotThrow(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;
        $this->project_analyzer->setPhpVersion('8.5', 'tests');

        $this->addFile(
            'somefile.php',
            '<?php
                function foo(): Closure {
                    return random_int(...);
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testUndocumentedThrowInsideLoop(): void
    {
        $this->expectExceptionMessage('MissingThrowsDocblock');
        $this->expectException(CodeException::class);
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                function foo(): void {
                    for ($i = 0; $i < 1; ++$i) {
                        throw new RuntimeException();
                    }
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testDoesNotReportUnusedThrowsDocblockForAbstractMethod(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                abstract class Foo {
                    /**
                     * @throws RuntimeException
                     */
                    abstract public function foo(): void;
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testDoesNotReportThrowsDocblockPropagatedFromTraitMethod(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                trait FooTrait {
                    /** @throws RuntimeException */
                    public function inner(bool $fail): void {
                        if ($fail) {
                            throw new RuntimeException();
                        }
                    }
                }

                class Foo {
                    use FooTrait;

                    /** @throws RuntimeException */
                    public function outer(bool $fail): void {
                        $this->inner($fail);
                    }
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testPropagatesInheritedThrowsThroughOverridingTraitMethod(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                class DatabaseException extends Exception {}
                class HttpException extends Exception {}

                class ActiveRecord {
                    /** @throws DatabaseException */
                    public function save(): void {
                        throw new DatabaseException();
                    }
                }

                trait SaveTrait {
                    /**
                     * @inheritDoc
                     * @throws HttpException
                     */
                    public function save(): void {
                        /** @psalm-suppress MissingThrowsDocblock */
                        parent::save();
                        throw new HttpException();
                    }
                }

                class ManagerJob extends ActiveRecord {
                    use SaveTrait;

                    /**
                     * @throws DatabaseException
                     * @throws HttpException
                     */
                    public function fail(): void {
                        $this->save();
                    }
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testDocumentedParentThrow(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                /**
                 * @throws Exception
                 * @psalm-pure
                 */
                function foo(int $x, int $y) : int {
                    if ($y === 0) {
                        throw new \RangeException("Cannot divide by zero");
                    }

                    if ($y < 0) {
                        throw new \InvalidArgumentException("This is also bad");
                    }

                    return intdiv($x, $y);
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testThrowableInherited(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                /**
                 * @throws Throwable
                 * @psalm-pure
                 */
                function foo(int $x, int $y) : int {
                    if ($y < 0) {
                        throw new \InvalidArgumentException("This is also bad");
                    }

                    return intdiv($x, $y);
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testUndocumentedThrowInFunctionCall(): void
    {
        $this->expectExceptionMessage('MissingThrowsDocblock');
        $this->expectException(CodeException::class);
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                /**
                 * @throws RangeException
                 * @throws InvalidArgumentException
                 * @psalm-pure
                 */
                function foo(int $x, int $y) : int {
                    if ($y === 0) {
                        throw new \RangeException("Cannot divide by zero");
                    }

                    if ($y < 0) {
                        throw new \InvalidArgumentException("This is also bad");
                    }

                    return intdiv($x, $y);
                }

                function bar(int $x, int $y) : void {
                    foo($x, $y);
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testDocumentedThrowInFunctionCallWithThrow(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                /**
                 * @throws RangeException
                 * @throws InvalidArgumentException
                 * @psalm-pure
                 */
                function foo(int $x, int $y) : int {
                    if ($y === 0) {
                        throw new \RangeException("Cannot divide by zero");
                    }

                    if ($y < 0) {
                        throw new \InvalidArgumentException("This is also bad");
                    }

                    return intdiv($x, $y);
                }

                /**
                 * @throws RangeException
                 * @throws InvalidArgumentException
                 * @psalm-pure
                 */
                function bar(int $x, int $y) : void {
                    foo($x, $y);
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testDocumentedThrowInFunctionCallWithoutThrow(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                class Foo
                {
                    /**
                     * @throws \TypeError
                     * @psalm-pure
                     * @psalm-suppress UnusedThrowsDocblock
                     */
                    public static function notReallyThrowing(int $a): string
                    {
                        if ($a > 0) {
                            return "";
                        }

                        return (string) $a;
                    }

                    public function test(): string
                    {
                        try {
                            return self::notReallyThrowing(2);
                        } catch (\Throwable $E) {
                            return "";
                        }
                    }
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testCaughtThrowInFunctionCall(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                /**
                 * @throws RangeException
                 * @throws InvalidArgumentException
                 * @psalm-pure
                 */
                function foo(int $x, int $y) : int {
                    if ($y === 0) {
                        throw new \RangeException("Cannot divide by zero");
                    }

                    if ($y < 0) {
                        throw new \InvalidArgumentException("This is also bad");
                    }

                    return intdiv($x, $y);
                }

                function bar(int $x, int $y) : void {
                    try {
                        foo($x, $y);
                    } catch (RangeException $e) {

                    } catch (InvalidArgumentException $e) {}
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testUncaughtThrowInFunctionCall(): void
    {
        $this->expectExceptionMessage('MissingThrowsDocblock');
        $this->expectException(CodeException::class);
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                /**
                 * @throws RangeException
                 * @throws InvalidArgumentException
                 * @psalm-pure
                 */
                function foo(int $x, int $y) : int {
                    if ($y === 0) {
                        throw new \RangeException("Cannot divide by zero");
                    }

                    if ($y < 0) {
                        throw new \InvalidArgumentException("This is also bad");
                    }

                    return intdiv($x, $y);
                }

                function bar(int $x, int $y) : void {
                    try {
                        foo($x, $y);
                    } catch (\RangeException $e) {

                    }
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testEmptyThrows(): void
    {
        $this->expectExceptionMessage('MissingDocblockType');
        $this->expectException(CodeException::class);
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                /**
                 * @throws
                 * @psalm-mutation-free
                 */
                function foo(int $x, int $y) : int {}',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testCaughtAllThrowInFunctionCall(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                /**
                 * @throws RangeException
                 * @throws InvalidArgumentException
                 * @psalm-pure
                 */
                function foo(int $x, int $y) : int {
                    if ($y === 0) {
                        throw new \RangeException("Cannot divide by zero");
                    }

                    if ($y < 0) {
                        throw new \InvalidArgumentException("This is also bad");
                    }

                    return intdiv($x, $y);
                }

                function bar(int $x, int $y) : void {
                    try {
                        foo($x, $y);
                    } catch (Exception $e) {}
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testDocumentedThrowInInterfaceWithInheritDocblock(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                interface Foo
                {
                    /**
                     * @throws \InvalidArgumentException
                     * @psalm-mutation-free
                     */
                    public function test(): void;
                }

                class Bar implements Foo
                {
                    /**
                     * {@inheritdoc}
                     * @psalm-mutation-free
                     */
                    public function test(): void
                    {
                        throw new \InvalidArgumentException();
                    }
                }
                ',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testDocumentedThrowInInterfaceWithoutInheritDocblock(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                interface Foo
                {
                    /**
                     * @throws \InvalidArgumentException
                     * @psalm-mutation-free
                     */
                    public function test(): void;
                }

                class Bar implements Foo
                {
                    /**
                     * @psalm-mutation-free
                     */
                    public function test(): void
                    {
                        throw new \InvalidArgumentException();
                    }
                }
                ',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testDocumentedThrowInSubclassWithExtendedInheritDocblock(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                interface Foo
                {
                    /**
                     * @throws \InvalidArgumentException
                     * @psalm-mutation-free
                     */
                    public function test(): void;
                }

                class Bar implements Foo
                {
                    /**
                     * {@inheritdoc}
                     * @throws \OutOfBoundsException
                     * @psalm-mutation-free
                     */
                    public function test(): void
                    {
                        throw new \OutOfBoundsException();
                    }
                }
                ',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testDocumentedThrowInInterfaceWithExtendedInheritDocblock(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                interface Foo
                {
                    /**
                     * @throws \InvalidArgumentException
                     * @psalm-mutation-free
                     */
                    public function test(): void;
                }

                class Bar implements Foo
                {
                    /**
                     * {@inheritdoc}
                     * @throws \OutOfBoundsException
                     * @psalm-mutation-free
                     * @psalm-suppress UnusedThrowsDocblock
                     */
                    public function test(): void
                    {
                        throw new \InvalidArgumentException();
                    }
                }
                ',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testDocumentedThrowInInterfaceWithOverriddenDocblock(): void
    {
        $this->expectExceptionMessage('MissingThrowsDocblock');
        $this->expectException(CodeException::class);
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                interface Foo
                {
                    /**
                     * @throws \InvalidArgumentException
                     * @psalm-mutation-free
                     */
                    public function test(): void;
                }

                class Bar implements Foo
                {
                    /**
                     * @throws \OutOfBoundsException
                     * @psalm-mutation-free
                     */
                    public function test(): void
                    {
                        throw new \InvalidArgumentException();
                    }
                }
                ',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testDocumentedThrowInsideCatch(): void
    {
        $this->expectExceptionMessage('MissingThrowsDocblock');
        $this->expectException(CodeException::class);
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                /**
                 * @return void
                 * @psalm-mutation-free
                 */
                function foo() : void {
                    try {
                        throw new Exception("foo");
                    } catch (Exception $e) {
                        throw new RuntimeException("bar");
                    }
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testNextCatchShouldIgnoreExceptionsCaughtByPreviousCatch(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                /**
                 * @throws \RuntimeException
                 * @psalm-mutation-free
                 */
                function method(): void
                {
                    try {
                        throw new \LogicException();
                    } catch (\LogicException $e) {
                        throw new \RuntimeException();
                    } catch (\Exception $e) {
                        throw new \RuntimeException();
                    }
                }',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testUnknownExceptionInThrowsOfACalledMethod(): void
    {
        $this->expectExceptionMessage('MissingThrowsDocblock');
        $this->expectException(CodeException::class);
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                final class Monkey {
                    /** @throws InvalidArgumentException
                     * @psalm-mutation-free */
                    public function spendsItsDay(): void {
                        $this->havingFun();
                    }
                    /** @throws \Monkey\Shit
                     * @psalm-mutation-free */
                    private function havingFun(): void {}
                }
            ',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testDocumentedThrowInterfaceWithFunctionCallWithImplementedExceptionThrow(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                interface TestExceptionInterface extends Throwable
                {
                }

                class TestException extends Exception implements TestExceptionInterface
                {
                }

                class Example
                {
                    /**
                     * @throws Throwable
                     * @psalm-mutation-free
                     */
                    private function methodOne(): void {
                        $this->methodTwo();
                    }

                    /**
                     * @throws TestExceptionInterface
                     * @psalm-mutation-free
                     * @psalm-suppress UnusedThrowsDocblock
                     */
                    private function methodTwo(): void {}
                }
            ',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testUndocumentedThrowFromTernaryElseBranch(): void
    {
        $this->expectExceptionMessage('MissingThrowsDocblock');
        $this->expectException(CodeException::class);
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                final class Thrower
                {
                    /** @throws RuntimeException */
                    public function getValue(): int
                    {
                        throw new RuntimeException();
                    }
                }

                function getValue(Thrower $thrower, bool $use_default): int
                {
                    return $use_default ? 0 : $thrower->getValue();
                }
            ',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testUndocumentedThrowFromTernaryIfBranch(): void
    {
        $this->expectExceptionMessage('MissingThrowsDocblock');
        $this->expectException(CodeException::class);
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                final class Thrower
                {
                    /** @throws RuntimeException */
                    public function getValue(): int
                    {
                        throw new RuntimeException();
                    }
                }

                function getValue(Thrower $thrower, bool $use_default): int
                {
                    return $use_default ? $thrower->getValue() : 0;
                }
            ',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }

    public function testThrowFromUnreachableTernaryBranchesIsNotPropagated(): void
    {
        Config::getInstance()->check_for_throws_docblock = true;

        $this->addFile(
            'somefile.php',
            '<?php
                final class Thrower
                {
                    /** @throws RuntimeException */
                    public function getValue(): int
                    {
                        throw new RuntimeException();
                    }
                }

                function getValueFromUnreachableElse(Thrower $thrower): int
                {
                    /** @psalm-suppress RedundantCondition */
                    return true ? 0 : $thrower->getValue();
                }

                function getValueFromUnreachableIf(Thrower $thrower): int
                {
                    /** @psalm-suppress TypeDoesNotContainType */
                    return false ? $thrower->getValue() : 0;
                }
            ',
        );

        $context = new Context();

        $this->analyzeFile('somefile.php', $context);
    }
}
