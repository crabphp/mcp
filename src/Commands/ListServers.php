<?php

declare(strict_types=1);

namespace Crab\Mcp\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Mcp\McpDefinition;

/**
 * `mcp:list`: every MCP server the application declares, with its tools,
 * resources and skills.
 */
final readonly class ListServers implements Command
{
    public function __construct(
        private McpDefinition $definition,
    ) {}

    public function description(): string
    {
        return 'List the MCP servers with their tools, resources and skills';
    }

    public function handle(Input $input, Output $output): int
    {
        $servers = $this->definition->servers();
        if ($servers === []) {
            $output->line(
                "No MCP server is declared. Declare one in a routes file: Mcp::server('/mcp')->tools([...]).",
            );
            return 0;
        }
        foreach ($servers as $path => $server) {
            $output->line("{$path}  {$server->name} {$server->version}");
            $rows = [];
            foreach ($server->tools as $name => $tool) {
                $rows[] = ['tool', $name, $tool->class];
            }
            foreach ($server->resources as $resource) {
                $rows[] = [
                    $resource->uri->isTemplate() ? 'template' : 'resource',
                    $resource->uri->template,
                    $resource->class,
                ];
            }
            foreach ($server->skills->skills ?? [] as $name => $skill) {
                $rows[] = ['skill', $name, $skill->uri];
            }
            if ($rows === []) {
                $output->line('  nothing offered');
                continue;
            }
            $output->table($rows, '  ');
        }
        return 0;
    }
}
