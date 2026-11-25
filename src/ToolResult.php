<?php

declare(strict_types=1);

namespace Crab\Mcp;

use Crab\Mcp\Content\Content;
use Crab\Mcp\Content\Text;
use Crab\Mcp\Protocol\Json;

/**
 * A tool's result when the plain return values do not say enough: content
 * of several kinds, structured data beside text of one's own, or an error.
 * A tool usually returns data, which is sent as structured content and as
 * its JSON text, a string, sent as text, or Content.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/server/tools#tool-result
 */
final readonly class ToolResult
{
    /**
     * @param list<Content> $content
     */
    private function __construct(
        public array $content,
        public bool $structured,
        public mixed $data,
        public bool $error,
    ) {}

    /**
     * Content and nothing else.
     */
    public static function of(Content ...$content): self
    {
        return new self(array_values($content), false, null, false);
    }

    /**
     * Structured data, with the content that says it for a client that
     * reads only content; its JSON text when none is given.
     */
    public static function structured(mixed $data, Content ...$content): self
    {
        $value = Json::value($data);
        return new self(
            $content === [] ? [new Text(Json::encode($value))] : array_values($content),
            true,
            $value,
            false,
        );
    }

    /**
     * A failure the model can read and act on, as ToolError is.
     */
    public static function error(string $message): self
    {
        return new self([new Text($message)], false, null, true);
    }

    /**
     * The CallToolResult's own fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = ['content' => array_map(static fn(Content $content): array => $content->toArray(), $this->content)];
        if ($this->structured) {
            $result['structuredContent'] = $this->data;
        }
        // Said either way: a client is told, not left to assume.
        $result['isError'] = $this->error;
        return $result;
    }
}
