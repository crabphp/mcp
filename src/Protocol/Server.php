<?php

declare(strict_types=1);

namespace Crab\Mcp\Protocol;

use Crab\Contracts\Http\Request;
use Crab\Mcp\McpPlugin;
use Crab\Mcp\Progress;
use Crab\Mcp\Skills\SkillLibrary;
use Crab\Mcp\ToolCalled;
use Crab\Mcp\ToolInfo;
use Crab\Mcp\ToolResult;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * One MCP server, as finished from its declaration: what it offers, and
 * the answer to each request, the same over every transport. It keeps
 * nothing between requests: each carries its protocol version and the
 * client's capabilities, and is answered on its own.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/index#statelessness
 * @internal
 */
final readonly class Server
{
    private const string META = 'io.modelcontextprotocol/';

    /**
     * The argument names that most often make a tool give less, for the
     * message that refuses a result too large.
     */
    private const array NARROWING = [
        'per_page',
        'limit',
        'cursor',
        'page',
        'from',
        'to',
        'before',
        'after',
        'section',
        'fields',
    ];

    /**
     * @param array<string, ToolHandle> $tools By name, in the order declared.
     * @param list<PreparedResource> $resources In the order declared, which is the order a URI is matched in.
     * @param \Closure(): ?LoggerInterface $logger Where a failure is reported, found when there is one.
     * @param \Closure(ToolInfo, Request): bool|null $filter Whether a request may see a tool; every tool when null.
     * @param \Closure(): ?EventDispatcherInterface $events Where ToolCalled is dispatched, found when there is one.
     * @param int|null $maxResult The most bytes a tool's result may be; no limit when null.
     */
    public function __construct(
        public string $path,
        public string $name,
        public string $version,
        public ?string $instructions,
        public int $ttl,
        public array $tools,
        public array $resources,
        public ?SkillLibrary $skills,
        private \Closure $logger,
        private ?\Closure $filter = null,
        private ?\Closure $events = null,
        public ?int $maxResult = null,
        public ?Discovery $discovery = null,
    ) {}

    /**
     * The answer to a message: a JSON-RPC response, nothing for a
     * notification, or for a tool that reports its progress while the
     * client listens, a generator of notifications that returns the
     * response. A request of a legacy revision is answered as that
     * revision would, without what only the modern one has.
     *
     * @param bool $stream Whether the transport can send notifications before the response.
     * @param string|null $headerVersion The MCP-Protocol-Version header, which a modern request's _meta must name too; null on stdio.
     * @return array<string, mixed>|\Generator<int, array<string, mixed>, mixed, array<string, mixed>>|null
     */
    public function handle(
        Message $message,
        Request $request,
        bool $stream = true,
        ?string $headerVersion = null,
    ): array|\Generator|null {
        if ($message->isNotification()) {
            // notifications/initialized and notifications/cancelled: nothing is kept to change, and nothing runs beside it to cancel.
            return null;
        }
        $legacy = Versions::legacy($message, $headerVersion) !== null;
        try {
            $result = $legacy
                ? $this->dispatchLegacy($message, $request, $stream)
                : $this->dispatch($message, $request, $stream, $headerVersion);
        } catch (McpError $error) {
            return self::error($message->id, $error);
        } catch (\Throwable $error) {
            $this->report($error, $message);
            return self::error($message->id, McpError::internal());
        }
        if ($result instanceof \Generator) {
            return $this->streamed($message, $result, $legacy);
        }
        return $this->response($message->id, $result, $legacy);
    }

    /**
     * A JSON-RPC error response.
     *
     * @return array<string, mixed>
     */
    public static function error(int|string|null $id, McpError $error): array
    {
        $response = ['jsonrpc' => '2.0'];
        if ($id !== null) {
            $response['id'] = $id;
        }
        $response['error'] = $error->error();
        return $response;
    }

    /**
     * Checks a modern request's _meta.
     *
     * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning#protocol-version-negotiation
     */
    private function version(Message $message, ?string $headerVersion): void
    {
        $meta = $message->meta();
        $version = $meta[self::META . 'protocolVersion'] ?? null;
        if (!is_string($version)) {
            if ($headerVersion !== null) {
                throw McpError::headerMismatch(
                    "The MCP-Protocol-Version header is {$headerVersion}, and params._meta names no \"io.modelcontextprotocol/protocolVersion\".",
                );
            }
            throw McpError::invalidParams(
                'A request names its protocol version in params._meta["io.modelcontextprotocol/protocolVersion"].',
            );
        }
        if ($headerVersion !== null && $version !== $headerVersion) {
            throw McpError::headerMismatch(
                "The MCP-Protocol-Version header is {$headerVersion} and params._meta names {$version}; they are the same.",
            );
        }
        if ($version !== McpPlugin::PROTOCOL_VERSION) {
            throw McpError::unsupportedVersion($version);
        }
        if (!Json::isObject($meta[self::META . 'clientCapabilities'] ?? null)) {
            throw McpError::invalidParams(
                'A request declares what the client can do in params._meta["io.modelcontextprotocol/clientCapabilities"], {} when nothing.',
            );
        }
    }

    /**
     * @return array<string, mixed>|\Generator<int, array<string, mixed>, mixed, array<string, mixed>>
     */
    private function dispatch(
        Message $message,
        Request $request,
        bool $stream,
        ?string $headerVersion,
    ): array|\Generator {
        $this->version($message, $headerVersion);
        $tools = $this->tools !== [];
        $resources = $this->resources !== [] || $this->skills !== null;
        $skills = $this->skills !== null;
        return match (true) {
            $message->method === 'server/discover' => $this->discover(),
            $message->method === 'tools/list' && $tools => $this->listTools($message, $request),
            $message->method === 'tools/call' && $tools => $this->callTool($message, $request, $stream),
            $message->method === 'resources/list' && $resources => $this->listResources($message),
            $message->method === 'resources/templates/list' && $resources => $this->listTemplates($message),
            $message->method === 'resources/read' && $resources => $this->readResource($message, $request),
            $message->method === 'resources/directory/read' && $skills => $this->readDirectory($message),
            $message->method === 'skills/list' && $skills => $this->listSkills($message),
            $message->method === 'skills/get' && $skills => $this->getSkill($message),
            default => throw McpError::methodNotFound($message->method),
        };
    }

    /**
     * A request of 2025-11-25, 2025-06-18 or 2025-03-26: initialize and
     * ping, and the tools, resources and skills as the modern revision has
     * them; clients of 2025-11-25 already declare the skills extension.
     *
     * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/lifecycle
     * @return array<string, mixed>|\Generator<int, array<string, mixed>, mixed, array<string, mixed>>
     */
    private function dispatchLegacy(Message $message, Request $request, bool $stream): array|\Generator
    {
        $tools = $this->tools !== [];
        $resources = $this->resources !== [] || $this->skills !== null;
        $skills = $this->skills !== null;
        return match (true) {
            $message->method === 'initialize' => $this->initialize($message),
            $message->method === 'ping' => [],
            $message->method === 'tools/list' && $tools => $this->listTools($message, $request),
            $message->method === 'tools/call' && $tools => $this->callTool($message, $request, $stream),
            $message->method === 'resources/list' && $resources => $this->listResources($message),
            $message->method === 'resources/templates/list' && $resources => $this->listTemplates($message),
            $message->method === 'resources/read' && $resources => $this->readResource($message, $request),
            $message->method === 'resources/directory/read' && $skills => $this->readDirectory($message),
            $message->method === 'skills/list' && $skills => $this->listSkills($message),
            $message->method === 'skills/get' && $skills => $this->getSkill($message),
            default => throw McpError::methodNotFound($message->method),
        };
    }

    /**
     * The legacy handshake, answered and forgotten: the version the client
     * asked for when it is served, otherwise the newest legacy one, which
     * the client may refuse. No session is given.
     *
     * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/lifecycle#initialization
     * @return array<string, mixed>
     */
    private function initialize(Message $message): array
    {
        $requested = $message->params['protocolVersion'] ?? null;
        $client = $message->params['clientInfo'] ?? null;
        if (
            !is_string($requested)
            || !Json::isObject($message->params['capabilities'] ?? null)
            || !is_array($client)
            || !is_string($client['name'] ?? null)
            || !is_string($client['version'] ?? null)
        ) {
            throw McpError::invalidParams(
                'initialize takes protocolVersion, capabilities, and clientInfo with its name and version.',
            );
        }
        $result = [
            'protocolVersion' => in_array($requested, Versions::LEGACY, true) ? $requested : Versions::LEGACY[0],
            'capabilities' => $this->capabilities(),
            'serverInfo' => ['name' => $this->name, 'version' => $this->version],
        ];
        if ($this->instructions !== null) {
            $result['instructions'] = $this->instructions;
        }
        return $result;
    }

    /**
     * What the server offers, as discovery or initialize says it.
     */
    private function capabilities(): array|\stdClass
    {
        $capabilities = [];
        if ($this->tools !== []) {
            $capabilities['tools'] = new \stdClass();
        }
        if ($this->resources !== [] || $this->skills !== null) {
            $capabilities['resources'] = new \stdClass();
        }
        if ($this->skills !== null) {
            $capabilities['extensions'] = ['io.modelcontextprotocol/skills' => ['directoryRead' => true]];
        }
        return $capabilities === [] ? new \stdClass() : $capabilities;
    }

    /**
     * @see https://modelcontextprotocol.io/specification/2026-07-28/server/discover
     * @return array<string, mixed>
     */
    private function discover(): array
    {
        $result = ['supportedVersions' => Versions::all(), 'capabilities' => $this->capabilities()];
        if ($this->instructions !== null) {
            $result['instructions'] = $this->instructions;
        }
        return $this->cacheable($result);
    }

    /**
     * The tools the caller may see; with discovery, those it asked for by
     * "?tools=", or the few it always lists and its own two.
     *
     * @see https://modelcontextprotocol.io/specification/2026-07-28/server/tools#listing-tools
     * @return array<string, mixed>
     */
    private function listTools(Message $message, Request $request): array
    {
        self::firstPage($message);
        $tools = $this->visible($request);
        $listed = null;
        $discovery = $this->discovery;
        if ($discovery !== null) {
            $asked = $request->getQueryParams()['tools'] ?? '';
            $groups = is_string($asked)
                ? array_values(array_filter(array_map(trim(...), explode(',', strtolower($asked)))))
                : [];
            if (!in_array('all', $groups, true)) {
                $listed = [
                    ...array_map(
                        static fn(ToolHandle $tool): array => $tool->definition(),
                        array_values(array_filter(
                            $tools,
                            static fn(ToolHandle $tool): bool => (
                                in_array($tool->info()->name, $discovery->always, true)
                                || in_array($discovery->group($tool->info()->name), $groups, true)
                            ),
                        )),
                    ),
                    ...$discovery->definitions($discovery->groups($tools)),
                ];
            }
        }
        $listed ??= array_map(static fn(ToolHandle $tool): array => $tool->definition(), $tools);
        return [
            'tools' => $listed,
            'ttlMs' => $this->ttl,
            // What a filter shows depends on who asks.
            'cacheScope' => $this->filter === null ? 'public' : 'private',
        ];
    }

    /**
     * @return list<ToolHandle>
     */
    private function visible(Request $request): array
    {
        $filter = $this->filter;
        if ($filter === null) {
            return array_values($this->tools);
        }
        return array_values(array_filter($this->tools, static fn(ToolHandle $tool): bool => $filter(
            $tool->info(),
            $request,
        )));
    }

    /**
     * @see https://modelcontextprotocol.io/specification/2026-07-28/server/tools#calling-tools
     * @return array<string, mixed>|\Generator<int, array<string, mixed>, mixed, array<string, mixed>>
     */
    private function callTool(Message $message, Request $request, bool $stream): array|\Generator
    {
        $name = $message->params['name'] ?? null;
        if (!is_string($name)) {
            throw McpError::invalidParams('tools/call names its tool in params.name.');
        }
        $arguments = $message->params['arguments'] ?? [];
        if (!Json::isObject($arguments)) {
            throw McpError::invalidParams('A tool\'s arguments are an object.');
        }
        $token = $message->meta()['progressToken'] ?? null;
        if ($token !== null && !is_string($token) && !is_int($token)) {
            throw McpError::invalidParams('A progress token is a string or an integer.');
        }
        $discovery = $this->discovery;
        if ($discovery !== null && $name === $discovery->search) {
            return $discovery->search($arguments, $this->visible($request));
        }
        $wrapped = $discovery !== null && $name === $discovery->call;
        if ($wrapped) {
            [$name, $arguments] = $discovery->unwrap($arguments);
        }
        // A tool the filter hides is still called: what answers it says why it refuses, as it would to a request.
        $tool = $this->tools[$name] ?? null;
        if ($tool === null) {
            if ($wrapped) {
                return ToolResult::error("Unknown tool: {$name}. Find tools with {$discovery?->search}.")->toArray();
            }
            throw McpError::invalidParams("Unknown tool: {$name}.");
        }
        try {
            $call = $tool->call($arguments, $request);
            if (!$call instanceof \Generator) {
                return $this->called($tool, $arguments, $request, $call);
            }
            if (!$stream || $token === null) {
                // Nobody is listening for progress: run the tool to its end and answer once.
                foreach ($call as $_) {
                }
                return $this->called($tool, $arguments, $request, $call->getReturn());
            }
        } catch (\Throwable $error) {
            $this->failed($tool, $arguments, $request, $error, $message);
        }
        return $this->progress($call, $token, $tool, $arguments, $request, $message);
    }

    /**
     * The result as sent, once it is known to fit: a result over the
     * server's limit is refused, with what may make it smaller. Listeners
     * are told of the call.
     *
     * @param array<mixed> $arguments
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function called(ToolHandle $tool, array $arguments, Request $request, array $result): array
    {
        if ($this->maxResult !== null) {
            $size = strlen(Json::encode($result));
            if ($size > $this->maxResult) {
                $takes = array_keys((array) ($tool->definition()['inputSchema']['properties'] ?? []));
                $narrowing = array_values(array_intersect(self::NARROWING, $takes));
                $result = ToolResult::error(
                    "The result is {$size} bytes, more than the {$this->maxResult} this server sends. Ask for less"
                    . ($narrowing === [] ? '.' : ', with ' . implode(', ', $narrowing) . '.'),
                )->toArray();
            }
        }
        $failed = ($result['isError'] ?? false) === true;
        $message = $failed ? $result['content'][0]['text'] ?? null : null;
        $this->dispatchCalled(
            new ToolCalled($tool->info(), $arguments, $request, $failed, is_string($message) ? $message : null),
        );
        return $result;
    }

    /**
     * A tool that failed: reported, unless the application reported it
     * already, told to listeners, and answered with an internal error.
     *
     * @param array<mixed> $arguments
     */
    private function failed(
        ToolHandle $tool,
        array $arguments,
        Request $request,
        \Throwable $error,
        Message $message,
    ): never {
        if (!$error instanceof RouteToolFailed) {
            $this->report($error, $message);
        }
        $this->dispatchCalled(new ToolCalled($tool->info(), $arguments, $request, true, 'Internal error.'));
        throw McpError::internal();
    }

    private function dispatchCalled(ToolCalled $event): void
    {
        $events = $this->events === null ? null : ($this->events)();
        if ($events === null) {
            return;
        }
        try {
            $events->dispatch($event);
        } catch (\Throwable $error) {
            // A listener's failure is logged and changes nothing of the answer.
            $this->report($error, "tools/call {$event->tool->name}, in a ToolCalled listener");
        }
    }

    /**
     * @param \Generator<int, Progress, mixed, array<string, mixed>> $call
     * @param array<mixed> $arguments
     * @return \Generator<int, array<string, mixed>, mixed, array<string, mixed>>
     */
    private function progress(
        \Generator $call,
        int|string $progressId,
        ToolHandle $tool,
        array $arguments,
        Request $request,
        Message $message,
    ): \Generator {
        try {
            foreach ($call as $progress) {
                $params = ['progressToken' => $progressId, 'progress' => $progress->progress];
                if ($progress->total !== null) {
                    $params['total'] = $progress->total;
                }
                if ($progress->message !== null) {
                    $params['message'] = $progress->message;
                }
                yield ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => $params];
            }
            $result = $call->getReturn();
        } catch (\Throwable $error) {
            $this->failed($tool, $arguments, $request, $error, $message);
        }
        return $this->called($tool, $arguments, $request, $result);
    }

    /**
     * @see https://modelcontextprotocol.io/specification/2026-07-28/server/resources#listing-resources
     * @return array<string, mixed>
     */
    private function listResources(Message $message): array
    {
        self::firstPage($message);
        $listed = [];
        foreach ($this->resources as $resource) {
            if ($resource->uri->isTemplate()) {
                continue;
            }

            $listed[] = $resource->definition();
        }
        return $this->cacheable(['resources' => $listed]);
    }

    /**
     * @see https://modelcontextprotocol.io/specification/2026-07-28/server/resources#resource-templates
     * @return array<string, mixed>
     */
    private function listTemplates(Message $message): array
    {
        self::firstPage($message);
        $listed = [];
        foreach ($this->resources as $resource) {
            if (!$resource->uri->isTemplate()) {
                continue;
            }

            $listed[] = $resource->definition();
        }
        return $this->cacheable(['resourceTemplates' => $listed]);
    }

    /**
     * A skill's file, a fixed resource, or the first template that matches.
     *
     * @see https://modelcontextprotocol.io/specification/2026-07-28/server/resources#reading-resources
     * @return array<string, mixed>
     */
    private function readResource(Message $message, Request $request): array
    {
        $uri = self::uri($message);
        $file = $this->skills?->file($uri);
        if ($file !== null) {
            return $this->cacheable(['contents' => [$file->contents()]]);
        }
        foreach ($this->resources as $resource) {
            $values = $resource->uri->match($uri);
            if ($values !== null) {
                return $resource->read($uri, $values, $request);
            }
        }
        throw McpError::invalidParams("Resource not found: {$uri}.");
    }

    /**
     * @see https://modelcontextprotocol.io/extensions/skills/overview#reading-directories
     * @return array<string, mixed>
     */
    private function readDirectory(Message $message): array
    {
        self::firstPage($message);
        $uri = self::uri($message);
        $children = $this->skills?->directory($uri) ?? throw McpError::invalidParams("Not a directory: {$uri}.");
        return $this->cacheable(['resources' => $children]);
    }

    /**
     * @see https://modelcontextprotocol.io/extensions/skills/overview#listing-skills
     * @return array<string, mixed>
     */
    private function listSkills(Message $message): array
    {
        self::firstPage($message);
        $skills = $this->skills->skills ?? [];
        return $this->cacheable(['skills' => array_values(array_map(
            static fn($skill): array => $skill->entry(),
            $skills,
        ))]);
    }

    /**
     * @see https://modelcontextprotocol.io/extensions/skills/overview#getting-a-skill
     * @return array<string, mixed>
     */
    private function getSkill(Message $message): array
    {
        $skill = $this->skills?->get(self::uri($message)) ?? throw McpError::internal();
        return $this->cacheable(['skill' => $skill->entry()]);
    }

    /**
     * Lists are given whole, so the only page is the first: a cursor is
     * one this server never gave.
     *
     * @see https://modelcontextprotocol.io/specification/2026-07-28/server/utilities/pagination#error-handling
     */
    private static function firstPage(Message $message): void
    {
        if (array_key_exists('cursor', $message->params)) {
            throw McpError::invalidParams('This server gives every list whole; ask again without a cursor.');
        }
    }

    private static function uri(Message $message): string
    {
        $uri = $message->params['uri'] ?? null;
        if (!is_string($uri) || $uri === '') {
            throw McpError::invalidParams("{$message->method} names what it reads in params.uri.");
        }
        return $uri;
    }

    /**
     * What every server answers alike, it may be kept for the server's
     * ttl and shared, since it is the same for every caller.
     *
     * @see https://modelcontextprotocol.io/specification/2026-07-28/server/utilities/caching
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function cacheable(array $result): array
    {
        return [...$result, 'ttlMs' => $this->ttl, 'cacheScope' => 'public'];
    }

    /**
     * @param \Generator<int, array<string, mixed>, mixed, array<string, mixed>> $result
     * @return \Generator<int, array<string, mixed>, mixed, array<string, mixed>>
     */
    private function streamed(Message $message, \Generator $result, bool $legacy): \Generator
    {
        try {
            yield from $result;
            return $this->response($message->id, $result->getReturn(), $legacy);
        } catch (McpError $error) {
            return self::error($message->id, $error);
        } catch (\Throwable $error) {
            $this->report($error, $message);
            return self::error($message->id, McpError::internal());
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function response(int|string|null $id, array $result, bool $legacy): array
    {
        if ($legacy) {
            // Cache hints, resultType and serverInfo in _meta came with 2026-07-28; the skills extension keeps its own.
            if (!isset($result['skills']) && !isset($result['skill'])) {
                unset($result['ttlMs'], $result['cacheScope']);
            }
            // Before 2026-07-28 structured content was an object: a list or a value is held as its "result".
            if (array_key_exists('structuredContent', $result) && !self::isObject($result['structuredContent'])) {
                $result['structuredContent'] = ['result' => $result['structuredContent']];
            }
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? new \stdClass() : $result];
        }
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'resultType' => 'complete',
                ...$result,
                '_meta' => [self::META . 'serverInfo' => ['name' => $this->name, 'version' => $this->version]],
            ],
        ];
    }

    /**
     * What a message asked for, for a report: its method and the tool or
     * resource it names.
     */
    private static function what(Message $message): string
    {
        $name = $message->params['name'] ?? $message->params['uri'] ?? null;
        return is_string($name) ? "{$message->method} {$name}" : $message->method;
    }

    private function report(\Throwable $error, Message|string $message): void
    {
        $what = is_string($message) ? $message : self::what($message);
        $logger = ($this->logger)();
        if ($logger === null) {
            // A failure is never dropped; with no logger it goes to the process's error output.
            file_put_contents('php://stderr', "The MCP server {$this->path} failed to answer {$what}: {$error}\n");
            return;
        }
        $logger->error("The MCP server {$this->path} failed to answer {$what}.", ['exception' => $error]);
    }

    private static function isObject(mixed $value): bool
    {
        return $value instanceof \stdClass || is_array($value) && $value !== [] && !array_is_list($value);
    }
}
