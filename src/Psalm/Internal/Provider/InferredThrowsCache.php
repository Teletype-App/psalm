<?php

declare(strict_types=1);

namespace Psalm\Internal\Provider;

use DirectoryIterator;
use Generator;
use Override;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Psalm\Config;

use function array_intersect_key;
use function array_keys;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function get_loaded_extensions;
use function hash;
use function hash_file;
use function in_array;
use function ini_get;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function pathinfo;
use function realpath;
use function rename;
use function serialize;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const PATHINFO_EXTENSION;
use const PHP_VERSION;

/**
 * Persistent body summaries; invalidation deliberately operates at file granularity.
 *
 * @internal
 * @psalm-type Fingerprint = array{hash: string, declarations: string}
 * @psalm-type Entry = array{
 *     summaries: array<lowercase-string, array<string, true>>,
 *     offsets: array<int, true>, dependencies: array<string, true>, contextual?: bool
 * }
 * @psalm-type Snapshot = array{files: array<string, Fingerprint>, global: string}
 * @psalm-type CacheState = array{
 *     schema?: int, global: string, files: array<string, Fingerprint>,
 *     entries: array<string, Entry>, selected: array<string, string>
 * }
 */
final class InferredThrowsCache
{
    /** @var CacheState */
    private array $state = ['global' => '', 'files' => [], 'entries' => [], 'selected' => []];
    /** @var Snapshot */
    private array $snapshot = ['files' => [], 'global' => ''];
    /** @var array<string, Entry> */
    private array $valid = [];
    private ?string $path = null;
    private bool $safe = true;

    /** @param array<string, string> $selected */
    public function __construct(
        private readonly Config $config,
        private readonly array $selected,
        private readonly int $analysis_php_version,
    ) {
        $directory = $config->getCacheDirectory();
        if ($directory === null) {
            return;
        }
        $this->path = $directory . '/inferred-throws-v1.json';
        /** @var mixed $decoded */
        $decoded = is_file($this->path) ? json_decode((string) file_get_contents($this->path), true) : null;
        if (is_array($decoded) && ($decoded['schema'] ?? null) === 1
            && is_string($decoded['global'] ?? null) && is_array($decoded['files'] ?? null)
            && is_array($decoded['entries'] ?? null) && is_array($decoded['selected'] ?? null)) {
            /** @var CacheState $decoded */
            $this->state = $decoded;
        }
        $this->snapshot = $this->snapshot();
        if (!$this->safe || ($this->state['global'] ?? null) !== $this->snapshot['global']) {
            return;
        }
        $invalid = [];
        $scope_changed = array_keys($this->state['selected'] ?? []) !== array_keys($selected);
        foreach ($this->state['entries'] ?? [] as $file => $entry) {
            if ($scope_changed && ($entry['contextual'] ?? false)) {
                $invalid[$file] = true;
            }
        }
        foreach ($this->state['files'] ?? [] as $file => $entry) {
            if (($entry['hash'] ?? null) !== ($this->snapshot['files'][$file]['hash'] ?? null)) {
                $invalid[$file] = true;
            }
        }
        do {
            $changed = false;
            foreach ($this->state['entries'] ?? [] as $file => $entry) {
                if (isset($invalid[$file])) {
                    continue;
                }
                foreach ($entry['dependencies'] as $dependency => $_) {
                    if (isset($invalid[$dependency]) || !isset($this->snapshot['files'][$dependency])) {
                        $invalid[$file] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);
        foreach ($this->state['entries'] ?? [] as $file => $entry) {
            if (!isset($invalid[$file])
                && !isset($selected[$file]) && isset($this->snapshot['files'][$file])) {
                $this->valid[$file] = $entry;
            }
        }
    }

    /** @return array<string, Entry> */
    public function entries(): array
    {
        return $this->valid;
    }

    /** @psalm-mutation-free */
    public function covers(string $file, int $offset): bool
    {
        return isset($this->valid[$file]['offsets'][$offset]);
    }

    /**
     * Save only after convergence and only if inputs remained identical.
     *
     * @param array<string, Entry> $entries
     */
    public function save(array $entries): void
    {
        if ($this->path === null || !$this->safe) {
            return;
        }
        $this->state['files'] = $this->snapshot['files'];
        if ($this->snapshot() !== $this->snapshot || !$this->safe) {
            return;
        }
        $entries = array_intersect_key($entries, $this->snapshot['files']);
        $state = ['schema' => 1, 'global' => $this->snapshot['global'],
            'files' => $this->snapshot['files'], 'selected' => $this->selected,
            'entries' => $entries + $this->valid];
        $temporary = tempnam(dirname($this->path), 'throws-');
        if ($temporary !== false) {
            if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR)) !== false) {
                rename($temporary, $this->path);
            } else {
                unlink($temporary);
            }
        }
    }

    /** @return Snapshot */
    private function snapshot(): array
    {
        $files = [];
        $global = ['config' => $this->config->computeHash(), 'php' => PHP_VERSION,
            'target' => $this->analysis_php_version,
            'runtime' => hash('sha256', serialize([get_loaded_extensions(), ini_get('precision'),
                ini_get('serialize_precision'), ini_get('zend.assertions'), ini_get('default_charset')]))];
        $roots = [$this->config->base_dir, dirname(__DIR__, 3),
            dirname(__DIR__, 4) . '/stubs', dirname(__DIR__, 4) . '/vendor'];
        foreach ($roots as $root) {
            foreach ($this->files($root) as $file) {
                $extension = pathinfo($file, PATHINFO_EXTENSION);
                if (!in_array($extension, ['php', 'phpstub', 'stubphp', 'json', 'xml', 'neon', 'phar'], true)
                    && !str_ends_with($file, '.dist') && !str_ends_with($file, '.lock')) {
                    continue;
                }
                $hash = hash_file('sha256', $file);
                if ($hash === false) {
                    $this->safe = false;
                    continue;
                }
                if ($extension === 'php' && $this->config->isInProjectDirs($file)) {
                    $previous = $this->state['files'][$file] ?? [];
                    $declarations = isset($previous['declarations']) && ($previous['hash'] ?? null) === $hash
                        ? $previous['declarations'] : $this->declarations((string) file_get_contents($file));
                    $files[$file] = ['hash' => $hash, 'declarations' => $declarations];
                    $global[$file] = $declarations;
                } else {
                    $global[$file] = $hash;
                    $files[$file] = ['hash' => $hash, 'declarations' => $hash];
                }
            }
        }
        ksort($global);
        return ['files' => $files, 'global' => hash('sha256', serialize($global))];
    }

    /**
     * @param array<string, true> $ancestors
     * @return Generator<int, string>
     */
    private function files(string $root, array $ancestors = []): Generator
    {
        $real = realpath($root);
        if ($real === false || isset($ancestors[$real])) {
            return;
        }
        $ancestors[$real] = true;
        foreach (new DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || str_starts_with($entry->getFilename(), '.')
                || in_array($entry->getFilename(), ['node_modules', 'cache', 'runtime', 'logs'], true)
                || ($this->config->cache_directory !== null
                    && $entry->getPathname() === $this->config->cache_directory)) {
                continue;
            }
            if ($entry->isDir()) {
                yield from $this->files($entry->getPathname(), $ancestors);
            } elseif ($entry->isFile()) {
                yield $entry->getPathname();
            }
        }
    }

    private function declarations(string $source): string
    {
        try {
            $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
            $traverser = new NodeTraverser(new class extends NodeVisitorAbstract {
                #[Override]
                public function enterNode(Node $node): ?Node
                {
                    if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
                        // Nested declarations and dynamic loading can alter symbol resolution.
                        $dynamic = (new NodeFinder())->findFirst($node->stmts ?? [], static fn(Node $n): bool =>
                            $n instanceof Node\Stmt\ClassLike || $n instanceof Node\Stmt\Function_
                            || $n instanceof Node\Expr\Include_ || $n instanceof Node\Expr\Eval_
                            || ($n instanceof Node\Expr\FuncCall && (!$n->name instanceof Node\Name
                                || in_array(strtolower($n->name->toString()), ['define', 'class_alias'], true))));
                        if ($dynamic === null) {
                            $node->stmts = [];
                        }
                    }
                    return null;
                }
            });
            return hash('sha256', (new Standard())->prettyPrint($traverser->traverse($nodes)));
        } catch (Error) {
            $this->safe = false;
            return hash('sha256', $source);
        }
    }
}
