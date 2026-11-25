<?php

declare(strict_types=1);

namespace Crab\Mcp\Content;

/**
 * A resource the client can read with resources/read, named rather than
 * included. It need not be one the server lists.
 */
final readonly class ResourceLink implements Content
{
    public function __construct(
        public string $uri,
        public string $name,
        public ?string $description = null,
        public ?string $mimeType = null,
        public ?string $title = null,
        public ?int $size = null,
    ) {}

    public function toArray(): array
    {
        return array_filter(
            [
                'type' => 'resource_link',
                'uri' => $this->uri,
                'name' => $this->name,
                'title' => $this->title,
                'description' => $this->description,
                'mimeType' => $this->mimeType,
                'size' => $this->size,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
