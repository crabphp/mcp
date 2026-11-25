<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests\Fixtures;

use Crab\Mcp\Tool;
use Crab\Mcp\ToolError;

#[Tool('refuse', 'Always refuses.', destructive: false)]
final readonly class Refuse
{
    public function handle(): never
    {
        throw new ToolError('The shop is closed on Sundays.');
    }
}
