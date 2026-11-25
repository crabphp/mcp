<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests\Fixtures;

use Crab\Mcp\Tool;

final readonly class OrderKeys
{
    /**
     * @return list<string>
     */
    #[Tool('orders_keys_list', 'Lists the keys that may place orders.', readOnly: true)]
    public function index(): array
    {
        return [];
    }
}
