# Foundation MCP — Finalized Production-Grade Project Plan

> Repository: `infocyph/Foundation-MCP`  
> Composer package: `infocyph/foundation-mcp`  
> Primary consumer: `infocyph/infbyte` through `require-dev`  
> MCP implementation: official `mcp/sdk` PHP SDK  
> Required development-intelligence substrate: `infocyph/phpforge`  
> Status: implementation source of truth for the first production-grade release

---

## 1. Mission

Foundation MCP is the local Model Context Protocol development-intelligence server for Infbyte applications and other projects that directly host Infocyph Foundation.

Its job is to give an AI coding agent precise, bounded, version-correct and safe knowledge of the project it is currently working on:

- the application source tree;
- the exact Foundation version installed;
- the exact Infocyph packages installed;
- Foundation's purpose-first module model;
- Composer dependencies and package ownership;
- PHP declarations, signatures and usages;
- routes, commands, providers, config, workers and schedules;
- Foundation runtime/bootstrap relationships;
- Git workspace changes;
- dependency-change impact;
- related tests;
- project/Foundation development standards.

Foundation MCP is a deterministic context and static-analysis layer. The AI client remains responsible for reasoning, code generation, editing and execution.

```text
AI coding agent
      |
      | MCP / STDIO
      v
Foundation MCP
      |
      +-- host project
      +-- installed Foundation
      +-- installed package source
      +-- Composer metadata
      +-- PHPForge analysis stack
      +-- Foundation module semantics
      +-- Git workspace
      +-- local docs/standards
```

---

## 2. Canonical Infbyte Integration

Foundation MCP is a **development dependency of Infbyte**.

Infbyte should ultimately contain:

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

Keeping PHPForge explicitly in Infbyte is intentional even though Foundation MCP also requires it: Infbyte itself uses PHPForge as its QA/release toolchain, while Foundation MCP uses PHPForge as its mandatory source-analysis substrate.

Production installation remains clean:

```bash
composer install --no-dev
```

must remove Foundation MCP and PHPForge entirely from the application runtime.

Foundation MCP must add no service provider, route, module, config file, worker, schedule, bootstrap hook or production dependency to Infbyte/Foundation.

Direct installation into another Foundation host is supported as a secondary path:

```bash
composer require --dev infocyph/foundation-mcp
```

---

## 3. Mandatory Package Dependencies

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

`composer-runtime-api ^2.1` is a virtual Composer runtime contract, not a dependency on full `composer/composer`. It is required because Foundation MCP directly uses `Composer\InstalledVersions`, including `getInstallPath()` for the strictly root-matched runtime fallback path.

`infocyph/phpforge` is **not** `require-dev` for Foundation MCP. It is a hard operational dependency of Foundation MCP.

Foundation MCP must not duplicate parser/static-analysis/refactoring/testing packages that PHPForge already provides through its toolchain. In particular, do not separately require packages merely because they are used internally by PHPForge's analysis stack, including PHP parser, Rector, PHPStan, Psalm, Symfony Process, Pest, PHPCS, Pint or benchmark tooling.

The hard rule is:

> Foundation MCP depends on the PHPForge toolchain as one unit. It does not recreate PHPForge's dependency graph.

`foundation-mcp doctor` and CI must verify that the PHPForge installation provides the parser/analysis capabilities Foundation MCP requires. A broken/incomplete PHPForge installation is an invalid Foundation MCP environment, not a reason to silently downgrade analysis quality.

Foundation MCP itself should have no extra `require-dev` package unless a future test/build need cannot be supplied by PHPForge. For this release, the target is no additional dev dependency.

---

## 4. Why PHPForge Is a Runtime Dependency of the MCP

Foundation MCP requires PHPForge for the development intelligence it exposes, not merely for maintaining Foundation MCP's own repository.

PHPForge supplies the surrounding analysis ecosystem required for production-grade project understanding, including its PHP-parser compatibility support and the parser/refactoring/static-analysis packages brought by its toolchain.

Foundation MCP should use that environment for:

- PHP AST parsing;
- source declaration extraction;
- reference/usage extraction;
- parser compatibility handling;
- code-quality/standards context where appropriate;
- release/CI validation of Foundation MCP itself.

Foundation MCP must not shell out to PHPForge for every source query. Analysis remains in-process where practical for performance. PHPForge is the mandatory dependency substrate; Foundation MCP owns the MCP-oriented indexing/query semantics.

---

## 5. Official MCP SDK Boundary

Foundation MCP uses the official PHP MCP SDK directly.

There is no `infocyph/mcp` abstraction between Foundation MCP and the official SDK.

```text
mcp/sdk
   ^
   |
Foundation MCP
   ^
   | require-dev
   |
Infbyte
```

Use `mcp/sdk ^0.8.0`, which supports the current MCP `2026-07-28` protocol revision and compatibility with the prior protocol era.

Use the SDK for:

- server lifecycle;
- protocol negotiation;
- JSON-RPC;
- STDIO transport;
- tools;
- resources/resource templates;
- schemas/structured content;
- errors/cancellation behavior supported by the SDK.

All SDK-specific code remains under `src/Mcp/`; domain/project-analysis code must not be polluted with protocol concerns.

Because the SDK remains pre-1.0, Foundation MCP must cover its integration with protocol-level tests and must not assume future source compatibility.

---

## 6. MCP Registration Strategy

Register the Foundation MCP surface explicitly with the official SDK.

Do not use filesystem-wide MCP capability discovery.

Reasons:

- the capability set is intentionally small and stable;
- startup is deterministic;
- schemas/descriptions remain reviewable;
- no discovery scan is required;
- accidental addition of a PHP class cannot alter public MCP capabilities.

Every exposed element must have an explicit stable name, description, input schema, output contract and server-side result limits.

---

## 7. Transport and Network Policy

Foundation MCP is local and **STDIO-only**.

Executable:

```bash
vendor/bin/foundation-mcp
```

Explicit form:

```bash
vendor/bin/foundation-mcp serve
```

STDOUT is protocol-only. Diagnostics/logging go to STDERR.

Normal operation performs **zero network calls**. It must not automatically contact GitHub, Packagist, Composer repositories, Infocyph servers, telemetry endpoints, analytics services or remote documentation.

The authoritative state is local:

```text
working tree
composer.json
composer.lock
Composer installed metadata
installed Foundation source
installed package source
local documentation
Git metadata
```

No HTTP MCP server is part of this package.

---

## 8. Foundation and Specialist-Package Boundary

Foundation MCP must **not require `infocyph/foundation` itself**. It inspects whichever Foundation version is installed by the host application.

The same applies to specialist packages: Foundation MCP must not require them merely for inspection.

Current Foundation runtime dependencies include:

```text
infocyph/arraykit ^5.1.1
infocyph/intermix ^9.2
infocyph/uid ^5.0
infocyph/webrick ^4.0.2
psr/log ^3.0.2
```

Current purpose-first module packages include:

```text
auth          -> infocyph/otp ^6.0 + web-auth/webauthn-lib ^5.3.5
cache         -> infocyph/cachelayer ^3.2.0
communication -> infocyph/talkingbytes ^2.0
database      -> infocyph/dblayer ^5.0
filesystem    -> infocyph/pathwise ^3.1
logging       -> Foundation built-in
messaging     -> infocyph/omnibus ^2.5
operations    -> Foundation built-in
resources     -> Foundation built-in
security      -> infocyph/epicrypt ^2.1
session       -> Foundation built-in
validation    -> infocyph/reqshield ^3.1
```

These values document the current graph; they are not a second mutable registry inside Foundation MCP.

At runtime, the exact package/module truth must be derived from the installed Foundation/Composer state.

---

## 9. Purpose-First Module Intelligence

Foundation modules represent application purposes, not package names.

Foundation MCP must statically read the installed Foundation `ModuleCatalog` and extract:

- canonical module name;
- aliases;
- description;
- package constraints;
- built-in flag;
- config files;
- schema ownership.

It must then correlate the catalog with local Composer/project state.

Useful statuses include:

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

Never equate package presence with runtime activation. Foundation capabilities are lazy and package presence alone is not activation.

Do not execute `ModuleCatalog` or bootstrap Foundation just to read it; parse installed source statically through the PHPForge-provided parser stack. The catalog reader accepts only literal catalog data, rejects executable/dynamic expressions and keeps reads bounded to the Composer-authorized Foundation install root.

The initial catalog-intelligence layer derives catalog, package, config and runtime-unknown statuses without scanning the project. `statically_referenced` is added later by the reference index when project-source evidence exists.

---

## 10. Foundation Architecture Semantics

Foundation MCP must understand and expose the real ownership boundaries of Foundation:

- InterMix: dependency injection, scopes, lifetimes;
- Webrick: HTTP routing/request/response mechanics;
- Foundation: bootstrap, runtime selection, providers, CLI policy, modules, scheduling/application orchestration;
- Omnibus: events/messages/queues/workers/workflows;
- DBLayer: database mechanics;
- CacheLayer: cache/locks/counters/shared state;
- Pathwise: storage/filesystem behavior;
- ReqShield: validation/sanitization;
- EpiCrypt: cryptographic primitives;
- TalkingBytes: HTTP/email/webhook/gRPC mechanics;
- UID: identifier algorithms.

Foundation owns exactly four explicit runtime graphs:

```text
web
cli
worker
scheduler
```

Runtime mode is not inferred from PHP SAPI.

The MCP must prevent misleading context such as inventing generic Foundation-prefixed facades for specialist libraries or treating application maintenance workers as Omnibus queue workers.

---

## 11. Host Project Detection

Support:

1. canonical Infbyte applications/skeleton;
2. custom applications directly using Infocyph Foundation.

Root discovery starts from the current working directory and walks upward unless `--root` is supplied.

An explicit `--root` is authoritative: resolve exactly that directory and do not climb above it. This keeps client configuration deterministic and prevents a mistyped explicit root from silently selecting a parent project.

Evidence may include:

- `composer.json` requiring `infocyph/foundation`;
- Composer-installed Foundation metadata;
- root `infbyte` executable;
- `bootstrap/app.php` Foundation runtime construction;
- canonical app/bootstrap/config/routes structure.

Do not require the Composer package name to remain `infocyph/infbyte`.

Classify host as:

```text
infbyte
foundation_custom
unsupported
```

and return detection evidence.

The resolved project root is immutable for the server process.

---

## 12. Source-of-Truth Precedence

Use this precedence when sources disagree:

```text
1. current project working tree
2. composer.lock
3. Composer installed-version/install-path metadata
4. installed package source/composer.json
5. project composer.json constraints
6. local package/project documentation
```

Never infer an exact version from a Composer constraint.

If lock and installed metadata disagree, expose an explicit dependency-state mismatch.

---

## 13. Composer Intelligence

Read:

- project `composer.json`;
- `composer.lock`;
- `vendor/composer/installed.json` as the normal installed-state source;
- `Composer\InstalledVersions` only when its reported root canonicalizes to the same resolved host project;
- installed package `composer.json` files as lower-precedence metadata fallback.

Never execute target-project `vendor/composer/installed.php` merely to inspect Composer state. Foundation MCP must not turn generated PHP metadata into an execution path when equivalent JSON metadata is available.

Composer metadata reads are bounded; the initial production limit is 32 MiB per Composer metadata JSON file. Package install paths are canonicalized with `realpath()` before being authorized. Composer path-repository/symlink installs may resolve outside the host root and are allowed only because their canonical root came from that project's installed Composer metadata.

Expose:

- runtime/dev direct dependencies;
- transitive graph;
- exact locked/installed versions kept as distinct values;
- locked/installed source references kept as distinct values;
- deterministic package state such as matched, declared-unlocked, missing-install, installed-unlocked, version-mismatch and source-reference-mismatch;
- canonical install paths;
- autoload mappings;
- suggest/provide/replace/conflict metadata;
- runtime/dev PHP/platform requirements;
- package ownership by canonical install root;
- missing package, invalid metadata and lock/install mismatch diagnostics.

Do not implement a Composer solver and do not require full `composer/composer` just to inspect local metadata.

Do not add `composer/semver` unless the implementation proves a necessary operation cannot be answered from existing Composer/runtime metadata without reimplementing constraint semantics.

---

## 14. PHP Source Analysis

The production analysis path is the parser/analysis environment supplied by required PHPForge.

Foundation MCP statically extracts:

- namespace/imports/aliases;
- class/interface/trait/enum/function declarations;
- methods/properties/promoted properties/constants/enum cases;
- parameters/types/return types;
- inheritance/implements/trait relationships;
- attributes;
- bounded PHPDoc summaries;
- constructor/static/function calls;
- resolvable and lexical method/property calls;
- type references;
- bounded literal arrays used by Foundation/module/config registration;
- file/line locations;
- per-reference confidence (`resolved`, `lexical`, `dynamic` at analyzer level; `exact` is added by indexes where declaration identity is proven).

The analyzer works in-process through the PHP parser supplied by the mandatory PHPForge dependency graph. It does not add a second direct parser dependency, does not shell out to PHPForge per file, does not retain full ASTs after compact extraction and does not execute host source.

Project source is constrained to approved project paths; package source is authorized only for the package explicitly requested using Composer-resolved install roots. Reads are limited to `.php` regular files, protected by the secret policy and bounded to 2 MiB per source file. Literal extraction is independently bounded by array count, item count, depth and string size.

Parse errors are file-local structured results. One malformed PHP file must not make the analyzer unusable for unrelated files. Unchanged analysis is cached by canonical file identity plus content fingerprint and automatically invalidates when source content changes.

The server must never `require` arbitrary project/package source files for discovery and must never use Reflection against host application classes as its primary analysis mechanism.

### Mandatory parser availability

There is no reduced tokenizer-only operating mode in the production contract.

If the PHPForge toolchain is present but the parser capability Foundation MCP requires is missing or incompatible:

- `doctor` fails;
- server startup may fail with a clear dependency/toolchain diagnostic;
- CI/release fails.

This keeps analysis quality deterministic and avoids maintaining two divergent analysis implementations.

---

## 15. No Application Bootstrap or Source Execution

Never bootstrap the host application for ordinary inspection.

Do not call:

```text
Foundation::web()
Foundation::cli()
Foundation::worker()
Foundation::scheduler()
```

Do not execute `php infbyte ...` to discover metadata.

Do not instantiate providers, controllers, workers, routes, database connections, cache stores or user classes.

Foundation MCP must remain useful when:

- application bootstrap is broken;
- config is invalid;
- a provider throws;
- database/cache/network is unavailable;
- a route references a missing class;
- an individual PHP file has a syntax error.

A parse failure in one file must be reported without making unrelated project context unavailable whenever safely possible.

---

## 16. Project and Package File Discovery

Use Composer autoload metadata rather than hardcoding only `app/`.

Discover PSR-4/PSR-0/classmap/dev roots and recognize canonical host areas:

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

Default project exclusions:

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

Do not globally scan `vendor/`.

Dependency source access is package-aware and rooted from Composer's actual install path. This must support path-repository/symlink installs while still preventing arbitrary filesystem access.

Default dependency intelligence covers installed `infocyph/*`, Foundation itself and packages named by the installed Foundation module catalog. Third-party installed packages may be inspected when explicitly requested.

---

## 17. Foundation Structural Inspectors

Provide dedicated static inspectors for the Foundation host conventions.

### Routes

Inspect Webrick/Foundation route registration and return method, path, name, handler, middleware/options, source/line and resolved/dynamic status.

### Commands

Inspect explicit `routes/console.php` registration. Foundation has no second command-directory discovery mechanism. Return command name/class/source/metadata where statically available.

### Providers

Inspect `bootstrap/providers.php` and equivalent host registration without instantiation.

### Configuration

Understand the precedence implemented by the installed Foundation `ConfigLoader`/`ConfigRepository`:

```text
Foundation defaults
-> project config/*.php
-> selected preset
-> inline bootstrap values
```

The inspector derives Foundation default sources from the installed Foundation config contract, statically reads project config and selected preset arrays, and correlates bootstrap runtime/inline configuration without executing application or package code. It returns key paths, literal defaults where provable, referenced classes, environment variable names/default literals, ownership and source evidence. Custom `paths.config` is followed only when it is a static project-contained path. Actual `.env` values are never loaded, and secret-looking literals/defaults are redacted.

### Schedules

Inspect `routes/schedule.php`, fluent timing/policy calls, stable schedule identity where present, overlap/single-server policy and dynamic sections.

### Workers

Keep distinct:

1. Foundation application maintenance workers from `routes/workers.php` / `WorkerProvider`;
2. Omnibus messaging workers configured through messaging config.

### Runtime/bootstrap

Inspect bootstrap runtime selection, base path and inline options. Preserve the four explicit Foundation runtime graphs.

### Modules

Use the installed Foundation `ModuleCatalog`, not a copied Foundation-MCP registry.

---

## 18. Symbol and Reference Indexes

Build lazy in-memory indexes over project source and requested dependency source.

The symbol index records declarations, signatures, ownership, relationships and line ranges. It is not built during project detection or lightweight project-summary work. Project indexing begins only when symbol-oriented intelligence is requested; dependency symbol indexing begins only for the explicitly requested package.

Source discovery is Composer/host-root aware. Project indexing covers discovered application/test roots plus approved structural roots, skips excluded directories, secret-bearing paths and nested symlinks, and never scans `vendor/`. Package indexing derives roots from that installed package's Composer autoload metadata instead of walking the entire package/dependency tree.

The symbol index keeps compact per-file entries. A path/size/mtime/ctime metadata state is used to identify unchanged files; only added/changed files are re-analyzed, removed files are evicted, and `PhpAnalyzer` still verifies changed contents through its stronger content fingerprint. Duplicate declarations are preserved as multiple candidates rather than guessed away. Exact symbol spelling is preferred and case-folded matching is a fallback that may return ambiguity.

Per-file parser/analysis failures become bounded index diagnostics without preventing symbols from unrelated valid files from being returned.

The reference index reuses the same lazy source manifest/analyzer stack and records:

```text
import
new
extends
implements
trait-use
attribute
type
call
class_constant
property
```

Foundation-specific relationships such as route, command, provider, config, worker, schedule and test are added by their dedicated inspectors/relationship layers rather than guessed from generic PHP nodes.

Every generic reference carries source path/line, owning source symbol when statically attributable, target, relationship and confidence:

```text
exact
resolved
lexical
dynamic
```

`exact` is promoted only when a unique compatible declaration exists in the current symbol index. Promotion is relationship/kind aware: a lexical method name is never made exact just because an unrelated global function has the same text. Duplicate declaration candidates therefore prevent exact promotion. Dynamic calls remain dynamic.

Usage queries are deterministic, case-preserving with case-folded fallback, support relationship filtering and are hard-bounded to at most 500 results. Project usage indexing is lazy; package-internal usage indexing occurs only for an explicitly requested package. Reference entries refresh incrementally using the same manifest state, while declaration changes can still alter exact-confidence decoration without forcing unrelated files to be reparsed.

Dynamic PHP that cannot be proven statically must be reported as unresolved rather than guessed.

---

## 19. Related-Test Discovery

For source symbols and project files, related tests are ranked by evidence in this order:

1. exact symbol references;
2. direct construction/call references;
3. path relationships;
4. filename conventions;
5. lexical fallback.

The `TestLocator` reuses the lazy symbol/reference indexes and Composer-aware `SourceFileFinder`; it does not maintain a second source index. Test roots come from Composer `autoload-dev`/discovered test roots plus conventional `tests/`, `test/` and `spec/` directories, so custom test layouts remain supported.

A unique exact reference receives exact confidence; direct resolved calls/construction receive resolved confidence; path, filename and text evidence remain lexical. Filename or path similarity alone is never upgraded to exact. Ambiguous source symbols fail explicitly rather than combining unrelated candidates.

File targets are canonicalized through the project `PathPolicy`. Files without PHP declarations can still find tests through path/filename and lexical evidence. Lexical fallback is bounded to 256 KiB per test file and 8 MiB total, rejects binary/invalid UTF-8 content, and never escapes approved project/test roots. Results are deterministically sorted and hard-bounded to 100 candidates.

---

## 20. Git Workspace Intelligence

Git integration is read-only and optional.

Expose:

- branch/HEAD/detached state;
- dirty state;
- staged/unstaged/untracked files;
- add/delete/rename status;
- changed PHP declarations/references;
- route/command/provider/config/module/worker/schedule changes;
- Composer dependency changes;
- initial affected tests/symbols.

Use argument-safe `proc_open()` calls and a fixed read-only Git allowlist.

Never call through `sh -c`, `bash -c`, `cmd /c` or PowerShell command strings.

No mutating Git command is permitted.

---

## 21. Dependency and Change Impact

When Composer files change, detect:

- package additions/removals;
- direct constraint changes;
- exact locked-version changes;
- runtime/dev movement;
- source-reference changes;
- changed transitive set;
- affected Foundation modules;
- project code referencing changed packages.

Provide deterministic bounded impact analysis for:

```text
symbol
file
package
module
route
config
workspace changes
```

Combine evidence from references, inheritance, implementations, traits, registrations, module/package graph, config relationships, Git changes and related tests.

Direct evidence and lexical/inferred evidence must remain distinguishable.

---

## 22. MCP Tool Surface

Expose exactly this compact production tool set:

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

The `foundation_` prefix prevents collisions when an AI client connects multiple MCP servers.

### `foundation_project`

Authoritative host summary: host type, PHP, Foundation declared/locked/installed version, Composer diagnostics, Git summary, autoload/test roots, analysis/PHPForge readiness and module/package overview.

### `foundation_search`

Unified project/Foundation/package/tests/routes/config/docs symbol/path/text search with deterministic ranking, small excerpts and result limits.

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

The domain search engine uses deterministic score bands and stable tie-breakers. Pure path/text searches do not invoke the PHP parser. Symbol searches activate only the relevant lazy symbol index. `packages` requires an explicit installed package; `all` searches the project plus installed Foundation and, when supplied, one explicit package rather than scanning all of `vendor/`.

Search output is hard-bounded to 100 results. Resource discovery is capped at 2,500 files per target; text search reads at most 512 KiB per file and 16 MiB total per target, skips secret/binary/oversized resources and emits redacted excerpts capped at 240 bytes.

### `foundation_read`

Bounded safe read of approved project/dependency resources with line ranges and secret/path protection.

The shared reader canonicalizes project/package paths through `PathPolicy`, requires an explicitly selected installed package for dependency reads, denies secret-bearing and binary resources, caps a file at 1 MiB and a response at 400 lines (200 default), redacts suspicious literals, and returns canonical relative path, actual line range, total lines, size, SHA-256 fingerprint and truncation state.

### `foundation_symbol`

Exact declaration/signature/relationships/source information for a PHP symbol.

### `foundation_usages`

References to a symbol with relationship and confidence.

### `foundation_inspect`

One structural tool with kinds:

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

### `foundation_packages`

Composer package/dependency/module graph with exact installed/locked versions and bounded depth.

### `foundation_changes`

Compact working/staged/branch change intelligence without dumping full diffs.

### `foundation_impact`

Bounded impact graph for symbols/files/packages/modules/routes/config/current changes.

Do not create dozens of tiny feature-specific tools.

---

## 23. MCP Resources

Use URI namespace:

```text
foundation://
```

Static resources:

```text
foundation://project/summary
foundation://project/architecture
foundation://project/composer
foundation://project/module-catalog
foundation://project/standards
```

Resource templates:

```text
foundation://project/file/{path}
foundation://package/{package}/file/{path}
foundation://symbol/{symbol}
```

Resources and tools share the same underlying services; no duplicate analysis logic in MCP handlers.

The architecture resource must be generated from the actual installed project/Foundation state rather than a stale hardcoded essay.

The standards resource should aggregate local project/Foundation/PHPForge development rules with source attribution; it does not become a second linter.

No MCP prompts are exposed. Agent behavior belongs in agent skills/instructions/user prompts.

---

## 24. Read-Only and Command-Safety Contract

Foundation MCP exposes no project mutation.

Forbidden capabilities include:

- edit/create/delete project files;
- Composer require/update/remove;
- module install/remove/config publish;
- migrations/database writes;
- cache mutation;
- worker/scheduler control;
- Git mutation;
- deployment operations;
- environment changes.

Never expose generic tools such as:

```text
shell
exec
run_command
php
composer
git
```

The AI coding agent may have its own separately authorized tools. Foundation MCP remains a context server.

---

## 25. Filesystem and Secret Security

Approved read roots are only:

1. resolved host project root;
2. Composer-registered install roots for explicitly selected installed packages.

Approved project/package roots are canonicalized once when the server context is built and remain immutable for that server process. Path-repository/symlink package installs are allowed only through their Composer-resolved canonical install root.

For every file read:

- reject NUL bytes;
- normalize/resolve real path;
- enforce allowed-root containment;
- reject traversal and symlink escape;
- reject device/socket/FIFO paths;
- reject binary/oversized content unless only metadata is requested;
- enforce byte/line limits.

Hard-deny secret-bearing files/patterns including:

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

`.env.example` is intentionally allowed.

Before MCP output, redact suspicious literal secrets associated with names such as password, secret, token, api_key, private_key, authorization, cookie, credential and DSN, plus recognized key/token patterns.

Configuration analysis returns environment variable **names/defaults**, never loaded values.

---

## 26. Output and Context Budgets

Every MCP response is bounded server-side.

Prefer:

- small ranked result sets;
- compact structured output;
- small excerpts;
- exact line ranges;
- resource URIs for follow-up.

Expected interaction:

```text
foundation_search
  -> small candidates
foundation_symbol
  -> exact API
foundation_read
  -> only required lines
```

The underlying search/read services already enforce their own hard limits before MCP serialization: search returns at most 100 ranked results and 240-byte excerpts; text scanning is bounded by files and bytes; reads are limited to 1 MiB and 400 lines. Related-test discovery is also capped at 100 results and its lexical fallback at 256 KiB per file / 8 MiB total. MCP handlers may impose tighter defaults but must never widen these service limits.

Never return whole repositories or many full source files from a search call.

---

## 27. Performance Architecture

Do not introduce:

- vector database;
- embeddings;
- Elasticsearch;
- Redis;
- SQL server;
- background queue/worker;
- LLM API;
- RAG framework;
- persistent project index by default.

Use:

- Composer metadata;
- PHPForge-provided parser stack;
- filesystem metadata;
- compact in-memory indexes;
- Git metadata;
- file fingerprints.

### Lazy analysis

`foundation_project` must not parse the whole project.

Build symbol/reference/route/etc. indexes only when the requested operation needs them.

Never scan all `vendor/` or parse every installed dependency on startup.

Path/text search remains parser-free. Symbol search activates only the relevant project or explicit-package symbol index. Search does not scan every installed dependency: Foundation is a known target and any other dependency target must be explicit. Related-test discovery reuses the already-lazy project symbol/reference indexes and scans only discovered test roots for bounded lexical fallback.

### In-memory cache

Cache compact parsed metadata using path + size + mtime/content fingerprint as appropriate. Invalidate automatically when files or `composer.lock` change.

Avoid retaining full ASTs after compact metadata extraction unless benchmarks prove retention beneficial.

### Persistent cache

Do not write an index/database into the host project. The MCP must not dirty the working tree merely by running.

If benchmarking proves a disposable disk cache necessary for this same production release, it must live in the OS cache directory outside the repository, be optional and be keyed by project/content identity.

---

## 28. Error and Failure Model

Distinguish errors such as:

```text
invalid_input
unsupported_project
phpforge_unavailable
analysis_backend_unavailable
analysis_backend_incompatible
composer_lock_missing
composer_lock_invalid
installed_metadata_missing
installed_metadata_invalid
dependency_state_mismatch
module_catalog_missing
module_catalog_invalid
resource_not_found
package_not_installed
symbol_not_found
ambiguous_symbol
path_denied
secret_denied
binary_resource
git_unavailable
invalid_git_ref
parse_error
dynamic_unresolved
output_limit_exceeded
internal_analysis_failure
```

Expected tool failures must not terminate the MCP server.

Do not expose raw stack traces or unnecessary private absolute paths through MCP responses.

Verbose diagnostics go only to STDERR.

---

## 29. CLI Surface

Required commands:

```bash
vendor/bin/foundation-mcp
vendor/bin/foundation-mcp serve
vendor/bin/foundation-mcp doctor
```

Common options:

```text
--root=<path>
--verbose
--no-git
```

`doctor` is read-only and verifies:

- host/root detection;
- Composer state;
- Foundation installed/locked version;
- official MCP SDK availability/version;
- PHPForge availability/version;
- required PHP parser/analysis capability supplied through PHPForge toolchain;
- ModuleCatalog readability;
- source roots;
- Git availability;
- path/security policy readiness.

A failed mandatory parser/PHPForge requirement is a failed doctor check, not a fallback mode.

---

## 30. Repository Structure

Target structure:

```text
Foundation-MCP/
├── bin/
│   └── foundation-mcp
├── src/
│   ├── Application.php
│   ├── Project/
│   │   ├── Project.php
│   │   ├── ProjectLocator.php
│   │   ├── ProjectDetector.php
│   │   └── SourceRoots.php
│   ├── Composer/
│   │   ├── ComposerInspector.php
│   │   ├── ComposerMetadataReader.php
│   │   ├── InstalledPackage.php
│   │   └── DependencyGraph.php
│   ├── Analysis/
│   │   ├── AnalyzedFile.php
│   │   ├── PhpAnalyzer.php
│   │   ├── SourceFileFinder.php
│   │   ├── Internal/
│   │   │   ├── PhpDeclarationVisitor.php
│   │   │   ├── PhpReferenceVisitor.php
│   │   │   └── PhpLiteralVisitor.php
│   │   ├── SymbolIndex.php
│   │   ├── ReferenceIndex.php
│   │   ├── SearchEngine.php
│   │   ├── TestLocator.php
│   │   └── ImpactAnalyzer.php
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
│   ├── Git/
│   │   ├── GitRunner.php
│   │   └── WorkspaceInspector.php
│   ├── Security/
│   │   ├── PathPolicy.php
│   │   ├── SecretPolicy.php
│   │   └── Redactor.php
│   ├── Resource/
│   │   └── ResourceReader.php
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
├── tests/
│   ├── Fixtures/
│   ├── Unit/
│   ├── Integration/
│   ├── Protocol/
│   ├── Security/
│   └── Performance/
├── composer.json
├── README.md
├── CHANGELOG.md
├── LICENSE
└── PROJECT_PLAN.md
```

Avoid interfaces/classes created only for symmetry. Introduce abstractions only at real replaceable/testing boundaries. `ComposerMetadataReader` is an intentional boundary between bounded raw Composer artifact acquisition and semantic package/graph correlation; it keeps file/runtime metadata mechanics out of `ComposerInspector`. `SourceFileFinder` is the shared PHP source-manifest boundary used by the lazy symbol/reference indexes; it centralizes PHP-source exclusion, secret, symlink and Composer-autoload discovery. `SearchEngine` owns a separate bounded text/resource discovery path because search also covers non-PHP project/config/route/doc files and must not force AST parsing. `ResourceReader` is the single safe line-range read service reused by later MCP read/resource handlers. `TestLocator` is a ranking layer over the existing indexes/source manifest rather than another parser/index.

---

## 31. Test Requirements

### Unit

Cover project detection, Composer graph, ModuleCatalog parsing, PHP analysis, symbols/references, search ranking, routes, commands, providers, config, schedules, workers, Git parsing, impact analysis, related tests, redaction, path security and output limits.

### Integration fixtures

Include:

- minimal Infbyte;
- custom Foundation host;
- all optional modules represented;
- custom autoload roots;
- no Git;
- dirty Git;
- Composer changes;
- missing package;
- lock/install mismatch;
- broken PHP file;
- dynamic PHP registration;
- Composer path/symlink package;
- secret-containing files;
- large source tree.

Ordinary test runs must be network-independent.

### Real ecosystem contract

CI must validate against the actual current Infbyte/Foundation ecosystem: project structure, installed Foundation, ModuleCatalog, package graph, canonical routes/console/schedule/workers, PHPForge dependency availability and Infbyte require-dev integration.

### MCP protocol

Use official `mcp/sdk` client integration tests against the real STDIO server for negotiation, tool/resource listing, tool calls, structured results, invalid inputs, error survival, output framing and supported protocol revisions.

### Security

Test traversal, symlink escape, secret files, binaries, large reads, malicious package names/Git refs, shell metacharacters, malformed source, parse failures and schema/output abuse.

---

## 32. Cross-Platform and Performance Gates

CI matrix:

```text
Linux / PHP 8.4
Linux / PHP 8.5
Windows / PHP 8.4
Windows / PHP 8.5
```

Add macOS smoke coverage when reasonable.

Windows support must not depend on Bash.

Benchmark cold/warm:

- server startup;
- `foundation_project`;
- exact symbol lookup;
- search;
- usages;
- module/route inspection;
- dependency graph;
- change analysis;
- impact analysis.

Use deterministic small/medium/large fixtures and establish release baselines.

Performance rules:

```text
no full vendor scan at startup
no whole-project parse for foundation_project
no per-file subprocess
no repeated parse of unchanged files
no unbounded graph/search output
no unnecessary full-AST retention
```

---

## 33. PHPForge Release Gate

Because PHPForge is both a **runtime dependency of Foundation MCP** and the repository QA/release toolchain, Foundation MCP's release must pass all applicable PHPForge gates available at release time, including formatting, lint/syntax, static analysis, architecture/dependency rules, complexity, security, tests, refactoring/duplicate checks, benchmarks and release constraints.

Do not weaken PHPForge rules to accommodate avoidable Foundation MCP complexity.

The release matrix must explicitly verify compatibility with the exact PHPForge constraint shipped by Foundation MCP (`dev-main@dev` through the consumer lock).

---

## 34. Generated Foundation Artifacts

Foundation deployment may create config, route/matcher, command, schedule, container and optimize artifacts under deployment-owned cache paths.

Foundation MCP recognizes these for diagnostics but treats authoritative source/config as primary for development intelligence.

Generated artifacts are excluded from normal indexing and are never created, cleared or modified by Foundation MCP.

---

## 35. Documentation Requirements

README must document:

- purpose and architecture;
- Infbyte `require-dev` integration;
- mandatory PHPForge dependency;
- official `mcp/sdk` dependency;
- nine tools;
- resources/templates;
- exact-version and module semantics;
- STDIO setup;
- zero-network/read-only policy;
- security/secret policy;
- `doctor` behavior;
- performance/output limits;
- troubleshooting;
- client-agnostic MCP launch command;
- stable client examples where useful.

Documentation must clearly state that Foundation MCP and PHPForge are development tooling and disappear from production under `composer install --no-dev`.

---

## 36. Explicit Non-Goals

Foundation MCP is not:

- a Foundation runtime component;
- a generic MCP framework;
- a hosted MCP service;
- a GitHub/Packagist client;
- a package updater/Composer solver;
- a module installer;
- an AI/LLM service;
- a vector/RAG platform;
- a PHPForge replacement;
- a linter/formatter replacement;
- a database execution server;
- an arbitrary shell server;
- a deployment/operations control plane;
- a code mutation tool.

---

## 37. Complete Production Release Checklist

The first release includes the entire intended scope; no desired capability is intentionally deferred to a hypothetical later release.

```text
[x] package skeleton/composer/binary
[ ] mcp/sdk STDIO integration
[x] PHPForge as mandatory `require` dependency
[x] PHPForge/parser compatibility doctor gate
[ ] explicit MCP registration
[x] Infbyte/custom-Foundation project detection
[x] secure root/package path model
[x] Composer exact-version/package graph
[x] installed Foundation ModuleCatalog parser
[x] purpose-first module intelligence
[x] PHPForge-backed AST/source analyzer
[x] lazy symbol index
[x] lazy reference/usage index
[x] deterministic search/read
[x] related-test discovery
[x] route inspector
[x] command inspector
[x] provider inspector
[x] config inspector
[ ] schedule inspector
[ ] Foundation maintenance-worker inspector
[ ] Omnibus worker distinction
[ ] runtime/bootstrap inspector
[ ] Git workspace inspector
[ ] dependency-change analyzer
[ ] impact analyzer
[ ] foundation_project
[ ] foundation_search
[ ] foundation_read
[ ] foundation_symbol
[ ] foundation_usages
[ ] foundation_inspect
[ ] foundation_packages
[ ] foundation_changes
[ ] foundation_impact
[ ] project summary resource
[ ] architecture resource
[ ] Composer resource
[ ] ModuleCatalog resource
[ ] standards resource
[ ] safe project/package file templates
[ ] symbol resource template
[ ] no application bootstrap/source execution
[ ] no arbitrary shell
[ ] no mutation
[ ] zero-network normal operation
[x] path/symlink/secret protections
[ ] bounded output
[ ] lazy in-memory cache/invalidation
[ ] doctor command
[ ] unit/integration/ecosystem tests
[ ] MCP protocol tests
[ ] security tests
[ ] performance benchmarks
[ ] Linux/Windows PHP 8.4/8.5 CI
[ ] PHPForge full release gates
[ ] README/security documentation
[ ] Infbyte require-dev integration
[ ] composer --no-dev zero-footprint validation
```

Update this checklist after each meaningful implementation chunk. Do not mark an item complete without implementation/test evidence.

### Implementation evidence

- `092e1ad9` — package skeleton, Composer contract, executable and initial SDK STDIO bootstrap.
- `ac78615a` — initial `doctor` dependency checks for the MCP SDK, PHPForge and PHP parser availability.
- `feat: add project detection and filesystem security model` — strict CLI root handling; immutable project context; renamed-canonical Infbyte/custom/unsupported classification; Composer-autoload source roots; project/package path containment; traversal/absolute/symlink-escape rejection; hard secret-file policy; output redaction; PHPForge parser parse-probe; unit coverage; PHP 8.4 syntax validation and local smoke validation.
- `feat: add Composer and Foundation package intelligence` — distinct declared/locked/installed package truth; runtime/dev direct dependencies; bounded transitive graph; source-reference and install-path state; `installed.json` normal path with root-proven `InstalledVersions` fallback; no `installed.php` execution; installed package composer metadata fallback; platform requirements; canonical package ownership/roots; Foundation exact-version diagnostics; lock/install mismatch diagnostics; focused unit coverage; PHP 8.4 syntax and standalone smoke validation.
- `9b0844b5` — bounded static parsing of installed Foundation `ModuleCatalog::MODULES` through the PHPForge-provided PHP parser; literal-only evaluation with dynamic-expression rejection; module/alias/package resolution with ambiguity checks; Composer package/config correlation; built-in/package/config/runtime-activation-safe statuses; ModuleCatalog doctor gate; focused unit coverage added; PHP 8.4 syntax validation passed locally. The dependency-complete Pest/PHPForge suite remains for CI/integration validation.
- `617c5e9d` — PHPForge-supplied in-process PHP parser backend; compact declarations/signatures/imports/inheritance/traits/attributes/PHPDoc extraction; promoted properties/constants/enum cases; resolved/lexical/dynamic reference extraction; bounded literal arrays; explicit-project and explicit-package path authorization; secret/file/size controls; file-local parse errors; content-fingerprint cache invalidation; focused unit coverage. Local PHP 8.4 syntax validation passed for the analyzer result/entry point and literal visitor; the dependency-complete Pest/PHPForge suite remains for CI/integration validation.
- `e8ec06af` — Composer/host-aware PHP source discovery; project-only lazy symbol build; explicitly requested package-only lazy build; excluded/secret/symlink path filtering; compact symbol ownership/source metadata; deterministic exact/case-folded lookup with duplicate ambiguity preserved; incremental added/changed/removed file refresh; per-file parse/analysis diagnostics; focused unit coverage including no-op refresh and single-file invalidation. Local PHP 8.4 syntax probes passed for the new source-finder/index constructs; the dependency-complete Pest/PHPForge suite remains for CI/integration validation.
- `05d95cbb` — lazy project/package reference indexing over the shared source manifest/analyzer; import/generic PHP relationships; source-symbol attribution; relationship/kind-aware unique-declaration promotion to exact confidence; lexical/dynamic preservation; deterministic bounded usage lookup with relationship filters; incremental refresh; per-file diagnostics; focused coverage for false-positive exactness, package usage and one-file invalidation. The dependency-complete Pest/PHPForge suite remains for CI/integration validation.
- `62e51109` — deterministic ranked symbol/path/text search across project/test/route/config/bootstrap/docs/Foundation/explicit-package/all scopes; pure path/text searches remain parser-free; explicit package targeting prevents broad vendor scans; secret/symlink/exclusion filtering; 2,500-file, 512 KiB/file, 16 MiB/target, 100-result and 240-byte-excerpt search bounds; canonical project/package line-range reads with 1 MiB/400-line limits, binary/secret denial, redaction, fingerprint/truncation metadata; focused tests for parser-free filesystem search, scope/package ranking, secret omission, bounded reads, redaction and traversal denial. Local PHP 8.4 syntax validation passed for the new search/read classes and focused tests; dependency-complete Pest/PHPForge execution remains for CI/integration validation.
- `feat: add related-test discovery` — symbol/file related-test ranking over the existing lazy symbol/reference indexes; Composer `autoload-dev` plus conventional test-root discovery; exact-reference/direct-call/direct-construction/path/filename/lexical evidence bands; explicit ambiguity handling; canonical file targets; 100-result bound and 256 KiB/file / 8 MiB lexical fallback limits; focused coverage for exact class/method references, structural fallback, custom `spec/` roots, lexical-only files and input bounds. Local PHP 8.4 syntax validation passed for `TestLocator` and its focused test; dependency-complete Pest/PHPForge execution remains for CI/integration validation.
- `e983e38e` — static Foundation/Webrick route inspection across authoritative project route files, Foundation OAuth candidates and Webrick route attributes; installed route-file/verb/resource contracts are derived from installed package source rather than copied registries; nested groups/preset groups/resource expansion/handlers/middleware/options/conditional and dynamic state are represented without application bootstrap or package execution; output/source limits and file-local parse diagnostics are enforced.
- `d91db26c` — static `routes/console.php` command registration inspection plus non-executing `CommandDefinition::define()` metadata extraction for command names, aliases, descriptions, groups, runtime, capabilities, visibility, arguments/options and dynamic/conditional definitions; command inspector files are attached at their canonical paths.
- `59dae9cf` — static `bootstrap/providers.php` inspection with provider groups derived from the installed Foundation contract, common/runtime effective provider graphs, declaration deduplication, project symbol/Composer ownership metadata, dynamic-state preservation and no provider instantiation.
- `f9ffb2a5` — static Foundation configuration inspection across installed default sources, project config, selected preset and inline bootstrap configuration using the installed runtime precedence; bounded AST evaluation records literal/default/env/class/source evidence, preserves dynamic state, applies project/package path authorization and redacts secret-looking values without loading `.env` or bootstrapping the application.

The overall `mcp/sdk STDIO integration`, `explicit MCP registration`, `doctor command`, `bounded output`, `lazy in-memory cache/invalidation` and broad test-suite checklist entries remain intentionally open until their complete production contracts are exercised across the relevant service/index/protocol/integration layers.

---

## 38. Recommended Implementation Order

All steps target the same production release:

1. package Composer/binary + official SDK STDIO server;
2. PHPForge runtime integration and doctor checks;
3. project/root/security model;
4. Composer/package/Foundation detection;
5. ModuleCatalog + Foundation architecture semantics;
6. PHPForge-backed source analyzer/symbol/search/read;
7. usages/test relationships;
8. route/command/provider/config/worker/schedule/runtime inspectors;
9. Git changes/dependency changes;
10. impact engine;
11. MCP tools/resources and output budgets;
12. failure handling/security hardening;
13. protocol/integration/ecosystem tests;
14. benchmarks/performance optimization;
15. documentation/Infbyte integration;
16. PHPForge full production release gate.

---

## 39. Definition of Done

Foundation MCP is production-release ready only when:

1. Infbyte can require `infocyph/foundation-mcp` only under `require-dev`.
2. Foundation MCP directly requires `composer-runtime-api ^2.1`, `infocyph/phpforge: dev-main@dev` and `mcp/sdk ^0.8.0`.
3. It does not duplicate PHPForge's parser/static-analysis toolchain in its Composer requirements.
4. `doctor` proves the required PHPForge/parser analysis capability is installed and compatible.
5. `vendor/bin/foundation-mcp` exposes the complete STDIO MCP surface.
6. It works for canonical Infbyte and custom Foundation hosts.
7. It reports exact locked/installed Foundation/package versions.
8. It reads the installed Foundation ModuleCatalog statically and preserves purpose-first semantics.
9. It distinguishes package presence from runtime activation.
10. It searches/reads/analyzes project and approved package source without executing host code.
11. It provides exact symbols/usages with confidence and bounded context.
12. It understands routes, commands, providers, config, workers, schedules and all four Foundation runtime graphs.
13. It understands Git/dependency changes and produces deterministic bounded impact/test relationships.
14. It remains useful when application bootstrap/runtime dependencies are broken.
15. It performs no normal-operation network requests.
16. It cannot mutate project/runtime/Git state or execute arbitrary commands.
17. It cannot read outside approved roots or expose protected secrets.
18. Lightweight calls do not scan/parse the entire project/vendor tree.
19. Protocol, integration, real-ecosystem, security, cross-platform and benchmark suites pass.
20. All applicable PHPForge release gates pass.
21. `composer install --no-dev` leaves zero Foundation MCP/PHPForge production runtime footprint.
22. Documentation accurately describes the dependency, security and capability contracts.

---

## 40. Final Responsibility Statement

> **Foundation MCP gives an AI coding agent precise, safe, current and version-correct knowledge of the Infbyte/Foundation application and the exact Infocyph ecosystem installed behind it, using PHPForge as its mandatory development-analysis substrate and the official PHP MCP SDK as its protocol implementation, while remaining completely outside the application's production runtime.**