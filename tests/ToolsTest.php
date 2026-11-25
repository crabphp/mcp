<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests;

use Crab\Mcp\Mcp;
use Crab\Mcp\Tests\Fixtures\Countdown;
use Crab\Mcp\Tests\Fixtures\Crash;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class ToolsTest extends McpTestCase
{
    public function testToolsAreListedFromTheirAttributeAndSignature(): void
    {
        $tools = self::answer(self::app(), 'tools/list')['tools'];

        self::assertSame(
            ['show_product', 'list_products', 'countdown', 'refuse', 'crash', 'who_am_i'],
            array_column($tools, 'name'),
        );
        self::assertSame(
            [
                'name' => 'show_product',
                'title' => 'Show product',
                'description' => 'Shows one product by its id.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
                    'required' => ['id'],
                ],
                'outputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                        'kind' => ['type' => 'string', 'enum' => ['tool', 'toy']],
                        'added' => ['type' => 'string', 'format' => 'date-time'],
                    ],
                    'required' => ['id', 'name', 'kind', 'added'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ],
            $tools[0],
        );
        self::assertSame(['destructiveHint' => false], $tools[3]['annotations']);
    }

    public function testAToolWithoutInputTakesAnEmptyObject(): void
    {
        $response = self::send(self::app(), 'tools/list', self::params());

        self::assertStringContainsString(
            '{"name":"list_products","description":"Lists every product.","inputSchema":{"type":"object","properties":{}}}',
            (string) $response->getBody(),
        );
    }

    public function testATypedResultIsStructuredContentAndItsText(): void
    {
        $result = self::answer(self::app(), 'tools/call', ['name' => 'show_product', 'arguments' => ['id' => 7]]);

        $product = ['id' => 7, 'name' => 'Hammer', 'kind' => 'tool', 'added' => '2026-01-02T03:04:05+00:00'];
        self::assertSame($product, $result['structuredContent']);
        self::assertSame(
            [['type' => 'text', 'text' => json_encode($product, JSON_UNESCAPED_SLASHES)]],
            $result['content'],
        );
        self::assertArrayNotHasKey('isError', $result);
    }

    public function testDataWithoutAClassIsStructuredWithNoOutputSchema(): void
    {
        $app = self::app();

        $listed = self::answer($app, 'tools/list')['tools'][1];
        $result = self::answer($app, 'tools/call', ['name' => 'list_products']);

        self::assertArrayNotHasKey('outputSchema', $listed);
        self::assertSame(
            [['id' => 1, 'name' => 'Hammer'], ['id' => 2, 'name' => 'Kite']],
            $result['structuredContent'],
        );
    }

    public function testArgumentsThatDoNotFitAreAnErrorTheModelCanRead(): void
    {
        $result = self::answer(self::app(), 'tools/call', ['name' => 'show_product', 'arguments' => ['id' => 0]]);

        self::assertTrue($result['isError']);
        self::assertSame("The request is invalid.\nid: must be at least 1", $result['content'][0]['text']);
    }

    public function testMissingArgumentsAreReportedByField(): void
    {
        $result = self::answer(self::app(), 'tools/call', ['name' => 'show_product']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('id: ', $result['content'][0]['text']);
    }

    public function testAToolErrorIsTheToolsAnswer(): void
    {
        $result = self::answer(self::app(), 'tools/call', ['name' => 'refuse']);

        self::assertSame(
            [
                'resultType' => 'complete',
                'content' => [['type' => 'text', 'text' => 'The shop is closed on Sundays.']],
                'isError' => true,
            ],
            array_diff_key($result, ['_meta' => true]),
        );
    }

    public function testAClientErrorTheToolThrowsIsTheToolsAnswer(): void
    {
        $result = self::answer(self::app(), 'tools/call', ['name' => 'show_product', 'arguments' => ['id' => 404]]);

        self::assertTrue($result['isError']);
        self::assertSame('There is no product 404.', $result['content'][0]['text']);
    }

    public function testAFailureIsLoggedAndSaysNothingOfItself(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var list<array{string, \Stringable|string, array<mixed>}>
             */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, $message, $context];
            }
        };
        $app = self::app(static function (): void {
            Mcp::server('/mcp')->tools(Crash::class);
        });
        $app->container->instance(LoggerInterface::class, $logger);

        $response = self::send($app, 'tools/call', self::params(['name' => 'crash']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['code' => -32_603, 'message' => 'Internal error.'], self::decode($response)['error']);
        self::assertStringNotContainsString('password', (string) $response->getBody());
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0][0]);
        self::assertSame('The MCP server /mcp failed to answer tools/call crash.', $logger->records[0][1]);
        self::assertInstanceOf(\RuntimeException::class, $logger->records[0][2]['exception']);
    }

    public function testAnUnknownToolIsInvalidParams(): void
    {
        $response = self::send(self::app(), 'tools/call', self::params(['name' => 'nope']));

        self::assertSame(['code' => -32_602, 'message' => 'Unknown tool: nope.'], self::decode($response)['error']);
    }

    public function testArgumentsThatAreNotAnObjectAreInvalidParams(): void
    {
        $response = self::send(self::app(), 'tools/call', self::params([
            'name' => 'show_product',
            'arguments' => [1, 2],
        ]));

        self::assertSame(-32_602, self::decode($response)['error']['code']);
    }

    public function testProgressIsStreamedBeforeTheResult(): void
    {
        $response = self::send(
            self::app(),
            'tools/call',
            self::params(['name' => 'countdown', 'arguments' => ['from' => 2]], ['progressToken' => 'p1']),
        );

        self::assertStringStartsWith('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame('no', $response->getHeaderLine('X-Accel-Buffering'));
        $messages = self::messages($response);
        self::assertCount(3, $messages);
        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/progress',
                'params' => ['progressToken' => 'p1', 'progress' => 1, 'total' => 2, 'message' => '1 of 2'],
            ],
            $messages[0],
        );
        self::assertSame(2, $messages[1]['params']['progress']);
        self::assertSame(1, $messages[2]['id']);
        self::assertSame([['type' => 'text', 'text' => 'Lift-off']], $messages[2]['result']['content']);
    }

    public function testWithoutAProgressTokenTheResultIsOneObject(): void
    {
        $response = self::send(self::app(), 'tools/call', self::params([
            'name' => 'countdown',
            'arguments' => ['from' => 3],
        ]));

        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('Lift-off', self::decode($response)['result']['content'][0]['text']);
    }

    public function testAClientThatTakesNoEventsGetsOneObject(): void
    {
        $response = self::send(
            self::app(),
            'tools/call',
            self::params(['name' => 'countdown', 'arguments' => ['from' => 3]], ['progressToken' => 1]),
            ['Accept' => 'application/json'],
        );

        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('Lift-off', self::decode($response)['result']['content'][0]['text']);
    }

    public function testAToolMayBeARouteActionToo(): void
    {
        $app = self::app(static function (): void {
            Mcp::server('/mcp')->tools(Countdown::class);
            \Crab\Route::post('/countdown', Countdown::class);
        });

        $route = $app->handle(
            new \Crab\Http\Request('POST', '/countdown', ['Content-Type' => 'application/json'], '{"from":1}'),
        );
        $tool = self::answer($app, 'tools/call', ['name' => 'countdown', 'arguments' => ['from' => 1]]);

        self::assertSame(200, $route->getStatusCode());
        self::assertSame('Lift-off', $tool['content'][0]['text']);
    }
}
