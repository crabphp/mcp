<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests\Fixtures;

use Crab\Http\HttpException;
use Crab\Mcp\Tool;

#[Tool('show_product', 'Shows one product by its id.', title: 'Show product', readOnly: true)]
final readonly class ShowProduct
{
    public function handle(ProductId $input): Product
    {
        if ($input->id === 404) {
            throw new HttpException(404, 'There is no product 404.');
        }
        return new Product($input->id, 'Hammer', Kind::Tool, new \DateTimeImmutable('2026-01-02T03:04:05+00:00'));
    }
}
