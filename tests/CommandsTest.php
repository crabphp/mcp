<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests;

use Crab\App;
use Crab\Console\Output;
use Crab\Mcp\Mcp;
use Crab\Mcp\Tests\Fixtures\ListProducts;

final class CommandsTest extends McpTestCase
{
    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private static function console(App $app, array $arguments): array
    {
        $out = fopen('php://memory', 'r+');
        $error = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($error);
        $app->define();
        $status = $app->console->run($arguments, new Output($out, $error));
        rewind($out);
        rewind($error);
        return [$status, (string) stream_get_contents($out), (string) stream_get_contents($error)];
    }

    public function testListShowsEachServersToolsResourcesAndSkills(): void
    {
        [$status, $out] = self::console(self::app(), ['mcp:list']);

        self::assertSame(0, $status);
        self::assertStringContainsString('/mcp  Shop 1.2.0', $out);
        self::assertMatchesRegularExpression(
            '/tool\s+show_product\s+Crab\\\\Mcp\\\\Tests\\\\Fixtures\\\\ShowProduct/',
            $out,
        );
        self::assertMatchesRegularExpression('/template\s+shop:\/\/products\/\{id\}/', $out);
        self::assertMatchesRegularExpression('/skill\s+code-review\s+skill:\/\/code-review\/SKILL\.md/', $out);
    }

    public function testServeWithoutAServerSaysHowToDeclareOne(): void
    {
        [$status, $out, $error] = self::console(self::app(static function (): void {}), ['mcp:serve']);

        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringContainsString("Mcp::server('/mcp')", $error);
    }

    public function testServeAmongSeveralNeedsAPath(): void
    {
        $app = self::app(static function (): void {
            Mcp::server('/a')->tools(ListProducts::class);
            Mcp::server('/b')->tools(ListProducts::class);
        });

        [$status, $out, $error] = self::console($app, ['mcp:serve']);

        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('say which with --path=/a', $error);
    }

    public function testServeRefusesAPathWithNoServer(): void
    {
        [$status, , $error] = self::console(self::app(), ['mcp:serve', '--path=/other']);

        self::assertSame(1, $status);
        self::assertStringContainsString('No MCP server is declared at /other; use --path= with one of /mcp.', $error);
    }

    public function testServeRefusesAPathOptionWithoutAValue(): void
    {
        [$status, , $error] = self::console(self::app(), ['mcp:serve', '--path']);

        self::assertSame(1, $status);
        self::assertStringContainsString('--path', $error);
    }
}
