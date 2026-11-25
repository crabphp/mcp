# Crab MCP

[Model Context Protocol](https://modelcontextprotocol.io) servers for [Crab](https://github.com/crabphp/framework) applications: tools, resources and skills, over Streamable HTTP and stdio.

It is built against MCP revision [`2026-07-28`](https://modelcontextprotocol.io/specification/2026-07-28), the stateless one, and serves clients of `2025-11-25`, `2025-06-18` and `2025-03-26` from the same endpoint. Those clients begin with `initialize`, as the official MCP Inspector still does.

> **Status: unreleased.** Everything below is implemented and tested end to end over HTTP and stdio, and against the official MCP Inspector CLI (2.16, which speaks `2025-11-25`) on both transports. The API may change before a first release.

## Installation

Requires PHP 8.4 or later and `crabphp/framework` 0.7.5 or later.

```sh
composer require crabphp/mcp
```

List the plugin in `app.php`:

```php
use Crab\App;
use Crab\Mcp\McpPlugin;

return App::configure(__DIR__)
    ->withPlugins([McpPlugin::class])
    ->withRouting(routes: 'routes/web.php')
    ->create();
```

## Declaring a server

A server is declared in a routes file, and lists everything it offers. Nothing is found by scanning:

```php
use App\Actions\ListProducts;
use App\Actions\ProductResource;
use App\Actions\ShowProduct;
use Crab\Auth\TokenAuth;
use Crab\Mcp\Mcp;

Mcp::server('/mcp')
    ->tools([ShowProduct::class, ListProducts::class])
    ->resources([ProductResource::class])
    ->skills('resources/skills')
    ->middleware(TokenAuth::class)
    ->info('Shop', '1.4.0')
    ->instructions('Look a product up before changing it.');
```

| Step | What it says |
| --- | --- |
| `tools()` | Classes marked `#[Tool]`; called again, it adds to them |
| `resources()` | Classes marked `#[Resource]` |
| `skills()` | A directory of skill folders, relative to the application's folder |
| `middleware()` | Middleware around the endpoint, as around a route: authentication, `RateLimit` |
| `info()` | The name and version every result's `serverInfo` gives; `app.name` and `0.0.0` until said |
| `instructions()` | Guidance on the server as a whole, sent by `server/discover` |
| `origins()` | Browser origins allowed besides the application's own; `'*'` for any |
| `ttl()` | Milliseconds a client may keep discovery, lists and skills; `0`, the default, says ask each time |

The declaration is read when the application is defined, before anything serves. A class that is not marked, two tools with one name, a signature that cannot be honoured or a skill that cannot be served stops startup with a message naming it. `php cua describe` and `php cua mcp:list` show every server with its tools, resources and skills, and the endpoint appears in `route:list` as `POST /mcp`.

## Tools

A tool is an action marked `#[Tool]`. Its arguments are validated by the same validator as a route's body, before `handle()` runs:

```php
use Crab\Mcp\Tool;
use Crab\Mcp\ToolError;

#[Tool('show_product', 'Shows one product by its id.', readOnly: true)]
final readonly class ShowProduct
{
    public function __construct(private Products $products) {}

    public function handle(ProductId $input): Product
    {
        return $this->products->find($input->id) ?? throw new ToolError("There is no product {$input->id}.");
    }
}
```

- **Input.** `handle()` takes the arguments as one input class or a `#[Fields]` `Data`, exactly as an action takes a body, and its `inputSchema` is the validator's JSON Schema of it. A tool with no input takes `{}`. It may also take the user (a class implementing `Identity`) and the request. It cannot take query fields or files.
- **Output.** A return type that is a plain class (its constructor promotes every public property, recursively) becomes the tool's `outputSchema`. Whatever data the tool returns is sent as `structuredContent`, with its JSON as a text block for clients that read only content. Dates are ISO 8601 text and enums their values, as in a route's JSON.
- **Other returns.** A string is sent as text and `null` as no content. Content blocks (`Text`, `Image`, `Audio`, `ResourceLink`, `EmbeddedResource`), or a list of them, are sent as they are. `ToolResult` covers anything else: `ToolResult::structured($data, ...$content)` and `ToolResult::error('...')`.
- **Errors the model can act on.** Arguments that do not fit, a `ToolError`, and an exception the framework would answer with a 4xx (such as `HttpException(404, ...)`) are all sent as the tool's result with `isError: true` and a readable message.
- **Failures.** Anything else a tool throws is logged with its trace and answered with JSON-RPC `-32603 Internal error`, which says nothing of it.
- **Hints.** `title`, `readOnly`, `destructive`, `idempotent` and `openWorld` on `#[Tool]` become the tool's title and annotations. Clients may show or weigh them; they promise nothing.

A tool may be a route action too: the same class can be listed on a server and given to `Route::post()`.

### Progress

A `handle()` that is a generator yields `Progress` and returns its result:

```php
use Crab\Mcp\Progress;

public function handle(ImportFiles $input): \Generator
{
    foreach ($input->files as $done => $file) {
        yield new Progress($done + 1, count($input->files), "Imported {$file}");
    }
    return 'Imported ' . count($input->files) . ' files.';
}
```

When the client sends a `progressToken` and accepts `text/event-stream`, each `Progress` is sent as a `notifications/progress` event as soon as it is yielded, and the response ends the stream. Otherwise the tool runs to its end and is answered once. Progress must grow from one to the next. A client that closes the stream cancels the call: the generator is not resumed.

## Resources

A resource is an action marked `#[Resource]`, with a fixed URI or a template whose `{name}` parts are `handle()`'s parameters, read from the URI as a route's are from its path:

```php
use Crab\Mcp\Resource;

#[Resource('shop://products/{id}', 'product', 'One product, as JSON.', mimeType: 'application/json')]
final readonly class ProductResource
{
    public function __construct(private Products $products) {}

    public function handle(int $id): ?Product
    {
        return $this->products->find($id);
    }
}
```

- **Listing.** Fixed URIs are listed by `resources/list` and templates by `resources/templates/list`. A template is level 1 of RFC 6570: each `{name}` is one value holding no `/`, `?` or `#`.
- **Returns.** `handle()` returns the contents: a string as text, `Contents::text()` or `Contents::blob()` (raw bytes, sent in base64), a list of `Contents` for several parts, or any other data as JSON.
- **Not found.** `null`, a part that does not fit its type (`{id}` that is not an int) or a 4xx exception answers `-32602 Resource not found`.
- **Caching.** A read may be kept for `ttl:` milliseconds (0 by default) and is private to the caller unless the resource says `public: true`.

## Skills

`->skills('resources/skills')` serves a directory of [Agent Skills](https://agentskills.io/specification) by the [skills extension](https://modelcontextprotocol.io/extensions/skills/overview) (`io.modelcontextprotocol/skills`): `skills/list`, `skills/get`, `resources/read` and `resources/directory/read`.

```
resources/skills/
  code-review/
    SKILL.md
    references/checklist.md
```

- **Reading.** Every skill is read whole when the application is defined, and what is served is what was read then. Each manifest's SHA-256 digests and sizes are therefore of the very bytes `resources/read` sends. A change on disk is served by the next deployment.
- **Startup checks.** A skill folder needs a `SKILL.md` whose frontmatter has a `name` equal to the folder's (lowercase letters, digits and single hyphens) and a `description`. Startup refuses a skill that breaks this, a link, a skill over 512 files or 16 MiB, and anything in the directory that is not a skill folder. Hidden files are left out.
- **URIs.** Files are `skill://{name}/{path}`, and directories have no trailing slash. Text is sent as text and other files in base64.
- **Frontmatter.** It is read without a YAML library, and only the part of YAML frontmatter is written in: mappings, lists, plain and quoted scalars, and `|` and `>` blocks. Anchors, aliases, tags and `{flow}` mappings are refused, by file and line.

## Transports

**Streamable HTTP** is the one POST route at the server's path, behind the application's middleware and the server's own.

- **Replies.** A request is answered with one JSON object, or with an event stream for a tool reporting progress. A notification is answered `202`.
- **Headers.** `MCP-Protocol-Version`, `Mcp-Method` and `Mcp-Name` must be present and match the body, or the request is refused with `400` and `-32020 HeaderMismatch`. An unsupported version gets `400` and `-32022` with the supported list.
- **Other methods.** An unknown method gets `404`. `GET` and `DELETE` get `405`, and `Mcp-Session-Id` is ignored.
- **Origin.** A browser request whose `Origin` is not the application's own (`app.url`'s, or the request's when unset) or one given to `origins()` is refused with `403`.
- **Error format.** Protocol errors are the protocol's JSON-RPC errors, not the application's error format, since the client reads them as JSON-RPC. What middleware refuses (a `401`, a `429`) is the application's own answer.

**stdio** is `php cua mcp:serve`, for a client that starts the server as a process of its own:

```json
{
  "mcpServers": {
    "shop": { "command": "php", "args": ["/path/to/app/cua", "mcp:serve", "--path=/mcp"] }
  }
}
```

- **Messages.** They are read one to a line from standard input and answered on standard output, in turn, until the input closes. Nothing else is written to standard output, so keep the application's log off it.
- **Choosing a server.** `--path=` selects a server and is needed when several are declared.
- **Limits.** A line longer than `server.max_body` is refused and skipped.
- **Callers.** No middleware runs, so whoever starts the process is the caller, and a tool that takes a user is refused one. Requests are answered one at a time, so `notifications/cancelled` always arrives after the request it names has been answered.

## Protocol versions

A request is served in the era it is written in, with nothing kept between requests:

- **Modern (`2026-07-28`).** A request whose `_meta` names its protocol version, with the headers above. `server/discover` lists every version served.
- **Legacy (`2025-11-25`, `2025-06-18`, `2025-03-26`).** A request with no such `_meta`, whose `MCP-Protocol-Version` header names one of these, or that has none, which is `2025-03-26`.
  - `initialize` agrees on the version the client asks for, or the newest legacy one, and gives no session: each request after it stands alone, which those revisions allow. `ping` answers `{}`, and `notifications/initialized` `202`.
  - Tools, resources and skills are served as in the modern era, without `resultType`, `serverInfo` in `_meta` or cache hints. Skills keep the extension's own `ttlMs` and `cacheScope`.
  - Errors other than a malformed message are sent with `200`, as those revisions did. A version header that is not served is refused with `400` and the supported list.

## What is not here

- **Prompts and completions.** Their methods answer `-32601`.
- **`subscriptions/listen` and change notifications.** Lists are fixed for the life of the process, so they never change while it runs. `ttlMs` is how a client knows when to ask again.
- **Multi round-trip input** (`input_required`, elicitation), the tasks extension, and OAuth protected resource metadata.
- **Pagination.** Every list is given whole, so a `cursor` is invalid.
- **`x-mcp-header`.** It is never emitted, so no `Mcp-Param-*` header is needed.
- **Deprecated features** (sampling, roots, logging) are not planned.

## License

MIT
