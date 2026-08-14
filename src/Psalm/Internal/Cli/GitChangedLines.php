<?php

declare(strict_types=1);

namespace Psalm\Internal\Cli;

use RuntimeException;
use Symfony\Component\Process\Process;

use function array_values;
use function explode;
use function is_file;
use function max;
use function preg_match_all;
use function str_ends_with;
use function strtolower;

use const DIRECTORY_SEPARATOR;
use const PHP_INT_MAX;
use const PREG_SET_ORDER;

/** @internal */
final class GitChangedLines
{
    /**
     * @return array<string, list<array{int, int}>>
     */
    public static function collect(string $project_path, ?string $base_ref): array
    {
        $diff_specs = $base_ref === null ? ['HEAD'] : [$base_ref . '...HEAD', 'HEAD'];
        $files = [];

        foreach ($diff_specs as $diff_spec) {
            foreach (self::run(
                ['git', 'diff', '--no-ext-diff', '--name-only', '--diff-filter=ACMRTU', '-z', $diff_spec],
                $project_path,
            ) as $file_path) {
                if ($file_path !== '' && str_ends_with(strtolower($file_path), '.php')) {
                    $files[$file_path] = true;
                }
            }
        }

        $untracked_files = [];
        foreach (self::run(['git', 'ls-files', '--others', '--exclude-standard', '-z'], $project_path) as $file_path) {
            if ($file_path !== '' && str_ends_with(strtolower($file_path), '.php')) {
                $files[$file_path] = true;
                $untracked_files[$file_path] = true;
            }
        }

        $changed_lines = [];
        foreach ($files as $file_path => $_) {
            $absolute_path = $project_path . DIRECTORY_SEPARATOR . $file_path;
            if (!is_file($absolute_path)) {
                continue;
            }

            if (isset($untracked_files[$file_path])) {
                $changed_lines[$absolute_path] = [[1, PHP_INT_MAX]];
                continue;
            }

            foreach ($diff_specs as $diff_spec) {
                $output = self::runRaw(
                    ['git', 'diff', '--no-ext-diff', '--unified=0', $diff_spec, '--', $file_path],
                    $project_path,
                );

                preg_match_all('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/m', $output, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    if (!isset($match[1])) {
                        continue;
                    }
                    $start = (int) $match[1];
                    $count = isset($match[2]) ? (int) $match[2] : 1;
                    $changed_lines[$absolute_path][] = [$start, $start + max(1, $count) - 1];
                }
            }
        }

        return $changed_lines;
    }

    /** @return list<string> */
    private static function run(array $command, string $working_directory): array
    {
        return explode("\0", self::runRaw($command, $working_directory));
    }

    private static function runRaw(array $command, string $working_directory): string
    {
        $process = new Process(array_values($command), $working_directory);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }

        return $process->getOutput();
    }
}
