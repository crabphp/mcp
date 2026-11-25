<?php

declare(strict_types=1);

namespace Crab\Mcp\Commands;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Http\Factory;
use Crab\Mcp\McpDefinition;
use Crab\Mcp\Stdio\StdioTransport;

/**
 * `mcp:serve`: speaks MCP over stdio, for a client that starts the server
 * as a process of its own, until the client closes its input.
 */
final readonly class Serve implements Command
{
    /**
     * The longest message read when "server.max_body" says nothing.
     */
    private const int MAX_LINE = 4 * 1024 * 1024;

    public function __construct(
        private McpDefinition $definition,
        private Factory $factory,
        private Config $config,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Serve an MCP server over stdio
              --path=/mcp   the server, by the path it is declared at; needed when there are several
            Messages are read from standard input and answered on standard output, one to a line,
            until the input closes. No middleware runs: whoever starts the process is the caller.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        try {
            $path = $input->value('path', '/mcp');
        } catch (\InvalidArgumentException $error) {
            $output->error($error->getMessage());
            return 1;
        }
        $servers = $this->definition->servers();
        if ($servers === []) {
            $output->error(
                "No MCP server is declared. Declare one in a routes file: Mcp::server('/mcp')->tools([...]).",
            );
            return 1;
        }
        if ($path === null && count($servers) > 1) {
            $output->error(
                'Several MCP servers are declared ('
                . implode(', ', array_keys($servers))
                . '); say which with --path='
                . array_key_first($servers)
                . '.',
            );
            return 1;
        }
        $server = $path === null ? $servers[array_key_first($servers)] : $servers[$path] ?? null;
        if ($server === null) {
            $output->error(
                "No MCP server is declared at {$path}; use --path= with one of "
                . implode(', ', array_keys($servers))
                . '.',
            );
            return 1;
        }
        $max = $this->config->get('server.max_body');
        $transport = new StdioTransport($server, $this->factory, is_int($max) && $max > 0 ? $max : self::MAX_LINE);
        $transport->run(STDIN, static function (string $line) use ($output): void {
            $output->write($line);
        });
        return 0;
    }
}
