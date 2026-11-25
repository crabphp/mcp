<?php

declare(strict_types=1);

namespace Crab\Mcp\Content;

/**
 * One block of a tool's result: Text, Image, Audio, ResourceLink or
 * EmbeddedResource. A tool may return one, a list of them, or a
 * ToolResult holding them.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/server/tools#tool-result
 */
interface Content
{
    /**
     * The block as sent.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
