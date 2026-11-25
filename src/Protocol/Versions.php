<?php

declare(strict_types=1);

namespace Crab\Mcp\Protocol;

use Crab\Mcp\McpPlugin;

/**
 * The protocol versions a server speaks, and which era a request belongs
 * to. The modern revision carries its version in every request's _meta;
 * the legacy ones agree on it once, with initialize. Legacy clients are
 * served statelessly too: initialize is answered and nothing is kept, no
 * session is given, and each request after it stands alone, which those
 * revisions allow.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning#backward-compatibility-with-initialization-based-versions
 * @internal
 */
final class Versions
{
    /**
     * The revisions before 2026-07-28 that are served, newest first.
     */
    public const array LEGACY = ['2025-11-25', '2025-06-18', '2025-03-26'];

    /**
     * What a legacy client that sends no MCP-Protocol-Version header
     * speaks: the header came with 2025-06-18.
     *
     * @see https://modelcontextprotocol.io/specification/2025-06-18/basic/transports#protocol-version-header
     */
    public const string HEADERLESS = '2025-03-26';

    /**
     * Every version served, the modern one first.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [McpPlugin::PROTOCOL_VERSION, ...self::LEGACY];
    }

    /**
     * The legacy version a request is in, or null when it is modern: a
     * request is legacy when its _meta names no protocol version and the
     * transport's header, if it has one, names a legacy version.
     *
     * @param string|null $header The MCP-Protocol-Version header; null on stdio, or when it is absent.
     */
    public static function legacy(Message $message, ?string $header): ?string
    {
        if (array_key_exists('io.modelcontextprotocol/protocolVersion', $message->meta())) {
            return null;
        }
        if ($header === null) {
            return self::HEADERLESS;
        }
        return in_array($header, self::LEGACY, true) ? $header : null;
    }
}
