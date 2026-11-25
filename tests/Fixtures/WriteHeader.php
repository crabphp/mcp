<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests\Fixtures;

use Crab\Contracts\Http\Request;
use Crab\Mcp\ToolFilter;
use Crab\Mcp\ToolInfo;

/**
 * Lets a caller that says X-Write: yes see every tool, and others only
 * those that read.
 */
final readonly class WriteHeader implements ToolFilter
{
    public function handle(ToolInfo $tool, Request $request): bool
    {
        return $tool->readOnly === true || $request->getHeaderLine('X-Write') === 'yes';
    }
}
