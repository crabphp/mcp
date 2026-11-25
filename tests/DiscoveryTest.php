<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests;

use Crab\App;
use Crab\Mcp\Mcp;
use Crab\Mcp\Tests\Fixtures\Orders;
use Crab\Mcp\Tests\Fixtures\RequireKey;
use Crab\Mcp\Tests\Fixtures\WriteHeader;
use Crab\Route;

final class DiscoveryTest extends McpTestCase
{
    private static function discovering(bool $filtered = false): App
    {
        return self::app(static function () use ($filtered): void {
            Route::middleware(new RequireKey())->group(static function (): void {
                Route::get('/projects/{id:\d+}/orders', [Orders::class, 'index']);
                Route::post('/projects/{id:\d+}/orders', [Orders::class, 'create']);
                Route::delete('/orders/{id:\d+}', [Orders::class, 'delete']);
                Route::get('/orders/{id:\d+}', [Orders::class, 'show']);
                Route::get('/whoami', [Orders::class, 'whoami']);
            });
            $server = Mcp::server('/mcp')
                ->fromRoutes()
                ->discovery('shop_search', 'shop_call', always: ['whoami'], groups: ['order' => 'orders']);
            if ($filtered) {
                $server->filter(WriteHeader::class);
            }
        });
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private static function call(App $app, string $tool, array $arguments = [], array $headers = []): array
    {
        $response = self::send($app, 'tools/call', self::params(['name' => $tool, 'arguments' => $arguments]), [
            'X-Key' => 'open',
            ...$headers,
        ]);
        return self::decode($response)['result'];
    }

    public function testTheListIsTheToolsAlwaysListedAndDiscoverysOwn(): void
    {
        $tools = self::answer(self::discovering(), 'tools/list')['tools'];

        self::assertSame(['whoami', 'shop_search', 'shop_call'], array_column($tools, 'name'));
        self::assertSame(['orders', 'whoami'], $tools[1]['inputSchema']['properties']['group']['enum']);
        self::assertStringContainsString('The groups are orders, whoami.', $tools[1]['description']);
    }

    public function testEveryToolOrSomeGroupsAreListedWhenAsked(): void
    {
        $app = self::discovering();

        $all = self::decode(self::send($app, 'tools/list', self::params(), path: '/mcp?tools=all'))['result']['tools'];
        $orders = self::decode(self::send(
            $app,
            'tools/list',
            self::params(),
            path: '/mcp?tools=Orders',
        ))['result']['tools'];

        self::assertSame(
            ['order_create', 'order_delete', 'order_show', 'orders_list', 'whoami'],
            array_column($all, 'name'),
        );
        self::assertSame(
            ['order_create', 'order_delete', 'order_show', 'orders_list', 'whoami', 'shop_search', 'shop_call'],
            array_column($orders, 'name'),
        );
    }

    public function testSearchFindsToolsByWordsWithTheirSchemas(): void
    {
        $found = self::call(self::discovering(), 'shop_search', ['query' => 'delete orders'])['structuredContent'];

        self::assertSame('order_delete', $found['tools'][0]['name']);
        self::assertFalse($found['tools'][0]['readOnly']);
        self::assertArrayHasKey('inputSchema', $found['tools'][0]);
    }

    public function testSearchByGroupGivesTheWholeGroup(): void
    {
        $found = self::call(self::discovering(), 'shop_search', ['group' => 'orders'])['structuredContent'];

        self::assertSame(
            ['order_create', 'order_delete', 'order_show', 'orders_list'],
            array_column($found['tools'], 'name'),
        );
    }

    public function testSearchWithNothingGivesEveryGroupsNames(): void
    {
        $found = self::call(self::discovering(), 'shop_search')['structuredContent'];

        self::assertSame(
            ['orders' => ['order_create', 'order_delete', 'order_show', 'orders_list'], 'whoami' => ['whoami']],
            $found['groups'],
        );
    }

    public function testSearchRefusesWhatItDoesNotTake(): void
    {
        $result = self::call(self::discovering(), 'shop_search', ['limit' => 99]);

        self::assertTrue($result['isError']);
    }

    public function testCallRunsTheToolFound(): void
    {
        $result = self::call(self::discovering(), 'shop_call', ['tool' => 'orders_list', 'arguments' => ['id' => 4]]);

        self::assertSame(4, $result['structuredContent']['project']);
    }

    public function testCallOfAnUnknownToolSaysWhereToFindOne(): void
    {
        $result = self::call(self::discovering(), 'shop_call', ['tool' => 'nope']);

        self::assertTrue($result['isError']);
        self::assertSame('Unknown tool: nope. Find tools with shop_search.', $result['content'][0]['text']);
    }

    public function testAToolIsStillCalledByItsName(): void
    {
        $result = self::call(self::discovering(), 'order_show', ['id' => 9]);

        self::assertSame(['id' => 9], $result['structuredContent']);
    }

    public function testTheFilterHoldsForSearch(): void
    {
        $app = self::discovering(filtered: true);

        $found = self::call($app, 'shop_search', ['group' => 'orders'])['structuredContent'];
        $writer = self::call($app, 'shop_search', ['group' => 'orders'], ['X-Write' => 'yes'])['structuredContent'];

        self::assertSame(['order_show', 'orders_list'], array_column($found['tools'], 'name'));
        self::assertCount(4, $writer['tools']);
    }

    public function testADiscoveryToolMayNotShadowATool(): void
    {
        $app = self::app(static function (): void {
            Route::get('/whoami', [Orders::class, 'whoami']);
            Mcp::server('/mcp')->fromRoutes()->discovery(search: 'whoami');
        });

        $this->expectExceptionMessage('names a discovery tool whoami, which is the name of another tool.');

        $app->define();
    }

    public function testAToolAlwaysListedMustBeThere(): void
    {
        $app = self::app(static fn() => Mcp::server('/mcp')->discovery(always: ['projects_list']));

        $this->expectExceptionMessage('always lists the tool projects_list, which it does not have.');

        $app->define();
    }

    public function testTheApplicationsOwnOriginIsTakenWithDiscovery(): void
    {
        $app = self::app(
            static fn() => Mcp::server('/mcp')
                ->tools(\Crab\Mcp\Tests\Fixtures\ListProducts::class)
                ->discovery(),
            ['app' => ['url' => 'http://127.0.0.1:8080']],
        );

        $response = self::send($app, 'tools/list', self::params(), ['Origin' => 'http://127.0.0.1:8080']);

        self::assertSame(200, $response->getStatusCode());
    }
}
