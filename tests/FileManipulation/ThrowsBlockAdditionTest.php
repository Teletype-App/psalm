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
            'removeUnusedThrowsAnnotation' => [
                'input' => '<?php
                    /**
                     * @throws RuntimeException
                     */
                    function foo(): void {}',
                'output' => '<?php
                    function foo(): void {}',
                'php_version' => '7.4',
                'issues_to_fix' => ['UnusedThrowsDocblock'],
                'safe_types' => true,
            ],
            'removeUnusedExceptionFromThrowsUnion' => [
                'input' => '<?php
                    /**
                     * @throws InvalidArgumentException|DomainException when the value is invalid
                     */
                    function foo(): void {
                        throw new DomainException();
                    }',
                'output' => '<?php
                    /**
                     * @throws DomainException when the value is invalid
                     */
                    function foo(): void {
                        throw new DomainException();
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['UnusedThrowsDocblock'],
                'safe_types' => true,
            ],
            'replaceUnusedThrowsAnnotationInSinglePass' => [
                'input' => '<?php
                    /**
                     * @throws DomainException
                     */
                    function foo(): void {
                        throw new InvalidArgumentException();
                    }',
                'output' => '<?php
                    /**
                     * @throws InvalidArgumentException
                     */
                    function foo(): void {
                        throw new InvalidArgumentException();
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock', 'UnusedThrowsDocblock'],
                'safe_types' => true,
            ],
            'narrowCustomParentThrowsAnnotation' => [
                'input' => '<?php
                    class ApplicationException extends Exception {}
                    class InvalidApplicationState extends ApplicationException {}

                    /** @throws ApplicationException when application state is invalid */
                    function foo(): void {
                        throw new InvalidApplicationState();
                    }',
                'output' => '<?php
                    class ApplicationException extends Exception {}
                    class InvalidApplicationState extends ApplicationException {}

                    /**
                     * @throws InvalidApplicationState when application state is invalid
                     */
                    function foo(): void {
                        throw new InvalidApplicationState();
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['OverlyBroadThrowsDocblock'],
                'safe_types' => true,
            ],
            'narrowThrowsAnnotationToMultipleInferredExceptions' => [
                'input' => '<?php
                    /** @throws Exception */
                    function foo(bool $invalid): void {
                        if ($invalid) {
                            throw new InvalidArgumentException();
                        }

                        throw new RuntimeException();
                    }',
                'output' => '<?php
                    /**
                     * @throws InvalidArgumentException|RuntimeException
                     */
                    function foo(bool $invalid): void {
                        if ($invalid) {
                            throw new InvalidArgumentException();
                        }

                        throw new RuntimeException();
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['OverlyBroadThrowsDocblock'],
                'safe_types' => true,
            ],
            'removeBroadThrowsCoveredByNarrowerAnnotation' => [
                'input' => '<?php
                    /**
                     * @throws Exception generic failure
                     * @throws RuntimeException runtime failure
                     */
                    function foo(): void {
                        throw new RuntimeException();
                    }',
                'output' => '<?php
                    /**
                     * @throws RuntimeException runtime failure
                     */
                    function foo(): void {
                        throw new RuntimeException();
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['OverlyBroadThrowsDocblock'],
                'safe_types' => true,
            ],
            'narrowThrowsAndAddUnrelatedExceptionInSinglePass' => [
                'input' => '<?php
                    /** @throws Exception */
                    function foo(bool $invalid): void {
                        if ($invalid) {
                            throw new InvalidArgumentException();
                        }

                        throw new TypeError();
                    }',
                'output' => '<?php
                    /**
                     * @throws InvalidArgumentException
                     * @throws TypeError
                     */
                    function foo(bool $invalid): void {
                        if ($invalid) {
                            throw new InvalidArgumentException();
                        }

                        throw new TypeError();
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock', 'OverlyBroadThrowsDocblock'],
                'safe_types' => true,
            ],
            'narrowThrowsFromFunctionWithReturnTypeProvider' => [
                'input' => '<?php
                    namespace App;

                    class Security {
                        /**
                         * @throws \Exception
                         * @throws \ValueError
                         */
                        public function random(int $length): int {
                            if ($length < 1) {
                                throw new \InvalidArgumentException();
                            }

                            return random_int(1, $length);
                        }
                    }',
                'output' => '<?php
                    namespace App;

                    use InvalidArgumentException;
                    use Random\RandomException;

                    class Security {
                        /**
                         * @throws InvalidArgumentException|RandomException
                         * @throws \ValueError
                         */
                        public function random(int $length): int {
                            if ($length < 1) {
                                throw new \InvalidArgumentException();
                            }

                            return random_int(1, $length);
                        }
                    }',
                'php_version' => '8.5',
                'issues_to_fix' => ['OverlyBroadThrowsDocblock'],
                'safe_types' => true,
            ],
            'preserveThrowsAnnotationWhenParentCanBeThrown' => [
                'input' => '<?php
                    class ApplicationException extends Exception {}
                    class InvalidApplicationState extends ApplicationException {}

                    /** @throws ApplicationException */
                    function foo(bool $specific): void {
                        if ($specific) {
                            throw new InvalidApplicationState();
                        }

                        throw new ApplicationException();
                    }',
                'output' => '<?php
                    class ApplicationException extends Exception {}
                    class InvalidApplicationState extends ApplicationException {}

                    /** @throws ApplicationException */
                    function foo(bool $specific): void {
                        if ($specific) {
                            throw new InvalidApplicationState();
                        }

                        throw new ApplicationException();
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['OverlyBroadThrowsDocblock'],
                'safe_types' => true,
            ],
            'preserveSuppressedOverlyBroadThrowsAnnotation' => [
                'input' => '<?php
                    class ApplicationException extends Exception {}
                    class InvalidApplicationState extends ApplicationException {}

                    /**
                     * @throws ApplicationException
                     * @psalm-suppress OverlyBroadThrowsDocblock
                     */
                    function foo(): void {
                        throw new InvalidApplicationState();
                    }',
                'output' => '<?php
                    class ApplicationException extends Exception {}
                    class InvalidApplicationState extends ApplicationException {}

                    /**
                     * @throws ApplicationException
                     * @psalm-suppress OverlyBroadThrowsDocblock
                     */
                    function foo(): void {
                        throw new InvalidApplicationState();
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['OverlyBroadThrowsDocblock'],
                'safe_types' => true,
            ],
            'preserveThrowsAnnotationOnAbstractMethod' => [
                'input' => '<?php
                    abstract class Foo {
                        /**
                         * @throws RuntimeException
                         */
                        abstract public function foo(): void;
                    }',
                'output' => '<?php
                    abstract class Foo {
                        /**
                         * @throws RuntimeException
                         */
                        abstract public function foo(): void;
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['UnusedThrowsDocblock'],
                'safe_types' => true,
            ],
            'preserveSuppressedUnusedThrowsAnnotation' => [
                'input' => '<?php
                    /**
                     * @throws RuntimeException
                     * @psalm-suppress UnusedThrowsDocblock
                     */
                    function foo(): void {}',
                'output' => '<?php
                    /**
                     * @throws RuntimeException
                     * @psalm-suppress UnusedThrowsDocblock
                     */
                    function foo(): void {}',
                'php_version' => '7.4',
                'issues_to_fix' => ['UnusedThrowsDocblock'],
                'safe_types' => true,
            ],
            'preserveThrowsAnnotationAfterStaticPropertyFetch' => [
                'input' => '<?php
                    class Service {
                        /** @throws RuntimeException */
                        public function execute(): void {
                            throw new RuntimeException();
                        }
                    }

                    class Yii {
                        /** @var Service */
                        public static $app;
                    }

                    class Consumer {
                        /** @throws RuntimeException */
                        public function execute(): void {
                            Yii::$app->execute();
                        }
                    }',
                'output' => '<?php
                    class Service {
                        /** @throws RuntimeException */
                        public function execute(): void {
                            throw new RuntimeException();
                        }
                    }

                    class Yii {
                        /** @var Service */
                        public static $app;
                    }

                    class Consumer {
                        /** @throws RuntimeException */
                        public function execute(): void {
                            Yii::$app->execute();
                        }
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['UnusedThrowsDocblock'],
                'safe_types' => true,
            ],
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
            'preserveExistingThrowsWhenOtherDocblockTagsAreInvalid' => [
                'input' => '<?php
                    namespace Foo;
                    use Exception;
                    class SomeClass {
                        /**
                         * @return void
                         * @return void
                         * @throws Exception
                         * @throws Exception
                         */
                        public function foo(): void {
                            throw new \RuntimeException();
                        }
                    }',
                'output' => '<?php
                    namespace Foo;
                    use Exception;
                    class SomeClass {
                        /**
                         * @return void
                         * @return void
                         *
                         * @throws Exception
                         */
                        public function foo(): void {
                            throw new \RuntimeException();
                        }
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'removeDuplicateThrowsAnnotation' => [
                'input' => '<?php
                    /**
                     * @throws Exception
                     * @throws Exception
                     */
                    function foo(): void {
                        throw new Exception();
                    }',
                'output' => '<?php
                    /**
                     * @throws Exception
                     */
                    function foo(): void {
                        throw new Exception();
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'ignoreMalformedThrowsAnnotation' => [
                'input' => '<?php
                    class SomeClass {
                        /** @throws Exception*@throws RuntimeException */
                        public function malformed(): void {}

                        public function caller(): void {
                            $this->malformed();
                        }
                    }',
                'output' => '<?php
                    class SomeClass {
                        /** @throws Exception*@throws RuntimeException */
                        public function malformed(): void {}

                        public function caller(): void {
                            $this->malformed();
                        }
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'recognizeThrowsAnnotationUsingImportedNamespacePrefix' => [
                'input' => '<?php
                    namespace yii\base {
                        class InvalidArgumentException extends \Exception {}
                    }
                    namespace app\models {
                        use DomainException as InvalidArgumentException;
                        use Yii;

                        class SomeClass {
                            /** @throws Yii\base\InvalidArgumentException */
                            public function foo(): void {
                                throw new \yii\base\InvalidArgumentException();
                            }
                        }
                    }',
                'output' => '<?php
                    namespace yii\base {
                        class InvalidArgumentException extends \Exception {}
                    }
                    namespace app\models {
                        use DomainException as InvalidArgumentException;
                        use Yii;

                        class SomeClass {
                            /** @throws Yii\base\InvalidArgumentException */
                            public function foo(): void {
                                throw new \yii\base\InvalidArgumentException();
                            }
                        }
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
            'reuseExistingImportForThrowsAnnotationInTrait' => [
                'input' => '<?php
                    namespace Foo;
                    use Exception;
                    trait SomeTrait {
                        public function foo(): void {
                            throw new Exception();
                        }
                    }
                    class UsesSomeTrait {
                        use SomeTrait;
                    }',
                'output' => '<?php
                    namespace Foo;
                    use Exception;
                    trait SomeTrait {
                        /**
                         * @throws Exception
                         */
                        public function foo(): void {
                            throw new Exception();
                        }
                    }
                    class UsesSomeTrait {
                        use SomeTrait;
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
            'keepFullyQualifiedNarrowedThrowsAnnotationsWhenImportsConflict' => [
                'input' => '<?php
                    namespace App {
                        /** @throws \Exception */
                        function foo(): void {
                            throw new \Foo\Problem();
                        }
                        /** @throws \Exception */
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
                'issues_to_fix' => ['OverlyBroadThrowsDocblock'],
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
