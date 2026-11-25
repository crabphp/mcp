<?php

declare(strict_types=1);

namespace Crab\Mcp\Protocol;

use Crab\Contracts\Http\Request;
use Crab\Mcp\McpPlugin;
use Crab\Mcp\Progress;
use Crab\Mcp\Skills\SkillLibrary;
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
     * @param array<string, PreparedTool> $tools By name, in the order declared.
     * @param list<PreparedResource> $resources In the order declared, which is the order a URI is matched in.
     * @param \Closure(): ?LoggerInterface $logger Where a failure is reported, found when there is one.
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
    ) {}

    /**
     * The answer to a message: a JSON-RPC response, nothing for a
     * notification, or for a tool that reports its progress while the
     * client listens, a generator of notifications that returns the
     * response.
     *
     * @param bool $stream Whether the transport can send notifications before the response.
     * @param string|null $headerVersion The protocol version the transport's headers name, which the request must name too.
     * @return array<string, mixed>|\Generator<int, array<string, mixed>, mixed, array<string, mixed>>|null
     */
    public function handle(
        Message $message,
        Request $request,
        bool $stream = true,
        ?string $headerVersion = null,
    ): array|\Generator|null {
        if ($message->isNotification()) {
            // notifications/cancelled is the only one, and nothing here runs alongside it to cancel.
            return null;
        }
        try {
            $result = $this->dispatch($message, $request, $stream, $headerVersion);
        } catch (McpError $error) {
            return self::error($message->id, $error);
        } catch (\Throwable $error) {
            $this->report($error, $message);
            return self::error($message->id, McpError::internal());
        }
        if ($result instanceof \Generator) {
            return $this->streamed($message, $result);
        }
        return $this->response($message->id, $result);
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
     * Checks the request's _meta and gives the protocol version it names.
     *
     * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning#protocol-version-negotiation
     */
    private function version(Message $message, ?string $headerVersion): string
    {
        if ($message->method === 'initialize') {
            // A client of 2025-11-25 or before: there is no handshake to give it, only the versions this server speaks.
            $requested = $message->params['protocolVersion'] ?? '';
            throw McpError::unsupportedVersion(is_string($requested) ? $requested : '');
        }
        $meta = $message->meta();
        $version = $meta[self::META . 'protocolVersion'] ?? null;
        if (!is_string($version)) {
            if ($headerVersion !== null) {
                throw McpError::headerMismatch(
                    "The MCP-Protocol-Version header is {$headerVersion}, and params._meta names no \"io.modelcontextprotocol/protocolVersion\".",
                );
            }
            throw McpError::invalidParams(
                'A request names its protocol version in params._meta["io.modelcontextprotocol/protocolVersion"]; this server speaks '
                . McpPlugin::PROTOCOL_VERSION
                . '.',
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
        return $version;
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
            $message->method === 'tools/list' && $tools => $this->listTools($message),
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
     * @see https://modelcontextprotocol.io/specification/2026-07-28/server/discover
     * @return array<string, mixed>
     */
    private function discover(): array
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
        $result = [
            'supportedVersions' => [McpPlugin::PROTOCOL_VERSION],
            'capabilities' => $capabilities === [] ? new \stdClass() : $capabilities,
        ];
        if ($this->instructions !== null) {
            $result['instructions'] = $this->instructions;
        }
        return $this->cacheable($result);
    }

    /**
     * @see https://modelcontextprotocol.io/specification/2026-07-28/server/tools#listing-tools
     * @return array<string, mixed>
     */
    private function listTools(Message $message): array
    {
        self::firstPage($message);
        return $this->cacheable([
            'tools' => array_values(array_map(
                static fn(PreparedTool $tool): array => $tool->definition(),
                $this->tools,
            )),
        ]);
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
        $tool = $this->tools[$name] ?? throw McpError::invalidParams("Unknown tool: {$name}.");
        $arguments = $message->params['arguments'] ?? [];
        if (!Json::isObject($arguments)) {
            throw McpError::invalidParams('A tool\'s arguments are an object.');
        }
        $token = $message->meta()['progressToken'] ?? null;
        if ($token !== null && !is_string($token) && !is_int($token)) {
            throw McpError::invalidParams('A progress token is a string or an integer.');
        }
        $call = $tool->call($arguments, $request);
        if (!$call instanceof \Generator) {
            return $call;
        }
        if (!$stream || $token === null) {
            // Nobody is listening for progress: run the tool to its end and answer once.
            foreach ($call as $_) {
            }
            return $call->getReturn();
        }
        return self::progress($call, $token);
    }

    /**
     * @param \Generator<int, Progress, mixed, array<string, mixed>> $call
     * @return \Generator<int, array<string, mixed>, mixed, array<string, mixed>>
     */
    private static function progress(\Generator $call, int|string $progressId): \Generator
    {
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
        return $call->getReturn();
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
    private function streamed(Message $message, \Generator $result): \Generator
    {
        try {
            yield from $result;
            return $this->response($message->id, $result->getReturn());
        } catch (\Throwable $error) {
            $this->report($error, $message);
            return self::error($message->id, McpError::internal());
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function response(int|string|null $id, array $result): array
    {
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

    private function report(\Throwable $error, Message $message): void
    {
        $what = $message->method;
        $name = $message->params['name'] ?? $message->params['uri'] ?? null;
        if (is_string($name)) {
            $what .= " {$name}";
        }
        $logger = ($this->logger)();
        if ($logger === null) {
            // A failure is never dropped; with no logger it goes to the process's error output.
            file_put_contents('php://stderr', "The MCP server {$this->path} failed to answer {$what}: {$error}\n");
            return;
        }
        $logger->error("The MCP server {$this->path} failed to answer {$what}.", ['exception' => $error]);
    }
}
