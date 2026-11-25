<?php

declare(strict_types=1);

namespace Crab\Mcp;

use Crab\App;
use Crab\Contracts\Definitions;

/**
 * Declares MCP servers on the current application, for route files:
 *
 *     Mcp::server('/mcp')->tools([ShowProduct::class]);
 *
 * Like Route, it declares what is fixed before any worker starts, which
 * is why a static name for it is safe. It needs McpPlugin in app.php.
 */
final class Mcp
{
    /**
     * A server whose endpoint is the path, to say on the same statement
     * what it offers; see ServerDeclaration.
     */
    public static function server(string $path): ServerDeclaration
    {
        $container = App::current()->container;
        foreach ($container->get(Definitions::class)->all() as $definition) {
            if ($definition instanceof McpDefinition) {
                return $definition->declare($path);
            }
        }
        throw new \LogicException(
            'Mcp::server() declares on McpPlugin, which the application does not list. Add '
            . McpPlugin::class
            . ' to withPlugins() in app.php.',
        );
    }
}
