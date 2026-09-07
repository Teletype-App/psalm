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
use function substr;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const PATHINFO_EXTENSION;
use const PHP_VERSION;

/**
 * Persistent body summaries with method invalidation and conservative file fallback.
 *
 * @internal
 * @psalm-type Body = array{hash: string, start: int, end: int}
 * @psalm-type Fingerprint = array{hash: string, declarations: string, bodies?: list<Body>}
 * @psalm-type Entry = array{
 *     summaries: array<lowercase-string, array<string, true>>,
 *     offsets: array<int, true>, dependencies: array<string, true>, contextual?: bool,
 *     method_offsets?: array<lowercase-string, int>,
 *     method_dependencies?: array<lowercase-string, array<string, array<int, true>>>
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
        if (is_array($decoded) && ($decoded['schema'] ?? null) === 2
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
        // The declaration fingerprint fixes declaration order and identity. Body
        // indices therefore survive line/byte shifts without using stale offsets.
        $invalid_bodies = [];
        $known_bodies = [];
        foreach ($this->state['entries'] as $file => $entry) {
            foreach ($entry['method_offsets'] ?? [] as $id => $offset) {
                $index = $this->bodyIndex($this->state['files'], $file, $offset);
                if ($index !== null && isset($entry['summaries'][$id], $entry['method_dependencies'][$id])) {
                    $known_bodies[$file][$index] = true;
                }
            }
        }
        foreach ($this->state['files'] as $file => $fingerprint) {
            foreach ($fingerprint['bodies'] ?? [] as $index => $body) {
                if ($body['hash'] !== ($this->snapshot['files'][$file]['bodies'][$index]['hash'] ?? null)) {
                    $invalid_bodies[$file][$index] = true;
                }
            }
        }
        do {
            $changed = false;
            foreach ($this->state['entries'] as $file => $entry) {
                foreach ($entry['method_offsets'] ?? [] as $id => $offset) {
                    $index = $this->bodyIndex($this->state['files'], $file, $offset);
                    if ($index === null || isset($invalid_bodies[$file][$index])) {
                        continue;
                    }
                    $dependencies = $entry['method_dependencies'][$id] ?? null;
                    $stale = $dependencies === null || ($entry['contextual'] ?? false);
                    foreach ($dependencies ?? [] as $callee => $indices) {
                        foreach ($indices as $callee_index => $_) {
                            if ($callee_index === -1 ? isset($invalid[$callee])
                                || !isset($this->snapshot['files'][$callee])
                                : isset($invalid_bodies[$callee][$callee_index])
                                || !isset($known_bodies[$callee][$callee_index])
                                || !isset($this->snapshot['files'][$callee]['bodies'][$callee_index])) {
                                $stale = true;
                            }
                        }
                    }
                    if ($stale) {
                        $invalid_bodies[$file][$index] = true;
                        $changed = true;
                    }
                }
            }
        } while ($changed);
        foreach ($this->state['entries'] as $file => $entry) {
            if (!isset($this->snapshot['files'][$file])) {
                continue;
            }
            $valid = $entry;
            $valid['summaries'] = [];
            $valid['offsets'] = [];
            $valid['method_offsets'] = [];
            $valid['method_dependencies'] = [];
            foreach ($entry['method_offsets'] ?? [] as $id => $offset) {
                $index = $this->bodyIndex($this->state['files'], $file, $offset);
                if ($index === null || isset($invalid_bodies[$file][$index])
                    || !isset(
                        $entry['summaries'][$id],
                        $entry['method_dependencies'][$id],
                        $this->snapshot['files'][$file]['bodies'][$index],
                    )) {
                    continue;
                }
                $new_offset = $this->snapshot['files'][$file]['bodies'][$index]['start'];
                $valid['summaries'][$id] = $entry['summaries'][$id];
                $valid['offsets'][$new_offset] = true;
                $valid['method_offsets'][$id] = $new_offset;
                $valid['method_dependencies'][$id] = $entry['method_dependencies'][$id];
            }
            if ($valid['summaries'] !== [] || !isset($invalid[$file])) {
                $this->valid[$file] = $valid;
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
     * @param array<string, array<int, array<string, array<int, true>>>> $edges
     */
    public function save(array $entries, array $edges): void
    {
        if ($this->path === null || !$this->safe) {
            return;
        }
        $this->state['files'] = $this->snapshot['files'];
        if ($this->snapshot() !== $this->snapshot || !$this->safe) {
            return;
        }
        $entries = array_intersect_key($entries, $this->snapshot['files']);
        $all_entries = $entries + $this->valid;
        foreach ($this->valid as $file => $valid) {
            $all_entries[$file]['offsets'] += $valid['offsets'];
        }
        foreach ($entries as $file => &$entry) {
            foreach ($entry['method_offsets'] ?? [] as $id => $offset) {
                $index = $this->bodyIndex($this->snapshot['files'], $file, $offset);
                if ($index === null || !isset($this->snapshot['files'][$file]['bodies'][$index])) {
                    continue;
                }
                $body = $this->snapshot['files'][$file]['bodies'][$index];
                $dependencies = [];
                $represented = [];
                foreach ($edges[$file] ?? [] as $position => $callees) {
                    foreach ($callees as $callee => $offsets) {
                        $represented[$callee] = true;
                        if ($position !== -1 && ($position < $body['start'] || $position > $body['end'])) {
                            continue;
                        }
                        foreach ($offsets as $callee_offset => $_) {
                            $callee_index = $this->bodyIndex($this->snapshot['files'], $callee, $callee_offset);
                            // Unknown contexts and trait/closure targets keep the
                            // complete file dependency as a safe fallback.
                            if ($position === -1 || ($all_entries[$callee]['contextual'] ?? false)
                                || !isset($all_entries[$callee]['offsets'][$callee_offset])) {
                                $callee_index = null;
                            }
                            $dependencies[$callee][$callee_index ?? -1] = true;
                        }
                    }
                }
                foreach ($entry['dependencies'] as $callee => $_) {
                    if (!isset($represented[$callee])) {
                        $dependencies[$callee][-1] = true;
                    }
                }
                $entry['method_dependencies'][$id] = $dependencies;
            }
            // A dependency file can be visited for just one invalid method;
            // retain valid siblings, including their dependency edges.
            $previous = $this->valid[$file] ?? [];
            $entry['summaries'] += $previous['summaries'] ?? [];
            $entry['offsets'] += $previous['offsets'] ?? [];
            $entry['dependencies'] += $previous['dependencies'] ?? [];
            $entry['method_offsets'] = ($entry['method_offsets'] ?? []) + ($previous['method_offsets'] ?? []);
            $entry['method_dependencies'] = ($entry['method_dependencies'] ?? [])
                + ($previous['method_dependencies'] ?? []);
        }
        unset($entry);
        $state = ['schema' => 2, 'global' => $this->snapshot['global'],
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
                    $fingerprint = isset($previous['declarations']) && ($previous['hash'] ?? null) === $hash
                        ? $previous : $this->declarations((string) file_get_contents($file));
                    $fingerprint['hash'] = $hash;
                    $files[$file] = $fingerprint;
                    $global[$file] = $fingerprint['declarations'];
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

    /**
     * @param array<string, Fingerprint> $files
     * @psalm-pure
     */
    private function bodyIndex(array $files, string $file, int $offset): ?int
    {
        foreach ($files[$file]['bodies'] ?? [] as $index => $body) {
            if ($body['start'] === $offset) {
                return $index;
            }
        }
        return null;
    }

    /** @return Fingerprint */
    private function declarations(string $source): array
    {
        try {
            $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
            $bodies = [];
            foreach ((new NodeFinder())->find($nodes, static fn(Node $node): bool =>
                $node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) as $node) {
                $start = $node->getStartFilePos();
                $end = $node->getEndFilePos();
                $bodies[] = ['start' => $start, 'end' => $end,
                    'hash' => hash('sha256', substr($source, $start, $end - $start + 1))];
            }
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
            return ['hash' => hash('sha256', $source), 'bodies' => $bodies,
                'declarations' => hash('sha256', (new Standard())->prettyPrint($traverser->traverse($nodes)))];
        } catch (Error) {
            $this->safe = false;
            return ['hash' => hash('sha256', $source), 'declarations' => hash('sha256', $source)];
        }
    }
}
