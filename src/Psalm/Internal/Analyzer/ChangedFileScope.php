<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Psalm\Codebase;
use Psalm\FileManipulation;

use function max;
use function min;
use function strlen;
use function strrpos;
use function substr;
use function substr_count;

/** @internal */
final class ChangedFileScope
{
    /** @var array<string, string> */
    private array $original_contents = [];

    /** @var array<string, string> */
    private array $updated_contents = [];

    /** @var array<string, list<FileManipulation>> */
    private array $edits = [];

    /**
     * @param array<string, list<array{int, int}>> $changed_lines
     * @psalm-mutation-free
     */
    public function __construct(private readonly array $changed_lines)
    {
    }

    /**
     * @param list<FileManipulation> $edits
     * @psalm-external-mutation-free
     */
    public function recordUpdate(string $file, string $original, string $updated, array $edits): void
    {
        $this->original_contents[$file] = $original;
        $this->updated_contents[$file] = $updated;
        $this->edits[$file] = $edits;
    }

    /**
     * @param array<string, list<IssueData>> $issues
     * @return array<string, list<IssueData>>
     */
    public function filterAndRelocate(array $issues, Codebase $codebase): array
    {
        $result = [];
        foreach ($issues as $file => $file_issues) {
            $ranges = $this->changed_lines[$file] ?? [];
            if ($ranges !== []) {
                $original = $this->original_contents[$file] ?? $codebase->getFileContents($file);
                $ranges = self::expandToFunctions($original, $ranges);
            }
            foreach ($file_issues as $issue) {
                // A syntax error can prevent analysis of a changed method even
                // when the parser locates the error on an unchanged line.
                $keep = $issue->line_from === 0 || $issue->type === 'ParseError';
                foreach ($ranges as [$start, $end]) {
                    if ($issue->line_from >= $start && $issue->line_from <= $end) {
                        $keep = true;
                        break;
                    }
                }
                if ($keep) {
                    $result[$file][] = $issue->line_from !== 0 && isset($this->updated_contents[$file])
                        ? $this->relocate($issue, $file)
                        : $issue;
                }
            }
        }
        return $result;
    }

    /**
     * @param list<array{int, int}> $ranges
     * @return list<array{int, int}>
     */
    private static function expandToFunctions(string $source, array $ranges): array
    {
        try {
            $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
        } catch (Error) {
            return $ranges;
        }
        $functions = (new NodeFinder())->find(
            $nodes,
            static fn(Node $node): bool => $node instanceof Node\Stmt\ClassMethod
                || $node instanceof Node\Stmt\Function_,
        );
        $expanded = $ranges;
        foreach ($functions as $function) {
            $start = $function->getDocComment()?->getStartLine() ?? $function->getStartLine();
            $end = $function->getEndLine();
            foreach ($ranges as [$changed_start, $changed_end]) {
                if ($start <= $changed_end && $end >= $changed_start) {
                    $expanded[] = [$start, $end];
                    break;
                }
            }
        }
        return $expanded;
    }

    /** @psalm-mutation-free */
    private function relocate(IssueData $issue, string $file): IssueData
    {
        $contents = $this->updated_contents[$file];
        $from = $this->mapOffset($file, $issue->from);
        $to = max($from, $this->mapOffset($file, $issue->to));
        $snippet_from = min($from, $this->mapOffset($file, $issue->snippet_from));
        $snippet_to = max($to, $this->mapOffset($file, $issue->snippet_to));

        return new IssueData(
            severity: $issue->severity,
            line_from: substr_count(substr($contents, 0, $from), "\n") + 1,
            line_to: substr_count(substr($contents, 0, $to), "\n") + 1,
            type: $issue->type,
            message: $issue->message,
            file_name: $issue->file_name,
            file_path: $issue->file_path,
            snippet: substr($contents, $snippet_from, $snippet_to - $snippet_from),
            selected_text: substr($contents, $from, $to - $from),
            from: $from,
            to: $to,
            snippet_from: $snippet_from,
            snippet_to: $snippet_to,
            column_from: self::columnAt($contents, $from),
            column_to: self::columnAt($contents, $to),
            shortcode: $issue->shortcode,
            error_level: $issue->error_level,
            taint_trace: $issue->taint_trace,
            other_references: $issue->other_references,
            dupe_key: $issue->dupe_key,
            documentation_url: $issue->link,
        );
    }

    /** @psalm-mutation-free */
    private function mapOffset(string $file, int $offset): int
    {
        // Edits are recorded in the same descending order in which they were
        // applied, including indentation and trailing-newline adjustments.
        foreach ($this->edits[$file] as $edit) {
            if ($offset >= $edit->end) {
                $offset += strlen($edit->insertion_text) - ($edit->end - $edit->start);
            } elseif ($offset > $edit->start) {
                $offset = $edit->start + min($offset - $edit->start, strlen($edit->insertion_text));
            }
        }
        return max(0, min($offset, strlen($this->updated_contents[$file])));
    }

    /** @psalm-pure */
    private static function columnAt(string $contents, int $offset): int
    {
        $newline = strrpos(substr($contents, 0, $offset), "\n");
        return $offset - ($newline === false ? -1 : $newline);
    }
}
