<?php

declare(strict_types=1);

namespace Psalm\Tests\EndToEnd;

use Exception;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function array_keys;
use function array_slice;
use function assert;
use function closedir;
use function copy;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function getcwd;
use function glob;
use function is_dir;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function opendir;
use function preg_replace;
use function readdir;
use function rmdir;
use function str_replace;
use function strpos;
use function substr;
use function substr_count;
use function sys_get_temp_dir;
use function tempnam;
use function touch;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;

/**
 * Tests some of the most important use cases of the psalm and psalter commands, by launching a new
 * process as if invoked by a real user.
 *
 * This is primarily intended to test the code in `psalm`, `src/psalm.php` and related files.
 */
final class PsalmEndToEndTest extends TestCase
{
    use PsalmRunnerTrait;

    private static string $tmpDir;

    #[Override]
    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = tempnam(sys_get_temp_dir(), 'PsalmEndToEndTest_');
        assert(self::$tmpDir !== false);
        unlink(self::$tmpDir);
        mkdir(self::$tmpDir);

        $getcwd = getcwd();
        if (!is_string($getcwd)) {
            throw new Exception('Couldn\'t get working directory');
        }

        copy(__DIR__ . '/../fixtures/DummyProjectWithErrors/composer.json', self::$tmpDir . '/composer.json');

        $process = new Process(['composer', 'install', '--no-plugins'], self::$tmpDir, null, null, 120);
        $process->mustRun();
    }

    #[Override]
    public static function tearDownAfterClass(): void
    {
        self::recursiveRemoveDirectory(self::$tmpDir);
        parent::tearDownAfterClass();
    }

    #[Override]
    public function setUp(): void
    {
        mkdir(self::$tmpDir . '/src');

        copy(
            __DIR__ . '/../fixtures/DummyProjectWithErrors/src/FileWithErrors.php',
            self::$tmpDir . '/src/FileWithErrors.php',
        );
        parent::setUp();
    }

    #[Override]
    public function tearDown(): void
    {
        @unlink(self::$tmpDir . '/psalm.xml');

        if (file_exists(self::$tmpDir . '/.git')) {
            self::recursiveRemoveDirectory(self::$tmpDir . '/.git');
        }

        if (file_exists(self::$tmpDir . '/cache')) {
            self::recursiveRemoveDirectory(self::$tmpDir . '/cache');
        }

        if (file_exists(self::$tmpDir . '/src')) {
            self::recursiveRemoveDirectory(self::$tmpDir . '/src');
        }

        parent::tearDown();
    }

    public function testHelpReturnsMessage(): void
    {
        $output = $this->runPsalm(['--help'], self::$tmpDir)['STDOUT'];

        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('--find-unused-variables', $output);
    }

    public function testPsalterHelpContainsFindUnusedVariablesOption(): void
    {
        $output = $this->runPsalm(['--alter', '--help'], self::$tmpDir)['STDOUT'];

        $this->assertStringContainsString('--find-unused-variables', $output);
        $this->assertStringContainsString('--changed', $output);
    }

    public function testPsalterChangesOnlyFunctionsTouchedByGitDiff(): void
    {
        $this->runPsalmInit();
        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace('<psalm', '<psalm checkForThrowsDocblock="true"', (string) $psalmXml);
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        $file_path = self::$tmpDir . '/src/FileWithErrors.php';
        file_put_contents(
            $file_path,
            <<<'PHP'
                <?php

                namespace Foo;

                function changed(): void
                {
                    throw new \RuntimeException();
                }

                function unchanged(): void
                {
                    throw new \LogicException();
                }
                PHP,
        );

        (new Process(['git', 'init', '-q'], self::$tmpDir))->mustRun();
        (new Process(['git', 'add', 'psalm.xml', 'src/FileWithErrors.php'], self::$tmpDir))->mustRun();
        (new Process(
            ['git', '-c', 'user.name=Psalm', '-c', 'user.email=psalm@example.com', 'commit', '-qm', 'Initial'],
            self::$tmpDir,
        ))->mustRun();

        $contents = file_get_contents($file_path);
        $this->assertIsString($contents);
        file_put_contents($file_path, str_replace('RuntimeException();', 'RuntimeException("changed");', $contents));

        $process = new Process([
            PHP_BINARY,
            $this->psalter,
            '--changed',
            '--issues=MissingThrowsDocblock',
            '--no-progress',
        ], self::$tmpDir);
        $process->run();
        $this->assertSame(2, $process->getExitCode());

        $contents = file_get_contents($file_path);
        $this->assertIsString($contents);
        $this->assertStringContainsString('@throws RuntimeException', $contents);
        $this->assertStringNotContainsString('@throws LogicException', $contents);
    }

    public function testPsalterMixesFullFilesWithChangedMethodsAndRelocatesErrors(): void
    {
        unlink(self::$tmpDir . '/src/FileWithErrors.php');
        file_put_contents(self::$tmpDir . '/psalm.xml', <<<'XML'
            <psalm xmlns="https://getpsalm.org/schema/config" errorLevel="8"
                checkForThrowsDocblock="true" findUnusedCode="false" cacheDirectory="cache">
                <projectFiles><directory name="src" /></projectFiles>
                <issueHandlers>
                    <MissingPureAnnotation errorLevel="suppress" />
                    <MissingImmutableAnnotation errorLevel="suppress" />
                </issueHandlers>
            </psalm>
            XML);
        $partial = self::$tmpDir . '/src/Partial.php';
        $full = self::$tmpDir . '/src/Whole file.php';
        file_put_contents($partial, <<<'PHP'
            <?php
            namespace Foo;
            class Partial {
                public function changed(): void {
                    partial_missing();
                    throw new \RuntimeException();
                }
                public function untouched(): void {
                    legacy_missing();
                    throw new \DomainException();
                }
            }
            PHP);
        file_put_contents($full, <<<'PHP'
            <?php
            namespace Foo;

            new \MissingFullClass();

            function whole(): void {
                whole_missing();
                throw new \LogicException();
            }
            PHP);
        (new Process(['git', 'init', '-q'], self::$tmpDir))->mustRun();
        (new Process(['git', 'add', 'src', 'psalm.xml'], self::$tmpDir))->mustRun();
        (new Process(
            ['git', '-c', 'user.name=Psalm', '-c', 'user.email=psalm@example.com', 'commit', '-qm', 'Initial'],
            self::$tmpDir,
        ))->mustRun();
        file_put_contents($partial, str_replace(
            'partial_missing();',
            'partial_missing(); // edited',
            (string) file_get_contents($partial),
        ));
        $before = (string) file_get_contents($partial);
        $arguments = [
            PHP_BINARY,
            $this->psalter,
            '--changed',
            '--report-changed',
            '--full-file=' . $full,
            '--issues=MissingThrowsDocblock,OverlyBroadThrowsDocblock,UnusedThrowsDocblock',
            '--threads=2',
            '--scan-threads=1',
            '--no-progress',
            '-m',
        ];
        $preview = new Process([...$arguments, '--dry-run', $partial], self::$tmpDir);
        $preview->run();
        $this->assertSame(2, $preview->getExitCode(), $preview->getOutput() . $preview->getErrorOutput());
        $this->assertSame($before, file_get_contents($partial));
        $this->assertStringNotContainsString('legacy_missing', $preview->getOutput());

        $process = new Process([...$arguments, $partial], self::$tmpDir);
        $process->run();
        $this->assertSame(2, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringNotContainsString('legacy_missing', $process->getOutput());
        $this->assertStringContainsString('UndefinedClass - src/Whole file.php:', $process->getOutput());
        foreach ([$partial => 'partial_missing', $full => 'whole_missing'] as $path => $call) {
            $source = (string) file_get_contents($path);
            $position = strpos($source, $call . '()');
            $this->assertNotFalse($position);
            $line = substr_count(substr($source, 0, $position), "\n") + 1;
            $this->assertStringContainsString(
                ' - src/' . ($path === $partial ? 'Partial.php' : 'Whole file.php') . ':' . $line . ':',
                $process->getOutput(),
            );
        }
        $contents = (string) file_get_contents($partial);
        $this->assertStringContainsString('@throws RuntimeException', $contents);
        $this->assertStringNotContainsString('@throws DomainException', $contents);
        $this->assertStringContainsString('@throws LogicException', (string) file_get_contents($full));
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertSame($contents, file_get_contents($partial));

        // Only the legacy error remains. It must neither appear nor cause exit 2.
        file_put_contents($partial, str_replace('partial_missing();', '', $contents));
        $filtered = new Process([
            PHP_BINARY, $this->psalter, '--changed', '--report-changed',
            '--issues=MissingThrowsDocblock', '--threads=1', '--scan-threads=1', '--no-progress', $partial,
        ], self::$tmpDir);
        $filtered->mustRun();
        $this->assertStringNotContainsString('legacy_missing', $filtered->getOutput());
    }

    public function testPsalterRejectsReportScopeWithoutChangedMode(): void
    {
        $this->runPsalmInit();
        foreach (['--report-changed', '--full-file=' . self::$tmpDir . '/src/FileWithErrors.php'] as $option) {
            $process = new Process([
                PHP_BINARY, $this->psalter, $option, '--issues=MissingThrowsDocblock', '--no-progress',
            ], self::$tmpDir);
            $process->run();
            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('require --changed', $process->getErrorOutput());
        }
    }

    public function testPsalterTreatsChangedDocblockAsChangedFunction(): void
    {
        $this->runPsalmInit();
        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace('<psalm', '<psalm checkForThrowsDocblock="true"', (string) $psalmXml);
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        $file_path = self::$tmpDir . '/src/FileWithErrors.php';
        file_put_contents(
            $file_path,
            <<<'PHP'
                <?php

                namespace Foo;

                /** Initial description */
                function changed(): void
                {
                    throw new \RuntimeException();
                }
                PHP,
        );

        (new Process(['git', 'init', '-q'], self::$tmpDir))->mustRun();
        (new Process(['git', 'add', 'psalm.xml', 'src/FileWithErrors.php'], self::$tmpDir))->mustRun();
        (new Process(
            ['git', '-c', 'user.name=Psalm', '-c', 'user.email=psalm@example.com', 'commit', '-qm', 'Initial'],
            self::$tmpDir,
        ))->mustRun();

        $contents = file_get_contents($file_path);
        $this->assertIsString($contents);
        file_put_contents($file_path, str_replace('Initial description', 'Changed description', $contents));

        $process = new Process([
            PHP_BINARY,
            $this->psalter,
            '--changed',
            '--issues=MissingThrowsDocblock',
            '--no-progress',
        ], self::$tmpDir);
        $process->run();
        $this->assertSame(2, $process->getExitCode());

        $contents = file_get_contents($file_path);
        $this->assertIsString($contents);
        $this->assertStringContainsString('@throws RuntimeException', $contents);
    }

    public function testPsalterBaseUsesWorkingTreeLineNumbers(): void
    {
        $this->runPsalmInit();
        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace('<psalm', '<psalm checkForThrowsDocblock="true"', (string) $psalmXml);
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        $file_path = self::$tmpDir . '/src/FileWithErrors.php';
        file_put_contents(
            $file_path,
            <<<'PHP'
                <?php

                namespace Foo;

                function changed(): void
                {
                }
                PHP,
        );

        (new Process(['git', 'init', '-q'], self::$tmpDir))->mustRun();
        (new Process(['git', 'add', 'psalm.xml', 'src/FileWithErrors.php'], self::$tmpDir))->mustRun();
        (new Process(
            ['git', '-c', 'user.name=Psalm', '-c', 'user.email=psalm@example.com', 'commit', '-qm', 'Initial'],
            self::$tmpDir,
        ))->mustRun();
        $base_process = new Process(['git', 'rev-parse', 'HEAD'], self::$tmpDir);
        $base_process->mustRun();
        $base_ref = trim($base_process->getOutput());

        $contents = file_get_contents($file_path);
        $this->assertIsString($contents);
        file_put_contents($file_path, str_replace("{\n}", "{\n    throw new \\RuntimeException();\n}", $contents));
        (new Process(['git', 'add', 'src/FileWithErrors.php'], self::$tmpDir))->mustRun();
        (new Process(
            ['git', '-c', 'user.name=Psalm', '-c', 'user.email=psalm@example.com', 'commit', '-qm', 'Feature'],
            self::$tmpDir,
        ))->mustRun();

        $contents = file_get_contents($file_path);
        $this->assertIsString($contents);
        file_put_contents(
            $file_path,
            str_replace('namespace Foo;', "namespace Foo;\n\n// Local work shifts committed line numbers.\n// 01\n// 02\n// 03", $contents),
        );

        $process = new Process([
            PHP_BINARY,
            $this->psalter,
            '--changed',
            '--base=' . $base_ref,
            '--issues=MissingThrowsDocblock',
            '--no-progress',
        ], self::$tmpDir);
        $process->run();
        $this->assertSame(2, $process->getExitCode());

        $contents = file_get_contents($file_path);
        $this->assertIsString($contents);
        $this->assertStringContainsString('@throws RuntimeException', $contents);
    }

    public function testPsalterConvergesForRecursiveMethodsWithStaleThrows(): void
    {
        $this->runPsalmInit();
        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace('<psalm', '<psalm checkForThrowsDocblock="true"', (string) $psalmXml);
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        $file_path = self::$tmpDir . '/src/FileWithErrors.php';
        file_put_contents(
            $file_path,
            <<<'PHP'
                <?php

                namespace Foo;

                use RuntimeException;

                final class RecursiveService
                {
                    /** @throws RuntimeException */
                    public function first(): void
                    {
                        $this->second();
                    }

                    public function second(): void
                    {
                        $this->first();
                    }
                }
                PHP,
        );

        $process = new Process([
            PHP_BINARY,
            $this->psalter,
            '--issues=MissingThrowsDocblock,OverlyBroadThrowsDocblock,UnusedThrowsDocblock',
            '--no-progress',
        ], self::$tmpDir);
        $process->setTimeout(10.0);
        $process->run();
        $this->assertSame(0, $process->getExitCode());

        $contents = file_get_contents($file_path);
        $this->assertIsString($contents);
        $this->assertStringNotContainsString('@throws', $contents);
    }

    public function testPsalterPreservesIgnoredThrowsAnnotations(): void
    {
        $this->runPsalmInit();
        $psalmXml = (string) file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace('<psalm', '<psalm checkForThrowsDocblock="true"', $psalmXml);
        $psalmXml = str_replace(
            '</psalm>',
            '<ignoreExceptions><class name="Exception" /><class name="RuntimeException" /></ignoreExceptions></psalm>',
            $psalmXml,
        );
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        $file_path = self::$tmpDir . '/src/FileWithErrors.php';
        file_put_contents(
            $file_path,
            <<<'PHP'
                <?php

                /**
                 * @psalm-pure
                 * @throws RuntimeException
                 */
                function ignoredUnused(): void {}

                /**
                 * @psalm-pure
                 * @throws Exception
                 */
                function ignoredBroad(): void {
                    throw new InvalidArgumentException();
                }

                /**
                 * @psalm-pure
                 * @throws LogicException
                 */
                function unused(): void {}
                PHP,
        );

        $process = new Process([
            PHP_BINARY,
            $this->psalter,
            '--issues=UnusedThrowsDocblock,OverlyBroadThrowsDocblock',
            '--no-progress',
        ], self::$tmpDir);
        $process->mustRun();

        $output = (string) file_get_contents($file_path);
        $this->assertStringContainsString('@throws RuntimeException', $output);
        $this->assertStringContainsString('@throws Exception', $output);
        $this->assertStringNotContainsString('@throws InvalidArgumentException', $output);
        $this->assertStringNotContainsString('@throws LogicException', $output);
    }

    public function testPsalterKeepsInterfaceThrowsAsContract(): void
    {
        $this->runPsalmInit();
        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace('<psalm', '<psalm checkForThrowsDocblock="true"', (string) $psalmXml);
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        $file_path = self::$tmpDir . '/src/FileWithErrors.php';
        file_put_contents(
            $file_path,
            <<<'PHP'
                <?php

                namespace Foo;

                use RuntimeException;

                interface Service
                {
                    /** @throws RuntimeException */
                    public function execute(): void;
                }

                final class Consumer
                {
                    public function __construct(private Service $service)
                    {
                    }

                    public function consume(): void
                    {
                        $this->service->execute();
                    }
                }
                PHP,
        );

        $process = new Process([
            PHP_BINARY,
            $this->psalter,
            '--issues=MissingThrowsDocblock,OverlyBroadThrowsDocblock,UnusedThrowsDocblock',
            '--no-progress',
        ], self::$tmpDir);
        $process->run();
        $this->assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());

        $contents = file_get_contents($file_path);
        $this->assertIsString($contents);
        $this->assertSame(2, substr_count($contents, '@throws RuntimeException'));
    }

    public function testInit(): void
    {
        $this->assertStringStartsWith(
            'Calculating best config level based on project files',
            $this->runPsalmInit()['STDOUT'],
        );
        $this->assertFileExists(self::$tmpDir . '/psalm.xml');
    }

    public function testAlter(): void
    {
        $this->runPsalmInit();

        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace('<psalm', '<psalm runTaintAnalysis="false"', (string)$psalmXml);
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        $this->assertStringContainsString(
            'No errors found!',
            $this->runPsalm(['--alter', '--issues=all'], self::$tmpDir, false, true)['STDOUT'],
        );

        $this->assertSame(0, $this->runPsalm([], self::$tmpDir)['CODE']);
    }

    public function testPsalter(): void
    {
        $this->runPsalmInit();
        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace('<psalm', '<psalm runTaintAnalysis="false"', (string)$psalmXml);
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        (new Process([PHP_BINARY, $this->psalter, '--alter', '--issues=InvalidReturnType'], self::$tmpDir))->mustRun();
        $this->assertSame(0, $this->runPsalm([], self::$tmpDir)['CODE']);
    }

    public function testPsalterReportsUnusedVariablesWhileAltering(): void
    {
        $this->runPsalmInit();

        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace(
            '<psalm',
            '<psalm checkForThrowsDocblock="true" runTaintAnalysis="false"',
            (string) $psalmXml,
        );
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);
        file_put_contents(
            self::$tmpDir . '/src/FileWithErrors.php',
            <<<'PHP'
                <?php

                namespace Foo;

                use RuntimeException;

                function execute(): void
                {
                    $unused = 1;

                    throw new RuntimeException();
                }

                /** @throws \Exception */
                function broad(): void
                {
                    throw new RuntimeException();
                }
                PHP,
        );
        file_put_contents(
            self::$tmpDir . '/src/SelectedFile.php',
            <<<'PHP'
                <?php

                namespace Foo;

                /** @throws \RuntimeException */
                function selected(): void
                {
                }
                PHP,
        );
        file_put_contents(
            self::$tmpDir . '/src/UnselectedFile.php',
            <<<'PHP'
                <?php

                namespace Foo;

                function unselected(): void
                {
                    undefined_function();
                }
                PHP,
        );

        $result = $this->runPsalm(
            [
                '--alter',
                '--issues=MissingThrowsDocblock,OverlyBroadThrowsDocblock,UnusedThrowsDocblock',
                '--find-unused-variables',
                self::$tmpDir . '/src/FileWithErrors.php',
                self::$tmpDir . '/src/SelectedFile.php',
            ],
            self::$tmpDir,
            true,
        );

        $this->assertSame(2, $result['CODE']);
        $this->assertStringContainsString('UnusedVariable', $result['STDOUT']);
        $this->assertStringNotContainsString('UnselectedFile.php', $result['STDOUT']);
        $this->assertStringNotContainsString('MissingThrowsDocblock -', $result['STDOUT']);
        $this->assertStringContainsString(
            '@throws RuntimeException',
            (string) file_get_contents(self::$tmpDir . '/src/FileWithErrors.php'),
        );
        $this->assertStringNotContainsString(
            '@throws \Exception',
            (string) file_get_contents(self::$tmpDir . '/src/FileWithErrors.php'),
        );
        $this->assertStringNotContainsString(
            '@throws',
            (string) file_get_contents(self::$tmpDir . '/src/SelectedFile.php'),
        );
    }

    public function testPsalterKeepsThrowsStableDuringInitializationAnalysis(): void
    {
        $this->runPsalmInit();

        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace(
            '<psalm',
            '<psalm checkForThrowsDocblock="true" runTaintAnalysis="false"',
            (string) $psalmXml,
        );
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);
        file_put_contents(
            self::$tmpDir . '/src/BaseModel.php',
            <<<'PHP'
                <?php

                namespace Foo;

                use RuntimeException;

                class BaseModel
                {
                    /** @throws RuntimeException */
                    protected function computeRelatedParams(): void
                    {
                        throw new RuntimeException();
                    }
                }
                PHP,
        );

        $selectedFile = self::$tmpDir . '/src/SelectedFile.php';
        $selectedFileContents = <<<'PHP'
            <?php

            namespace Foo;

            use RuntimeException;

            class SelectedFile extends BaseModel
            {
                private string $value;

                /** @throws RuntimeException */
                public function __construct()
                {
                    $this->value = '';
                    $this->computeRelatedParams();
                }
            }
            PHP;
        file_put_contents($selectedFile, $selectedFileContents);

        $arguments = [
            '--alter',
            '--php-version=8.3',
            '--issues=MissingThrowsDocblock,UnusedThrowsDocblock',
            $selectedFile,
        ];

        $this->runPsalm($arguments, self::$tmpDir);
        $this->assertSame($selectedFileContents, file_get_contents($selectedFile));

        $this->runPsalm($arguments, self::$tmpDir);
        $this->assertSame($selectedFileContents, file_get_contents($selectedFile));
    }

    public function testPsalterConvergesAfterNarrowingCalleeThrows(): void
    {
        $this->runPsalmInit();

        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace(
            '<psalm',
            '<psalm checkForThrowsDocblock="true" runTaintAnalysis="false"',
            (string) $psalmXml,
        );
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        $selectedFile = self::$tmpDir . '/src/SelectedFile.php';
        file_put_contents(
            $selectedFile,
            <<<'PHP'
                <?php

                namespace Foo;

                use RuntimeException;

                class SelectedFile
                {
                    private int $calls = 0;

                    /**
                     * @throws \Throwable
                     * @psalm-external-mutation-free
                     */
                    public function notify(): void
                    {
                        $this->getPayload();
                    }

                    /**
                     * @throws \Throwable
                     * @psalm-external-mutation-free
                     */
                    public function getPayload(): void
                    {
                        $this->calls++;
                        throw new RuntimeException();
                    }
                }
                PHP,
        );

        $arguments = [
            '--alter',
            '--php-version=8.3',
            '--issues=MissingThrowsDocblock,OverlyBroadThrowsDocblock,UnusedThrowsDocblock',
            $selectedFile,
        ];

        $this->runPsalm($arguments, self::$tmpDir);
        $contents = file_get_contents($selectedFile);
        $this->assertIsString($contents);
        $this->assertSame(2, substr_count($contents, '@throws RuntimeException'));
        $this->assertStringNotContainsString('@throws \Throwable', $contents);

        $this->runPsalm($arguments, self::$tmpDir);
        $this->assertSame($contents, file_get_contents($selectedFile));
    }

    public function testPsalterConvergesAfterRemovingCalleeThrows(): void
    {
        $this->runPsalmInit();

        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace(
            '<psalm',
            '<psalm checkForThrowsDocblock="true" runTaintAnalysis="false"',
            (string) $psalmXml,
        );
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        $selectedFile = self::$tmpDir . '/src/SelectedFile.php';
        file_put_contents(
            $selectedFile,
            <<<'PHP'
                <?php

                namespace Foo;

                use RuntimeException;

                class SelectedFile
                {
                    private int $calls = 0;

                    /**
                     * @throws RuntimeException
                     * @psalm-external-mutation-free
                     */
                    public function execute(): void
                    {
                        $this->doNothing();
                    }

                    /**
                     * @throws RuntimeException
                     * @psalm-external-mutation-free
                     */
                    private function doNothing(): void
                    {
                        $this->calls++;
                    }
                }
                PHP,
        );

        $arguments = [
            '--alter',
            '--php-version=8.3',
            '--issues=MissingThrowsDocblock,UnusedThrowsDocblock',
            $selectedFile,
        ];

        $this->runPsalm($arguments, self::$tmpDir);
        $contents = file_get_contents($selectedFile);
        $this->assertIsString($contents);
        $this->assertStringNotContainsString('@throws', $contents);

        $this->runPsalm($arguments, self::$tmpDir);
        $this->assertSame($contents, file_get_contents($selectedFile));
    }

    public function testPsalterUsesConfiguredThrowsImportAlias(): void
    {
        $this->runPsalmInit();

        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace(
            '<psalm',
            '<psalm checkForThrowsDocblock="true" runTaintAnalysis="false"',
            (string) $psalmXml,
        );
        $psalmXml = str_replace(
            '</psalm>',
            <<<'XML'
                <throwsImportAliases>
                    <class name="yii\base\Exception" alias="BaseException"/>
                </throwsImportAliases>
                </psalm>
                XML,
            $psalmXml,
        );
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        $selectedFile = self::$tmpDir . '/src/SelectedFile.php';
        file_put_contents(
            $selectedFile,
            <<<'PHP'
                <?php

                namespace yii\base {
                    class Exception extends \Exception {}
                }

                namespace App {
                    use DomainException as Exception;
                    use Yii;

                    /** @psalm-pure */
                    function execute(): void
                    {
                        throw new \yii\base\Exception();
                    }
                }
                PHP,
        );

        $this->runPsalm(
            [
                '--alter',
                '--php-version=8.3',
                '--issues=MissingThrowsDocblock',
                $selectedFile,
            ],
            self::$tmpDir,
        );

        $contents = file_get_contents($selectedFile);
        $this->assertIsString($contents);
        $this->assertStringContainsString('use yii\base\Exception as BaseException;', $contents);
        $this->assertStringContainsString('@throws BaseException', $contents);
    }

    public function testPsalterKeepsThrowsDocumentedByMultipleCallersOfPrivateMethod(): void
    {
        $this->runPsalmInit();

        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace(
            '<psalm',
            '<psalm checkForThrowsDocblock="true" runTaintAnalysis="false"',
            (string) $psalmXml,
        );
        $psalmXml = str_replace('findUnusedCode="true"', 'findUnusedCode="false"', $psalmXml);
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        $selectedFile = self::$tmpDir . '/src/SelectedFile.php';
        $selectedFileContents = <<<'PHP'
            <?php

            namespace Foo;

            use Exception;
            use InvalidArgumentException;

            class SelectedFile
            {
                /**
                 * @throws Exception
                 * @throws InvalidArgumentException
                 */
                public function first(): void
                {
                    $this->fail();
                }

                /**
                 * @throws Exception
                 * @throws InvalidArgumentException
                 */
                public function second(): void
                {
                    $this->fail();
                }

                /**
                 * @throws Exception
                 * @throws InvalidArgumentException
                 */
                private function fail(): void
                {
                    throw new InvalidArgumentException();
                }
            }
            PHP;
        file_put_contents($selectedFile, $selectedFileContents);

        $arguments = [
            '--alter',
            '--php-version=8.3',
            '--issues=MissingThrowsDocblock,UnusedThrowsDocblock',
            $selectedFile,
        ];

        $this->runPsalm($arguments, self::$tmpDir);
        $this->assertSame($selectedFileContents, file_get_contents($selectedFile));

        $this->runPsalm($arguments, self::$tmpDir);
        $this->assertSame($selectedFileContents, file_get_contents($selectedFile));
    }

    public function testPsalterKeepsParentThrowsInheritedByTraitMethod(): void
    {
        $this->runPsalmInit();

        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace(
            '<psalm',
            '<psalm checkForThrowsDocblock="true" runTaintAnalysis="false"',
            (string) $psalmXml,
        );
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        file_put_contents(
            self::$tmpDir . '/src/BaseModel.php',
            <<<'PHP'
                <?php

                namespace Foo;

                use RuntimeException;

                class DatabaseException extends RuntimeException {}

                class BaseModel
                {
                    /** @throws DatabaseException */
                    public function save(): void
                    {
                        throw new DatabaseException();
                    }
                }
                PHP,
        );
        file_put_contents(
            self::$tmpDir . '/src/SaveTrait.php',
            <<<'PHP'
                <?php

                namespace Foo;

                use RuntimeException;

                class HttpException extends RuntimeException {}

                trait SaveTrait
                {
                    /**
                     * @inheritDoc
                     * @throws HttpException
                     */
                    public function save(): void
                    {
                        parent::save();
                        throw new HttpException();
                    }
                }
                PHP,
        );

        $selectedFile = self::$tmpDir . '/src/ManagerJob.php';
        $selectedFileContents = <<<'PHP'
            <?php

            namespace Foo;

            class ManagerJob extends BaseModel
            {
                use SaveTrait;

                /**
                 * @throws DatabaseException
                 * @throws HttpException
                 */
                public function fail(): void
                {
                    $this->save();
                }
            }
            PHP;
        file_put_contents($selectedFile, $selectedFileContents);

        $this->runPsalm(
            [
                '--alter',
                '--php-version=8.3',
                '--issues=MissingThrowsDocblock,UnusedThrowsDocblock',
                $selectedFile,
            ],
            self::$tmpDir,
        );

        $this->assertSame($selectedFileContents, file_get_contents($selectedFile));
    }

    public function testPsalterPropagatesThrowsToAllCallersInOneRun(): void
    {
        $this->runPsalmInit();

        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace(
            '<psalm',
            '<psalm checkForThrowsDocblock="true" runTaintAnalysis="false"',
            (string) $psalmXml,
        );
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        $files = [
            'A.php' => <<<'PHP'
                <?php

                namespace Foo;

                use RuntimeException;

                class A
                {
                    public function execute(B $b): void
                    {
                        $b->execute();
                    }
                }
                PHP,
            'B.php' => <<<'PHP'
                <?php

                namespace Foo;

                use RuntimeException;

                class B
                {
                    public function execute(C $c): void
                    {
                        $c->execute();
                    }
                }
                PHP,
            'C.php' => <<<'PHP'
                <?php

                namespace Foo;

                use RuntimeException;

                class C
                {
                    public function execute(): void
                    {
                        throw new RuntimeException();
                    }
                }
                PHP,
        ];

        foreach ($files as $filename => $contents) {
            file_put_contents(self::$tmpDir . '/src/' . $filename, $contents);
        }

        $arguments = [
            '--alter',
            '--php-version=8.3',
            '--issues=MissingThrowsDocblock',
            self::$tmpDir . '/src/A.php',
            self::$tmpDir . '/src/B.php',
            self::$tmpDir . '/src/C.php',
        ];

        $this->runPsalm($arguments, self::$tmpDir, true);

        foreach (array_keys($files) as $filename) {
            $contents = (string) file_get_contents(self::$tmpDir . '/src/' . $filename);
            $this->assertStringContainsString(
                '@throws RuntimeException',
                $contents,
            );
        }

        $contentsAfterFirstRun = [];
        foreach (array_keys($files) as $filename) {
            $contentsAfterFirstRun[$filename] = file_get_contents(self::$tmpDir . '/src/' . $filename);
        }

        $this->runPsalm($arguments, self::$tmpDir, true);

        foreach ($contentsAfterFirstRun as $filename => $contents) {
            $this->assertSame($contents, file_get_contents(self::$tmpDir . '/src/' . $filename));
        }
    }

    public function testChangedThrowsRootsFollowSameFileHelpersButSkipUnrelatedMethods(): void
    {
        unlink(self::$tmpDir . '/src/FileWithErrors.php');
        file_put_contents(self::$tmpDir . '/psalm.xml', '<psalm xmlns="https://getpsalm.org/schema/config"'
            . ' errorLevel="8" phpVersion="8.2" checkForThrowsDocblock="true" findUnusedCode="false" cacheDirectory="cache">'
            . '<projectFiles><directory name="src" /></projectFiles></psalm>');
        $file = self::$tmpDir . '/src/Selected.php';
        $source = <<<'PHP'
            <?php
            class Selected {
                public function entry(): void {
                    $this->helper();
                }
                private function helper(): void {
                    (new Leaf())->go();
                }
                public function unrelated(): void {
                    (new Noise())->go();
                }
            }
            PHP;
        file_put_contents($file, $source);
        file_put_contents(
            self::$tmpDir . '/src/Leaf.php',
            '<?php class Leaf { public function go(): void { throw new DomainException(); } }',
        );
        file_put_contents(
            self::$tmpDir . '/src/Noise.php',
            '<?php class Noise { public function go(): void { throw new UnderflowException(); } }',
        );
        (new Process(['git', 'init', '-q'], self::$tmpDir))->mustRun();
        (new Process(['git', 'add', 'src', 'psalm.xml'], self::$tmpDir))->mustRun();
        (new Process(['git', '-c', 'user.name=Psalm', '-c', 'user.email=psalm@example.com',
            'commit', '-qm', 'Initial'], self::$tmpDir))->mustRun();
        file_put_contents($file, str_replace('$this->helper();', '$this->helper(); // changed', $source));
        $process = new Process([PHP_BINARY, $this->psalter, '--changed', '--report-changed',
            '--issues=MissingThrowsDocblock', '--threads=1', '--dry-run', '--debug', $file], self::$tmpDir);
        $process->setTimeout(30);
        $process->mustRun();
        $this->assertStringContainsString('@throws DomainException', $process->getOutput());
        $this->assertStringNotContainsString('@throws UnderflowException', $process->getOutput());
        $this->assertStringNotContainsString('Analyzing ' . self::$tmpDir . '/src/Noise.php', $process->getErrorOutput());
        $this->assertSame(1, substr_count($process->getOutput(), '+     * @throws DomainException'));
    }

    public function testSharedTraitSummariesSurvivePartialAnalysisWaves(): void
    {
        unlink(self::$tmpDir . '/src/FileWithErrors.php');
        file_put_contents(self::$tmpDir . '/psalm.xml', '<psalm xmlns="https://getpsalm.org/schema/config"'
            . ' errorLevel="8" phpVersion="8.2" checkForThrowsDocblock="true" findUnusedCode="false" cacheDirectory="cache">'
            . '<projectFiles><directory name="src" /></projectFiles></psalm>');
        $files = [
            'Shared' => 'trait Shared { public function work(): void { $this->step(); } }',
            'Left' => 'class Left { use Shared; public function step(): void { (new Bridge())->go(); } }',
            'Right' => 'class Right { use Shared; public function step(): void { throw new DomainException(); } }',
            'Bridge' => 'class Bridge { public function go(): void { (new Leaf())->go(); } }',
            'Leaf' => 'class Leaf { public function go(): void { throw new LogicException(); } }',
            'Entry' => 'function entry(): void { (new Left())->work(); (new Right())->work(); }',
        ];
        foreach ($files as $name => $body) {
            file_put_contents(self::$tmpDir . '/src/' . $name . '.php', '<?php ' . $body);
        }
        foreach ([1, 2] as $threads) {
            $process = new Process([PHP_BINARY, $this->psalter, '--issues=MissingThrowsDocblock',
                '--threads=' . $threads, '--scan-threads=1', '--dry-run', '--no-progress',
                self::$tmpDir . '/src/Entry.php'], self::$tmpDir);
            $process->setTimeout(30);
            $process->mustRun();
            $this->assertStringContainsString('@throws LogicException', $process->getOutput());
            $this->assertStringContainsString('@throws DomainException', $process->getOutput());
        }
    }

    public function testMethodThrowsCacheKeepsSiblingsAcrossRepeatedDependencyEdits(): void
    {
        unlink(self::$tmpDir . '/src/FileWithErrors.php');
        file_put_contents(self::$tmpDir . '/psalm.xml', '<psalm xmlns="https://getpsalm.org/schema/config"'
            . ' errorLevel="8" phpVersion="8.2" checkForThrowsDocblock="true" findUnusedCode="false" cacheDirectory="cache">'
            . '<projectFiles><directory name="src" /></projectFiles></psalm>');
        foreach (['A' => 'B', 'B' => 'C'] as $caller => $callee) {
            file_put_contents(self::$tmpDir . '/src/' . $caller . '.php', '<?php class ' . $caller . ' {'
                . ' public function changed(): void { (new ' . $callee . '())->changed(); }'
                . ' public function sibling(): void { (new ' . $callee . '())->sibling(); }}');
        }
        $leaf = self::$tmpDir . '/src/C.php';
        $source = '<?php class C {'
            . ' public function changed(): void { throw new LogicException(); }'
            . ' public function sibling(): void { throw new UnderflowException(); }}';
        file_put_contents($leaf, $source);
        $entry = self::$tmpDir . '/src/Entry.php';
        file_put_contents($entry, '<?php function entry(): void { (new A())->changed(); (new A())->sibling(); }');
        $args = [PHP_BINARY, $this->psalter, '--issues=MissingThrowsDocblock',
            '--threads=2', '--scan-threads=1', '--debug', '--dry-run', $entry];
        $run = static function (array $arguments): Process {
            $process = new Process($arguments, self::$tmpDir);
            $process->setTimeout(60);
            $process->mustRun();
            return $process;
        };
        $run($args);
        $mtime = filemtime($leaf);
        foreach (['ErrorException', 'RangeException'] as $exception) {
            file_put_contents($leaf, str_replace('LogicException', $exception, $source));
            touch($leaf, $mtime);
            $updated = $run($args);
            $this->assertStringContainsString('@throws ' . $exception, $updated->getOutput());
            $this->assertStringNotContainsString('@throws LogicException', $updated->getOutput());
            foreach (['a', 'b', 'c'] as $class) {
                $this->assertStringContainsString(
                    'Reusing inferred throws method: ' . $class . '::sibling',
                    $updated->getErrorOutput(),
                );
                $this->assertStringNotContainsString(
                    'Reusing inferred throws method: ' . $class . '::changed',
                    $updated->getErrorOutput(),
                );
            }
        }
        // Shift every following declaration, then remove the exception entirely.
        file_put_contents($leaf, str_replace('throw new LogicException();', "\n // no exception\n", $source));
        $removed = $run($args);
        $this->assertStringNotContainsString('@throws RangeException', $removed->getOutput());
        $this->assertStringContainsString('@throws UnderflowException', $removed->getOutput());
        foreach (['a', 'b', 'c'] as $class) {
            $this->assertStringContainsString(
                'Reusing inferred throws method: ' . $class . '::sibling',
                $removed->getErrorOutput(),
            );
        }
        $forced = $run([...array_slice($args, 0, -1), '--no-cache', $entry]);
        $normalize = static fn(string $text): string => preg_replace('/^Checks took .*$/m', '', $text);
        $this->assertSame($normalize($forced->getOutput()), $normalize($removed->getOutput()));
        $this->assertStringNotContainsString('Reusing inferred throws:', $forced->getErrorOutput());
        $caches = glob(self::$tmpDir . '/cache/*/inferred-throws-v1.json');
        $this->assertCount(1, $caches);
        $cache = $caches[0];
        $state = json_decode((string) file_get_contents($cache), true);
        // A missing method graph must not be interpreted as "no dependencies".
        unset($state['entries'][self::$tmpDir . '/src/B.php']['method_dependencies']);
        file_put_contents($cache, json_encode($state));
        $uncertain = $run($args);
        $this->assertSame($normalize($forced->getOutput()), $normalize($uncertain->getOutput()));
        $this->assertStringNotContainsString('Reusing inferred throws method: b::sibling', $uncertain->getErrorOutput());
        $this->assertStringNotContainsString('Reusing inferred throws method: a::sibling', $uncertain->getErrorOutput());
        $this->assertStringContainsString('Reusing inferred throws method: c::sibling', $uncertain->getErrorOutput());
        $state = json_decode((string) file_get_contents($cache), true);
        unset($state['entries'][self::$tmpDir . '/src/B.php']);
        file_put_contents($cache, json_encode($state));
        $missing = $run($args);
        $this->assertSame($normalize($forced->getOutput()), $normalize($missing->getOutput()));
        $this->assertStringNotContainsString('Reusing inferred throws method: a::sibling', $missing->getErrorOutput());
        file_put_contents($cache, '{broken');
        $broken = $run($args);
        $this->assertSame($normalize($forced->getOutput()), $normalize($broken->getOutput()));
        $this->assertStringNotContainsString('Reusing inferred throws:', $broken->getErrorOutput());
    }

    public function testPersistentInferredThrowsWithoutDocblocks(): void
    {
        unlink(self::$tmpDir . '/src/FileWithErrors.php');
        file_put_contents(self::$tmpDir . '/psalm.xml', '<psalm xmlns="https://getpsalm.org/schema/config"'
            . ' errorLevel="8" phpVersion="8.2" checkForThrowsDocblock="true" findUnusedCode="false" cacheDirectory="cache">'
            . '<projectFiles><directory name="src" /></projectFiles></psalm>');
        $caller = self::$tmpDir . '/src/A.php';
        $bridge = self::$tmpDir . '/src/B.php';
        $leaf = self::$tmpDir . '/src/C.php';
        $other = self::$tmpDir . '/src/D.php';
        file_put_contents($caller, '<?php class A { public function run(B $b, D $d): void { $b->run(); $d->run(); } }');
        file_put_contents($bridge, '<?php class B { public function run(): void { (new C())->run(); } }');
        file_put_contents($leaf, '<?php class C { public function run(): void { throw new \LogicException(); } }');
        file_put_contents($other, '<?php class D { public function run(): void { throw new \UnderflowException(); } }');
        $args = [PHP_BINARY, $this->psalter, '--issues=MissingThrowsDocblock,OverlyBroadThrowsDocblock,UnusedThrowsDocblock',
            '--threads=1', '--scan-threads=1', '--debug', '--dry-run', $caller];
        $run = static function (array $arguments): Process {
            $process = new Process($arguments, self::$tmpDir);
            $process->setTimeout(60);
            $process->mustRun();
            return $process;
        };
        $cold = $run($args);
        $this->assertStringContainsString('@throws LogicException', $cold->getOutput());
        $warm = $run($args);
        foreach ([$bridge, $leaf, $other] as $file) {
            $this->assertStringContainsString('Reusing inferred throws: ' . $file, $warm->getErrorOutput());
        }
        $normalize = static fn(string $text): string => preg_replace('/^Checks took .*$/m', '', $text);
        $this->assertSame($normalize($cold->getOutput()), $normalize($warm->getOutput()));
        $mtime = filemtime($leaf);
        file_put_contents($leaf, str_replace('LogicException', 'ErrorException', (string) file_get_contents($leaf)));
        touch($leaf, $mtime);
        $updated = $run($args);
        $this->assertStringContainsString('@throws ErrorException', $updated->getOutput());
        $this->assertStringNotContainsString('@throws LogicException', $updated->getOutput());
        foreach ([$bridge, $leaf] as $file) {
            $this->assertStringNotContainsString('Reusing inferred throws: ' . $file, $updated->getErrorOutput());
        }
        $this->assertStringContainsString('Reusing inferred throws: ' . $other, $updated->getErrorOutput());
        $forced = $run([...array_slice($args, 0, -1), '--no-cache', $caller]);
        $this->assertStringNotContainsString('Reusing inferred throws:', $forced->getErrorOutput());
        $this->assertSame($normalize($updated->getOutput()), $normalize($forced->getOutput()));
        $this->assertStringNotContainsString('@throws', (string) file_get_contents($leaf));
        file_put_contents($leaf, '<?php class C { public function run(): void {} }');
        $removed = $run($args);
        $this->assertStringNotContainsString('@throws ErrorException', $removed->getOutput());
        $this->assertStringContainsString('@throws UnderflowException', $removed->getOutput());
        // A declaration change invalidates even the otherwise unrelated D summary.
        file_put_contents($leaf, '<?php class C { public function run(int $unused = 0): void {} }');
        $signature = $run($args);
        $this->assertStringNotContainsString('Reusing inferred throws:', $signature->getErrorOutput());
        file_put_contents(self::$tmpDir . '/psalm.xml', str_replace(
            'errorLevel="8"',
            'errorLevel="7"',
            (string) file_get_contents(self::$tmpDir . '/psalm.xml'),
        ));
        $configuration = $run($args);
        $this->assertStringNotContainsString('Reusing inferred throws:', $configuration->getErrorOutput());
    }

    public function testPsalterFollowsUnselectedThrowsDependenciesWithWarmCache(): void
    {
        unlink(self::$tmpDir . '/src/FileWithErrors.php');
        file_put_contents(self::$tmpDir . '/psalm.xml', <<<'XML'
            <psalm xmlns="https://getpsalm.org/schema/config" errorLevel="8"
                checkForThrowsDocblock="true" findUnusedCode="false" cacheDirectory="cache">
                <projectFiles><directory name="src" /></projectFiles>
                <issueHandlers>
                    <MissingPureAnnotation errorLevel="suppress" />
                    <MissingImmutableAnnotation errorLevel="suppress" />
                </issueHandlers>
            </psalm>
            XML);
        $caller = self::$tmpDir . '/src/Caller.php';
        $bridge = self::$tmpDir . '/src/Bridge.php';
        $leaf = self::$tmpDir . '/src/Leaf.php';
        file_put_contents($caller, <<<'PHP'
            <?php
            namespace Foo;
            class Caller {
                public function run(Bridge $bridge): void {
                    $bridge->run();
                }
                public function unchanged(): void {
                    throw new \UnderflowException();
                }
            }
            PHP);
        $bridgeCode = <<<'PHP'
            <?php
            namespace Foo;
            require_once __DIR__ . '/Leaf.php';
            class Bridge {
                public function run(): void { leaf(); }
            }
            PHP;
        file_put_contents($bridge, $bridgeCode);
        file_put_contents($leaf, '<?php namespace Foo; function leaf(): void {}');
        (new Process(['git', 'init', '-q'], self::$tmpDir))->mustRun();
        (new Process(['git', 'add', 'src', 'psalm.xml'], self::$tmpDir))->mustRun();
        (new Process(
            ['git', '-c', 'user.name=Psalm', '-c', 'user.email=psalm@example.com', 'commit', '-qm', 'Initial'],
            self::$tmpDir,
        ))->mustRun();

        foreach ([1, 2] as $threads) {
            // The two exception names have equal length. Preserve mtime as well,
            // so only a content-aware cache can notice the replacement.
            foreach (['LogicException', 'ErrorException', null] as $exception) {
                $body = $exception === null ? '' : 'throw new \\' . $exception . '();';
                $leafCode = '<?php namespace Foo; function leaf(): void { ' . $body . ' }';
                file_put_contents($leaf, $leafCode);
                touch($leaf, 1_700_000_000);
                $contents = (string) file_get_contents($caller);
                file_put_contents($caller, str_replace('$bridge->run();', '$bridge->run(); // edited', $contents));
                touch($caller, 1_700_000_000);

                $process = new Process([
                    PHP_BINARY,
                    $this->psalter,
                    '--changed',
                    '--issues=MissingThrowsDocblock,OverlyBroadThrowsDocblock,UnusedThrowsDocblock',
                    '--threads=' . $threads,
                    '--scan-threads=1',
                    '--no-progress',
                    $caller,
                ], self::$tmpDir);
                $process->mustRun();
                $contents = (string) file_get_contents($caller);
                if ($exception !== null) {
                    $this->assertStringContainsString('@throws ' . $exception, $contents);
                }
                foreach (['LogicException', 'ErrorException', 'UnderflowException'] as $absent) {
                    if ($absent !== $exception) {
                        $this->assertStringNotContainsString('@throws ' . $absent, $contents);
                    }
                }
                $this->assertSame($bridgeCode, file_get_contents($bridge));
                $this->assertSame($leafCode, file_get_contents($leaf));
                $process->mustRun();
                $this->assertSame($contents, file_get_contents($caller));
            }
        }
    }

    public function testPsalterFollowsUnselectedTraitAndRecursiveFunctionThrows(): void
    {
        unlink(self::$tmpDir . '/src/FileWithErrors.php');
        file_put_contents(self::$tmpDir . '/psalm.xml', <<<'XML'
            <psalm xmlns="https://getpsalm.org/schema/config" errorLevel="8"
                checkForThrowsDocblock="true" findUnusedCode="false" cacheDirectory="cache">
                <projectFiles><directory name="src" /></projectFiles>
                <issueHandlers>
                    <MissingPureAnnotation errorLevel="suppress" />
                    <MissingImmutableAnnotation errorLevel="suppress" />
                </issueHandlers>
            </psalm>
            XML);
        $files = [
            'Caller.php' => <<<'PHP'
                <?php
                namespace Foo;
                class Caller {
                    /** @throws \Throwable */
                    public function run(Service $service, int $depth): void {
                        $service->run($depth);
                    }
                }
                PHP,
            'Service.php' => <<<'PHP'
                <?php
                namespace Foo;
                class Service {
                    use Work;
                    public function helper(): void { throw new \LogicException(); }
                }
                PHP,
            'Work.php' => <<<'PHP'
                <?php
                namespace Foo;
                require_once __DIR__ . '/Functions.php';
                trait Work {
                    public function run(int $depth): void {
                        if ($depth > 0) { recurse($depth); }
                        $this->helper();
                    }
                }
                PHP,
            'Functions.php' => <<<'PHP'
                <?php
                namespace Foo;
                function recurse(int $depth): void {
                    if ($depth > 1) { recurse($depth - 1); }
                    throw new \RuntimeException();
                }
                PHP,
        ];
        foreach ($files as $name => $contents) {
            file_put_contents(self::$tmpDir . '/src/' . $name, $contents);
        }
        $process = new Process([
            PHP_BINARY,
            $this->psalter,
            '--issues=MissingThrowsDocblock',
            '--threads=2',
            '--scan-threads=2',
            '--no-progress',
            self::$tmpDir . '/src/Caller.php',
        ], self::$tmpDir);
        $process->mustRun();
        $contents = (string) file_get_contents(self::$tmpDir . '/src/Caller.php');
        $this->assertStringContainsString('@throws RuntimeException', $contents);
        $this->assertStringContainsString('@throws LogicException', $contents);
        $this->assertStringContainsString('@throws \Throwable', $contents);
        foreach ($files as $name => $original) {
            if ($name !== 'Caller.php') {
                $this->assertSame($original, file_get_contents(self::$tmpDir . '/src/' . $name));
            }
        }
        $process->mustRun();
        $this->assertSame($contents, file_get_contents(self::$tmpDir . '/src/Caller.php'));
    }

    public function testPsalterPreservesConcretePropagatedThrowAlongsideThrowable(): void
    {
        $this->runPsalmInit();

        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace(
            '<psalm',
            '<psalm checkForThrowsDocblock="true" runTaintAnalysis="false"',
            (string) $psalmXml,
        );
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        $selectedFile = self::$tmpDir . '/src/Task.php';
        file_put_contents(
            $selectedFile,
            <<<'PHP'
                <?php

                namespace Foo;

                use Exception;
                use Throwable;

                class ServerException extends Exception {}

                final class Service
                {
                    /** @throws Throwable */
                    public function execute(): void
                    {
                        throw new ServerException();
                    }
                }

                final class Task
                {
                    /** @throws Throwable */
                    public function estimate(Service $service): void
                    {
                        $service->execute();
                    }
                }
                PHP,
        );

        $arguments = [
            '--alter',
            '--php-version=8.3',
            '--issues=MissingThrowsDocblock',
            $selectedFile,
        ];

        $this->runPsalm($arguments, self::$tmpDir, true);

        $contents = (string) file_get_contents($selectedFile);
        $this->assertSame(2, substr_count($contents, '@throws ServerException'));
        $this->assertSame(2, substr_count($contents, '@throws Throwable'));

        $this->runPsalm($arguments, self::$tmpDir, true);
        $this->assertSame($contents, file_get_contents($selectedFile));
    }

    public function testPsalmPropagatesInferredThrowsToAllSelectedCallers(): void
    {
        $this->runPsalmInit();

        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace(
            '<psalm',
            '<psalm checkForThrowsDocblock="true" runTaintAnalysis="false"',
            (string) $psalmXml,
        );
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        $files = [
            'A.php' => <<<'PHP'
                <?php

                namespace Foo;

                class A
                {
                    public function execute(B $b): void
                    {
                        $b->execute();
                    }
                }
                PHP,
            'B.php' => <<<'PHP'
                <?php

                namespace Foo;

                class B
                {
                    public function execute(C $c): void
                    {
                        $c->execute();
                    }
                }
                PHP,
            'C.php' => <<<'PHP'
                <?php

                namespace Foo;

                use RuntimeException;

                class C
                {
                    public function execute(): void
                    {
                        throw new RuntimeException();
                    }
                }
                PHP,
        ];

        foreach ($files as $filename => $contents) {
            file_put_contents(self::$tmpDir . '/src/' . $filename, $contents);
        }

        $result = $this->runPsalm(
            [
                '--php-version=8.3',
                '--show-info=false',
                self::$tmpDir . '/src/A.php',
                self::$tmpDir . '/src/B.php',
                self::$tmpDir . '/src/C.php',
            ],
            self::$tmpDir,
            true,
        );

        $this->assertSame(2, $result['CODE']);
        $this->assertSame(3, substr_count($result['STDOUT'], 'RuntimeException is thrown but not caught'));
    }

    public function testPsalm(): void
    {
        $this->runPsalmInit(1);
        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace('<psalm', '<psalm runTaintAnalysis="false"', (string)$psalmXml);
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        $result = $this->runPsalm([], self::$tmpDir, true);
        $this->assertStringContainsString(
            'Target PHP version: 7.1 (inferred from composer.json)',
            $result['STDERR'],
        );
        $this->assertStringContainsString('InvalidReturnType', $result['STDOUT']);
        $this->assertStringContainsString('InvalidReturnStatement', $result['STDOUT']);
        $this->assertStringContainsString('2 errors', $result['STDOUT']);
        $this->assertSame(2, $result['CODE']);
    }

    public function testPsalmWithPHPVersionOverride(): void
    {
        $this->runPsalmInit(1);
        $result = $this->runPsalm(['--php-version=8.0'], self::$tmpDir, true);
        $this->assertStringContainsString(
            'Target PHP version: 8.0 (set by CLI argument)',
            $result['STDERR'],
        );
    }

    public function testPsalmWithPHPVersionFromConfig(): void
    {
        $this->runPsalmInit(1, '7.4');
        $result = $this->runPsalm([], self::$tmpDir, true);
        $this->assertStringContainsString(
            'Target PHP version: 7.4 (set by config file)',
            $result['STDERR'],
        );
    }

    /*
    public function testPsalmDiff(): void
    {
        copy(__DIR__ . '/../fixtures/DummyProjectWithErrors/diff_composer.lock', self::$tmpDir . '/composer.lock');

        $this->runPsalmInit(1);
        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        $psalmXml = str_replace('<psalm', '<psalm runTaintAnalysis="false"', (string)$psalmXml);
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        $result = $this->runPsalm(['--diff', '-m'], self::$tmpDir, true);
        $this->assertStringContainsString('InvalidReturnType', $result['STDOUT']);
        $this->assertStringContainsString('InvalidReturnStatement', $result['STDOUT']);
        $this->assertStringContainsString('2 errors', $result['STDOUT']);
        $this->assertStringContainsString('E', $result['STDERR']);

        $this->assertSame(2, $result['CODE']);

        $result = $this->runPsalm(['--diff', '-m'], self::$tmpDir, true);

        $this->assertStringContainsString('InvalidReturnType', $result['STDOUT']);
        $this->assertStringContainsString('InvalidReturnStatement', $result['STDOUT']);
        $this->assertStringContainsString('2 errors', $result['STDOUT']);
        $this->assertStringNotContainsString('E', $result['STDERR']);

        $this->assertSame(2, $result['CODE']);

        @unlink(self::$tmpDir . '/composer.lock');
    }*/

    public function testTainting(): void
    {
        $this->runPsalmInit(1);
        $result = $this->runPsalm(['--taint-analysis'], self::$tmpDir, true);

        $this->assertStringContainsString('TaintedHtml', $result['STDOUT']);
        $this->assertStringContainsString('TaintedTextWithQuotes', $result['STDOUT']);
        $this->assertStringContainsString('4 errors', $result['STDOUT']);
        $this->assertSame(2, $result['CODE']);
    }

    public function testPsalmSetBaseline(): void
    {
        $this->runPsalmInit(1);
        $this->runPsalm(['--set-baseline'], self::$tmpDir, true);

        $this->assertSame(0, $this->runPsalm([], self::$tmpDir)['CODE']);
    }

    public function testPsalmSetBaselineWithArgument(): void
    {
        $this->runPsalmInit(1);
        $this->runPsalm(['--set-baseline=psalm-custom-baseline.xml'], self::$tmpDir, true);

        $this->assertSame(0, $this->runPsalm([], self::$tmpDir)['CODE']);
    }

    public function testTaintingWithoutInit(): void
    {
        $result = $this->runPsalm(['--taint-analysis'], self::$tmpDir, true, false);

        $this->assertStringContainsString('TaintedHtml', $result['STDOUT']);
        $this->assertStringContainsString('TaintedTextWithQuotes', $result['STDOUT']);
        $this->assertStringContainsString('4 errors', $result['STDOUT']);
        $this->assertSame(2, $result['CODE']);
    }

    public function testTaintGraphDumping(): void
    {
        $this->runPsalmInit(1);
        $result = $this->runPsalm(
            [
                '--taint-analysis',
                '--dump-taint-graph=' . self::$tmpDir . '/taints.dot',
            ],
            self::$tmpDir,
            true,
        );

        $this->assertSame(2, $result['CODE']);
        $this->assertFileEquals(
            __DIR__ . '/../fixtures/expected_taint_graph.dot',
            self::$tmpDir . '/taints.dot',
        );
    }

    public function testLegacyConfigWithoutresolveFromConfigFile(): void
    {
        $this->runPsalmInit(1);
        $psalmXmlContent = file_get_contents(self::$tmpDir . '/psalm.xml');
        assert($psalmXmlContent !== false);
        $count = 0;
        $psalmXmlContent = (string) preg_replace('/resolveFromConfigFile="true"/', 'resolveFromConfigFile="false"', $psalmXmlContent, -1, $count);
        $this->assertEquals(1, $count);

        file_put_contents(self::$tmpDir . '/src/psalm.xml', $psalmXmlContent);

        $process = new Process([PHP_BINARY, $this->psalm, '--config=src/psalm.xml'], self::$tmpDir);
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('InvalidReturnType', $process->getOutput());
    }

    public function testPsalmWithNoProgressDoesNotProduceOutputOnStderr(): void
    {
        $this->runPsalmInit();

        $psalmXml = file_get_contents(self::$tmpDir . '/psalm.xml');
        assert($psalmXml !== false);
        $psalmXml = (string) preg_replace('/findUnusedCode="(true|false)"/', 'runTaintAnalysis="false"', $psalmXml, 1);
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalmXml);

        $result = $this->runPsalm(['--no-progress'], self::$tmpDir);

        $this->assertSame('', $result['STDERR']);
    }

    /**
     * @return array{STDOUT: string, STDERR: string, CODE: int|null}
     */
    private function runPsalmInit(?int $level = null, ?string $php_version = null): array
    {
        $args = ['--init'];

        if ($level) {
            $args[] = 'src';
            $args[] = (string)$level;
        }

        $ret = $this->runPsalm($args, self::$tmpDir, false, false);

        $psalm_config_contents = file_get_contents(self::$tmpDir . '/psalm.xml');
        assert($psalm_config_contents !== false);
        $psalm_config_contents = str_replace(
            'errorLevel="1"',
            'errorLevel="1" '
            . 'cacheDirectory="' . self::$tmpDir . '/cache" '
            . ($php_version ? ('phpVersion="' . $php_version . '"') : ''),
            $psalm_config_contents,
        );
        file_put_contents(self::$tmpDir . '/psalm.xml', $psalm_config_contents);

        return $ret;
    }

    /** from comment by itay at itgoldman dot com at
     * https://www.php.net/manual/en/function.rmdir.php#117354
     */
    private static function recursiveRemoveDirectory(string $src): void
    {
        $dir = opendir($src);
        assert($dir !== false);
        while (false !== ($file = readdir($dir))) {
            if (($file !== '.') && ($file !== '..')) {
                $full = $src . DIRECTORY_SEPARATOR . $file;
                if (is_dir($full)) {
                    self::recursiveRemoveDirectory($full);
                } else {
                    unlink($full);
                }
            }
        }
        closedir($dir);
        rmdir($src);
    }
}
