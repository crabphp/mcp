<?php

declare(strict_types=1);

namespace Crab\Mcp;

/**
 * What a resource holds, or one part of it: text, or bytes, which are sent
 * in base64. A resource returns one or a list; a plain string is text.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/server/resources#resource-contents
 */
final readonly class Contents
{
    private function __construct(
        public ?string $text,
        public ?string $bytes,
        public ?string $mimeType,
        public ?string $uri,
    ) {}

    /**
     * @param string|null $uri The part's own URI; the one read when null.
     */
    public static function text(string $text, ?string $mimeType = null, ?string $uri = null): self
    {
        return new self($text, null, $mimeType, $uri);
    }

    /**
     * @param string $bytes The raw bytes; they are encoded when sent.
     * @param string|null $uri The part's own URI; the one read when null.
     */
    public static function blob(string $bytes, ?string $mimeType = null, ?string $uri = null): self
    {
        return new self(null, $bytes, $mimeType, $uri);
    }

    /**
     * As sent: TextResourceContents or BlobResourceContents.
     *
     * @param string $uri The URI read, for a part that names none.
     * @param string|null $mimeType The resource's declared media type, for a part that gives none.
     * @return array<string, string>
     */
    public function toArray(string $uri, ?string $mimeType = null): array
    {
        $contents = ['uri' => $this->uri ?? $uri];
        $type = $this->mimeType ?? $mimeType;
        if ($type !== null) {
            $contents['mimeType'] = $type;
        }
        if ($this->text !== null) {
            $contents['text'] = $this->text;
        } else {
            $contents['blob'] = base64_encode((string) $this->bytes);
        }
        return $contents;
    }
}
