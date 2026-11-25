<?php

declare(strict_types=1);

namespace Crab\Mcp\Protocol;

/**
 * One JSON-RPC message from the client, read from what was decoded: a
 * request, which has an id and is answered, or a notification, which has
 * none and is not. A response from the client is refused, since this
 * revision sends the client no requests to answer.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/index#messages
 * @internal
 */
final readonly class Message
{
    /**
     * @param array<string, mixed> $params
     * @param bool|null $objectArguments Whether params.arguments was written as a JSON object; null when it is absent or the text was not given.
     */
    private function __construct(
        public int|string|null $id,
        public string $method,
        public array $params,
        public ?bool $objectArguments = null,
    ) {}

    /**
     * @param string|null $text The message as written: an object and a list are told apart only there, since an empty one decodes the same.
     * @throws McpError A message that is not a JSON-RPC request or notification.
     */
    public static function read(mixed $decoded, ?string $text = null): self
    {
        if (!Json::isObject($decoded) || $decoded === []) {
            throw McpError::invalidRequest(
                is_array($decoded)
                    ? 'A message is one JSON-RPC object; MCP has no batches.'
                    : 'A message is one JSON-RPC object.',
            );
        }
        if (($decoded['jsonrpc'] ?? null) !== '2.0') {
            throw McpError::invalidRequest('A message has "jsonrpc": "2.0".');
        }
        if (!array_key_exists('method', $decoded)) {
            throw McpError::invalidRequest('The client sends requests and notifications, which have a method.');
        }
        if (!is_string($decoded['method']) || $decoded['method'] === '') {
            throw McpError::invalidRequest('A message\'s method is a string.');
        }
        $id = null;
        if (array_key_exists('id', $decoded)) {
            $id = $decoded['id'];
            if (!is_int($id) && !is_string($id)) {
                throw McpError::invalidRequest('A request\'s id is a string or an integer.');
            }
        }
        $params = $decoded['params'] ?? [];
        $written = $text === null ? null : json_decode($text, false, 512);
        $writtenParams = $written instanceof \stdClass ? $written->params ?? null : null;
        if (!Json::isObject($params) || $writtenParams !== null && !$writtenParams instanceof \stdClass) {
            throw McpError::invalidRequest('A message\'s params are an object.');
        }
        $arguments = $writtenParams instanceof \stdClass && property_exists($writtenParams, 'arguments')
            ? $writtenParams->arguments instanceof \stdClass
            : null;
        return new self($id, $decoded['method'], $params, $arguments);
    }

    public function isNotification(): bool
    {
        return $this->id === null;
    }

    /**
     * The request's _meta, or an empty array when it has none.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $meta = $this->params['_meta'] ?? [];
        return Json::isObject($meta) ? $meta : [];
    }
}
