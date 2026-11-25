<?php

declare(strict_types=1);

namespace Crab\Mcp;

use Crab\Contracts\Http\Request;

/**
 * Decides which of a server's tools a request is shown, for an
 * application whose callers may do different things: a token that may
 * only read is shown the tools that read. A tool it hides is not listed
 * and not found by discovery.
 *
 *     final readonly class TokenTools implements ToolFilter
 *     {
 *         public function handle(ToolInfo $tool, Request $request): bool
 *         {
 *             return $tool->readOnly === true || $this->tokens->canWrite($request);
 *         }
 *     }
 *
 * It shows; it does not guard. A hidden tool called by name is called,
 * and whatever answers it, a route's middleware or the action itself,
 * checks who may do what and says why it refuses, which tells the model
 * more than an unknown tool would. A server with a filter lists its tools
 * as private to the caller.
 */
interface ToolFilter
{
    public function handle(ToolInfo $tool, Request $request): bool;
}
