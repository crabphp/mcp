<?php

declare(strict_types=1);

namespace Crab\Mcp\Protocol;

/**
 * A route tool whose route answered with a failure the application has
 * already reported, through its own error handler; the server answers it
 * with an internal error and does not report it again.
 *
 * @internal
 */
final class RouteToolFailed extends \RuntimeException {}
