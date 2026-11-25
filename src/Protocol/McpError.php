<?php

declare(strict_types=1);

namespace Crab\Mcp\Protocol;

use Crab\Mcp\McpPlugin;

/**
 * A request the server answers with a JSON-RPC error rather than a result.
 * The message is shown to the client, so it never carries what a tool
 * threw. Each code is the specification's, and so is the HTTP status the
 * Streamable HTTP transport sends it with.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/index#error-codes
 * @internal
 */
final class McpError extends \RuntimeException
{
    public const int PARSE_ERROR = -32_700;
    public const int INVALID_REQUEST = -32_600;
    public const int METHOD_NOT_FOUND = -32_601;
    public const int INVALID_PARAMS = -32_602;
    public const int INTERNAL_ERROR = -32_603;
    public const int HEADER_MISMATCH = -32_020;
    public const int MISSING_REQUIRED_CLIENT_CAPABILITY = -32_021;
    public const int UNSUPPORTED_PROTOCOL_VERSION = -32_022;

    /**
     * @param array<string, mixed>|null $data
     */
    private function __construct(
        int $code,
        string $message,
        public readonly ?array $data = null,
    ) {
        parent::__construct($message, $code);
    }

    public static function parse(string $message = 'The message is not valid JSON.'): self
    {
        return new self(self::PARSE_ERROR, $message);
    }

    public static function invalidRequest(string $message): self
    {
        return new self(self::INVALID_REQUEST, $message);
    }

    public static function methodNotFound(string $method): self
    {
        return new self(self::METHOD_NOT_FOUND, "Method not found: {$method}.");
    }

    public static function invalidParams(string $message): self
    {
        return new self(self::INVALID_PARAMS, $message);
    }

    public static function internal(): self
    {
        return new self(self::INTERNAL_ERROR, 'Internal error.');
    }

    public static function headerMismatch(string $message): self
    {
        return new self(self::HEADER_MISMATCH, $message);
    }

    public static function unsupportedVersion(string $requested): self
    {
        return new self(
            self::UNSUPPORTED_PROTOCOL_VERSION,
            'Unsupported protocol version; this server speaks ' . McpPlugin::PROTOCOL_VERSION . '.',
            ['supported' => [McpPlugin::PROTOCOL_VERSION], 'requested' => $requested],
        );
    }

    /**
     * The HTTP status the error goes out with on Streamable HTTP: 400 for
     * a message that is not a request or does not match its headers, 404
     * for a method the server does not have, and 200 for the rest, which
     * are answers to a request that was understood.
     *
     * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http#request-metadata
     */
    public function status(): int
    {
        return self::statusOf($this->getCode());
    }

    /**
     * The same for an error code.
     */
    public static function statusOf(int $code): int
    {
        return match ($code) {
            self::PARSE_ERROR,
            self::INVALID_REQUEST,
            self::HEADER_MISMATCH,
            self::MISSING_REQUIRED_CLIENT_CAPABILITY,
            self::UNSUPPORTED_PROTOCOL_VERSION,
                => 400,
            self::METHOD_NOT_FOUND => 404,
            default => 200,
        };
    }

    /**
     * The JSON-RPC error object.
     *
     * @return array{code: int, message: string, data?: array<string, mixed>}
     */
    public function error(): array
    {
        $error = ['code' => $this->getCode(), 'message' => $this->getMessage()];
        if ($this->data !== null) {
            $error['data'] = $this->data;
        }
        return $error;
    }
}
