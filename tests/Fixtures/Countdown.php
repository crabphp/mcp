<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests\Fixtures;

use Crab\Mcp\Progress;
use Crab\Mcp\Tool;
use Crab\Validation\Data;
use Crab\Validation\Fields;

#[Tool('countdown', 'Counts down, telling how far it is.')]
final readonly class Countdown
{
    public function handle(#[Fields(['from' => 'int'])] Data $input): \Generator
    {
        $from = (int) $input->from;
        for ($done = 1; $done <= $from; $done++) {
            yield new Progress($done, $from, "{$done} of {$from}");
        }
        return 'Lift-off';
    }
}
