# Foundation MCP

Foundation MCP is the local, read-only Model Context Protocol development-intelligence server for Infbyte applications and custom projects hosting Infocyph Foundation.

It gives MCP-capable coding agents precise local context about the host project, the exact installed Foundation ecosystem, PHP symbols and usages, structural registration, Git changes, dependency changes, related tests and impact without bootstrapping the application or exposing a general-purpose execution surface.

`PROJECT_PLAN.md` is the implementation and release-readiness source of truth.

## Requirements

- PHP 8.4 or newer
- Composer 2
- Git when workspace/change intelligence is enabled
- `mcp/sdk ^0.8.0`
- `infocyph/phpforge: dev-main@dev`
- `composer-runtime-api ^2.1`

PHPForge is a mandatory runtime dependency **of this development tool** because it supplies the parser/static-analysis ecosystem Foundation MCP uses. It is not a production dependency of the Infbyte application.

## Installation

### Infbyte

Foundation MCP belongs in `require-dev`:

```json
{
  "require-dev": {
    "infocyph/foundation-mcp": "^1.0",
    "infocyph/phpforge": "dev-main@dev"
  }
}
```

Then install development dependencies normally:

```bash
composer install
```

A production installation remains clean:

```bash
composer install --no-dev
```

That installation must contain neither Foundation MCP nor PHPForge.

### Other Foundation hosts

```bash
composer require --dev infocyph/foundation-mcp:^1.0
```

Foundation MCP does not require `infocyph/foundation` itself. It inspects the version already installed by the host.

## Running the server

```bash
vendor/bin/foundation-mcp
```

or explicitly:

```bash
vendor/bin/foundation-mcp serve
```

Useful options:

```text
--root=<path>  Inspect exactly this host root.
--no-git       Disable Git inspection entirely.
--verbose      Add diagnostic class information to STDERR startup failures.
```

STDOUT is reserved exclusively for MCP JSON-RPC traffic. Diagnostics are written to STDERR.

Foundation MCP uses the official PHP MCP SDK over **STDIO only**. It does not expose an HTTP server.

### Client configuration

A generic MCP client can launch the server with `php` and the installed binary path:

```json
{
  "mcpServers": {
    "foundation": {
      "command": "php",
      "args": [
        "/absolute/project/vendor/bin/foundation-mcp",
        "serve",
        "--root=/absolute/project"
      ]
    }
  }
}
```

The SDK serves both the `2025-11-25` handshake protocol era and the modern `2026-07-28` request-envelope era.

## Tool surface

Foundation MCP exposes exactly nine tools:

| Tool | Purpose |
| --- | --- |
| `foundation_project` | Host, Foundation, Composer, source-root, module, analysis and lightweight Git summary. |
| `foundation_search` | Deterministic symbol/path/text search across approved project/Foundation/package scopes. |
| `foundation_read` | Bounded redacted line-range reads from approved project or explicitly selected package files. |
| `foundation_symbol` | Exact PHP declaration/signature/source/relationship information. |
| `foundation_usages` | Bounded references with exact/resolved/lexical/dynamic confidence. |
| `foundation_inspect` | Architecture, modules, routes, commands, providers, config, workers, schedules, runtime and autoload inspection. |
| `foundation_packages` | Composer dependency/package/module graph and exact installed/locked state. |
| `foundation_changes` | Compact read-only Git and Composer change intelligence. |
| `foundation_impact` | Bounded evidence graph for symbol/file/package/module/route/config/current changes. |

All tools are explicitly registered. Foundation MCP does not use filesystem-wide MCP capability discovery and exposes no generic shell, PHP, Composer or Git command tool.

## Resources

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

Resources and tools share the same domain services; resources do not maintain a second analysis implementation.

## Foundation semantics

Foundation MCP derives mutable ecosystem truth from the installed project instead of maintaining a copied registry.

It statically reads the installed Foundation `ModuleCatalog` for purpose-first modules, resolves exact package state through Composer metadata, derives routing/runtime/scheduling/provider contracts from installed package source, and keeps these important distinctions intact:

- package presence is **not** runtime activation;
- Foundation owns explicit `web`, `cli`, `worker` and `scheduler` runtime graphs;
- Foundation maintenance workers are separate from Omnibus messaging workers;
- project working-tree state has precedence over locked/installed metadata for development context;
- exact locked and installed package versions remain separate facts when they disagree.

## Safety model

Foundation MCP is a context server, not a control plane.

Normal operation:

- does not bootstrap the host application;
- does not require or execute arbitrary project/package source;
- does not instantiate providers, controllers, workers or user classes;
- performs no network requests;
- exposes no project, Composer, runtime or Git mutation;
- exposes no arbitrary shell command;
- restricts Git to a fixed read-only argument-vector boundary and disables optional Git locks;
- restricts reads to the resolved host root or explicitly selected Composer-authorized package roots;
- rejects traversal and symlink escape;
- denies `.env`, private keys, credentials and similar secret-bearing files;
- redacts suspicious literal secrets before MCP output;
- never loads `.env` values during configuration inspection.

See `SECURITY.md` for the threat model and reporting guidance.

## Output and performance budgets

Foundation MCP is intentionally bounded. Current hard ceilings include:

- 1 MiB global serialized MCP tool result;
- 512 KiB JSON resource payload;
- 1 MiB / 400-line safe file read;
- 100 search results and 240-byte excerpts;
- 2,500 discovered files per search target;
- 512 KiB per text-search file and 16 MiB aggregate scan per target;
- 500 usage results;
- 100 related-test results;
- 500 Git workspace files, 200 PHP deltas and 100 initial related tests;
- 4 MiB Git subprocess output;
- 200 impact results.

Analysis is lazy. Lightweight project summary work does not parse the entire project, path/text search stays parser-free, package source is indexed only when explicitly requested, and full ASTs are not retained after compact extraction.

The long-running server keeps compact in-memory services and automatically invalidates Composer-dependent state when `composer.json`, `composer.lock` or `vendor/composer/installed.json` changes. PHP source indexes perform incremental per-file refresh.

## Doctor

Run the read-only environment diagnostic:

```bash
vendor/bin/foundation-mcp doctor --root=/path/to/project
```

or without Git:

```bash
vendor/bin/foundation-mcp doctor --root=/path/to/project --no-git
```

Doctor verifies:

- PHP version;
- official MCP SDK installation;
- PHPForge installation;
- parser capability;
- host detection;
- Composer lock/install consistency;
- exact Foundation package state;
- installed ModuleCatalog readability;
- approved source roots;
- path/secret policy;
- Git availability when enabled;
- construction of the complete explicit MCP tool/resource surface.

A missing/incompatible mandatory parser is a failed environment, not a reduced tokenizer fallback mode.

## Development and release validation

Install dependencies:

```bash
composer install
```

Run the standard PHPForge quality path:

```bash
composer ic:doctor
composer ic:ci
```

Run the full release gate:

```bash
composer ic:release:guard
```

Run benchmarks:

```bash
composer ic:benchmark
```

The repository CI additionally validates:

- PHP 8.4 and 8.5;
- Linux and Windows;
- real STDIO MCP negotiation/list/call/read behavior using the official SDK client;
- current Infbyte/Foundation ecosystem compatibility;
- Infbyte `require-dev` integration;
- `composer install --no-dev` zero Foundation-MCP/PHPForge production footprint.

Ordinary tests are network-independent. The real ecosystem checkout is isolated to its dedicated CI job.

## Troubleshooting

### The project is reported as unsupported

Ensure `composer.json` directly requires `infocyph/foundation`, or pass the correct host root explicitly:

```bash
vendor/bin/foundation-mcp doctor --root=/path/to/project
```

### Composer lock/install mismatch

Foundation MCP intentionally reports locked and installed state separately. Bring the development checkout back into alignment with the project's normal Composer workflow before relying on exact package-impact information.

### Parser/PHPForge unavailable

Foundation MCP deliberately has no production tokenizer-only fallback. Confirm the development install includes PHPForge and its parser ecosystem:

```bash
composer install
composer ic:doctor
vendor/bin/foundation-mcp doctor --root=/path/to/project
```

### Git is unavailable or intentionally prohibited

Use `--no-git`. Search, read, symbol, package and structural analysis remain available; workspace/change features report Git as unavailable rather than executing an alternative shell path.

### A file cannot be read

Protected secrets, binaries, oversized files, traversal and paths outside approved roots are rejected by design. Use a narrower approved text resource rather than weakening the server policy.

## Production footprint

Foundation MCP and PHPForge are development tooling. They must remain under the consuming application's `require-dev` section and disappear completely from the runtime dependency tree under:

```bash
composer install --no-dev
```

Foundation MCP registers no Foundation provider, route, module, config, worker, schedule or bootstrap hook.

## License

MIT
