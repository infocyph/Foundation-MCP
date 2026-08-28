# Changelog

All notable changes to Foundation MCP will be documented in this file.

## Unreleased

### Added

- Initial Composer package skeleton.
- `foundation-mcp` executable with STDIO server entry point.
- Official `mcp/sdk` server bootstrap through explicit registration infrastructure.
- Mandatory PHPForge runtime dependency.
- Project/root detection and Composer-autoload source-root discovery.
- Project/package path containment, secret-file denial and output redaction primitives.
- Exact Composer lock/installed package inspection, dependency graph, install-root ownership and Foundation package diagnostics.
- Explicit `composer-runtime-api ^2.1` contract for `Composer\InstalledVersions` install-path fallback.
- Static installed-Foundation `ModuleCatalog` parsing with purpose-first package/config correlation and runtime-activation-safe module intelligence.
- `doctor` validation for installed Foundation `ModuleCatalog` readability.
- PHPForge-backed in-process PHP source analyzer for declarations, signatures, relationships, references and bounded literal-array intelligence without executing host source.
- File-local parse-error isolation and content-fingerprint analysis caching for unchanged PHP source.
- Lazy project/package symbol indexing with deterministic lookup, duplicate-symbol surfacing and per-file diagnostics.
- Incremental symbol-index refresh using source metadata states so unchanged files are not reparsed.
- Lazy project/package reference indexing with source-symbol attribution, relationship-aware exact-confidence upgrades and bounded usage queries.
- Incremental usage refresh and per-file reference diagnostics without reparsing unchanged source.
- Deterministic bounded symbol/path/text search across project, Foundation, explicit package and structural scopes with parser-free filesystem-only search paths.
- Safe redacted project/package resource reads with canonical path containment, binary/secret denial, line ranges and hard byte/line limits.
- Ranked related-test discovery for symbol/file targets using exact references, direct calls/construction, path/filename relationships and bounded lexical fallback across Composer test roots.
- Static Foundation/Webrick route, console-command, provider and configuration inspectors with installed-package contract derivation and dynamic-state preservation.
- Static Foundation schedule inspection with timing, identity, lock, timeout and memory policies derived from installed scheduling contracts.
- Distinct Foundation maintenance-worker and Omnibus messaging-worker inspection, including bounded/redacted `routes/workers.php` analysis and installed `WorkerOptions` schema derivation for `config/messaging.php`.
- Static Foundation runtime/bootstrap inspection with installed runtime-contract derivation, explicit web/CLI/worker/scheduler graph selection, preset awareness, semantic project-root handling and redacted inline options.
- Read-only Git workspace inspection through a fixed argument-vector command surface, including HEAD/branch/detached state, staged/unstaged/untracked/rename status, PHP declaration/reference deltas, structural areas and initial related-test impact.
- Composer dependency-change analysis against Git HEAD for direct constraints/scopes, locked versions/source references, transitive changes, affected Foundation modules and project references to changed package namespaces.
- Bounded deterministic impact analysis for symbols, files, packages, modules, routes, config and current workspace changes with exact/resolved/lexical/dynamic evidence kept distinct.
- Explicit read-only registration for all nine production `foundation_*` MCP tools over shared lazy domain services, with stable schemas, bounded outputs, closed-world annotations and focused cross-tool/SDK construction coverage.
- Explicit project summary, architecture, Composer, ModuleCatalog and standards resources plus safe project/package file and symbol resource templates, all reusing the shared bounded analysis/read services.
