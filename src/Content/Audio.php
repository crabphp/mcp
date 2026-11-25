<?php

declare(strict_types=1);

namespace Crab\Mcp\Content;

/**
 * Audio, given as its bytes; it is sent in base64.
 */
final readonly class Audio implements Content
{
    /**
     * @param string $bytes The raw audio.
     * @param string $mimeType Such as "audio/wav".
     */
    public function __construct(
        public string $bytes,
        public string $mimeType,
    ) {}

    public function toArray(): array
    {
        return ['type' => 'audio', 'data' => base64_encode($this->bytes), 'mimeType' => $this->mimeType];
    }
}
