<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests\Fixtures;

use Crab\Contracts\Http\Request;
use Crab\Http\HttpException;
use Crab\Http\Response;
use Crab\Mcp\Tool;
use Crab\Mcp\ToolInfo;
use Crab\Routing\FromQuery;
use Crab\Validation\Data;
use Crab\Validation\Fields;
use Crab\Validation\Range;
use Psr\Http\Message\ResponseInterface;

/**
 * Routes whose methods are tools: path parameters, query fields, a body,
 * a response of its own, and refusals.
 */
final readonly class Orders
{
    /**
     * @return array<string, mixed>
     */
    #[Tool('orders_list', 'Lists a project\'s orders.', readOnly: true)]
    public function index(int $id, #[FromQuery] int $per_page = 10, #[FromQuery] ?string $status = null): array
    {
        return ['project' => $id, 'per_page' => $per_page, 'status' => $status];
    }

    /**
     * @return array<string, mixed>
     */
    #[Tool('order_create', 'Creates an order in a project.', readOnly: false)]
    public function create(
        int $id,
        #[Fields(['item' => 'string', 'quantity' => ['int', new Range(min: 1)]])]
        Data $order,
    ): array {
        return ['project' => $id, 'item' => $order->item, 'quantity' => $order->quantity];
    }

    #[Tool('order_delete', 'Deletes an order.', readOnly: false, destructive: true)]
    public function delete(int $id): ResponseInterface
    {
        return Response::noContent();
    }

    /**
     * @return array<string, mixed>
     */
    #[Tool('order_show', 'Shows one order.', readOnly: true)]
    public function show(int $id): array
    {
        if ($id === 404) {
            throw new HttpException(404, 'There is no order 404.');
        }
        if ($id === 500) {
            throw new \RuntimeException('the database is down');
        }
        return ['id' => $id];
    }

    /**
     * @return list<array<string, int|string>>
     */
    #[Tool('orders_export', 'Every order there ever was.', readOnly: true)]
    public function export(#[FromQuery] int $limit = 1000): array
    {
        return array_map(static fn(int $id): array => ['id' => $id, 'note' => str_repeat('x', 50)], range(1, $limit));
    }

    /**
     * @return array<string, mixed>
     */
    #[Tool('whoami', 'Says how the call arrived.', readOnly: true)]
    public function whoami(Request $request): array
    {
        $tool = $request->getAttribute(ToolInfo::ATTRIBUTE);
        return [
            'tool' => $tool instanceof ToolInfo ? $tool->name : null,
            'key' => $request->getHeaderLine('X-Key'),
            'ip' => $request->ip(),
            'mcp' => $request->getHeaderLine('Mcp-Method'),
        ];
    }

    public function notATool(): string
    {
        return 'hidden';
    }
}
