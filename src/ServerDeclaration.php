<?php

declare(strict_types=1);

namespace Crab\Mcp;

use Crab\Contracts\Http\Middleware;

/**
 * What an MCP server offers, said where it is declared, one step to a
 * line:
 *
 *     Mcp::server('/mcp')
 *         ->tools([ShowProduct::class, ListProducts::class])
 *         ->resources([ProductResource::class])
 *         ->skills('resources/skills')
 *         ->middleware(new TokenAuth());
 *
 * Nothing is found by itself: every tool and resource is listed here.
 * The declaration is read when the application is defined, before
 * anything serves, and a class that cannot be served stops it there.
 */
final class ServerDeclaration
{
    /**
     * @var list<class-string>
     */
    public private(set) array $tools = [];

    /**
     * @var list<class-string>
     */
    public private(set) array $resources = [];

    public private(set) ?string $skills = null;

    /**
     * @var list<Middleware|class-string<Middleware>>
     */
    public private(set) array $middleware = [];

    public private(set) ?string $name = null;

    public private(set) string $version = '0.0.0';

    public private(set) ?string $instructions = null;

    /**
     * @var list<string>
     */
    public private(set) array $origins = [];

    public private(set) int $ttl = 0;

    public private(set) bool $fromRoutes = false;

    /**
     * @var ToolFilter|class-string<ToolFilter>|null
     */
    public private(set) ToolFilter|string|null $filter = null;

    public private(set) ?int $maxResult = null;

    /**
     * @var array{search: string, call: string, always: list<string>, groups: array<string, string>}|null
     */
    public private(set) ?array $discovery = null;

    private bool $sealed = false;

    /**
     * @param string $path The MCP endpoint's path, such as "/mcp".
     */
    public function __construct(
        public readonly string $path,
    ) {}

    /**
     * Tools the server lists, each a class with handle() marked #[Tool].
     * Called again, it adds to them.
     *
     * @param class-string|list<class-string> $tools
     */
    public function tools(string|array $tools): static
    {
        $this->open();
        array_push($this->tools, ...(array) $tools);
        return $this;
    }

    /**
     * Resources the server reads, each a class with handle() marked
     * #[Resource]. Called again, it adds to them.
     *
     * @param class-string|list<class-string> $resources
     */
    public function resources(string|array $resources): static
    {
        $this->open();
        array_push($this->resources, ...(array) $resources);
        return $this;
    }

    /**
     * Serves the skills in a directory, one folder each, by the skills
     * extension: a relative path is inside the application's folder.
     *
     * @see https://modelcontextprotocol.io/extensions/skills/overview
     */
    public function skills(string $directory): static
    {
        $this->open();
        $this->skills = $directory;
        return $this;
    }

    /**
     * Middleware around the endpoint, as around a route: authentication,
     * a rate limit. Called again, it adds inside what is there.
     *
     * @param Middleware|class-string<Middleware> ...$middleware
     */
    public function middleware(Middleware|string ...$middleware): static
    {
        $this->open();
        array_push($this->middleware, ...array_values($middleware));
        return $this;
    }

    /**
     * Who the server says it is, in every result's serverInfo: the
     * application's name ("app.name") and "0.0.0" until said.
     */
    public function info(string $name, string $version): static
    {
        $this->open();
        $this->name = $name;
        $this->version = $version;
        return $this;
    }

    /**
     * Guidance for the model on using the server as a whole, sent by
     * server/discover. What a tool's description says need not be said
     * again here.
     */
    public function instructions(string $instructions): static
    {
        $this->open();
        $this->instructions = $instructions;
        return $this;
    }

    /**
     * Origins a browser may call the server from, besides the
     * application's own: "https://app.example.com", or "*" for any. A
     * request from any other origin is refused with 403.
     */
    public function origins(string ...$origins): static
    {
        $this->open();
        array_push($this->origins, ...array_values($origins));
        return $this;
    }

    /**
     * How many milliseconds a client may keep what server/discover, the
     * lists and the skills say before asking again; 0, the default, says
     * it should ask each time. They are the same for every caller and may
     * be kept by a shared cache.
     *
     * @see https://modelcontextprotocol.io/specification/2026-07-28/server/utilities/caching
     */
    public function ttl(int $milliseconds): static
    {
        $this->open();
        if ($milliseconds < 0) {
            throw new \InvalidArgumentException(
                "The MCP server {$this->path} is given a ttl of {$milliseconds} ms; it is 0 or more.",
            );
        }
        $this->ttl = $milliseconds;
        return $this;
    }

    /**
     * Takes as tools the application's routes whose action is marked
     * #[Tool], on the method or, for a class with handle(), on the class.
     * A call is a request to the route through the whole application,
     * every middleware included, made with the caller's headers, so that
     * what guards the route guards the tool. Its arguments are the route's
     * path parameters, query fields and body fields side by side.
     */
    public function fromRoutes(): static
    {
        $this->open();
        $this->fromRoutes = true;
        return $this;
    }

    /**
     * Decides which tools a request is shown; see ToolFilter. A class name
     * is made by the container in the worker.
     *
     * @param ToolFilter|class-string<ToolFilter> $filter
     */
    public function filter(ToolFilter|string $filter): static
    {
        $this->open();
        $this->filter = $filter;
        return $this;
    }

    /**
     * The most bytes a tool's result may be, as sent: a larger one is
     * answered with an error saying which arguments ask for less, rather
     * than filling the model's context.
     */
    public function maxResult(int $bytes): static
    {
        $this->open();
        if ($bytes < 1) {
            throw new \InvalidArgumentException(
                "The MCP server {$this->path} is given a result limit of {$bytes} bytes; it is 1 or more.",
            );
        }
        $this->maxResult = $bytes;
        return $this;
    }

    /**
     * Lists a few tools and two of discovery's own instead of every tool:
     * one that searches the tools by words or by group, and one that calls
     * a tool found. A model then reads a tool's description when it needs
     * the tool. "?tools=all" on the endpoint lists every tool, and
     * "?tools=a,b" those groups. Every tool can still be called by name.
     *
     * A tool's group is the first word of its name, before "_"; $groups
     * names another group for a word ("error" => "errors").
     *
     * @param list<string> $always Tools always listed, such as the one that lists what the others take ids from.
     * @param array<string, string> $groups
     */
    public function discovery(
        string $search = 'tools_search',
        string $call = 'tools_call',
        array $always = [],
        array $groups = [],
    ): static {
        $this->open();
        $this->discovery = [
            'search' => $search,
            'call' => $call,
            'always' => array_values($always),
            'groups' => $groups,
        ];
        return $this;
    }

    /**
     * Closes the declaration once it has been read.
     *
     * @internal
     */
    public function seal(): void
    {
        $this->sealed = true;
    }

    private function open(): void
    {
        if ($this->sealed) {
            throw new \LogicException(
                "The MCP server {$this->path} has been read already; declare all of it before the application starts, in a routes file.",
            );
        }
    }
}
