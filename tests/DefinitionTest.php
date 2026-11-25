<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests;

use Crab\App;
use Crab\Config\Config;
use Crab\Mcp\Mcp;
use Crab\Mcp\McpDefinition;
use Crab\Mcp\Servers;
use Crab\Mcp\Tests\Fixtures\Duplicate;
use Crab\Mcp\Tests\Fixtures\ListProducts;
use Crab\Mcp\Tests\Fixtures\Notes;
use Crab\Mcp\Tests\Fixtures\ShowProduct;
use Crab\Mcp\Tests\Fixtures\Unmarked;
use PHPUnit\Framework\Attributes\DataProvider;

final class DefinitionTest extends McpTestCase
{
    /**
     * @return iterable<string, array{\Closure, string}>
     */
    public static function refused(): iterable
    {
        yield 'a tool not marked' => [
            static fn() => Mcp::server('/mcp')->tools(Unmarked::class),
            'is not marked as one. Add #[Tool(',
        ];
        yield 'two tools of one name' => [
            static fn() => Mcp::server('/mcp')->tools([ListProducts::class, Duplicate::class]),
            'has two tools named list_products',
        ];
        yield 'a tool that is not a class' => [
            static fn() => Mcp::server('/mcp')->tools('App\Nothing'),
            'is not a class',
        ];
        yield 'a resource not marked' => [
            static fn() => Mcp::server('/mcp')->resources(ShowProduct::class),
            'Add #[Resource(',
        ];
        yield 'a resource twice' => [
            static fn() => Mcp::server('/mcp')->resources([Notes::class, Notes::class]),
            'has two resources at shop://notes',
        ];
        yield 'a bad origin' => [
            static fn() => Mcp::server('/mcp')->origins('example.com'),
            'an origin is a scheme and host',
        ];
        yield 'a missing skills directory' => [
            static fn() => Mcp::server('/mcp')->skills('/nowhere/skills'),
            'is not there',
        ];
        yield 'relative skills without a folder' => [
            static fn() => Mcp::server('/mcp')->skills('resources/skills'),
            'the application has no folder',
        ];
    }

    #[DataProvider('refused')]
    public function testWhatCannotBeServedStopsTheApplicationWhenDefined(\Closure $declare, string $message): void
    {
        $app = self::app($declare);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);

        $app->define();
    }

    public function testAServerDeclaredAgainIsTheSameOne(): void
    {
        $app = self::app(static function (): void {
            Mcp::server('/mcp')->tools(ListProducts::class);
        });
        $app->container->get(Servers::class)->server('/mcp')->tools(ShowProduct::class);
        $app->define();

        $servers = $app->container->get(McpDefinition::class)->servers();

        self::assertSame(['/mcp'], array_keys($servers));
        self::assertSame(['list_products', 'show_product'], array_keys($servers['/mcp']->tools));
    }

    public function testAPathIsAPath(): void
    {
        $this->expectExceptionMessage("An MCP server's path is a path such as /mcp");

        self::app(static fn() => Mcp::server('mcp'));
    }

    public function testADeclarationIsClosedOnceRead(): void
    {
        $declaration = null;
        $app = self::app(static function () use (&$declaration): void {
            $declaration = Mcp::server('/mcp');
        });
        $app->define();

        $this->expectExceptionMessage('has been read already');

        $declaration?->tools(ListProducts::class);
    }

    public function testWithoutThePluginServerSaysWhatToAdd(): void
    {
        new App(new Config(['log' => ['path' => 'php://memory']]));

        $this->expectExceptionMessage('Add Crab\Mcp\McpPlugin to withPlugins() in app.php.');

        Mcp::server('/mcp');
    }

    public function testDescribeListsWhatEachServerOffers(): void
    {
        $app = self::app();

        $declared = $app->declared();

        self::assertSame(
            [
                'server /mcp Shop 1.2.0',
                'tool /mcp show_product Crab\Mcp\Tests\Fixtures\ShowProduct',
                'tool /mcp list_products Crab\Mcp\Tests\Fixtures\ListProducts',
                'tool /mcp countdown Crab\Mcp\Tests\Fixtures\Countdown',
                'tool /mcp refuse Crab\Mcp\Tests\Fixtures\Refuse',
                'tool /mcp crash Crab\Mcp\Tests\Fixtures\Crash',
                'tool /mcp who_am_i Crab\Mcp\Tests\Fixtures\WhoAmI',
                'resource /mcp shop://notes Crab\Mcp\Tests\Fixtures\Notes',
                'resource /mcp shop://products/{id} Crab\Mcp\Tests\Fixtures\ProductResource',
                'skill /mcp code-review skill://code-review/SKILL.md',
                'skill /mcp pdf-tools skill://pdf-tools/SKILL.md',
            ],
            $declared['mcp'],
        );
    }

    public function testEveryClassATransportMakesIsCheckedBeforeServing(): void
    {
        $app = self::app();
        $app->define();

        $entries = $app->container->get(McpDefinition::class)->entries();

        self::assertSame('Tool show_product (MCP server /mcp)', $entries[ShowProduct::class]);
        self::assertSame('Resource shop://notes (MCP server /mcp)', $entries[Notes::class]);
        self::assertSame([], $app->unmade());
    }

    public function testTheEndpointIsARouteOfTheApplication(): void
    {
        $app = self::app();
        $app->define();

        $routes = array_map(static fn($route): string => "{$route->method} {$route->pattern}", $app->router->routes());

        self::assertContains('POST /mcp', $routes);
    }

    public function testAServersToolsCanBeReadOnceDefined(): void
    {
        $app = self::app();
        $app->define();

        $tools = $app->container->get(Servers::class)->tools('/mcp');

        self::assertSame('Shows one product by its id.', $tools['show_product']->description);
        self::assertTrue($tools['show_product']->readOnly);
    }
}
