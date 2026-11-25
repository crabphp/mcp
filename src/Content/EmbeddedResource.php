<?php

declare(strict_types=1);

namespace Crab\Mcp\Content;

use Crab\Mcp\Contents;

/**
 * A resource's contents, included in the result.
 */
final readonly class EmbeddedResource implements Content
{
    public function __construct(
        public string $uri,
        public Contents $contents,
    ) {}

    public function toArray(): array
    {
        return ['type' => 'resource', 'resource' => $this->contents->toArray($this->uri)];
    }
}
