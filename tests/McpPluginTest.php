<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests;

use Crab\Container\Container;
use Crab\Mcp\McpPlugin;
use PHPUnit\Framework\TestCase;

final class McpPluginTest extends TestCase
{
    public function testRegistersWithoutServices(): void
    {
        $container = new Container();

        new McpPlugin()->register($container);

        self::assertSame('2026-07-28', McpPlugin::PROTOCOL_VERSION);
    }
}
