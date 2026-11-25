<?php

declare(strict_types=1);

namespace Crab\Mcp;

/**
 * The MCP servers being declared, for a plugin that adds to one: list it
 * after McpPlugin and, in register(),
 *
 *     $container->get(Servers::class)->server('/mcp')->tools([ShowInvoice::class]);
 *
 * A path declared again is the same server, so the application and its
 * plugins each add what is theirs. Mcp::server() is the same, for route
 * files.
 */
final readonly class Servers
{
    public function __construct(
        private McpDefinition $definition,
    ) {}

    public function server(string $path): ServerDeclaration
    {
        return $this->definition->declare($path);
    }

    /**
     * The tools a server offers, by name, once the application is defined:
     * for documentation, or a test that every route an agent needs is one.
     *
     * @return array<string, ToolInfo>
     */
    public function tools(string $path): array
    {
        $server = $this->definition->servers()[$path] ?? throw new \LogicException(
            "No MCP server is defined at {$path}; tools are known once the application is defined.",
        );
        return array_map(static fn(Protocol\ToolHandle $tool): ToolInfo => $tool->info(), $server->tools);
    }
}
