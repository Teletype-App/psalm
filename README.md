[English](README.md) · [Русский](README-ru.md)

# Teletype Psalm fork

This repository is a maintained fork of [Psalm](https://github.com/vimeo/psalm) for precise exception PHPDoc maintenance in large PHP applications and additional Yii 2 analysis.

For the general Psalm overview, configuration reference, and editor integrations, read the [canonical upstream README](https://github.com/vimeo/psalm/blob/master/README.md) and [Psalm documentation](https://psalm.dev/docs). This page only documents behavior specific to this fork.

The maintained fork branch is [`teletype`](https://github.com/Teletype-App/psalm/tree/teletype). GitHub's default branch for this repository is still `6.x`, so a plain clone must explicitly switch to `teletype`. The Composer package name remains `vimeo/psalm`, but this code is not the Packagist build published by upstream.

## What is different

| Area | Fork behavior | Practical effect |
| --- | --- | --- |
| Exception inference | Builds transitive inferred-throws summaries from project code, including call chains, conditions, catch/rethrow paths, closures, traits, and statically resolved polymorphic calls | `@throws` can be maintained from analyzed behavior instead of only direct annotations |
| Psalter fixes | Adds, narrows, and removes exception annotations through `MissingThrowsDocblock`, `OverlyBroadThrowsDocblock`, and `UnusedThrowsDocblock` | One run can synchronize exception PHPDoc with the selected code |
| Git-scoped analysis | Adds `--changed`, `--base`, `--full-file`, `--report-changed`, and combined unused-variable reporting | Large repositories can update only changed declarations while still following their dependencies |
| Explanation mode | Adds `--show-inferred-throws` | Reports why an exception reaches a function or method, including its call chain and known conditions |
| Cache | Stores content- and dependency-aware inferred-throws summaries in the normal Psalm cache directory | Repeated analysis can reuse unaffected method summaries without changing results |
| Plugin API | Adds method/property throws providers and mixed method return-type providers | Framework integrations can describe runtime dispatch without analyzing every vendor body |
| Yii 2 plugin | Understands ActiveRecord query return types, relations, magic properties, lifecycle hooks, validation callbacks, database terminal calls, and selected dynamic construction/assignment paths | Reduces false positives and recovers exceptions hidden behind Yii runtime conventions |

No upstream feature is intentionally advertised as removed. Unless listed above, behavior should be treated as upstream Psalm behavior at the commit currently merged into this branch.

## Installation

Configure Composer to use this repository before requiring Psalm:

```bash
composer config repositories.teletype-psalm vcs https://github.com/Teletype-App/psalm.git
composer require --dev vimeo/psalm:dev-teletype
```

Commit `composer.lock`: it pins the exact fork revision even when `dev-teletype` advances. Fork releases use tags such as `7.0.0-p46`; prefer a suitable fork tag when one includes all features you need.

Installing `vimeo/psalm` from Packagist without the VCS repository installs upstream Psalm. Upstream release archives, PHAR downloads, Docker images, and self-update instructions likewise do not install this fork.

## Enabling exception maintenance and Yii support

Enable exception-docblock checks and the bundled Yii 2 plugin in `psalm.xml`:

```xml
<psalm checkForThrowsDocblock="true">
    <plugins>
        <pluginClass class="Psalm\Plugin\Yii2\Plugin"/>
    </plugins>
</psalm>
```

Preview changed-code PHPDoc updates before writing them:

```bash
vendor/bin/psalter --changed --base=origin/develop --dry-run \
  --threads=1 --scan-threads=1 \
  --issues=MissingThrowsDocblock,OverlyBroadThrowsDocblock,UnusedThrowsDocblock
```

Remove `--dry-run` to apply the edits. Use `--show-inferred-throws` with Psalm or Psalter to inspect inferred call chains. See [Fixing Code](docs/manipulating_code/fixing.md) for the complete fork-specific CLI and cache behavior.

For memory-constrained large projects, start with one analysis and scan worker as shown above, keep the cache enabled, and increase concurrency only after measuring the project.

## Analysis boundaries

- Runtime-only targets such as arbitrary callable variables, dynamic method names, service-locator configuration, behaviors, and some deferred closures cannot always be resolved statically. The fork does not guess every possible target and does not retain an existing `@throws` annotation as a fallback for an unresolved edge.
- Vendor code is normally represented by its declared contracts. An undocumented runtime exception in a dependency is invisible unless a focused provider or stub supplies that contract.
- Yii support is convention-based and deliberately limited to targets that can be resolved without broad vendor-body analysis.
- The initial inferred-throws calculation can be expensive. Interrupted analyses do not persist partially converged summaries.
- The cache is an optimization only; cache invalidation is designed to preserve the same analysis result as a clean run.

## Development and upstream synchronization

`origin` is the Teletype fork and `upstream` is the canonical Psalm repository. Synchronize the maintained branch with an explicit merge so fork changes and conflict resolutions remain visible:

```bash
git fetch upstream
git switch teletype
git merge --no-ff upstream/master
composer install
composer tests
```

Review fork-specific exception inference, cache, Psalter, and Yii tests after every upstream merge. Do not replace the maintained branch with upstream or assume that an upstream release contains these changes.

The general test workflow runs on pushes and pull requests. Some upstream-derived artifact workflows only target `master`, `6.x`, or release events, so a push to `teletype` does not by itself publish every upstream artifact.

## Upstream project

Psalm was created by Matt Brown and is maintained upstream by the Psalm contributors. Use the [upstream repository](https://github.com/vimeo/psalm) for upstream issues, documentation, and project history. Report fork-specific behavior against this repository with the exact fork commit or tag.
