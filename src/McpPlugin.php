<?php

declare(strict_types=1);

namespace Crab\Mcp;

use Crab\Contracts\Console\Console;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Definitions;
use Crab\Contracts\Plugin;
use Crab\Mcp\Commands\ListServers;
use Crab\Mcp\Commands\Serve;

/**
 * Adds MCP servers to an application: Mcp::server() in a routes file
 * declares one, which serves Streamable HTTP at its path and stdio with
 * `php cua mcp:serve`; `php cua mcp:list` lists what each offers. It has
 * no settings, and costs a request nothing until one reaches a server's
 * path.
 */
final class McpPlugin implements Plugin
{
    /**
     * The protocol revision the package is built against.
     */
    public const string PROTOCOL_VERSION = '2026-07-28';

    public function register(Container $container): void
    {
        $definition = new McpDefinition($container);
        $container->instance(McpDefinition::class, $definition);
        $container->instance(Servers::class, new Servers($definition));
        $container->get(Definitions::class)->add($definition);
        if ($container->has(Console::class)) {
            $console = $container->get(Console::class);
            $console->add('mcp:serve', Serve::class);
            $console->add('mcp:list', ListServers::class);
        }
    }
}
