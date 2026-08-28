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
