<?php

declare(strict_types=1);

namespace Crab\Mcp\Http;

use Crab\Contracts\Http\Factory;
use Crab\Contracts\Http\HttpError;
use Crab\Contracts\Http\Request;
use Crab\Mcp\McpPlugin;
use Crab\Mcp\Protocol\Json;
use Crab\Mcp\Protocol\McpError;
use Crab\Mcp\Protocol\Message;
use Crab\Mcp\Protocol\Server;
use Crab\Mcp\Protocol\Versions;
use Psr\Http\Message\ResponseInterface;

/**
 * The MCP endpoint of the Streamable HTTP transport: the action of the
 * one POST route a server adds. Each request is one JSON-RPC message,
 * answered with one JSON object, or, for a tool reporting its progress to
 * a client that takes events, an event stream that ends with the
 * response; a notification is answered 202. GET and DELETE, which earlier
 * revisions used, have no route and are answered 405 by the router.
 *
 * What the client says in its headers must be what it says in its body,
 * so that a proxy routing on one cannot be told another story than the
 * server: a missing or differing MCP-Protocol-Version, Mcp-Method or
 * Mcp-Name is refused with 400 and HeaderMismatch.
 *
 * The answers are the protocol's own JSON-RPC errors, not the
 * application's error format, since the client reads them as JSON-RPC.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http
 * @internal
 */
final readonly class Endpoint
{
    /**
     * Methods whose Mcp-Name header carries a parameter, and which one.
     */
    private const array NAMED = ['tools/call' => 'name', 'resources/read' => 'uri', 'prompts/get' => 'name'];

    /**
     * @param string|null $own The application's origin, from app.url; the request's own when null.
     * @param list<string> $origins Other origins a browser may call from, each as origin() writes it, or "*" for any.
     */
    public function __construct(
        private Server $server,
        private Factory $factory,
        private ?string $own,
        private array $origins,
    ) {}

    /**
     * @return ResponseInterface|\Generator<int, string> A generator is sent by the router as server-sent events, each a message.
     */
    public function handle(Request $request): ResponseInterface|\Generator
    {
        if (!$this->allowsOrigin($request)) {
            // A page on another site must not reach a server it has no business with, by DNS rebinding or otherwise.
            return $this->json(403, Server::error(
                null,
                McpError::invalidRequest('Requests from this origin are refused.'),
            ));
        }
        try {
            $message = Message::read($this->body($request));
        } catch (McpError $error) {
            return $this->json($error->status(), Server::error(null, $error));
        }
        if ($message->isNotification()) {
            return $this->factory->createResponse(202);
        }
        try {
            $version = $this->header($request, 'MCP-Protocol-Version', false);
            $legacy = Versions::legacy($message, $version) !== null;
            if (!$legacy) {
                $this->headers($request, $message, $version);
            }
        } catch (McpError $error) {
            return $this->json($error->status(), Server::error($message->id, $error));
        }
        $stream = self::acceptsEvents($request);
        $reply = $this->server->handle($message, $request, $stream, $version);
        if ($reply instanceof \Generator) {
            return self::events($reply);
        }
        $code = isset($reply['error']['code']) ? (int) $reply['error']['code'] : null;
        // Before 2026-07-28 an unknown method was an answer like any other, sent with 200.
        $status = match (true) {
            $code === null, $legacy && $code === McpError::METHOD_NOT_FOUND => 200,
            default => McpError::statusOf($code),
        };
        return $this->json($status, (array) $reply);
    }

    /**
     * The body as decoded JSON, through the framework's own reader, which
     * bounds what it decodes.
     */
    private function body(Request $request): mixed
    {
        if (preg_match('~^application/(?:[a-z0-9.\-]+\+)?json\b~i', $request->getHeaderLine('Content-Type')) !== 1) {
            throw McpError::parse('A message is sent as application/json.');
        }
        try {
            $body = $request->getParsedBody();
        } catch (HttpError $error) {
            throw McpError::parse($error->status === 413 ? $error->getMessage() : 'The message is not valid JSON.');
        }
        if ($body === null) {
            throw McpError::parse();
        }
        // A strict reading gives objects; the protocol is read from arrays.
        return is_object($body) ? json_decode(Json::encode($body), true) : $body;
    }

    /**
     * Checks a modern request's headers against its body. A legacy
     * request has only MCP-Protocol-Version, which named its era.
     *
     * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http#server-validation
     */
    private function headers(Request $request, Message $message, ?string $version): void
    {
        if ($version === null) {
            throw McpError::headerMismatch(
                'A request carries the MCP-Protocol-Version header; this server speaks '
                . McpPlugin::PROTOCOL_VERSION
                . '.',
            );
        }
        $named = $message->meta()['io.modelcontextprotocol/protocolVersion'] ?? null;
        if (is_string($named) && $named !== $version) {
            throw McpError::headerMismatch(
                "The MCP-Protocol-Version header is {$version} and params._meta names {$named}; they are the same.",
            );
        }
        if ($version !== McpPlugin::PROTOCOL_VERSION) {
            throw McpError::unsupportedVersion($version);
        }
        $method = $this->header($request, 'Mcp-Method', false);
        if ($method !== $message->method) {
            throw McpError::headerMismatch(
                $method === null
                    ? 'A request carries its method in the Mcp-Method header too.'
                    : "The Mcp-Method header is {$method} and the request's method is {$message->method}.",
            );
        }
        $parameter = self::NAMED[$message->method] ?? null;
        if ($parameter !== null) {
            $name = $this->header($request, 'Mcp-Name', true);
            $value = $message->params[$parameter] ?? null;
            if ($name === null || $name !== $value) {
                throw McpError::headerMismatch(
                    $name === null
                        ? "A {$message->method} request carries params.{$parameter} in the Mcp-Name header too."
                        : "The Mcp-Name header does not match params.{$parameter}.",
                );
            }
        }
    }

    /**
     * A header's one value, decoded from the Base64 sentinel where that
     * is allowed, or null when it is absent.
     *
     * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http#value-encoding
     */
    private function header(Request $request, string $name, bool $encoded): ?string
    {
        $values = $request->getHeader($name);
        if ($values === []) {
            return null;
        }
        if (count($values) > 1) {
            throw McpError::headerMismatch("The {$name} header is given more than once.");
        }
        $value = $values[0];
        if (preg_match('/[^\x20-\x7E\t]/', $value) === 1) {
            throw McpError::headerMismatch("The {$name} header holds characters a header may not.");
        }
        if ($encoded && preg_match('/^=\?base64\?([A-Za-z0-9+\/]*={0,2})\?=$/', $value, $matches) === 1) {
            $decoded = base64_decode($matches[1], true);
            if ($decoded === false) {
                throw McpError::headerMismatch("The {$name} header is not valid Base64.");
            }
            return $decoded;
        }
        return $value;
    }

    /**
     * Whether a browser's request comes from an origin the server takes:
     * the application's own, which is app.url's, or the request's when
     * there is none, and those the server was given.
     *
     * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http#security--endpoint
     */
    private function allowsOrigin(Request $request): bool
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            // Not sent by a browser on another site's behalf: a browser names the origin of every cross-site POST.
            return true;
        }
        if (in_array('*', $this->origins, true)) {
            return true;
        }
        $uri = $request->getUri();
        $host = $request->getHeaderLine('Host') !== '' ? $request->getHeaderLine('Host') : $uri->getAuthority();
        $own = $this->own ?? self::origin(($uri->getScheme() !== '' ? $uri->getScheme() : 'http') . '://' . $host);
        $origin = self::origin($origin);
        return $origin !== null && ($origin === $own || in_array($origin, $this->origins, true));
    }

    /**
     * An origin in one form, "scheme://host" with the port when it is not
     * the scheme's own, or null when it is not one.
     */
    public static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $default = ['http' => 80, 'https' => 443][$scheme] ?? null;
        return $scheme . '://' . strtolower($parts['host']) . ($port === null || $port === $default ? '' : ":{$port}");
    }

    private static function acceptsEvents(Request $request): bool
    {
        return (
            preg_match('~(^|,)\s*(text/event-stream|text/\*|\*/\*)\s*(;|,|$)~i', $request->getHeaderLine('Accept'))
            === 1
        );
    }

    /**
     * @param \Generator<int, array<string, mixed>, mixed, array<string, mixed>> $reply
     * @return \Generator<int, string>
     */
    private static function events(\Generator $reply): \Generator
    {
        foreach ($reply as $notification) {
            yield Json::encode($notification);
        }
        yield Json::encode($reply->getReturn());
    }

    /**
     * @param array<string, mixed> $message
     */
    private function json(int $status, array $message): ResponseInterface
    {
        return $this->factory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream(Json::encode($message)));
    }
}
