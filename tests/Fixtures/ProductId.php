<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests\Fixtures;

use Crab\Validation\Range;

final readonly class ProductId
{
    public function __construct(
        #[Range(min: 1)]
        public int $id,
    ) {}
}
