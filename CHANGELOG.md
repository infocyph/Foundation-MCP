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
