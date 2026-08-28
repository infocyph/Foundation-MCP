# Foundation MCP

Foundation MCP is the local, read-only Model Context Protocol development-intelligence server for Infbyte applications and custom projects hosting Infocyph Foundation.

The implementation follows [`PROJECT_PLAN.md`](PROJECT_PLAN.md), which is the source of truth for scope and release readiness.

## Development status

Foundation MCP is under active development toward its first production release.

Current baseline:

- PHP 8.4+
- official `mcp/sdk ^0.8.0`
- mandatory `infocyph/phpforge: dev-main@dev`
- MCP over STDIO only
- explicit MCP capability registration only
- zero application bootstrap for inspection
- read-only project intelligence

## Install for development

```bash
composer install
```

## Run

```bash
vendor/bin/foundation-mcp
```

or explicitly:

```bash
vendor/bin/foundation-mcp serve
```

STDOUT is reserved for MCP protocol messages. Diagnostics are written to STDERR.

## Architecture

SDK/protocol-specific code lives under `src/Mcp/`. Project, Composer, Foundation, analysis, Git, security and resource intelligence will remain independent of MCP protocol concerns as the implementation progresses.

## Security contract

Foundation MCP is designed as a context server, not a control plane. It will not expose arbitrary shell execution, project mutation, Composer mutation, Git mutation, application bootstrap or normal-operation network access.

See [`PROJECT_PLAN.md`](PROJECT_PLAN.md) for the complete production contract.
