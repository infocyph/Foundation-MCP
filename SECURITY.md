# Security Policy

Foundation MCP reads source code and dependency metadata for AI coding agents, so its primary security boundary is deliberately narrower than a general developer shell.

## Supported release

Security fixes target the current supported 1.x release line and the active development branch preparing that line.

## Security invariants

Foundation MCP is expected to preserve all of these invariants:

- STDIO-only MCP server; no HTTP listener.
- Zero normal-operation network requests.
- No host-application bootstrap for inspection.
- No execution of arbitrary host/package PHP source.
- No generic shell, PHP, Composer or Git command capability.
- No project/runtime/Composer/Git mutation.
- Git access limited to fixed read-only operations through an argument-vector subprocess boundary with shell bypass and optional locks disabled.
- Project reads restricted to the canonical host root.
- Dependency reads restricted to explicitly selected installed packages whose canonical roots came from the inspected project's Composer metadata.
- Traversal, symlink escape, device/socket/FIFO and binary-text reads rejected.
- Secret-bearing resources denied, including `.env`, private keys, credential files and similar patterns.
- Environment values are never loaded during config inspection.
- Suspicious literal secrets are redacted before MCP output.
- Tool/resource/search/graph/Git output is bounded server-side.
- Analysis failures remain file-local where safely possible rather than triggering fallback execution paths.

A change that weakens one of these properties is security-sensitive even if it does not immediately demonstrate data exfiltration or code execution.

## Threat model

Foundation MCP assumes the inspected working tree may be malformed or actively hostile. PHP files, route/config/bootstrap files, Composer metadata, Git filenames and resource paths must therefore be treated as untrusted input.

The server must remain useful when project bootstrap is broken, providers throw, source has parse errors, runtime services are unavailable, or dependency metadata is inconsistent. None of those conditions justify executing the host application or contacting a remote service.

The MCP client itself is outside Foundation MCP's trust boundary. The server enforces roots, secrets and output limits even if a client requests a prohibited path or oversized result.

## Reporting a vulnerability

Do not publish exploit details, secrets or unpatched vulnerabilities in a public issue.

Prefer GitHub's private vulnerability reporting / Security Advisory flow for this repository when available. Include:

- affected commit/version;
- minimal reproduction;
- security boundary crossed;
- expected versus actual behavior;
- platform/PHP version;
- whether the issue requires a malicious project, package metadata, Git filename or MCP request.

If private reporting is temporarily unavailable, contact the repository maintainers through a private channel and keep public discussion limited to non-sensitive coordination until a fix is available.

## Security validation

The release gate includes PHPForge security/static-analysis checks plus dedicated tests for traversal, secret files, binaries, oversized reads/results, shell-shaped Git paths, mutation/network/execution primitives and real MCP protocol boundaries.

The CI release-evidence job also verifies an Infbyte production-style `composer install --no-dev` contains neither Foundation MCP nor PHPForge.
