<?php

declare(strict_types=1);

namespace Psalm\Tests\EndToEnd;

use Exception;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function array_keys;
use function assert;
use function closedir;
use function copy;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function is_dir;
use function is_string;
use function mkdir;
use function opendir;
use function preg_replace;
use function readdir;
use function rmdir;
use function str_replace;
use function substr_count;
use function sys_get_temp_dir;
use function tempnam;
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
