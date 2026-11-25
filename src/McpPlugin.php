<?php

declare(strict_types=1);

namespace Crab\Mcp;

use Crab\Contracts\Container\Container;
use Crab\Contracts\Plugin;

/**
 * Adds MCP servers to an application. It registers nothing yet; the
 * package is being designed, see the README.
 */
final class McpPlugin implements Plugin
{
    /**
     * The protocol revision the package is built against.
     */
    public const string PROTOCOL_VERSION = '2026-07-28';

    public function register(Container $container): void {}
}
