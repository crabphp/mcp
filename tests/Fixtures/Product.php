<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests\Fixtures;

final readonly class Product
{
    public function __construct(
        public int $id,
        public string $name,
        public Kind $kind,
        public \DateTimeImmutable $added,
    ) {}
}
