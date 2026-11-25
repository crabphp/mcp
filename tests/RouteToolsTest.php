<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests;

use Crab\App;
use Crab\Contracts\Events\Dispatcher;
use Crab\Events\EventsPlugin;
use Crab\Mcp\Mcp;
use Crab\Mcp\Tests\Fixtures\ListProducts;
use Crab\Mcp\Tests\Fixtures\Orders;
use Crab\Mcp\Tests\Fixtures\RequireKey;
use Crab\Mcp\Tests\Fixtures\WriteHeader;
use Crab\Mcp\ToolCalled;
use Crab\Route;
use PHPUnit\Framework\Attributes\DataProvider;

final class RouteToolsTest extends McpTestCase
{
    /**
     * @param \Closure(\Crab\Mcp\ServerDeclaration): void|null $more
     */
    private static function routed(?\Closure $more = null): App
    {
        return self::app(static function () use ($more): void {
            Route::prefix('/api')
                ->middleware(new RequireKey())
                ->group(static function (): void {
                    Route::get('/projects/{id:\d{1,9}}/orders', [Orders::class, 'index']);
                    Route::post('/projects/{id:\d+}/orders', [Orders::class, 'create']);
                    Route::delete('/orders/{id:\d+}', [Orders::class, 'delete']);
                    Route::get('/orders/{id:\d+}', [Orders::class, 'show']);
                    Route::get('/orders', [Orders::class, 'export']);
                    Route::get('/whoami', [Orders::class, 'whoami']);
                    Route::get('/hidden', [Orders::class, 'notATool']);
                });
            $server = Mcp::server('/mcp')->fromRoutes();
            if ($more !== null) {
                $more($server);
            }
        });
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private static function call(
        App $app,
        string $tool,
        array $arguments = [],
        array $headers = ['X-Key' => 'open'],
    ): array {
        $response = self::send(
            $app,
            'tools/call',
            self::params(['name' => $tool, 'arguments' => $arguments]),
            $headers,
        );
        return self::decode($response);
    }

    public function testRoutesMarkedAsToolsAreListedWithOneSchemaOfPathQueryAndBody(): void
    {
        $tools = self::answer(self::routed(), 'tools/list')['tools'];

        self::assertSame(
            ['orders_list', 'order_create', 'order_delete', 'order_show', 'orders_export', 'whoami'],
            array_column($tools, 'name'),
        );
        self::assertSame(
            [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer', 'default' => 10],
                    'status' => ['type' => ['string', 'null'], 'default' => null],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            $tools[0]['inputSchema'],
        );
        self::assertSame(['id', 'item', 'quantity'], array_keys($tools[1]['inputSchema']['properties']));
        self::assertSame(['id', 'item', 'quantity'], $tools[1]['inputSchema']['required']);
        self::assertSame(['readOnlyHint' => false, 'destructiveHint' => true], $tools[2]['annotations']);
    }

    public function testACallIsARequestToTheRouteThroughItsMiddleware(): void
    {
        $app = self::routed();

        $refused = self::call($app, 'orders_list', ['id' => 7], headers: []);
        $let = self::call($app, 'orders_list', ['id' => 7, 'per_page' => 2, 'status' => 'open']);

        // The route's own guard answers a call without the key, as it answers a request without it.
        self::assertTrue($refused['result']['isError']);
        self::assertSame(['message' => 'No key.'], $refused['result']['structuredContent']);
        self::assertSame(['project' => 7, 'per_page' => 2, 'status' => 'open'], $let['result']['structuredContent']);
        self::assertFalse($let['result']['isError']);
    }

    public function testABodyIsSentAsJson(): void
    {
        $result = self::call(self::routed(), 'order_create', ['id' => 3, 'item' => 'Kite', 'quantity' => 2])['result'];

        self::assertSame(['project' => 3, 'item' => 'Kite', 'quantity' => 2], $result['structuredContent']);
    }

    public function testTheRoutesValidationIsTheToolsError(): void
    {
        $result = self::call(self::routed(), 'order_create', ['id' => 3, 'item' => 'Kite', 'quantity' => 0])['result'];

        self::assertTrue($result['isError']);
        self::assertSame(422, $result['structuredContent']['error']['status']);
        self::assertStringContainsString('quantity', $result['content'][0]['text']);
    }

    public function testAnAnswerWithoutABodyIsNoContent(): void
    {
        $result = self::call(self::routed(), 'order_delete', ['id' => 3])['result'];

        self::assertSame([], $result['content']);
    }

    public function testAClientErrorIsTheToolsError(): void
    {
        $result = self::call(self::routed(), 'order_show', ['id' => 404])['result'];

        self::assertTrue($result['isError']);
        self::assertSame('There is no order 404.', $result['structuredContent']['error']['message']);
    }

    public function testAServerErrorIsAnInternalErrorThatSaysNothing(): void
    {
        $response = self::send(
            self::routed(),
            'tools/call',
            self::params(['name' => 'order_show', 'arguments' => ['id' => 500]]),
            ['X-Key' => 'open'],
        );

        self::assertSame(['code' => -32_603, 'message' => 'Internal error.'], self::decode($response)['error']);
        self::assertStringNotContainsString('database', (string) $response->getBody());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badArguments(): iterable
    {
        yield 'unknown' => [
            ['id' => 1, 'colour' => 'red'],
            'colour is not an argument of orders_list; it takes id, per_page, status.',
        ];
        yield 'no id' => [[], 'id is required, as a string or an integer.'];
        yield 'an id of another form' => [['id' => '1/../2'], 'id is not of the form this tool takes.'];
        yield 'too long for the expression' => [['id' => 1_234_567_890], 'id is not of the form this tool takes.'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('badArguments')]
    public function testArgumentsThatCannotMakeTheRequestAreTheToolsError(array $arguments, string $message): void
    {
        $result = self::call(self::routed(), 'orders_list', $arguments)['result'];

        self::assertTrue($result['isError']);
        self::assertSame($message, $result['content'][0]['text']);
    }

    public function testTheCallCarriesTheCallersHeadersAndAddressButNotTheTransports(): void
    {
        $result = self::call(self::routed(), 'whoami')['result'];

        self::assertSame(['tool' => 'whoami', 'key' => 'open', 'ip' => '', 'mcp' => ''], $result['structuredContent']);
    }

    public function testAFilterHidesToolsFromTheListButTheRouteDecidesTheCall(): void
    {
        $app = self::routed(static fn($server) => $server->filter(WriteHeader::class));

        $reader = self::send($app, 'tools/list', self::params(), ['X-Key' => 'open']);
        $writer = self::send($app, 'tools/list', self::params(), ['X-Key' => 'open', 'X-Write' => 'yes']);
        $call = self::call($app, 'order_delete', ['id' => 1]);

        $listed = self::decode($reader)['result'];
        self::assertSame(
            ['orders_list', 'order_show', 'orders_export', 'whoami'],
            array_column($listed['tools'], 'name'),
        );
        self::assertSame('private', $listed['cacheScope']);
        self::assertCount(6, self::decode($writer)['result']['tools']);
        // The route has no guard of its own here, so the call it is given goes through.
        self::assertFalse($call['result']['isError']);
    }

    public function testEveryCallIsDispatchedAsToolCalled(): void
    {
        $app = self::routed();
        $app->register(EventsPlugin::class);
        $seen = [];
        $app->container->get(Dispatcher::class)->listen(ToolCalled::class, static function (ToolCalled $event) use (
            &$seen,
        ): void {
            $seen[] = [
                $event->tool->name,
                $event->arguments,
                $event->failed,
                $event->message,
                $event->request->getHeaderLine('X-Key'),
            ];
        });

        self::call($app, 'order_create', ['id' => 3, 'item' => 'Kite', 'quantity' => 1]);
        self::call($app, 'order_show', ['id' => 404]);
        self::call($app, 'order_show', ['id' => 500]);

        self::assertSame(
            [
                ['order_create', ['id' => 3, 'item' => 'Kite', 'quantity' => 1], false, null, 'open'],
                [
                    'order_show',
                    ['id' => 404],
                    true,
                    '{"error":{"status":404,"message":"There is no order 404."}}',
                    'open',
                ],
                ['order_show', ['id' => 500], true, 'Internal error.', 'open'],
            ],
            $seen,
        );
    }

    public function testAListenerThatFailsChangesNothingOfTheAnswer(): void
    {
        $app = self::routed();
        $app->register(EventsPlugin::class);
        $app->container->get(Dispatcher::class)->listen(ToolCalled::class, static function (): void {
            throw new \RuntimeException('the activity log is full');
        });

        $result = self::call($app, 'orders_list', ['id' => 1])['result'];

        self::assertSame(1, $result['structuredContent']['project']);
    }

    public function testAResultOverTheLimitIsRefusedWithWhatAsksForLess(): void
    {
        $app = self::routed(static fn($server) => $server->maxResult(2_000));

        $large = self::call($app, 'orders_export')['result'];
        $small = self::call($app, 'orders_export', ['limit' => 2])['result'];

        self::assertTrue($large['isError']);
        self::assertMatchesRegularExpression(
            '/^The result is \d+ bytes, more than the 2000 this server sends\. Ask for less, with limit\.$/',
            $large['content'][0]['text'],
        );
        self::assertCount(2, $small['structuredContent']);
    }

    public function testTwoRoutesOfOneToolAreRefused(): void
    {
        $app = self::app(static function (): void {
            Route::get('/orders', [Orders::class, 'export']);
            Mcp::server('/mcp')->fromRoutes();
            Route::get('/again', [Orders::class, 'export']);
        });

        $this->expectExceptionMessage(
            'has two tools named orders_export, in the route GET /orders and the route GET /again',
        );

        $app->define();
    }

    public function testDescribeNamesARouteToolsRoute(): void
    {
        $lines = self::routed()->declared()['mcp'];

        self::assertContains('tool /mcp orders_list GET /api/projects/{id:\d{1,9}}/orders', $lines);
    }

    public function testAnOldClientGetsAListOrAValueAsTheResultOfAnObject(): void
    {
        $app = self::routed(static fn($server) => $server->tools(ListProducts::class));
        $body = static fn(string $tool, array $arguments): array => self::decode(self::post(
            $app,
            (string) json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments],
            ]),
            ['Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2025-06-18', 'X-Key' => 'open'],
        ))['result'];

        $list = $body('list_products', []);
        $object = $body('orders_list', ['id' => 1]);

        self::assertSame(
            [['id' => 1, 'name' => 'Hammer'], ['id' => 2, 'name' => 'Kite']],
            $list['structuredContent']['result'],
        );
        self::assertStringContainsString('Hammer', $list['content'][0]['text']);
        self::assertSame(1, $object['structuredContent']['project']);
    }
}
