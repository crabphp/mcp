<?php

declare(strict_types=1);

namespace Crab\Mcp;

use Crab\Contracts\Http\Request;

/**
 * A tool was called, and answered: dispatched after every tools/call
 * through the application's event dispatcher when it has one, for an
 * activity log or a count. What a listener throws is logged and changes
 * nothing of the answer.
 */
final readonly class ToolCalled
{
    /**
     * @param array<mixed> $arguments As the client sent them; they may hold what the caller would not have logged.
     * @param Request $request The MCP request, with whoever made it.
     * @param bool $failed Whether the result is an error, or the call failed.
     * @param string|null $message What the error said, when it failed.
     */
    public function __construct(
        public ToolInfo $tool,
        #[\SensitiveParameter]
        public array $arguments,
        public Request $request,
        public bool $failed,
        public ?string $message,
    ) {}
}
