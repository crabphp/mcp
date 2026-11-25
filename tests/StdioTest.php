<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests;

use Crab\Contracts\Http\Factory;
use Crab\Mcp\McpDefinition;
use Crab\Mcp\Stdio\StdioTransport;

final class StdioTest extends McpTestCase
{
    /**
     * @param list<string> $lines
     * @return list<array<string, mixed>>
     */
    private static function serve(array $lines, int $maxLine = 1_000_000): array
    {
        $app = self::app();
        $app->define();
        $server = $app->container->get(McpDefinition::class)->servers()['/mcp'];
        $input = fopen('php://memory', 'r+');
        self::assertIsResource($input);
        fwrite($input, implode("\n", $lines) . "\n");
        rewind($input);
        $written = [];
        new StdioTransport(
            $server,
            $app->container->get(Factory::class),
            $maxLine,
        )->run($input, static function (string $line) use (&$written): void {
            $written[] = $line;
        });
        foreach ($written as $line) {
            self::assertStringEndsWith("\n", $line);
            self::assertStringNotContainsString("\n", substr($line, 0, -1));
        }
        return array_map(static fn(string $line): array => json_decode(
            $line,
            true,
            flags: JSON_THROW_ON_ERROR,
        ), $written);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function line(string $method, array $params = [], int|string|null $id = 1): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            ...($id === null ? [] : ['id' => $id]),
            'method' => $method,
            'params' => $params === [] ? new \stdClass() : $params,
        ], JSON_THROW_ON_ERROR);
    }

    public function testEachLineIsAnsweredInTurn(): void
    {
        $replies = self::serve([
            self::line('server/discover', self::params(), 'a'),
            '',
            self::line('notifications/cancelled', ['requestId' => 'a'], null),
            self::line('tools/call', self::params(['name' => 'show_product', 'arguments' => ['id' => 2]]), 'b'),
        ]);

        self::assertCount(2, $replies);
        self::assertSame('a', $replies[0]['id']);
        self::assertSame('2026-07-28', $replies[0]['result']['supportedVersions'][0]);
        self::assertSame('b', $replies[1]['id']);
        self::assertSame(2, $replies[1]['result']['structuredContent']['id']);
    }

    public function testProgressIsWrittenAsItIsMade(): void
    {
        $replies = self::serve([
            self::line('tools/call', self::params(['name' => 'countdown', 'arguments' => ['from' => 2]], [
                'progressToken' => 9,
            ])),
        ]);

        self::assertSame(
            ['notifications/progress', 'notifications/progress'],
            array_column(array_slice($replies, 0, 2), 'method'),
        );
        self::assertSame('Lift-off', $replies[2]['result']['content'][0]['text']);
    }

    public function testALegacyClientInitializesAndIsServed(): void
    {
        $replies = self::serve([
            self::line('initialize', [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'Old', 'version' => '1'],
            ]),
            self::line('notifications/initialized', [], null),
            self::line('tools/list', [], 2),
        ]);

        self::assertCount(2, $replies);
        self::assertSame('2025-06-18', $replies[0]['result']['protocolVersion']);
        self::assertCount(6, $replies[1]['result']['tools']);
        self::assertArrayNotHasKey('resultType', $replies[1]['result']);
    }

    public function testAModernRequestWithoutCapabilitiesIsRefused(): void
    {
        $params = self::params();
        unset($params['_meta']['io.modelcontextprotocol/clientCapabilities']);

        $replies = self::serve([self::line('tools/list', $params)]);

        self::assertSame(-32_602, $replies[0]['error']['code']);
    }

    public function testABadLineIsAnsweredAndTheNextIsRead(): void
    {
        $replies = self::serve(['{nope', self::line('server/discover', self::params())]);

        self::assertSame(
            ['jsonrpc' => '2.0', 'error' => ['code' => -32_700, 'message' => 'The message is not valid JSON.']],
            $replies[0],
        );
        self::assertSame(1, $replies[1]['id']);
    }

    public function testALineTooLongIsRefusedAndSkipped(): void
    {
        $replies = self::serve([str_repeat('x', 900), self::line('server/discover', self::params())], maxLine: 400);

        self::assertCount(2, $replies);
        self::assertSame(-32_700, $replies[0]['error']['code']);
        self::assertSame('A message is at most 400 bytes.', $replies[0]['error']['message']);
        self::assertSame(1, $replies[1]['id']);
    }

    public function testAToolThatTakesAUserIsRefusedOne(): void
    {
        $replies = self::serve([self::line('tools/call', self::params(['name' => 'who_am_i']))]);

        self::assertTrue($replies[0]['result']['isError']);
        self::assertSame('Authentication is required.', $replies[0]['result']['content'][0]['text']);
    }

    public function testArgumentsWrittenAsAListAreRefused(): void
    {
        $replies = self::serve([
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"list_products","arguments":[]}}',
        ]);

        self::assertSame(-32_602, $replies[0]['error']['code']);
    }
}
