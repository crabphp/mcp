<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests\Fixtures;

use Crab\Mcp\Tool;

#[Tool('list_products', 'The same name as another.')]
final readonly class Duplicate
{
    public function handle(): string
    {
        return 'again';
    }
}
