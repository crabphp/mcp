<?php

declare(strict_types=1);

namespace Crab\Mcp;

/**
 * Thrown by a tool when what it was asked cannot be done, such as a date
 * in the past or a product that is sold out. The message is the tool's
 * answer, sent with isError so that the model can read it and try again;
 * write it for the model. Anything else a tool throws is logged and
 * answered with an internal error that says nothing of it.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/server/tools#error-handling
 */
final class ToolError extends \RuntimeException {}
