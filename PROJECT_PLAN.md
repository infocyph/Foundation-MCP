# Foundation MCP — Finalized Production-Grade Project Plan

> Repository: `infocyph/Foundation-MCP`  
> Composer package: `infocyph/foundation-mcp`  
> Primary consumer: `infocyph/infbyte` through `require-dev`  
> Protocol implementation: official `mcp/sdk` PHP SDK  
> Status: source-of-truth implementation plan for the first production-grade release

---

## 1. Purpose

Foundation MCP is the local Model Context Protocol development-intelligence server for applications built on Infocyph Foundation.

Its job is to give an AI coding agent precise, bounded, version-correct and safe knowledge of:

- the current Infbyte/Foundation host project;
- the exact Foundation version actually installed;
- the exact Infocyph packages actually installed;
- Foundation's purpose-first module model;
- application source, routes, commands, providers, configuration, workers and schedules;
- PHP symbols and references;
- Composer dependency relationships;
- current Git workspace changes;
- likely impact of code, configuration, module and dependency changes;
- relevant tests and project standards.

Foundation MCP is a deterministic context and analysis layer. It is not an AI model and must not perform LLM reasoning internally.

The design target is:

```text
AI coding agent
      |
      | MCP / STDIO
      v
Foundation MCP
      |
      +-- project source
      +-- Foundation source
      +-- installed package source
      +-- Composer metadata
      +-- Foundation module semantics
      +-- Git workspace
      +-- project documentation / standards
```

The AI agent reasons and edits. Foundation MCP observes, resolves and explains.

---

## 2. Canonical Distribution Model

Foundation MCP is a development dependency of Infbyte.

Infbyte should contain:

```json
{
    "require": {
        "php": "^8.4",
        "infocyph/foundation": "^2.1.1"
    },
    "require-dev": {
        "infocyph/foundation-mcp": "^1.0",
        "infocyph/phpforge": "dev-main@dev"
    }
}
```

This is the primary installation model.

New applications created from Infbyte therefore receive Foundation MCP automatically in development environments.

Production deployment remains naturally clean:

```bash
composer install --no-dev
```

must remove Foundation MCP entirely and leave no runtime registration, service provider, route, worker, cache, bootstrap hook or application behavior behind.

Direct installation into a custom Foundation host may be documented as a secondary usage:

```bash
composer require --dev infocyph/foundation-mcp
```

but the package is optimized and tested first against the Infbyte development model.

---

## 3. Package Boundary

Foundation MCP uses the official PHP MCP SDK directly.

There will be no intermediate `infocyph/mcp` abstraction in this project.

Dependency direction:

```text
mcp/sdk
   ^
   |
infocyph/foundation-mcp
   ^
   | require-dev
   |
infocyph/infbyte
   |
   +-- infocyph/foundation
```

Foundation MCP must not require Foundation itself.

It observes the Foundation version installed by the host application. This prevents the development tool from influencing or constraining the application's Foundation dependency resolution.

The same rule applies to specialist Infocyph packages: Foundation MCP must not require CacheLayer, DBLayer, Omnibus, TalkingBytes, OTP, Pathwise, ReqShield, EpiCrypt, ArrayKit, InterMix, UID or Webrick merely to inspect them.

---

## 4. Official MCP SDK Contract

Use:

```json
"mcp/sdk": "^0.8.0"
```

The selected SDK line supports the current MCP `2026-07-28` protocol revision, stateless-era protocol semantics and compatibility with the earlier protocol era.

Foundation MCP will use the SDK for:

- server lifecycle;
- protocol negotiation;
- JSON-RPC handling;
- tool registration;
- resources and resource templates;
- schemas;
- STDIO transport;
- structured content where negotiated;
- cancellation/error propagation supported by the SDK.

SDK-specific code must remain isolated under `src/Mcp/` so an SDK update does not leak protocol classes through analysis/domain code.

The SDK is pre-1.0, therefore Foundation MCP must cover its own SDK integration with protocol and integration tests instead of assuming API stability.

---

## 5. MCP Registration Strategy

Use explicit/manual registration for the fixed Foundation MCP surface.

Do not use filesystem-wide SDK discovery.

Reasons:

- the server exposes a small known capability set;
- startup remains deterministic;
- no SDK discovery scan is needed;
- no extra Finder dependency is needed;
- names, schemas and descriptions remain explicit and reviewable;
- tool registration cannot change merely because a new PHP class was added.

Each MCP element must have:

- explicit stable name;
- explicit description;
- explicit input schema where inference would be ambiguous;
- bounded output contract;
- tests for successful and invalid input.

---

## 6. Runtime Transport

Foundation MCP is local and STDIO-only.

Executable:

```text
vendor/bin/foundation-mcp
```

Equivalent explicit command:

```text
vendor/bin/foundation-mcp serve
```

STDOUT is reserved exclusively for MCP protocol traffic.

Diagnostics go to STDERR.

No HTTP transport is exposed by this package.

This is deliberate because Foundation MCP works with private local source, uncommitted code and local Composer state. A remote development-information server would create additional authentication, exposure and data-governance concerns without improving the primary Infbyte use case.

---

## 7. Zero-Network Normal Operation

Normal MCP operation performs no network calls.

It must not automatically contact:

- GitHub;
- Packagist;
- Composer repositories;
- Infocyph websites;
- telemetry services;
- analytics services;
- update servers;
- remote documentation endpoints.

Authoritative context comes from the local development installation:

```text
working tree
composer.json
composer.lock
Composer installed metadata
vendor package source
Foundation package source
local docs
Git metadata
```

This guarantees that generated advice can target what the application actually has installed instead of an unrelated latest upstream version.

---

## 8. Foundation Dependency Model to Understand

Foundation currently owns the composition/runtime layer and has these direct runtime package relationships:

```text
infocyph/foundation
  +-- infocyph/arraykit ^5.1.1
  +-- infocyph/intermix ^9.2
  +-- infocyph/uid ^5.0
  +-- infocyph/webrick ^4.0.2
  +-- psr/log ^3.0.2
```

Foundation's optional purpose-first modules are represented by its installed `ModuleCatalog`, currently including:

```text
auth
  - infocyph/otp ^6.0
  - web-auth/webauthn-lib ^5.3.5

cache
  - infocyph/cachelayer ^3.2.0

communication
  - infocyph/talkingbytes ^2.0

database
  - infocyph/dblayer ^5.0

filesystem
  - infocyph/pathwise ^3.1

logging
  - Foundation built-in

messaging
  - infocyph/omnibus ^2.5

operations
  - Foundation built-in

resources
  - Foundation built-in

security
  - infocyph/epicrypt ^2.1

session
  - Foundation built-in

validation
  - infocyph/reqshield ^3.1
```

This table is architectural documentation, not a runtime hardcoded registry in Foundation MCP.

The MCP must derive the active catalog from the installed Foundation source whenever possible so package constraints, aliases, config ownership and schema ownership remain correct for the exact Foundation version in the project.

Transitive package relationships must be read dynamically from Composer metadata rather than duplicated in Foundation MCP.

---

## 9. Purpose-First Module Semantics

Foundation modules represent application purposes, not package names.

Foundation MCP must preserve that distinction.

For example:

```text
module: database
backing package: infocyph/dblayer
```

and:

```text
module: auth
backing packages:
  infocyph/otp
  web-auth/webauthn-lib
```

The module inspector must understand:

- canonical module name;
- aliases;
- description;
- required package constraints;
- built-in status;
- owned/publishable configuration files;
- owned schemas;
- whether required packages are installed;
- whether project config files are present;
- whether static evidence exists for use.

It must not equate package installation with runtime activation.

Foundation intentionally keeps package presence separate from lazy capability activation.

Use status language such as:

```text
cataloged
built_in
packages_installed
packages_missing
config_present
config_missing
statically_referenced
runtime_activation_unknown
```

Do not report an optional capability as "active" merely because its package exists in `vendor/`.

---

## 10. Foundation Architecture Knowledge

Foundation MCP must model the current Foundation architecture correctly.

Foundation owns four explicit runtime graphs:

```text
web
cli
worker
scheduler
```

Runtime mode is explicit and is not inferred from `PHP_SAPI`.

The MCP must understand these important architecture boundaries:

- InterMix owns dependency injection, scopes and lifetimes;
- Webrick owns HTTP routing/request/response mechanics;
- Foundation owns CLI definitions/parsing/execution policy;
- Omnibus owns messaging/event/queue mechanics;
- DBLayer owns database mechanics;
- CacheLayer owns cache/locks/counters/shared state;
- Pathwise owns storage/filesystem behavior;
- ReqShield owns validation/sanitization mechanics;
- EpiCrypt owns cryptographic primitives;
- TalkingBytes owns communication protocol mechanics;
- UID owns identifier algorithms;
- Foundation owns composition, runtime selection, providers, modules, application policy and bridges.

Foundation MCP should expose these boundaries through architecture resources and inspection results so an agent does not incorrectly invent Foundation forwarding facades for specialist libraries.

---

## 11. Host Project Detection

Foundation MCP must support:

1. the canonical Infbyte skeleton/application;
2. custom projects that directly host Infocyph Foundation.

Detection begins from the current working directory and walks upward unless `--root` is provided.

Strong evidence includes:

- `composer.json`;
- an `infocyph/foundation` dependency;
- the root `infbyte` executable for Infbyte hosts;
- `bootstrap/app.php` using Foundation runtime construction;
- canonical `routes/` / `config/` / `app/` structure;
- Composer-installed Foundation metadata.

Do not require the Composer package name to remain `infocyph/infbyte`, because create-project consumers may rename their application package.

Return host classification:

```text
infbyte
foundation_custom
unsupported
```

along with evidence used for detection.

---

## 12. Project Root Security

Root discovery must:

- normalize paths;
- resolve real paths;
- reject nonexistent roots;
- reject unsupported roots;
- remain immutable for the process lifetime;
- never silently switch projects;
- prevent resource traversal outside allowed roots.

CLI override:

```bash
vendor/bin/foundation-mcp --root=/absolute/project/path
```

The server must fail clearly when the provided root is not a valid Foundation host.

---

## 13. Source-of-Truth Precedence

When information conflicts, use this order:

```text
1. current project working tree
2. composer.lock
3. Composer installed-version/install-path metadata
4. installed dependency source/composer.json
5. project composer.json constraints
6. bundled local documentation
```

Examples:

If `composer.json` says:

```text
infocyph/foundation ^2.1
```

and the lock/installed metadata says:

```text
2.1.4
```

then `2.1.4` is the implementation target.

If the lock and installed package disagree, return an explicit dependency-state mismatch.

Never invent an exact version from a constraint.

---

## 14. Composer Intelligence

Build a dedicated Composer metadata layer.

Inputs:

```text
composer.json
composer.lock
Composer\InstalledVersions when available
vendor/composer/installed.php / installed.json when needed
installed package composer.json files
```

Capabilities:

- direct runtime dependencies;
- direct dev dependencies;
- transitive dependency graph;
- exact locked version;
- exact installed version;
- source/reference metadata;
- install path;
- autoload mappings;
- `suggest`;
- `provide`;
- `replace`;
- `conflict`;
- PHP/platform requirements;
- runtime/dev relationship;
- missing installed package diagnostics;
- lock/install version mismatch diagnostics.

Do not implement a Composer dependency solver.

Do not add `composer/composer` merely for introspection.

Do not add `composer/semver` unless implementation proves a required runtime operation cannot be performed correctly without it. The primary MCP contract can expose declared constraints and exact versions without re-solving Composer constraints.

---

## 15. Minimal Foundation-MCP Composer Dependencies

Target package shape:

```json
{
    "name": "infocyph/foundation-mcp",
    "description": "Local MCP development intelligence for Infocyph Foundation applications.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.4",
        "mcp/sdk": "^0.8.0"
    },
    "require-dev": {
        "infocyph/phpforge": "dev-main@dev"
    },
    "autoload": {
        "psr-4": {
            "Infocyph\\FoundationMcp\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Infocyph\\FoundationMcp\\Tests\\": "tests/"
        }
    },
    "bin": [
        "bin/foundation-mcp"
    ],
    "config": {
        "allow-plugins": {
            "ergebnis/composer-normalize": true,
            "infocyph/phpforge": true,
            "pestphp/pest-plugin": true
        },
        "optimize-autoloader": true,
        "sort-packages": true
    }
}
```

Do not duplicate tooling already supplied by PHPForge in the development environment.

In particular, do not directly add development-only copies of:

- PHP parser packages;
- PHPStan;
- Psalm;
- Rector;
- Pint;
- PHPCS;
- Pest;
- Symfony Process;
- benchmark tools;
- architecture tools.

If implementation code eventually imports a third-party class directly at runtime and cannot safely operate without it, that library becomes a direct runtime dependency. Incidental transitive availability must never be treated as a contractual runtime dependency.

The initial architecture is intentionally designed to avoid requiring such additions.

---

## 16. PHP Analysis Backends

Foundation MCP needs strong PHP source intelligence without bloating its dependency graph.

Use two analysis tiers.

### 16.1 AST backend

When `nikic/php-parser` classes are available in the host development environment, use them through an internal optional adapter.

This is expected to be available in the canonical Infbyte + PHPForge development setup.

The adapter must be dynamically detected and isolated; Foundation MCP's package metadata does not directly require the parser.

The AST backend provides the highest-confidence extraction for:

- namespaces/imports;
- classes/interfaces/traits/enums;
- methods/functions;
- types;
- attributes;
- inheritance;
- static calls;
- constructor calls;
- function calls;
- resolvable method calls;
- literal arrays/configuration;
- route/schedule builder chains;
- line ranges.

### 16.2 Native tokenizer backend

Always provide a native PHP tokenizer fallback.

The fallback must support at minimum:

- namespace/import detection;
- class/interface/trait/enum declarations;
- method/function names;
- inheritance/implements names;
- obvious class references;
- Composer/module literal extraction needed for core inspection;
- lexical/path search;
- safe source line mapping.

Results must identify which backend produced them and the confidence level.

The absence of the AST backend must degrade precision, not make the MCP server unusable.

---

## 17. No Application Bootstrap

Foundation MCP must not bootstrap the host application merely to inspect it.

Do not call:

```text
Foundation::web()
Foundation::cli()
Foundation::worker()
Foundation::scheduler()
```

for ordinary context discovery.

Do not run:

```bash
php infbyte ...
```

for inspection.

Do not instantiate providers, DB connections, cache stores, workers, route handlers or application services.

Reasons:

- partially broken applications must remain inspectable;
- provider/bootstrap code may have side effects;
- database/cache/network connections could be opened;
- secrets could be loaded;
- inspection should be deterministic;
- development tooling must survive invalid application state.

Composer's generated autoload metadata may be used because the MCP executable itself necessarily runs through Composer, but application runtime construction is prohibited.

---

## 18. Project File Discovery

Read PSR autoload configuration from Composer rather than assuming only `app/`.

Discover:

- PSR-4 roots;
- PSR-0 roots if present;
- classmap roots where practical;
- autoload-dev roots;
- test namespaces.

Also recognize canonical Infbyte/Foundation host areas:

```text
app/
bootstrap/
config/
routes/
tests/
database/
docs/
composer.json
composer.lock
README.md
CONTRIBUTING.md
SECURITY.md
```

Generated and writable directories must not be treated as authoritative source.

Default exclusions:

```text
.git/
node_modules/
storage/
bootstrap/cache/
public/build/
coverage/
build/
dist/
tmp/
temp/
```

`vendor/` is not globally scanned. Dependency source access is package-aware.

---

## 19. Package-Aware Vendor Access

The MCP must understand installed packages without recursively indexing the entire `vendor/` tree.

Default package intelligence includes:

- `infocyph/foundation`;
- Foundation's direct Infocyph dependencies;
- packages named by the installed Foundation module catalog;
- other `infocyph/*` packages installed in the project.

Third-party dependencies may be inspected when explicitly requested by package name.

Package roots must come from Composer's installed metadata.

Never construct a vendor path from arbitrary user input.

Path-repository/symlink installs must be supported by using Composer's registered install path and resolved real path as an allowed dependency root.

---

## 20. Static Module Catalog Reader

Foundation MCP must read the installed Foundation `ModuleCatalog` without loading/executing the class.

Extract:

- module names;
- backing package constraints;
- `built_in` flag;
- descriptions;
- aliases;
- config files;
- schema ownership.

Preferred extraction order:

1. AST backend if available;
2. safe literal/token parser fallback;
3. explicit diagnostic if the installed Foundation implementation cannot be parsed statically.

Do not keep a second mutable package/module registry inside Foundation MCP.

A small compatibility fixture may exist in tests, but runtime truth comes from the installed Foundation version.

---

## 21. Configuration Intelligence

Foundation configuration precedence is:

```text
Foundation defaults
-> selected preset
-> project config/*.php
-> inline bootstrap values
```

Foundation MCP should inspect these sources statically where possible.

Return:

- config file/group;
- nested key paths;
- literal defaults when safely resolvable;
- referenced classes;
- environment variable names;
- source path/line;
- owning Foundation module when known;
- whether project config overrides a Foundation template;
- whether a value is dynamic/unresolved.

Never load `.env` or `.env.local`.

For:

```php
'host' => env('DB_HOST', '127.0.0.1')
```

Foundation MCP may return:

```text
key: host
environment: DB_HOST
default: 127.0.0.1
```

but never the current secret environment value.

Recognize Foundation config-cache artifacts as deployment-owned generated data, not authoritative source.

---

## 22. Route Intelligence

Inspect route files statically, including canonical Infbyte route definitions such as `routes/api.php`.

Where resolvable, return:

- HTTP method;
- URI/path;
- route name;
- callable/controller symbol;
- middleware/options;
- source path;
- source line;
- confidence;
- unresolved/dynamic state.

Support common Webrick registration patterns such as method calls on the route registrar.

Never fabricate values for dynamically generated routes.

---

## 23. Command Intelligence

Foundation does not use a second console framework or command-directory auto-discovery.

Application commands are explicitly registered in `routes/console.php`.

Foundation MCP must inspect:

- command name/key;
- command class;
- source registration;
- class declaration;
- statically available description/metadata;
- package/project ownership;
- confidence.

Foundation system commands may be inspected from installed Foundation source where practical.

Do not execute commands to discover metadata.

---

## 24. Schedule Intelligence

Schedules are declared in `routes/schedule.php` using Foundation scheduling APIs.

Inspect fluent schedule definitions statically where possible and return:

- command/action;
- explicit schedule key/identity when present;
- frequency/timing calls;
- overlap policy;
- single-server policy;
- source lines;
- dynamic/unresolved segments.

Foundation schedule identity semantics must be preserved: duplicated command strings are not automatically the same schedule.

Compiled `bootstrap/cache/schedule.php` is an optimization artifact, not the primary source for development intelligence.

---

## 25. Worker Intelligence

Foundation has two distinct worker families:

1. application maintenance workers registered in `routes/workers.php` and implementing Foundation `WorkerProvider`;
2. Omnibus messaging workers configured under messaging configuration.

Foundation MCP must not merge these concepts.

Return for application workers:

- worker key;
- provider class;
- registration source;
- singleton/coordination configuration when statically available;
- class declaration;
- confidence.

For Omnibus messaging workers, inspect messaging configuration and installed Omnibus source/metadata as needed.

Do not start workers.

---

## 26. Provider Intelligence

Inspect application provider registration such as `bootstrap/providers.php` and equivalent host configuration.

Return:

- provider class;
- source location;
- project/package ownership;
- referenced services where statically obvious;
- confidence.

Never instantiate providers.

---

## 27. Runtime/Bootstrap Intelligence

Inspect bootstrap code such as Infbyte's `bootstrap/app.php` and identify:

- selected Foundation runtime constructor;
- base path setup;
- inline bootstrap options;
- provider registration links;
- route/config relationships;
- dynamic/unresolved bootstrap expressions.

Foundation MCP must understand that Infbyte's canonical web bootstrap uses the explicit web runtime and that CLI/worker/scheduler are separate Foundation runtime graphs.

Do not infer runtime from the process SAPI.

---

## 28. PHP Symbol Index

Build a lazy symbol index over project source and requested dependency source.

Supported declarations:

```text
class
interface
trait
enum
function
method
property
class constant
enum case
```

Capture where available:

- FQCN/name;
- declaration kind;
- namespace/imports;
- visibility;
- static/final/abstract flags;
- parameters and types;
- return type;
- parent/interfaces;
- used traits;
- attributes;
- PHPDoc summary;
- source path;
- line range;
- project/package owner.

Index construction is lazy and cacheable for the MCP process lifetime.

---

## 29. Usage/Reference Index

Track references including:

- imports;
- `new`;
- `extends`;
- `implements`;
- trait use;
- attributes;
- declared parameter/property/return types;
- static calls;
- resolvable method calls;
- function calls;
- class-constant references;
- route handlers;
- command registration;
- worker registration;
- schedule references;
- provider registration;
- configuration class references;
- test references.

Every result must include confidence:

```text
exact
resolved
lexical
dynamic
```

Never promote a lexical/dynamic guess to an exact reference.

---

## 30. Dynamic PHP Policy

PHP permits patterns static analysis cannot prove.

Examples:

```php
$class = $config['handler'];
new $class();
```

or dynamic method/route construction.

For unresolved code, return:

```text
dynamic: true
resolved: false
```

with the relevant source excerpt/location.

Accuracy is more important than pretending to have whole-program knowledge.

---

## 31. Test Relationship Discovery

For a source symbol or file, locate likely related tests using this priority:

1. exact symbol references;
2. direct construction/call references;
3. namespace/path conventions;
4. test filename conventions;
5. lexical fallback.

Return confidence for every relationship.

A filename similarity alone must never be reported as an exact relationship.

---

## 32. Git Workspace Intelligence

Git integration is read-only and optional.

When Git is available, inspect:

- current branch;
- HEAD commit;
- detached-HEAD state;
- dirty state;
- staged files;
- unstaged files;
- untracked files;
- added/deleted/renamed files;
- changed line ranges where useful;
- Composer changes;
- changed PHP symbols;
- route/config/module/provider/worker/schedule changes.

If Git is unavailable, all non-Git MCP capabilities remain functional.

---

## 33. Git Process Security

Use native `proc_open()` with argument-safe process construction.

Never route through:

```text
sh -c
bash -c
cmd /c
powershell -Command
```

Allowed Git operations must be a fixed read-only allowlist, for example:

```text
rev-parse
status
diff --name-status
diff --numstat
diff --unified=0
show
ls-files
```

Never permit Foundation MCP to invoke mutating Git operations.

User-provided refs must be validated and supplied as isolated arguments.

---

## 34. Change Analysis

When the working tree differs from a base reference, Foundation MCP should identify:

- changed files;
- changed PHP declarations;
- changed references;
- route changes;
- command changes;
- provider changes;
- config changes;
- module-catalog/config relationship changes;
- worker/schedule changes;
- Composer dependency changes;
- likely affected tests.

Do not return an entire large Git diff by default.

Return compact structured facts and allow precise follow-up reads.

---

## 35. Dependency Change Analysis

When `composer.json` or `composer.lock` changes, identify:

- added packages;
- removed packages;
- exact locked-version changes;
- direct constraint changes;
- runtime/dev movement;
- source-reference changes where present;
- changed transitive package set;
- Foundation module relationships affected by those packages;
- project symbols/files referencing changed packages.

This analysis uses local before/after Git content and Composer metadata.

No remote release lookup is required.

---

## 36. Impact Engine

Provide deterministic bounded impact analysis for targets such as:

```text
symbol
file
package
Foundation module
route
config key
current workspace changes
```

Combine evidence from:

- references;
- inheritance;
- interface implementations;
- trait users;
- route registration;
- command registration;
- worker/schedule registration;
- provider registration;
- config references;
- Composer graph;
- module catalog;
- related tests;
- Git changes.

Impact results must distinguish direct evidence from inferred/lexical relationships.

Traversal depth and result count must be bounded.

---

## 37. MCP Tool Surface

Expose a deliberately small stable tool set.

All tool names use a Foundation prefix to reduce collisions in clients that flatten tools from multiple MCP servers.

Required tools:

```text
foundation_project
foundation_search
foundation_read
foundation_symbol
foundation_usages
foundation_inspect
foundation_packages
foundation_changes
foundation_impact
```

Do not create one MCP tool per framework feature.

A compact surface reduces schema/context overhead and tool-selection mistakes.

---

## 38. Tool: `foundation_project`

Purpose: authoritative project/environment summary.

Input:

```json
{}
```

Return:

- resolved project root identifier without unnecessarily exposing full private absolute paths;
- host type (`infbyte` / `foundation_custom`);
- PHP runtime version;
- project PHP constraint;
- Foundation declared constraint;
- Foundation locked version;
- Foundation installed version;
- Foundation install path identifier;
- analysis backend (`ast` / `tokenizer`);
- Composer state diagnostics;
- Git branch/HEAD/dirty state when available;
- project autoload roots;
- test roots;
- direct runtime/dev dependency summary;
- recognized Foundation module count;
- capability limitations/diagnostics.

This is the preferred first tool call for an agent entering a Foundation project.

---

## 39. Tool: `foundation_search`

Purpose: unified symbol/path/text/documentation search.

Input shape:

```json
{
    "query": "CacheLayer multiGet",
    "scope": "all",
    "package": null,
    "kind": "auto",
    "limit": 10
}
```

Scopes:

```text
project
tests
foundation
packages
routes
config
bootstrap
docs
all
```

Kinds:

```text
auto
symbol
path
text
```

Results include:

- owner (`project` or package name);
- path;
- line range;
- symbol when applicable;
- match type;
- confidence;
- short excerpt;
- resource URI.

Ranking priority:

```text
exact FQ symbol
exact local symbol
exact identifier
exact phrase
structured reference
path/filename
case-insensitive text
weak lexical
```

No embeddings or vector database.

---

## 40. Tool: `foundation_read`

Purpose: precise bounded file/resource reading.

Input supports:

- resource URI or approved relative path;
- optional line start/end;
- maximum bounded range.

Allowed content:

- project source/doc files;
- Foundation package source/docs;
- approved installed dependency source/docs;
- Composer metadata.

Reject:

- disallowed secrets;
- arbitrary absolute paths;
- traversal;
- binaries;
- devices/sockets/FIFOs;
- oversized unbounded reads.

Large files require ranged access.

---

## 41. Tool: `foundation_symbol`

Purpose: exact declaration/API context for a PHP symbol.

Input example:

```json
{
    "symbol": "Infocyph\\Foundation\\Foundation::web"
}
```

Return:

- package/project owner;
- FQCN/symbol;
- declaration kind;
- signature;
- parameters/types;
- return type;
- visibility/modifiers;
- attributes;
- PHPDoc summary;
- parent/interfaces/traits;
- member summary for types;
- source path/line;
- analysis confidence;
- resource URI.

Do not automatically append every usage; that belongs to `foundation_usages`.

---

## 42. Tool: `foundation_usages`

Purpose: references to a symbol.

Input example:

```json
{
    "symbol": "Infocyph\\Foundation\\Foundation::web",
    "scope": "project",
    "limit": 50
}
```

Return each usage with:

- owner;
- path;
- line;
- containing symbol;
- relationship (`call`, `new`, `extends`, `implements`, `type`, `route`, `provider`, `worker`, `schedule`, `config`, `test`, etc.);
- confidence;
- short excerpt.

---

## 43. Tool: `foundation_inspect`

Purpose: Foundation-aware structural inspection through one tool.

Input:

```json
{
    "kind": "modules",
    "filter": null
}
```

Supported kinds:

```text
architecture
modules
routes
commands
providers
config
workers
schedules
runtime
autoload
```

Each result includes source evidence and resolved/unresolved state.

Important behavior:

- `modules` reads installed Foundation's actual catalog;
- `routes` understands Webrick/Foundation host registration patterns;
- `commands` understands explicit `routes/console.php` registration;
- `workers` keeps Foundation maintenance workers separate from Omnibus workers;
- `schedules` understands Foundation schedule identity/policy;
- `runtime` understands the four explicit Foundation graphs.

---

## 44. Tool: `foundation_packages`

Purpose: installed package and dependency graph intelligence.

Input supports:

```json
{
    "package": "infocyph/foundation",
    "depth": 2,
    "scope": "all"
}
```

Scopes:

```text
runtime
dev
infocyph
all
```

Return:

- package name;
- direct/transitive relationship;
- project constraint when direct;
- locked version;
- installed version;
- source reference where available;
- install location identifier;
- dependencies;
- Foundation module relationships;
- mismatch diagnostics.

Depth and result count are bounded and cycles are handled.

---

## 45. Tool: `foundation_changes`

Purpose: summarize current Git changes as development context.

Input:

```json
{
    "mode": "working",
    "base": "HEAD"
}
```

Modes:

```text
working
staged
branch
```

Return:

- changed files/types;
- changed PHP symbols;
- route/command/provider/config/module/worker/schedule changes;
- Composer dependency changes;
- related tests;
- initial impact candidates.

Do not dump full diffs by default.

---

## 46. Tool: `foundation_impact`

Purpose: deterministic impact analysis.

Input example:

```json
{
    "target": "App\\Contracts\\PaymentGateway",
    "type": "auto",
    "depth": 2
}
```

Target types:

```text
auto
symbol
path
package
module
route
config
changes
```

Return grouped evidence for:

- direct usages;
- implementations/children;
- registrations;
- module/package relationships;
- routes/commands/providers/workers/schedules;
- config references;
- tests;
- current Git changes;
- confidence.

---

## 47. MCP Resources

Use the URI scheme:

```text
foundation://
```

Required static resources:

```text
foundation://project/summary
foundation://project/architecture
foundation://project/composer
foundation://project/module-catalog
foundation://project/standards
```

Required resource templates:

```text
foundation://project/file/{path}
foundation://package/{package}/file/{path}
foundation://symbol/{symbol}
```

Resources and tools must share the same underlying analysis services; do not duplicate business logic in MCP handlers.

---

## 48. Architecture Resource

`foundation://project/architecture` is dynamically generated from:

- installed Foundation version/source;
- host bootstrap;
- autoload roots;
- providers;
- route files;
- module catalog;
- package graph;
- workers/schedules;
- project structure.

It should explain the actual project rather than return a static generic Foundation essay.

Static Foundation ownership rules may supplement discovered facts.

---

## 49. Standards Resource

`foundation://project/standards` aggregates development rules available locally, with source attribution.

Potential sources:

- project `CONTRIBUTING.md`;
- Foundation documentation;
- project development documentation;
- PHPForge configuration/rules available in the development environment.

Foundation MCP exposes standards as context; it does not become a second linter or style engine.

Do not duplicate PHPForge's QA responsibilities.

---

## 50. No MCP Prompts

Do not expose MCP prompts such as:

```text
review_project
write_feature
fix_bug
upgrade_package
```

Foundation MCP is an intelligence server.

Agent behavior belongs in:

- Codex/agent skills;
- project instructions;
- user prompts;
- the AI client's own workflow.

This prevents conflicting instruction layers and keeps MCP context factual.

---

## 51. Read-Only Guarantee

Foundation MCP is strictly read-only.

It must not expose or internally perform project mutation such as:

- create/edit/delete source files;
- Composer require/update/remove;
- module installation/removal;
- configuration publication;
- migration execution;
- database writes;
- cache writes;
- worker/scheduler operations;
- Git add/commit/checkout/reset/merge/rebase/push/pull;
- deployment actions;
- environment changes.

The AI coding agent can use its own authorized editing/execution tools.

Foundation MCP must not duplicate those capabilities.

---

## 52. No Arbitrary Command Execution

Never expose generic MCP tools such as:

```text
shell
exec
run_command
php
composer
git
```

Subprocess use is internal and restricted to approved read-only Git inspection.

No user-supplied shell string is ever evaluated.

---

## 53. Secret Protection

Hard-deny reads for secret-bearing files/patterns including:

```text
.env
.env.*
*.pem
*.key
*.p12
*.pfx
SSH private keys
credential files
Git credential files
```

Explicit safe exception:

```text
.env.example
```

may be inspected because it intentionally documents variable names/default templates.

Before MCP output, redact suspicious literal values associated with keys/names such as:

```text
password
passwd
secret
token
api_key
apikey
private_key
authorization
cookie
credential
dsn
```

Also detect common private-key/token patterns.

Config analysis should prefer environment-variable names/defaults, never loaded secret values.

---

## 54. Filesystem Security

All reads must be rooted in an approved real path.

Approved root classes:

1. host project root;
2. Composer-registered dependency install roots explicitly allowed by package selection.

For every read:

- normalize input;
- reject NUL bytes;
- resolve path;
- confirm the resulting target remains inside the selected allowed root;
- reject traversal;
- reject disallowed secret files;
- reject devices, sockets and FIFOs;
- reject binaries unless only metadata was requested;
- enforce byte/line limits.

Composer path repositories may resolve outside the project `vendor/` directory; they are allowed only when Composer metadata identifies their install path as the requested package root.

---

## 55. Output Budgeting

Every MCP response is bounded.

Default behavior favors:

- small ranked lists;
- concise structured fields;
- small excerpts;
- exact line ranges;
- resource URIs for follow-up.

Never return dozens of full source files from a search.

Recommended flow:

```text
foundation_search
  -> small matches
foundation_symbol
  -> exact API
foundation_read
  -> precise lines only
```

Server-side hard maximums apply even if a client requests excessive limits.

---

## 56. Performance Architecture

Do not add heavy infrastructure.

Explicitly avoid:

- vector databases;
- embedding APIs/models;
- Elasticsearch;
- Redis;
- SQL database servers;
- background workers/queues;
- LLM APIs;
- RAG frameworks;
- persistent project index services.

Use:

- Composer metadata;
- filesystem metadata;
- native tokenizer;
- optional AST backend already available in the development environment;
- compact in-memory indexes;
- Git metadata;
- content fingerprints.

---

## 57. Lazy Indexing

`foundation_project` must not parse the entire project.

Build only what an operation needs.

Examples:

```text
foundation_project
  -> Composer + root + lightweight Git metadata

foundation_symbol
  -> symbol catalog as needed

foundation_usages
  -> reference index as needed

foundation_inspect(kind=routes)
  -> route files + referenced symbols only
```

Do not AST-parse all installed dependencies on startup.

Do not scan all `vendor/` on startup.

---

## 58. In-Memory Cache and Invalidation

A long-lived STDIO process may cache compact analysis metadata.

Key invalidation by combinations of:

- resolved path;
- file size;
- modification time;
- content hash where correctness requires it;
- composer.lock fingerprint;
- relevant Git HEAD/worktree state.

When a file changes during the MCP session, stale analysis must be invalidated automatically.

Do not retain complete AST trees once compact metadata has been extracted unless a benchmark proves it materially improves performance without excessive memory cost.

---

## 59. No Persistent Project Index by Default

Do not create an index/database inside an Infbyte/Foundation project.

Foundation MCP should not dirty the working tree merely by connecting an agent.

No default:

```text
.foundation-mcp/
*.sqlite
*.index
project cache database
```

If a future implementation optimization inside the same first-release work proves disk caching necessary, it must use an operating-system cache directory outside the repository, be disposable, be keyed by project identity/content fingerprint and remain optional. The release should prefer memory-only operation unless benchmarks demonstrate otherwise.

---

## 60. Error Model

Distinguish failures clearly:

```text
invalid_input
unsupported_project
resource_not_found
package_not_installed
symbol_not_found
ambiguous_symbol
path_denied
secret_denied
binary_resource
git_unavailable
invalid_git_ref
analysis_backend_limited
parse_error
dynamic_unresolved
output_limit_exceeded
internal_analysis_failure
```

Do not expose raw stack traces through MCP responses.

Debug information goes to STDERR in verbose mode.

One malformed PHP file must not make the whole project uninspectable; report the file error and continue where possible.

---

## 61. Diagnostics and Logging

Default behavior:

```text
no telemetry
no analytics
minimal STDERR diagnostics
```

Optional:

```bash
vendor/bin/foundation-mcp --verbose
```

Diagnostics may include:

- operation;
- elapsed time;
- analyzed file count;
- backend selected;
- cache hit/miss;
- warning category.

Never log secret contents, entire source files or environment values.

---

## 62. CLI Surface

Keep CLI minimal:

```bash
vendor/bin/foundation-mcp
vendor/bin/foundation-mcp serve
vendor/bin/foundation-mcp doctor
```

Supported common options:

```text
--root=<path>
--verbose
--no-git
```

`serve` starts STDIO MCP.

`doctor` performs read-only checks:

- Foundation host detection;
- Composer metadata availability;
- Foundation installed/locked version;
- MCP SDK availability/version;
- analysis backend availability;
- project source roots;
- Git availability;
- module-catalog readability;
- path/security policy readiness.

`doctor` must never modify the project.

Do not build a second general CLI framework around this package.

---

## 63. Proposed Repository Structure

```text
Foundation-MCP/
├── bin/
│   └── foundation-mcp
│
├── src/
│   ├── Application.php
│   │
│   ├── Project/
│   │   ├── Project.php
│   │   ├── ProjectLocator.php
│   │   ├── ProjectDetector.php
│   │   └── SourceRoots.php
│   │
│   ├── Composer/
│   │   ├── ComposerInspector.php
│   │   ├── InstalledPackage.php
│   │   └── DependencyGraph.php
│   │
│   ├── Analysis/
│   │   ├── Analyzer.php
│   │   ├── TokenAnalyzer.php
│   │   ├── AstAnalyzer.php
│   │   ├── SymbolIndex.php
│   │   ├── ReferenceIndex.php
│   │   ├── SearchEngine.php
│   │   ├── TestLocator.php
│   │   └── ImpactAnalyzer.php
│   │
│   ├── Foundation/
│   │   ├── ArchitectureInspector.php
│   │   ├── ModuleCatalogReader.php
│   │   ├── RouteInspector.php
│   │   ├── CommandInspector.php
│   │   ├── ProviderInspector.php
│   │   ├── ConfigInspector.php
│   │   ├── WorkerInspector.php
│   │   ├── ScheduleInspector.php
│   │   └── RuntimeInspector.php
│   │
│   ├── Git/
│   │   ├── GitRunner.php
│   │   └── WorkspaceInspector.php
│   │
│   ├── Security/
│   │   ├── PathPolicy.php
│   │   ├── SecretPolicy.php
│   │   └── Redactor.php
│   │
│   ├── Resource/
│   │   └── ResourceReader.php
│   │
│   └── Mcp/
│       ├── ServerFactory.php
│       ├── Tool/
│       │   ├── ProjectTool.php
│       │   ├── SearchTool.php
│       │   ├── ReadTool.php
│       │   ├── SymbolTool.php
│       │   ├── UsagesTool.php
│       │   ├── InspectTool.php
│       │   ├── PackagesTool.php
│       │   ├── ChangesTool.php
│       │   └── ImpactTool.php
│       └── Resource/
│           ├── SummaryResource.php
│           ├── ArchitectureResource.php
│           ├── ComposerResource.php
│           ├── ModuleCatalogResource.php
│           ├── StandardsResource.php
│           ├── ProjectFileResource.php
│           ├── PackageFileResource.php
│           └── SymbolResource.php
│
├── tests/
│   ├── Fixtures/
│   ├── Unit/
│   ├── Integration/
│   ├── Protocol/
│   ├── Security/
│   └── Performance/
│
├── composer.json
├── README.md
├── CHANGELOG.md
├── LICENSE
└── PROJECT_PLAN.md
```

Do not create interfaces merely for architectural symmetry. Introduce abstractions where substitution, testing or backend separation actually requires them.

---

## 64. Testing — Unit

Unit coverage must include:

- project detection/root traversal;
- source-root discovery;
- Composer metadata parsing;
- package graph traversal/cycle handling;
- ModuleCatalog static parsing;
- module alias resolution;
- tokenizer analysis;
- optional AST adapter behavior;
- symbol extraction;
- reference extraction;
- search ranking;
- route parsing;
- command registration parsing;
- schedule fluent-chain parsing;
- worker registration parsing;
- provider parsing;
- config/env-name extraction;
- Git output parsing;
- impact traversal;
- secret redaction;
- filesystem guards;
- output limits.

---

## 65. Testing — Integration Fixtures

Provide fixture hosts for at least:

- minimal Infbyte project;
- custom Foundation host;
- project with all optional Foundation modules/packages represented;
- project with custom PSR-4 roots;
- project without Git;
- dirty Git project;
- changed `composer.json` / `composer.lock`;
- missing vendor package;
- lock/install mismatch;
- broken PHP file;
- dynamic route/config code;
- symlink/path repository package;
- secret-containing files;
- large source tree.

Fixtures should be deterministic and not require network access for ordinary test runs.

---

## 66. Testing — Real Ecosystem Contract

CI must also include a clean integration job against the actual Infbyte/Foundation ecosystem.

At minimum verify:

- current Infbyte structure;
- current Foundation installed package;
- current ModuleCatalog parsing;
- direct Foundation package graph;
- optional module package relationships;
- canonical route/console/schedule/worker files;
- Foundation MCP registration through Infbyte `require-dev`;
- `composer install --no-dev` removes Foundation MCP.

The fixture suite protects deterministic behavior; the ecosystem contract suite detects real repository drift.

---

## 67. MCP Protocol Tests

Use the official PHP MCP SDK client to launch Foundation MCP through STDIO and verify:

- initialize/negotiation;
- tool listing;
- resource listing;
- resource-template listing;
- valid tool calls;
- structured content where supported;
- earlier/current protocol negotiation behavior supported by the SDK line;
- invalid input errors;
- server survival after expected tool failures;
- cancellation behavior where applicable;
- clean STDIO framing;
- no accidental diagnostic text on STDOUT.

Foundation MCP must pass protocol-level tests independently of unit tests for handler methods.

---

## 68. Security Tests

Explicit tests must cover:

- `../` traversal;
- encoded/normalized traversal variants;
- absolute-path escape;
- symlink escape;
- Composer path-repository allowed-root handling;
- `.env` access denial;
- private key/certificate credential denial;
- suspicious literal redaction;
- binary files;
- device/FIFO/socket rejection where platform applicable;
- oversized read/search requests;
- malicious package names;
- malicious Git refs/arguments;
- shell-metacharacter strings;
- malformed UTF-8/source;
- parse-error isolation;
- MCP schema abuse/oversized limits.

---

## 69. Cross-Platform CI

Required CI matrix:

```text
Linux / PHP 8.4
Linux / PHP 8.5
Windows / PHP 8.4
Windows / PHP 8.5
```

Add a macOS smoke job if CI cost remains reasonable.

Windows support must not depend on Bash.

Git subprocess handling must work with native process execution.

---

## 70. Performance Benchmarks

Benchmark at least:

- server startup;
- `foundation_project`;
- exact symbol lookup;
- text search;
- usage search;
- module inspection;
- route inspection;
- package graph;
- Git change analysis;
- impact analysis.

Use small, medium and large deterministic fixtures.

Record cold and warm results separately.

Before release, establish CI/reference baseline and reject unjustified material regressions.

Performance rules:

- no full `vendor/` scan on startup;
- no full-project AST parse for `foundation_project`;
- no per-source-file subprocess;
- no repeated parse of unchanged files;
- no unbounded graph traversal;
- no unbounded search output;
- no unnecessary full AST retention.

---

## 71. PHPForge Development and Release Gate

Foundation MCP development uses:

```json
"infocyph/phpforge": "dev-main@dev"
```

Use PHPForge as the development/release QA authority rather than duplicating its dependencies/configuration.

The release must pass all applicable PHPForge gates available at release time, including relevant:

- formatting/style;
- syntax/lint;
- static analysis;
- refactoring checks;
- architecture/dependency checks;
- complexity checks;
- security checks;
- tests;
- duplicate/dead-code style checks where configured;
- benchmarks/performance validation;
- dependency/release constraints.

Do not loosen PHPForge policy to accommodate avoidable MCP implementation complexity.

---

## 72. Failure Tolerance

Foundation MCP is a debugging/development tool and must work when the host application is unhealthy.

It should remain useful when:

- bootstrap would throw;
- config is invalid;
- a route references a missing class;
- one PHP file has a syntax error;
- database/cache/network is unavailable;
- an optional package is missing;
- generated artifacts are stale;
- Git is unavailable.

Degrade the affected capability and report diagnostics instead of terminating the whole MCP server whenever safe.

---

## 73. Generated Artifact Policy

Foundation supports deployment-owned generated artifacts such as:

- config cache;
- Webrick route/matcher cache;
- command metadata cache;
- schedule metadata cache;
- compiled InterMix container artifacts;
- aggregate optimize artifacts.

Foundation MCP must recognize these artifacts but treat authoritative development source as primary.

Generated deployment artifacts should normally be excluded from search/indexing unless a specific diagnostic asks about them.

The MCP must never build, clear or mutate optimization artifacts.

---

## 74. Documentation Requirements

README must document:

- what Foundation MCP is;
- why it is `require-dev` in Infbyte;
- official MCP SDK dependency;
- STDIO setup;
- the nine MCP tools;
- resources/templates;
- supported Foundation/Infbyte host discovery;
- exact-version behavior;
- module semantics;
- analyzer backends;
- zero-network policy;
- read-only policy;
- secret/security model;
- `doctor` command;
- troubleshooting;
- client-agnostic MCP configuration principle;
- examples for stable popular MCP clients where useful.

Documentation must clearly state that package installation is development tooling and is not part of the Foundation application runtime.

---

## 75. Infbyte Integration Requirements

After Foundation MCP is release-ready, update Infbyte's `composer.json`:

```json
"require-dev": {
    "infocyph/foundation-mcp": "^1.0",
    "infocyph/phpforge": "dev-main@dev"
}
```

Do not add Foundation MCP to:

- Foundation service providers;
- `bootstrap/app.php`;
- module catalog;
- application config;
- routes;
- workers;
- schedules;
- production runtime bootstrap.

Composer dev installation plus the MCP binary is the complete integration.

---

## 76. No Composer Plugin / Install Side Effects

Foundation MCP must not be a Composer plugin.

Installation must not automatically:

- edit project files;
- register MCP client configuration;
- create cache/index files;
- execute analysis;
- modify Git hooks;
- run Foundation commands.

The package remains passive until `vendor/bin/foundation-mcp` is launched by an MCP client or developer.

---

## 77. Explicit Non-Goals

Foundation MCP is not:

- a production Foundation runtime component;
- a general-purpose MCP framework;
- a hosted MCP service;
- a GitHub MCP;
- a Packagist client;
- a package updater;
- a Composer solver;
- a module installer;
- an AI/LLM service;
- a vector-search/RAG platform;
- a PHPForge replacement;
- a code formatter/linter replacement;
- a database introspection/execution server;
- a shell server;
- a deployment/operations control plane;
- a code-writing tool.

These boundaries are required for security, predictability and low development overhead.

---

## 78. Complete First Release Scope

The first production-grade release is not a reduced MVP. It includes the complete desired Foundation development-intelligence surface described in this plan:

```text
[ ] package skeleton / Composer / binary
[ ] official mcp/sdk integration
[ ] explicit STDIO server registration
[ ] project/root detection
[ ] Infbyte + custom Foundation-host support
[ ] Composer exact-version intelligence
[ ] installed package graph
[ ] Foundation ModuleCatalog static reader
[ ] purpose-first module semantics
[ ] optional AST analyzer backend
[ ] tokenizer fallback backend
[ ] lazy symbol index
[ ] usage/reference index
[ ] test relationship discovery
[ ] configuration inspection
[ ] route inspection
[ ] command inspection
[ ] provider inspection
[ ] schedule inspection
[ ] Foundation-worker inspection
[ ] Omnibus-worker distinction
[ ] runtime/bootstrap inspection
[ ] Git workspace inspection
[ ] dependency-change analysis
[ ] impact analysis
[ ] foundation_project tool
[ ] foundation_search tool
[ ] foundation_read tool
[ ] foundation_symbol tool
[ ] foundation_usages tool
[ ] foundation_inspect tool
[ ] foundation_packages tool
[ ] foundation_changes tool
[ ] foundation_impact tool
[ ] project summary resource
[ ] architecture resource
[ ] Composer resource
[ ] module-catalog resource
[ ] standards resource
[ ] safe project-file template
[ ] safe package-file template
[ ] symbol resource template
[ ] zero-network normal operation
[ ] no application bootstrap
[ ] no arbitrary shell
[ ] no mutation
[ ] path isolation
[ ] secret denial/redaction
[ ] output budgets
[ ] lazy in-memory caching/invalidation
[ ] doctor command
[ ] protocol tests
[ ] unit/integration tests
[ ] real ecosystem contract tests
[ ] security tests
[ ] performance benchmarks
[ ] Linux/Windows PHP 8.4/8.5 CI
[ ] PHPForge release gates
[ ] complete README/security documentation
[ ] Infbyte require-dev integration validation
[ ] no-dev production-footprint validation
```

No desired feature above is intentionally deferred to a hypothetical later Foundation-MCP release.

---

## 79. Implementation Order for the Single Release

Implementation may proceed in work chunks, but all chunks belong to the same release target.

Recommended order:

1. repository/composer/binary + SDK STDIO skeleton;
2. root/project detection + security/path policy;
3. Composer/package graph + Foundation version detection;
4. Foundation ModuleCatalog/config/runtime semantics;
5. tokenizer analyzer + source catalog/search/read;
6. optional AST backend + symbol/reference indexing;
7. route/command/provider/worker/schedule inspectors;
8. Git change analyzer;
9. impact/test relationships;
10. MCP tools/resources wiring and output budgets;
11. diagnostics/doctor/failure tolerance;
12. security hardening;
13. full protocol/integration/ecosystem tests;
14. performance benchmarks/optimization;
15. documentation and Infbyte integration validation;
16. PHPForge full release gate.

The plan file must be updated after each meaningful completed work chunk. Do not mark checklist items complete without implementation/test evidence.

---

## 80. Definition of Done

Foundation MCP is ready for its first production-grade release only when all statements below are true:

1. `infocyph/foundation-mcp` installs as an Infbyte dev dependency without changing Foundation/runtime dependency resolution.
2. `vendor/bin/foundation-mcp` starts a valid MCP STDIO server through official `mcp/sdk`.
3. It operates correctly against the actual Infbyte skeleton and custom Foundation hosts.
4. It identifies exact locked/installed Foundation and package versions.
5. It reads the installed Foundation ModuleCatalog statically and preserves purpose-first module semantics.
6. It distinguishes package presence from runtime activation.
7. It can search/read project and approved package source without executing host code.
8. It provides symbol and usage intelligence with explicit confidence.
9. It understands routes, commands, providers, config, workers, schedules and explicit Foundation runtime graphs.
10. It understands current Git workspace changes and dependency changes.
11. It provides bounded deterministic impact analysis and related-test discovery.
12. It works when the host application cannot bootstrap.
13. It performs no normal-operation network requests.
14. It exposes no project-mutation capability.
15. It exposes no arbitrary command execution.
16. It cannot read outside approved project/package roots.
17. It denies `.env`, private keys and credential files and redacts suspicious literal secrets.
18. It does not scan all vendor or parse the entire project during lightweight calls.
19. Its output is bounded and designed to reduce AI context usage.
20. Its fallback tokenizer mode remains functional when optional AST tooling is absent.
21. Its canonical Infbyte + PHPForge environment uses available advanced parser tooling without duplicating parser packages in Foundation MCP Composer requirements.
22. Protocol, integration, security, cross-platform and benchmark suites pass.
23. Applicable PHPForge release gates pass.
24. Infbyte can add it only under `require-dev`.
25. `composer install --no-dev` leaves zero Foundation MCP runtime footprint.
26. README/security documentation accurately describes capabilities, limits and threat model.

---

## 81. Final Architecture

```text
                         AI CODING AGENT
                               |
                               | MCP / STDIO
                               v
                  +-----------------------------+
                  |   infocyph/foundation-mcp   |
                  |                             |
                  | foundation_project          |
                  | foundation_search           |
                  | foundation_read             |
                  | foundation_symbol           |
                  | foundation_usages           |
                  | foundation_inspect          |
                  | foundation_packages         |
                  | foundation_changes          |
                  | foundation_impact           |
                  +--------------+--------------+
                                 |
             +-------------------+--------------------+
             |                   |                    |
             v                   v                    v
       Project source       Composer state       Git workspace
             |                   |                    |
             +---------+---------+---------+----------+
                       |                   |
                       v                   v
                Foundation source     package source
                       |                   |
                       v                   v
               installed ModuleCatalog  exact versions
               runtime/config semantics dependency graph
                       |                   |
                       +---------+---------+
                                 |
                                 v
                       deterministic context
```

Operating properties:

```text
local
read-only
zero-network by default
version-aware
Foundation-aware
module-aware
bounded
lazy
failure-tolerant
secret-protected
no application bootstrap
no runtime mutation
```

The final responsibility statement is:

> Foundation MCP gives an AI coding agent precise, safe and current knowledge of the Infbyte/Foundation project it is working on and the exact Infocyph ecosystem installed behind that project, while remaining completely outside the application's production runtime.
