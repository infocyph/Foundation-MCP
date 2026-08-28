<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$planPath = $root.DIRECTORY_SEPARATOR.'PROJECT_PLAN.md';
$changelogPath = $root.DIRECTORY_SEPARATOR.'CHANGELOG.md';

$plan = file_get_contents($planPath);
$changelog = file_get_contents($changelogPath);

if (!is_string($plan) || !is_string($changelog)) {
    throw new RuntimeException('Release contract files must be readable.');
}

$replacements = [
    <<<'OLD'
Direct installation into another Foundation host is supported as a secondary path:

```bash
composer require --dev infocyph/foundation-mcp
```
OLD => <<<'NEW'
Direct installation into another Foundation host is supported as a secondary path. Both development tools must be explicit because Composer does not install a dependency package's `require-dev` transitively:

```bash
composer require --dev infocyph/foundation-mcp:^1.0 infocyph/phpforge:dev-main@dev
```
NEW,
    <<<'OLD'
Foundation MCP is itself development tooling, but the dependencies it needs **while the MCP server is running** belong in its Composer `require` section.

The required package contract is:

```json
{
    "require": {
        "php": "^8.4",
        "composer-runtime-api": "^2.1",
        "mcp/sdk": "^0.8.0",
        "infocyph/phpforge": "dev-main@dev"
    }
}
```
OLD => <<<'NEW'
Foundation MCP is itself development tooling. Its stable protocol/runtime libraries belong in Composer `require`, while PHPForge is an explicit development-time operational prerequisite kept in `require-dev`.

The required package contract is:

```json
{
    "require": {
        "php": "^8.4",
        "composer-runtime-api": "^2.1",
        "mcp/sdk": "^0.8.0"
    },
    "require-dev": {
        "infocyph/phpforge": "dev-main@dev"
    }
}
```
OLD => NEW,
    '`infocyph/phpforge` is **not** `require-dev` for Foundation MCP. It is a hard operational dependency of Foundation MCP.' => '`infocyph/phpforge` is a **require-dev** dependency of Foundation MCP and a hard operational prerequisite whenever the MCP is used. Consuming projects must require it explicitly under their own `require-dev`; `doctor` and server startup fail when it is absent.',
    'Foundation MCP itself should have no extra `require-dev` package unless a future test/build need cannot be supplied by PHPForge. For this release, the target is no additional dev dependency.' => 'Beyond PHPForge, Foundation MCP should have no extra `require-dev` package unless a future test/build need cannot be supplied by PHPForge.',
    '## 4. Why PHPForge Is a Runtime Dependency of the MCP' => '## 4. Why PHPForge Is a Mandatory Development Prerequisite',
    'Because PHPForge is both a **runtime dependency of Foundation MCP** and the repository QA/release toolchain, Foundation MCP\'s release must pass all applicable PHPForge gates available at release time, including formatting, lint/syntax, static analysis, architecture/dependency rules, complexity, security, tests, refactoring/duplicate checks, benchmarks and release constraints.' => 'Because PHPForge is both a **mandatory development-time operational prerequisite of Foundation MCP** and the repository QA/release toolchain, Foundation MCP\'s release must pass all applicable PHPForge gates available at release time, including formatting, lint/syntax, static analysis, architecture/dependency rules, complexity, security, tests, refactoring/duplicate checks, benchmarks and release constraints.',
    '[x] PHPForge as mandatory `require` dependency' => '[x] PHPForge as mandatory development prerequisite',
    '2. Foundation MCP directly requires `composer-runtime-api ^2.1`, `infocyph/phpforge: dev-main@dev` and `mcp/sdk ^0.8.0`.' => '2. Foundation MCP directly requires `composer-runtime-api ^2.1` and `mcp/sdk ^0.8.0`, keeps `infocyph/phpforge: dev-main@dev` in `require-dev`, and refuses MCP startup when PHPForge/parser capability is unavailable.',
];

foreach ($replacements as $from => $to) {
    $count = substr_count($plan, $from);
    if ($count !== 1) {
        throw new RuntimeException(sprintf('Expected one PROJECT_PLAN.md match, found %d for: %s', $count, substr($from, 0, 80)));
    }

    $plan = str_replace($from, $to, $plan);
}

$oldChangelog = '- Mandatory PHPForge runtime dependency.';
$newChangelog = '- Mandatory PHPForge development prerequisite with explicit consumer `require-dev` installation and doctor/server startup enforcement.';

if (substr_count($changelog, $oldChangelog) !== 1) {
    throw new RuntimeException('Expected exactly one PHPForge changelog contract entry.');
}

$changelog = str_replace($oldChangelog, $newChangelog, $changelog);

file_put_contents($planPath, $plan);
file_put_contents($changelogPath, $changelog);
