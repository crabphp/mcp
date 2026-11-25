<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests\Fixtures;

use Crab\Mcp\Tool;

#[Tool('list_products', 'Lists every product.')]
final readonly class ListProducts
{
    /**
     * @return list<array{id: int, name: string}>
     */
    public function handle(): array
    {
        return [['id' => 1, 'name' => 'Hammer'], ['id' => 2, 'name' => 'Kite']];
    }
}
