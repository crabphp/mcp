<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests\Fixtures;

use Crab\Mcp\Tool;

#[Tool('crash', 'Fails.')]
final readonly class Crash
{
    public function handle(): string
    {
        throw new \RuntimeException('secret database password in this message');
    }
}
