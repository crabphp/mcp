# Changelog

Crab MCP follows [semantic versioning](https://semver.org). Before 1.0, a minor version may break what an application writes; the entry says what to change.

## 0.1.3 — 2026-11-26

- Discovery's search puts, among the tools whose names hold every word asked for, the one with the fewest other words first: "errors list" finds `errors_list` before `errors_keys_list`.

## 0.1.2 — 2026-11-26

- The endpoint's route says what it is in its doc comment, for whatever describes an application's routes, such as an API document.

## 0.1.1 — 2026-11-26

- A tool's `arguments`, and a request's `params`, written as a JSON list are refused even when empty (`[]`), which PHP's decoding alone would take for `{}`.
- Route tools are listed by name, so the list is the same however the plugins that declared their routes were ordered.

## 0.1.0 — 2026-11-26

- **First release,** for `crabphp/framework` ^0.7.5: Model Context Protocol servers declared with `Mcp::server()`, for revision `2026-07-28` and for clients of `2025-11-25`, `2025-06-18` and `2025-03-26` on the same endpoint, statelessly.
- Tools: `#[Tool]` actions, validated by the framework's validator, with `outputSchema` and `structuredContent` from typed returns, `ToolError`, content blocks, and progress streamed over SSE from a generator.
- Route tools: `fromRoutes()` serves the routes whose action is marked `#[Tool]`, called through the whole application so that what guards a route guards its tool.
- `ToolFilter` decides who is shown which tools, `ToolCalled` is dispatched after every call, `maxResult()` bounds a result, and `discovery()` lists a search and a call tool in place of every tool.
- Resources with RFC 6570 level 1 templates, and skills by the `io.modelcontextprotocol/skills` extension with SHA-256 manifests.
- Streamable HTTP with header and Origin validation, stdio with `php cua mcp:serve`, and `php cua mcp:list`.
