<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests\Fixtures;

use Crab\Mcp\Resource;

#[Resource('shop://notes', 'notes', 'What the shop keeps in mind.', mimeType: 'text/plain', ttl: 60_000, public: true)]
final readonly class Notes
{
    public function handle(): string
    {
        return "Open 9 to 5.\n";
    }
}
