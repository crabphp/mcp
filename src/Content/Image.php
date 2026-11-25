<?php

declare(strict_types=1);

namespace Crab\Mcp\Content;

/**
 * An image, given as its bytes; it is sent in base64.
 */
final readonly class Image implements Content
{
    /**
     * @param string $bytes The raw image.
     * @param string $mimeType Such as "image/png".
     */
    public function __construct(
        public string $bytes,
        public string $mimeType,
    ) {}

    public function toArray(): array
    {
        return ['type' => 'image', 'data' => base64_encode($this->bytes), 'mimeType' => $this->mimeType];
    }
}
