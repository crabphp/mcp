<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests\Fixtures;

use Crab\Contracts\Auth\Identity;
use Crab\Mcp\Tool;

#[Tool('who_am_i', 'Says who is calling.')]
final readonly class WhoAmI
{
    public function handle(Identity $user): string
    {
        return (string) $user->id;
    }
}
