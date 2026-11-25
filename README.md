# Crab MCP

[Model Context Protocol](https://modelcontextprotocol.io) servers for [Crab](https://github.com/crabphp/framework) applications: tools, resources and skills, over Streamable HTTP and stdio.

> **Status: in design.** Nothing below is implemented yet. This README is the draft the package is being built from.

## Draft

A server is declared in the routes file, and lists what it offers:

```php
Mcp::server('/mcp')
    ->tools([ShowProduct::class, ListProducts::class])
    ->resources([ProductResource::class])
    ->skills('resources/skills')
    ->middleware(new TokenAuth());
```

- **Tools** are classes with `handle()`, an action included, marked with `#[Tool]`. The input schema comes from the framework's validator, as for a route; a typed return becomes `structuredContent` and an `outputSchema`. A generator streams progress over SSE.
- **Resources** are classes marked with `#[Resource('shop://products/{id}')]`.
- **Skills** ([`io.modelcontextprotocol/skills`](https://modelcontextprotocol.io/extensions/skills/overview)) are served from a directory of `SKILL.md` folders, with manifests of SHA-256 digests and sizes, `skills/list`, `skills/get`, `resources/read` and directory reads.
- **Protocol** `2026-07-28`: `server/discover`, per-request `_meta`, `resultType`, `ttlMs` and `cacheScope`. Streamable HTTP is one POST route behind the application's own middleware; `php cua mcp:serve` speaks stdio.
- **Later:** `subscriptions/listen`, multi round-trip input, tasks, OAuth metadata. Deprecated features (sampling, roots, logging) are not planned.

Open questions: support for `2025-11-25` clients (`initialize` and sessions), and whether a class may be both a route and a tool.

## License

MIT
