<?php

declare(strict_types=1);

namespace Crab\Mcp;

/**
 * Marks a class with handle() as a resource an MCP server can read. The
 * URI is fixed, or a template whose {name} parts are handle()'s parameters
 * of the same names, read from the URI as a route's are from its path:
 *
 *     #[Resource('shop://products/{id}', 'product', 'A product, as JSON.', mimeType: 'application/json')]
 *     final readonly class ProductResource
 *     {
 *         public function handle(int $id): ?Product { ... }
 *     }
 *
 * handle() returns the contents: a string as text, Contents for bytes or
 * several parts, anything else as JSON; null when there is no such
 * resource.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/server/resources
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Resource
{
    /**
     * @param string $uri The resource's URI, or a template of it with {name} parts (RFC 6570, level 1).
     * @param string $name A short name for it.
     * @param string|null $description What it holds, for the model.
     * @param string|null $mimeType Its media type, when every resource it stands for has the same.
     * @param string|null $title A name for people.
     * @param int $ttl How many milliseconds a client may keep what it read before reading it again; 0, never.
     * @param bool $public Whether what it reads is the same for everyone, so that a shared cache may keep it; it is private to the caller by default.
     */
    public function __construct(
        public string $uri,
        public string $name,
        public ?string $description = null,
        public ?string $mimeType = null,
        public ?string $title = null,
        public int $ttl = 0,
        public bool $public = false,
    ) {}
}
