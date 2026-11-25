<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests\Fixtures;

use Crab\Contracts\Http\Handler;
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Http\Request;
use Crab\Http\Response;
use Psr\Http\Message\ResponseInterface;

final readonly class RequireKey implements Middleware
{
    public function handle(Request $request, Handler $next): ResponseInterface
    {
        if ($request->getHeaderLine('X-Key') !== 'open') {
            return Response::json(['message' => 'No key.'], 401);
        }
        return $next->handle($request);
    }
}
