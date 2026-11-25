<?php

declare(strict_types=1);

namespace Crab\Mcp\Skills;

/**
 * A file of a skill, held as the bytes that were read, so that what is
 * sent is what its digest was taken of.
 *
 * @internal
 */
final readonly class SkillFile
{
    public function __construct(
        public string $uri,
        public string $bytes,
        public string $mimeType,
    ) {}

    public function digest(): string
    {
        return 'sha256:' . hash('sha256', $this->bytes);
    }

    public function size(): int
    {
        return strlen($this->bytes);
    }

    /**
     * Its contents as resources/read sends them: text when it is text,
     * otherwise its bytes in base64.
     *
     * @return array<string, string>
     */
    public function contents(): array
    {
        $contents = ['uri' => $this->uri, 'mimeType' => $this->mimeType];
        if (MimeTypes::isText($this->mimeType) && mb_check_encoding($this->bytes, 'UTF-8')) {
            $contents['text'] = $this->bytes;
        } else {
            $contents['blob'] = base64_encode($this->bytes);
        }
        return $contents;
    }
}
