<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests\Fixtures;

use Crab\Mcp\Tool;
use Crab\Validation\Data;
use Crab\Validation\Fields;

#[Tool('anything', 'Takes any value.')]
final readonly class Anything
{
    public function handle(#[Fields(['value' => 'mixed'])] Data $input): string
    {
        return get_debug_type($input->value);
    }
}
