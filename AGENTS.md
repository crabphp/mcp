# Agents

The framework's [AGENTS.md](https://github.com/crabphp/framework/blob/main/AGENTS.md) holds for this package: its rules for code, comments, tests and documentation all apply. What follows is particular to MCP.

## MCP

- The package is a server only, built against MCP revision `2026-07-28`. The specification at modelcontextprotocol.io is the authority; link the section a behaviour follows.
- Tools, resources and skills are listed explicitly on a server; nothing is discovered by scanning.
- Input schemas come from the framework's validator, the same as routes; do not write a second schema reader.
- Clients of `2025-11-25`, `2025-06-18` and `2025-03-26` are served statelessly beside modern ones: a request without a protocol version in `_meta` is legacy (`Protocol\Versions::legacy()`), `initialize` is answered and forgotten, and no `Mcp-Session-Id` is ever given. Do not add sessions: everything the server answers is the same whoever asks it twice.
- A tool or resource is an action, prepared through `Contracts\Routing\Actions`, so its input is read and validated exactly as a route's. A class may be a route and a tool at once.
- The endpoint answers with the protocol's JSON-RPC errors, built with `Contracts\Http\Factory`, never `Contracts\Http\Errors`: an `ErrorRenderer` must not rewrite what the client parses as JSON-RPC. What middleware refuses stays the application's answer.
- An error the model can act on (arguments that do not fit, `ToolError`, an `HttpError` below 500) is the tool's result with `isError`; any other failure is logged and answered `-32603` with nothing of its message.
- An `outputSchema` is sent only where the return type says the JSON exactly: a class whose public properties are its promoted constructor parameters, all the way down. When in doubt, send none, since a client checks results against it.
- Everything a server lists is fixed when the application is defined, skills' bytes included, so that a manifest's digests are of what is served. There are no list-changed notifications until something can change while the process runs.
- `Mcp::server()` reaches the application through `App::current()`, as `Psx::page()` does: a static for declaring what is fixed before workers start, and nothing else.
