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
            'preserveDescribedThrowsUnionWhenPartIsUnused' => [
                'input' => '<?php
                    /**
                     * @throws InvalidArgumentException|DomainException when the value is invalid
                     */
                    function foo(): void {
                        throw new DomainException();
                    }',
                'output' => '<?php
                    /**
                     * @throws InvalidArgumentException|DomainException when the value is invalid
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
            'preserveDescribedCustomParentThrowsAnnotation' => [
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

                    /** @throws ApplicationException when application state is invalid */
                    function foo(): void {
                        throw new InvalidApplicationState();
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['OverlyBroadThrowsDocblock'],
                'safe_types' => true,
            ],
            'addDirectThrowWithoutRewritingDescribedParentAnnotation' => [
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
                     * @throws ApplicationException when application state is invalid
                     * @throws InvalidApplicationState
                     */
                    function foo(): void {
                        throw new InvalidApplicationState();
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'addDirectThrowFromImmediatelyInvokedClosure' => [
                'input' => '<?php
                    interface Mutex {
                        /**
                         * @template T
                         * @param callable(): T $callback
                         * @return T
                         * @throws Throwable
                         */
                        public function synchronized(callable $callback);
                    }

                    abstract class AbstractMutex implements Mutex {
                        public function synchronized(callable $callback) {
                            return $callback();
                        }
                    }

                    final class RedisMutex extends AbstractMutex {}

                    final class Service {
                        /** @throws Throwable */
                        public function run(RedisMutex $mutex): bool {
                            return $mutex->synchronized(static function (): bool {
                                throw new DomainException();
                            });
                        }
                    }',
                'output' => '<?php
                    interface Mutex {
                        /**
                         * @template T
                         * @param callable(): T $callback
                         * @return T
                         * @throws Throwable
                         */
                        public function synchronized(callable $callback);
                    }

                    abstract class AbstractMutex implements Mutex {
                        public function synchronized(callable $callback) {
                            return $callback();
                        }
                    }

                    final class RedisMutex extends AbstractMutex {}

                    final class Service {
                        /**
                         * @throws DomainException
                         * @throws Throwable
                         */
                        public function run(RedisMutex $mutex): bool {
                            return $mutex->synchronized(static function (): bool {
                                throw new DomainException();
                            });
                        }
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'addDirectThrowFromCallUserFuncImmediatelyInvokedClosure' => [
                'input' => '<?php
                    final class Cache {
                        public function getOrSet(callable $callback) {
                            return call_user_func($callback);
                        }

                        public function getOrSetArray(callable $callback) {
                            return call_user_func_array($callback, []);
                        }
                    }

                    final class Service {
                        /** @throws Throwable */
                        public function run(Cache $cache): bool {
                            return $cache->getOrSet(static function (): bool {
                                throw new DomainException();
                            });
                        }

                        /** @throws Throwable */
                        public function runArray(Cache $cache): bool {
                            return $cache->getOrSetArray(static function (): bool {
                                throw new InvalidArgumentException();
                            });
                        }
                    }',
                'output' => '<?php
                    final class Cache {
                        public function getOrSet(callable $callback) {
                            return call_user_func($callback);
                        }

                        public function getOrSetArray(callable $callback) {
                            return call_user_func_array($callback, []);
                        }
                    }

                    final class Service {
                        /**
                         * @throws DomainException
                         */
                        public function run(Cache $cache): bool {
                            return $cache->getOrSet(static function (): bool {
                                throw new DomainException();
                            });
                        }

                        /**
                         * @throws InvalidArgumentException
                         */
                        public function runArray(Cache $cache): bool {
                            return $cache->getOrSetArray(static function (): bool {
                                throw new InvalidArgumentException();
                            });
                        }
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock', 'OverlyBroadThrowsDocblock', 'UnusedThrowsDocblock'],
                'safe_types' => true,
            ],
            'addThrowsFromImmediatelyInvokedNativeCallbacks' => [
                'input' => '<?php
                    final class CollectionService {
                        /** @throws Throwable */
                        public function map(array $values): array {
                            return array_map(
                                static function (int $value): int {
                                    if ($value < 0) {
                                        throw new DomainException();
                                    }
                                    return $value * 2;
                                },
                                $values,
                            );
                        }

                        /** @throws Throwable */
                        public function sort(array $values): array {
                            usort($values, static function (int $left, int $right): int {
                                if ($left === $right) {
                                    throw new LogicException();
                                }
                                return $left <=> $right;
                            });
                            return $values;
                        }
                    }',
                'output' => '<?php
                    final class CollectionService {
                        /**
                         * @throws DomainException
                         */
                        public function map(array $values): array {
                            return array_map(
                                static function (int $value): int {
                                    if ($value < 0) {
                                        throw new DomainException();
                                    }
                                    return $value * 2;
                                },
                                $values,
                            );
                        }

                        /**
                         * @throws LogicException
                         */
                        public function sort(array $values): array {
                            usort($values, static function (int $left, int $right): int {
                                if ($left === $right) {
                                    throw new LogicException();
                                }
                                return $left <=> $right;
                            });
                            return $values;
                        }
                    }',
                'php_version' => '8.3',
                'issues_to_fix' => ['MissingThrowsDocblock', 'OverlyBroadThrowsDocblock', 'UnusedThrowsDocblock'],
                'safe_types' => true,
            ],
            'resolveLiteralContainerGetFromPseudoProperty' => [
                'input' => '<?php
                    final class Gateway {
                        /** @throws DomainException */
                        public function dispatch(): void {
                            throw new DomainException();
                        }
                    }

                    /** @property-read Gateway $gateway */
                    final class ServiceContainer {
                        public function get(string $id): object {
                            return new Gateway();
                        }
                    }

                    final class Worker {
                        /** @throws Throwable */
                        public function run(ServiceContainer $container): void {
                            $container->get("gateway")->dispatch();
                        }
                    }',
                'output' => '<?php
                    final class Gateway {
                        /** @throws DomainException */
                        public function dispatch(): void {
                            throw new DomainException();
                        }
                    }

                    /** @property-read Gateway $gateway */
                    final class ServiceContainer {
                        public function get(string $id): object {
                            return new Gateway();
                        }
                    }

                    final class Worker {
                        /**
                         * @throws DomainException
                         */
                        public function run(ServiceContainer $container): void {
                            $container->get("gateway")->dispatch();
                        }
                    }',
                'php_version' => '8.3',
                'issues_to_fix' => ['MissingThrowsDocblock', 'OverlyBroadThrowsDocblock', 'UnusedThrowsDocblock'],
                'safe_types' => true,
            ],
            'doNotAddThrowFromDeferredClosure' => [
                'input' => '<?php
                    final class CallbackStore {
                        public function defer(callable $callback): Closure {
                            return static function () use ($callback): void {
                                $callback();
                            };
                        }
                    }

                    final class Service {
                        public function register(CallbackStore $store): Closure {
                            return $store->defer(static function (): void {
                                throw new DomainException();
                            });
                        }
                    }',
                'output' => '<?php
                    final class CallbackStore {
                        public function defer(callable $callback): Closure {
                            return static function () use ($callback): void {
                                $callback();
                            };
                        }
                    }

                    final class Service {
                        public function register(CallbackStore $store): Closure {
                            return $store->defer(static function (): void {
                                throw new DomainException();
                            });
                        }
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'doNotAddThrowFromNativeDeferredCallback' => [
                'input' => '<?php
                    final class Bootstrap {
                        public function register(): void {
                            register_shutdown_function(static function (): void {
                                throw new DomainException();
                            });
                        }
                    }',
                'output' => '<?php
                    final class Bootstrap {
                        public function register(): void {
                            register_shutdown_function(static function (): void {
                                throw new DomainException();
                            });
                        }
                    }',
                'php_version' => '8.3',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'alwaysThrowingFinallyMasksTryException' => [
                'input' => '<?php
                    final class Service {
                        /** @throws Throwable */
                        public function run(): void {
                            try {
                                throw new LogicException();
                            } finally {
                                throw new RuntimeException();
                            }
                        }
                    }',
                'output' => '<?php
                    final class Service {
                        /**
                         * @throws RuntimeException
                         */
                        public function run(): void {
                            try {
                                throw new LogicException();
                            } finally {
                                throw new RuntimeException();
                            }
                        }
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock', 'OverlyBroadThrowsDocblock', 'UnusedThrowsDocblock'],
                'safe_types' => true,
            ],
            'doNotAddThrowFromGeneratorCallback' => [
                'input' => '<?php
                    final class Stream {
                        public function defer(callable $callback): Generator {
                            yield $callback();
                        }
                    }

                    final class Service {
                        public function register(Stream $stream): Generator {
                            return $stream->defer(static function (): void {
                                throw new DomainException();
                            });
                        }
                    }',
                'output' => '<?php
                    final class Stream {
                        public function defer(callable $callback): Generator {
                            yield $callback();
                        }
                    }

                    final class Service {
                        public function register(Stream $stream): Generator {
                            return $stream->defer(static function (): void {
                                throw new DomainException();
                            });
                        }
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'treatFluentExceptionConstructionAsDirectThrow' => [
                'input' => '<?php
                    class ApiException extends Exception {}
                    class BadRequestExceptionWithContext extends ApiException {
                        public function withContext(): self {
                            return $this;
                        }
                    }

                    /** @throws ApiException */
                    function foo(): void {
                        throw (new BadRequestExceptionWithContext())->withContext();
                    }',
                'output' => '<?php
                    class ApiException extends Exception {}
                    class BadRequestExceptionWithContext extends ApiException {
                        public function withContext(): self {
                            return $this;
                        }
                    }

                    /**
                     * @throws ApiException
                     * @throws BadRequestExceptionWithContext
                     */
                    function foo(): void {
                        throw (new BadRequestExceptionWithContext())->withContext();
                    }',
                'php_version' => '8.1',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'preserveFluentDirectThrowThroughNarrowedRethrow' => [
                'input' => '<?php
                    class ApiException extends Exception {}
                    class BadRequestException extends ApiException {}
                    class BadRequestExceptionWithContext extends BadRequestException {
                        public function withContext(): self {
                            return $this;
                        }
                    }

                    /** @throws ApiException */
                    function foo(): void {
                        try {
                            throw (new BadRequestExceptionWithContext())->withContext();
                        } catch (Throwable $throwable) {
                            if ($throwable instanceof ApiException) {
                                throw $throwable;
                            }

                            throw new BadRequestException();
                        }
                    }',
                'output' => '<?php
                    class ApiException extends Exception {}
                    class BadRequestException extends ApiException {}
                    class BadRequestExceptionWithContext extends BadRequestException {
                        public function withContext(): self {
                            return $this;
                        }
                    }

                    /**
                     * @throws ApiException
                     * @throws BadRequestException
                     * @throws BadRequestExceptionWithContext
                     */
                    function foo(): void {
                        try {
                            throw (new BadRequestExceptionWithContext())->withContext();
                        } catch (Throwable $throwable) {
                            if ($throwable instanceof ApiException) {
                                throw $throwable;
                            }

                            throw new BadRequestException();
                        }
                    }',
                'php_version' => '8.1',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'treatExceptionReturnedByFactoryAsDirectThrow' => [
                'input' => '<?php
                    class ApiException extends Exception {}
                    class BadRequestException extends ApiException {}
                    class ExceptionFactory {
                        public function create(): BadRequestException {
                            return new BadRequestException();
                        }
                    }

                    /** @throws ApiException */
                    function foo(ExceptionFactory $factory): void {
                        throw $factory->create();
                    }',
                'output' => '<?php
                    class ApiException extends Exception {}
                    class BadRequestException extends ApiException {}
                    class ExceptionFactory {
                        public function create(): BadRequestException {
                            return new BadRequestException();
                        }
                    }

                    /**
                     * @throws ApiException
                     * @throws BadRequestException
                     */
                    function foo(ExceptionFactory $factory): void {
                        throw $factory->create();
                    }',
                'php_version' => '8.1',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'treatExceptionReturnedByStaticHelperAsDirectThrow' => [
                'input' => '<?php
                    class BaseException extends Exception {}
                    class ApiException extends BaseException {}
                    class ExceptionFactory {
                        public static function create(): ApiException {
                            return new ApiException();
                        }
                    }

                    /** @throws BaseException */
                    function foo(): void {
                        throw ExceptionFactory::create();
                    }',
                'output' => '<?php
                    class BaseException extends Exception {}
                    class ApiException extends BaseException {}
                    class ExceptionFactory {
                        public static function create(): ApiException {
                            return new ApiException();
                        }
                    }

                    /**
                     * @throws ApiException
                     * @throws BaseException
                     */
                    function foo(): void {
                        throw ExceptionFactory::create();
                    }',
                'php_version' => '8.1',
                'issues_to_fix' => ['MissingThrowsDocblock'],
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
                     * @throws InvalidArgumentException
                     * @throws RuntimeException
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
            'addSpecificExceptionLostByRethrownThrowable' => [
                'input' => '<?php
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
                'output' => '<?php
                    interface Service {
                        /** @throws Throwable */
                        public function execute(): void;
                    }

                    /**
                     * @throws RuntimeException
                     * @throws Throwable
                     */
                    function foo(Service $service): void {
                        try {
                            $service->execute();
                            throw new RuntimeException();
                        } catch (Throwable $throwable) {
                            error_log($throwable->getMessage());
                            throw $throwable;
                        }
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'narrowRethrownThrowableAndPreserveSpecificExceptions' => [
                'input' => '<?php
                    class ApiException extends Exception {}
                    class ExternalServiceOperationError extends ApiException {}
                    class ValidateException extends ApiException {}

                    interface Service {
                        /** @throws Throwable */
                        public function execute(): void;
                    }

                    function foo(Service $service): void {
                        try {
                            $service->execute();
                            throw new ExternalServiceOperationError();
                        } catch (Throwable $throwable) {
                            if ($throwable instanceof ApiException) {
                                throw $throwable;
                            }

                            throw new ValidateException();
                        }
                    }',
                'output' => '<?php
                    class ApiException extends Exception {}
                    class ExternalServiceOperationError extends ApiException {}
                    class ValidateException extends ApiException {}

                    interface Service {
                        /** @throws Throwable */
                        public function execute(): void;
                    }

                    /**
                     * @throws ApiException
                     * @throws ExternalServiceOperationError
                     * @throws ValidateException
                     */
                    function foo(Service $service): void {
                        try {
                            $service->execute();
                            throw new ExternalServiceOperationError();
                        } catch (Throwable $throwable) {
                            if ($throwable instanceof ApiException) {
                                throw $throwable;
                            }

                            throw new ValidateException();
                        }
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'preserveDirectAndNarrowedThrowsButCollapsePropagatedSubtype' => [
                'input' => '<?php
                    class ApiException extends Exception {}
                    class ExternalServiceOperationError extends ApiException {}
                    class ValidateException extends ApiException {}

                    class Service {
                        /** @throws ExternalServiceOperationError */
                        public function execute(): void {}
                    }

                    function foo(Service $service): void {
                        try {
                            $service->execute();
                        } catch (Throwable $throwable) {
                            if ($throwable instanceof ApiException) {
                                throw $throwable;
                            }

                            throw new ValidateException();
                        }
                    }',
                'output' => '<?php
                    class ApiException extends Exception {}
                    class ExternalServiceOperationError extends ApiException {}
                    class ValidateException extends ApiException {}

                    class Service {
                        /** @throws ExternalServiceOperationError */
                        public function execute(): void {}
                    }

                    /**
                     * @throws ApiException
                     * @throws ValidateException
                     */
                    function foo(Service $service): void {
                        try {
                            $service->execute();
                        } catch (Throwable $throwable) {
                            if ($throwable instanceof ApiException) {
                                throw $throwable;
                            }

                            throw new ValidateException();
                        }
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'preserveDescribedBroadThrowsCoveredByNarrowerAnnotation' => [
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
                     * @throws Exception generic failure
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
                         * @throws InvalidArgumentException
                         * @throws RandomException
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

                    class Facade {
                        /** @var Service */
                        public static $app;
                    }

                    class Consumer {
                        /** @throws RuntimeException */
                        public function execute(): void {
                            Facade::$app->execute();
                        }
                    }',
                'output' => '<?php
                    class Service {
                        /** @throws RuntimeException */
                        public function execute(): void {
                            throw new RuntimeException();
                        }
                    }

                    class Facade {
                        /** @var Service */
                        public static $app;
                    }

                    class Consumer {
                        /** @throws RuntimeException */
                        public function execute(): void {
                            Facade::$app->execute();
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
                     * @throws DomainException
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
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'preserveCompletePropagatedThrowsHierarchy' => [
                'input' => '<?php
                    /** @throws \Exception */
                    function throwsException(): void {}

                    /** @throws \Throwable */
                    function throwsThrowable(): void {}

                    /** @throws \RuntimeException */
                    function throwsRuntimeException(): void {}

                    function foo(): void {
                        throwsException();
                        throwsThrowable();
                        throwsRuntimeException();
                    }',
                'output' => '<?php
                    /** @throws \Exception */
                    function throwsException(): void {}

                    /** @throws \Throwable */
                    function throwsThrowable(): void {}

                    /** @throws \RuntimeException */
                    function throwsRuntimeException(): void {}

                    /**
                     * @throws Exception
                     * @throws RuntimeException
                     * @throws Throwable
                     */
                    function foo(): void {
                        throwsException();
                        throwsThrowable();
                        throwsRuntimeException();
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
                     * @throws DomainException
                     * @throws Exception
                     * @throws InvalidArgumentException
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
                     * @throws DomainException
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
                    use RuntimeException;
                    class SomeClass {
                        /**
                         * @return void
                         * @return void
                         *
                         * @throws Exception
                         * @throws RuntimeException
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
            'removeDuplicateThrowsTypeFromUnionAnnotation' => [
                'input' => '<?php
                    /**
                     * @throws InvalidArgumentException|DomainException
                     * @throws InvalidArgumentException
                     */
                    function foo(bool $invalid): void {
                        if ($invalid) {
                            throw new InvalidArgumentException();
                        }

                        throw new DomainException();
                    }',
                'output' => '<?php
                    /**
                     * @throws DomainException
                     * @throws InvalidArgumentException
                     */
                    function foo(bool $invalid): void {
                        if ($invalid) {
                            throw new InvalidArgumentException();
                        }

                        throw new DomainException();
                    }',
                'php_version' => '7.4',
                'issues_to_fix' => ['MissingThrowsDocblock'],
                'safe_types' => true,
            ],
            'removeUndescribedDuplicateBeforeDescribedThrowsUnion' => [
                'input' => '<?php
                    /**
                     * @throws InvalidArgumentException
                     * @throws InvalidArgumentException|DomainException when validation fails
                     */
                    function foo(bool $invalid): void {
                        if ($invalid) {
                            throw new InvalidArgumentException();
                        }

                        throw new DomainException();
                    }',
                'output' => '<?php
                    /**
                     * @throws InvalidArgumentException|DomainException when validation fails
                     */
                    function foo(bool $invalid): void {
                        if ($invalid) {
                            throw new InvalidArgumentException();
                        }

                        throw new DomainException();
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
            'keepFullyQualifiedThrowsAnnotationWhenNamespacePrefixAliasConflicts' => [
                'input' => '<?php
                    namespace yii\base {
                        class Exception extends \Exception {}
                    }
                    namespace App {
                        use DomainException as Exception;
                        use Yii;
                        function foo(): void {
                            throw new \yii\base\Exception();
                        }
                    }',
                'output' => '<?php
                    namespace yii\base {
                        class Exception extends \Exception {}
                    }
                    namespace App {
                        use DomainException as Exception;
                        use Yii;
                        /**
                         * @throws \yii\base\Exception
                         */
                        function foo(): void {
                            throw new \yii\base\Exception();
                        }
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
