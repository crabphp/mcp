<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests\Fixtures;

use Crab\Mcp\Resource;

#[Resource('shop://products/{id}', 'product', 'One product, as JSON.', mimeType: 'application/json')]
final readonly class ProductResource
{
    /**
     * @return array{id: int, name: string}|null
     */
    public function handle(int $id): ?array
    {
        return $id === 1 ? ['id' => 1, 'name' => 'Hammer'] : null;
    }
}
