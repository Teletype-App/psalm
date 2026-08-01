<?php

declare(strict_types=1);

namespace Psalm\Tests\FileManipulation;

use Override;

/**
 * @psalm-immutable
 */
final class ThrowsBlockAdditionTest extends FileManipulationTestCase
{
    /**
     * @psalm-pure
     */
    #[Override]
    public function providerValidCodeParse(): array
    {
        return [
            'addThrowsAnnotationToFunction' => [
                'input' => '<?php
                    function foo(string $s): string {
                        if("" === $s) {
                            throw new \InvalidArgumentException();
                        }
                        return $s;
                    }',
                'output' => '<?php
                    /**
                     * @throws InvalidArgumentException
                     */
                    function foo(string $s): string {
                        if("" === $s) {
                            throw new \InvalidArgumentException();
                        }
                        return $s;
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'addMultipleThrowsAnnotationToFunction' => [
                'input' => '<?php
                    function foo(string $s): string {
                        if("" === $s) {
                            throw new \InvalidArgumentException();
                        }
                        if("" === \trim($s)) {
                            throw new \DomainException();
                        }
                        return $s;
                    }',
                'output' => '<?php
                    /**
                     * @throws InvalidArgumentException|DomainException
                     */
                    function foo(string $s): string {
                        if("" === $s) {
                            throw new \InvalidArgumentException();
                        }
                        if("" === \trim($s)) {
                            throw new \DomainException();
                        }
                        return $s;
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'simplifyThrowsAnnotationToBroadestException' => [
                'input' => '<?php
                    /** @throws \Exception */
                    function throwsException(): void {}

                    /** @throws \Throwable */
                    function throwsThrowable(): void {}

                    function foo(): void {
                        throwsException();
                        throwsThrowable();
                    }',
                'output' => '<?php
                    /** @throws \Exception */
                    function throwsException(): void {}

                    /** @throws \Throwable */
                    function throwsThrowable(): void {}

                    /**
                     * @throws Throwable
                     */
                    function foo(): void {
                        throwsException();
                        throwsThrowable();
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'preserveUnrelatedEmptyDocblock' => [
                'input' => '<?php
                    /** */
                    function foo(): void {}

                    function bar(): void {
                        throw new \RuntimeException();
                    }',
                'output' => '<?php
                    /** */
                    function foo(): void {}

                    /**
                     * @throws RuntimeException
                     */
                    function bar(): void {
                        throw new \RuntimeException();
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'preservesExistingThrowsAnnotationToFunction' => [
                'input' => '<?php
                    /**
                     * @throws InvalidArgumentException|DomainException
                     */
                    function foo(string $s): string {
                        if("" === $s) {
                            throw new \Exception();
                        }
                        return $s;
                    }',
                'output' => '<?php
                    /**
                     * @throws InvalidArgumentException|DomainException
                     * @throws Exception
                     */
                    function foo(string $s): string {
                        if("" === $s) {
                            throw new \Exception();
                        }
                        return $s;
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'doesNotAddDuplicateThrows' => [
                'input' => '<?php
                    /**
                     * @throws InvalidArgumentException
                     */
                    function foo(string $s): string {
                        if("" === $s) {
                            throw new \InvalidArgumentException();
                        }
                        if("" === \trim($s)) {
                            throw new \DomainException();
                        }
                        return $s;
                    }',
                'output' => '<?php
                    /**
                     * @throws InvalidArgumentException
                     * @throws DomainException
                     */
                    function foo(string $s): string {
                        if("" === $s) {
                            throw new \InvalidArgumentException();
                        }
                        if("" === \trim($s)) {
                            throw new \DomainException();
                        }
                        return $s;
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'addThrowsAnnotationToFunctionInNamespace' => [
                'input' => '<?php
                    namespace Foo;
                    function foo(string $s): string {
                        if("" === $s) {
                            throw new \InvalidArgumentException();
                        }
                        return $s;
                    }',
                'output' => '<?php
                    namespace Foo;
                    use InvalidArgumentException;

                    /**
                     * @throws InvalidArgumentException
                     */
                    function foo(string $s): string {
                        if("" === $s) {
                            throw new \InvalidArgumentException();
                        }
                        return $s;
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'addThrowsAnnotationToFunctionFromFunctionFromOtherNamespace' => [
                'input' => '<?php
                    namespace Foo {
                        function foo(): void {
                            \Bar\bar();
                        }
                    }
                    namespace Bar {
                        class BarException extends \DomainException {}
                        /**
                         * @throws BarException
                         */
                        function bar(): void {
                            throw new BarException();
                        }
                    }',
                'output' => '<?php
                    namespace Foo {
                        use Bar\BarException;

                        /**
                         * @throws BarException
                         */
                        function foo(): void {
                            \Bar\bar();
                        }
                    }
                    namespace Bar {
                        class BarException extends \DomainException {}
                        /**
                         * @throws BarException
                         */
                        function bar(): void {
                            throw new BarException();
                        }
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'addImportForThrowsAnnotation' => [
                'input' => '<?php
                    namespace Foo;
                    use DomainException;
                    function foo(): void {
                        throw new \InvalidArgumentException();
                    }',
                'output' => '<?php
                    namespace Foo;
                    use DomainException;
                    use InvalidArgumentException;
                    /**
                     * @throws InvalidArgumentException
                     */
                    function foo(): void {
                        throw new \InvalidArgumentException();
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'keepFullyQualifiedThrowsAnnotationWhenImportAliasConflicts' => [
                'input' => '<?php
                    namespace Foo;
                    use DomainException as InvalidArgumentException;
                    function foo(): void {
                        throw new \InvalidArgumentException();
                    }',
                'output' => '<?php
                    namespace Foo;
                    use DomainException as InvalidArgumentException;
                    /**
                     * @throws \InvalidArgumentException
                     */
                    function foo(): void {
                        throw new \InvalidArgumentException();
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'keepFullyQualifiedThrowsAnnotationsWhenAddedImportsConflict' => [
                'input' => '<?php
                    namespace App {
                        function foo(): void {
                            throw new \Foo\Problem();
                        }
                        function bar(): void {
                            throw new \Bar\Problem();
                        }
                    }
                    namespace Foo {
                        class Problem extends \Exception {}
                    }
                    namespace Bar {
                        class Problem extends \Exception {}
                    }',
                'output' => '<?php
                    namespace App {
                        /**
                         * @throws \Foo\Problem
                         */
                        function foo(): void {
                            throw new \Foo\Problem();
                        }
                        /**
                         * @throws \Bar\Problem
                         */
                        function bar(): void {
                            throw new \Bar\Problem();
                        }
                    }
                    namespace Foo {
                        class Problem extends \Exception {}
                    }
                    namespace Bar {
                        class Problem extends \Exception {}
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'addThrowsAnnotationAccountsForUseStatements' => [
                'input' => '<?php
                    namespace Foo {
                        use Bar\BarException;
                        function foo(): void {
                            bar();
                        }
                        /**
                         * @throws BarException
                         */
                        function bar(): void {
                            throw new BarException();
                        }
                    }
                    namespace Bar {
                        class BarException extends \DomainException {}
                    }',
                'output' => '<?php
                    namespace Foo {
                        use Bar\BarException;
                        /**
                         * @throws BarException
                         */
                        function foo(): void {
                            bar();
                        }
                        /**
                         * @throws BarException
                         */
                        function bar(): void {
                            throw new BarException();
                        }
                    }
                    namespace Bar {
                        class BarException extends \DomainException {}
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
        ];
    }
}
