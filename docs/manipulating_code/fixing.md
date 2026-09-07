# Fixing Code

Psalm is good at finding potential issues in large codebases, but once found, it can be something of a gargantuan task to fix all the issues.

It comes with a tool, called Psalter, that helps you fix code.

You can either run it via its binary

```
vendor/bin/psalter [args]
```

or via Psalm's binary:

```
vendor/bin/psalm --alter [args]
```

## Safety features

Updating code is inherently risky, doing so automatically is even more so. I've added a few features to make it a little more reassuring:

- To see what changes Psalter will make ahead of time, you can run it with `--dry-run`.
- You can target particular versions of PHP via `--php-version`, so that (for example) you don't add nullable typehints to PHP 7.0 code, or any typehints at all to PHP 5.6 code. `--php-version` defaults to your current version.
- it has a `--safe-types` mode that will only update PHP 7 return typehints with information Psalm has gathered from non-docblock sources of type information (e.g. typehinted params, `instanceof` checks, other return typehints etc.)
- using `--allow-backwards-incompatible-changes=false` you can make sure to not create backwards incompatible changes

## Reporting unused variables while fixing code

Psalter can report unused variables and parameters during the same analysis pass that applies fixes:

```bash
vendor/bin/psalter --issues=MissingThrowsDocblock,OverlyBroadThrowsDocblock,UnusedThrowsDocblock --find-unused-variables src/Service.php
```

Issues selected by `--issues` are fixed and omitted from the report. Remaining issues, including unused variables and parameters, are reported normally and produce a non-zero exit code.


## Updating throws in changed methods

With `checkForThrowsDocblock="true"`, you can update exception documentation only in functions and methods touched by Git changes:

```bash
vendor/bin/psalter --changed --base=origin/develop \
  --issues=MissingThrowsDocblock,OverlyBroadThrowsDocblock,UnusedThrowsDocblock \
  --threads=1 --scan-threads=1 src/Service.php
```

Paths and `--changed` restrict which files and declarations are edited. Psalm also analyzes the transitive method and function calls in configured project files to infer their exceptions, including bodies without `@throws`. Dependency files are not edited unless selected. Library code outside the configured project continues to use its declared contracts; unresolved dynamic calls remain subject to the normal limits of static analysis.

`MissingThrowsDocblock` adds each inferred exception type even when an existing `@throws Throwable` already covers it. Combining the three issues also removes unused or overly broad annotations based on the analyzed bodies.

Keep the default cache enabled for repeated runs. Scanned storage is validated against file contents, and inferred exception summaries are rebuilt on each run, so replacing or removing a throw in a dependency invalidates the result even when its timestamp is unchanged. One analysis and scan process can reduce startup overhead for small selections; larger selections may benefit from more processes.

To apply fixes and report remaining issues in the same invocation, use `--report-changed`:

```bash
vendor/bin/psalter --changed --report-changed --base=origin/develop \
  --full-file=src/NewService.php \
  --issues=MissingThrowsDocblock,OverlyBroadThrowsDocblock,UnusedThrowsDocblock \
  src/ExistingService.php
```

`--full-file=PATH` is repeatable and adds each file to the selection. These files receive the configured fixes and all normally enabled diagnostics, including unchanged code. Other selected files receive fixes and diagnostics in changed functions and methods; diagnostics outside functions are restricted to changed lines. Syntax errors remain visible because they can prevent analysis of changed code. New files in the Git diff already have all their lines selected; `--full-file` also supports explicitly treating an existing file as a whole.

Both options require `--changed` and retain its throws-only restriction on fixes. `--report-changed` enables reporting even without `--find-unused-variables`. Remaining errors produce exit code 2; hidden errors from unchanged code do not. After writing docblocks and imports, reported locations refer to the updated file. With `--dry-run`, locations refer to the original file and no changes are written.

## Plugins

You can pass in your own manipulation plugins e.g.
```bash
vendor/bin/psalter --plugin=vendor/vimeo/psalm/examples/plugins/ClassUnqualifier.php --dry-run
```

The above example plugin converts all unnecessarily qualified classnames in your code to shorter aliased versions.

## Supported fixes

This initial release provides support for the following alterations, corresponding to the names of issues Psalm finds.
To fix all of these at once, run `vendor/bin/psalter --issues=all`

### MissingReturnType

Running `vendor/bin/psalter --issues=MissingReturnType --php-version=7.0` on

```php
<?php
function foo() {
  return "hello";
}
```

gives

```php
<?php
function foo() : string {
  return "hello";
}
```

and running `vendor/bin/psalter --issues=MissingReturnType --php-version=5.6` on

```php
<?php
function foo() {
  return "hello";
}
```

gives

```php
<?php
/**
 * @return string
 */
function foo() {
  return "hello";
}
```

### MissingClosureReturnType

As above, except for closures

### InvalidReturnType

Running `vendor/bin/psalter --issues=InvalidReturnType` on

```php
<?php
/**
 * @return int
 */
function foo() {
  return "hello";
}
```

gives

```php
<?php
/**
 * @return string
 */
function foo() {
  return "hello";
}
```

There's also support for return typehints, so running `vendor/bin/psalter --issues=InvalidReturnType` on

```php
<?php
function foo() : int {
  return "hello";
}
```

gives

```php
<?php
function foo() : string {
  return "hello";
}
```

### InvalidNullableReturnType

Running `vendor/bin/psalter --issues=InvalidNullableReturnType  --php-version=7.1` on

```php
<?php
function foo() : string {
  return rand(0, 1) ? "hello" : null;
}
```

gives

```php
<?php
function foo() : ?string {
  return rand(0, 1) ? "hello" : null;
}
```

and running `vendor/bin/psalter --issues=InvalidNullableReturnType  --php-version=7.0` on

```php
<?php
function foo() : string {
  return rand(0, 1) ? "hello" : null;
}
```

gives

```php
<?php
/**
 * @return string|null
 */
function foo() {
  return rand(0, 1) ? "hello" : null;
}
```

### InvalidFalsableReturnType

Running `vendor/bin/psalter --issues=InvalidFalsableReturnType` on

```php
<?php
function foo() : string {
  return rand(0, 1) ? "hello" : false;
}
```

gives

```php
<?php
/**
 * @return string|false
 */
function foo() {
  return rand(0, 1) ? "hello" : false;
}
```

### MissingParamType

Running `vendor/bin/psalter --issues=MissingParamType` on

```php
<?php
class C {
  public static function foo($s) : void {
    echo $s;
  }
}
C::foo("hello");
```

gives

```php
<?php
class C {
  /**
   * @param string $s
   */
  public static function foo($s) : void {
    echo $s;
  }
}
C::foo("hello");
```

### MissingPropertyType

Running `vendor/bin/psalter --issues=MissingPropertyType` on

```php
<?php
class A {
    public $foo;
    public $bar;
    public $baz;

    public function __construct()
    {
        if (rand(0, 1)) {
            $this->foo = 5;
        } else {
            $this->foo = "hello";
        }

        $this->bar = "baz";
    }

    public function setBaz() {
        $this->baz = [1, 2, 3];
    }
}
```

gives

```php
<?php
class A {
    /**
     * @var string|int
     */
    public $foo;

    public string $bar;

    /**
     * @var array<int, int>|null
     * @psalm-var non-empty-list<int>|null
     */
    public $baz;

    public function __construct()
    {
        if (rand(0, 1)) {
            $this->foo = 5;
        } else {
            $this->foo = "hello";
        }

        $this->bar = "baz";
    }

    public function setBaz() {
        $this->baz = [1, 2, 3];
    }
}
```

### MismatchingDocblockParamType

Given

```php
<?php
class A {}
class B extends A {}
class C extends A {}
class D {}
```

running `vendor/bin/psalter --issues=MismatchingDocblockParamType` on
```php
<?php
/**
 * @param B|C $first
 * @param D $second
 */
function foo(A $first, A $second) : void {}
```

gives

```php
<?php
/**
 * @param B|C $first
 * @param A $second
 */
function foo(A $first, A $second) : void {}
```

### MismatchingDocblockReturnType

Running `vendor/bin/psalter --issues=MismatchingDocblockReturnType` on
```php
<?php
/**
 * @return int
 */
function foo() : string {
  return "hello";
}
```

gives

```php
<?php
/**
 * @return string
 */
function foo() : string {
  return "hello";
}
```

### LessSpecificReturnType

Running `vendor/bin/psalter --issues=LessSpecificReturnType` on

```php
<?php
function foo() : ?string {
  return "hello";
}
```

gives

```php
<?php
function foo() : string {
  return "hello";
}
```

### PossiblyUndefinedVariable

Running `vendor/bin/psalter --issues=PossiblyUndefinedVariable` on

```php
<?php
function foo()
{
    if (rand(0, 1)) {
      $a = 5;
    }
    echo $a;
}
```

gives

```php
<?php
function foo()
{
    $a = null;
    if (rand(0, 1)) {
      $a = 5;
    }
    echo $a;
}
```


### PossiblyUndefinedGlobalVariable

Running `vendor/bin/psalter --issues=PossiblyUndefinedGlobalVariable` on

```php
<?php
if (rand(0, 1)) {
  $a = 5;
}
echo $a;
```

gives

```php
<?php
$a = null;
if (rand(0, 1)) {
  $a = 5;
}
echo $a;
```

### UnusedMethod

This removes private unused methods.

Running `vendor/bin/psalter --issues=UnusedMethod` on

```php
<?php
class A {
    private function foo() : void {}
}

new A();
```

gives

```php
<?php
class A {

}

new A();
```

### PossiblyUnusedMethod

This removes protected/public unused methods.

Running `vendor/bin/psalter --issues=PossiblyUnusedMethod` on

```php
<?php
class A {
    protected function foo() : void {}
    public function bar() : void {}
}

new A();
```

gives

```php
<?php
class A {

}

new A();
```

### UnusedProperty

This removes private unused properties.

Running `vendor/bin/psalter --issues=UnusedProperty` on

```php
<?php
class A {
    /** @var string */
    private $foo;
}

new A();
```

gives

```php
<?php
class A {

}

new A();
```

### PossiblyUnusedProperty

This removes protected/public unused properties.

Running `vendor/bin/psalter --issues=PossiblyUnusedProperty` on

```php
<?php
class A {
    /** @var string */
    public $foo;

    /** @var string */
    protected $bar;
}

new A();
```

gives

```php
<?php
class A {

}

new A();
```

### UnusedVariable

This removes unused variables.

Running `vendor/bin/psalter --issues=UnusedVariable` on

```php
<?php
function foo(string $s) : void {
    $a = 5;
    $b = 6;
    $c = $b += $a -= intval($s);
    echo "foo";
}
```

gives

```php
<?php
function foo(string $s) : void {
    echo "foo";
}
```

### UnnecessaryVarAnnotation

This removes unused `@var` annotations

Running `vendor/bin/psalter --issues=UnnecessaryVarAnnotation` on

```php
<?php
function foo() : string {
    return "hello";
}

/** @var string */
$a = foo();
```

gives

```php
<?php
function foo() : string {
    return "hello";
}

$a = foo();
```

### ParamNameMismatch

This aligns child class param names with their parent.

Running `vendor/bin/psalter --issues=ParamNameMismatch` on

```php
<?php

class A {
    public function foo(string $str, bool $b = false) : void {}
}

class AChild extends A {
    public function foo(string $string, bool $b = false) : void {
        echo $string;
    }
}
```

gives

```php
<?php

class A {
    public function foo(string $str, bool $b = false) : void {}
}

class AChild extends A {
    public function foo(string $str, bool $b = false) : void {
        echo $str;
    }
}
```

### Cached inferred exceptions in this fork

When throws analysis is enabled, Psalm stores computed exception sets in
`inferred-throws-v1.json` inside its project cache directory. A summary is keyed by
method/function identity and does not require an existing `@throws` annotation.
Only results after convergence are saved; the cache does not contain diagnostics
or authorize edits outside the selected scope.

Selected files are still analyzed. With `--changed --report-changed`, the initial
body analysis starts at changed methods/functions; called helpers, including
helpers in the same file, are discovered transitively. `--full-file` retains full
coverage for new files. Ordinary full-file diagnostics are not narrowed.
For dependencies, unchanged summaries can replace body analysis. Content hashes invalidate a changed file and its
transitive callers, including replacements that preserve file size and mtime.
The first implementation invalidates bodies at file granularity, rather than
trying to reuse other methods in the same changed file. Changes to declarations,
PHPDoc, imports, the project file set, configuration, runtime, vendor or analyzer
implementation invalidate the cache conservatively. Missing dependency inputs
prevent reuse. `--no-cache` disables both reading and writing these summaries.
Language-server/in-memory analysis does not use this disk cache.

The first calculation can still be expensive. A completed `make quality` has an
additional outer cache that can skip analyzers entirely on an identical run;
this summary cache helps when an analyzer actually needs to run again. Writer
changes to PHPDoc/imports invalidate pre-write summaries on the next analyzer
invocation. No partially converged summaries are saved after an interrupted run.

Shared trait methods retain separate results for their using-file contexts during
convergence. Revisiting one class must not erase exceptions inferred in another
class and cause endless alternating passes. Trait bodies are not persisted as
context-free summaries; graph-only nodes preserve their dependencies on using
classes, and a changed selection invalidates these contextual nodes and callers.
