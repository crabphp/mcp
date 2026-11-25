<?php

declare(strict_types=1);

namespace Crab\Mcp\Content;

/**
 * Text for the model.
 */
final readonly class Text implements Content
{
    public function __construct(
        public string $text,
    ) {}

    public function toArray(): array
    {
        return ['type' => 'text', 'text' => $this->text];
    }
}
