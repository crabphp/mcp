<?php

declare(strict_types=1);

namespace Crab\Mcp\Protocol;

use Crab\Contracts\Http\Request;
use Crab\Mcp\Progress;
use Crab\Mcp\ToolInfo;

/**
 * A tool a server can list and call: an action it calls itself
 * (PreparedTool), or a route it calls through the application
 * (RouteTool).
 *
 * @internal
 */
interface ToolHandle
{
    public function info(): ToolInfo;

    /**
     * The Tool, as tools/list sends it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array;

    /**
     * The tool's result, or for a tool that reports progress a generator
     * that yields it and returns the result. What a tool means to say,
     * arguments that do not fit included, is the result with isError;
     * anything else it throws is thrown.
     *
     * @param array<mixed> $arguments
     * @return array<string, mixed>|\Generator<int, Progress, mixed, array<string, mixed>>
     */
    public function call(array $arguments, Request $request): array|\Generator;

    /**
     * The classes the container makes to call it.
     *
     * @return list<class-string>
     */
    public function dependencies(): array;
}
