<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests;

final class ResourcesTest extends McpTestCase
{
    public function testFixedResourcesAreListed(): void
    {
        $result = self::answer(self::app(), 'resources/list');

        self::assertSame(
            [[
                'uri' => 'shop://notes',
                'name' => 'notes',
                'description' => 'What the shop keeps in mind.',
                'mimeType' => 'text/plain',
            ]],
            $result['resources'],
        );
        self::assertSame('public', $result['cacheScope']);
    }

    public function testTemplatesAreListedApart(): void
    {
        $result = self::answer(self::app(), 'resources/templates/list');

        self::assertSame(
            [[
                'uriTemplate' => 'shop://products/{id}',
                'name' => 'product',
                'description' => 'One product, as JSON.',
                'mimeType' => 'application/json',
            ]],
            $result['resourceTemplates'],
        );
    }

    public function testAFixedResourceIsReadWithItsCacheHints(): void
    {
        $result = self::answer(self::app(), 'resources/read', ['uri' => 'shop://notes']);

        self::assertSame(
            [['uri' => 'shop://notes', 'mimeType' => 'text/plain', 'text' => "Open 9 to 5.\n"]],
            $result['contents'],
        );
        self::assertSame(60_000, $result['ttlMs']);
        self::assertSame('public', $result['cacheScope']);
    }

    public function testATemplatesPartsAreTheActionsParameters(): void
    {
        $result = self::answer(self::app(), 'resources/read', ['uri' => 'shop://products/1']);

        self::assertSame(
            [['uri' => 'shop://products/1', 'mimeType' => 'application/json', 'text' => '{"id":1,"name":"Hammer"}']],
            $result['contents'],
        );
        self::assertSame(0, $result['ttlMs']);
        self::assertSame('private', $result['cacheScope']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missing(): iterable
    {
        yield 'returned null' => ['shop://products/2'];
        yield 'not an int' => ['shop://products/hammer'];
        yield 'no resource' => ['shop://orders/1'];
        yield 'a part with a slash' => ['shop://products/1/2'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missing')]
    public function testAResourceNotThereIsInvalidParams(string $uri): void
    {
        $response = self::send(self::app(), 'resources/read', self::params(['uri' => $uri]));

        self::assertSame(200, $response->getStatusCode());
        $error = self::decode($response)['error'];
        self::assertSame(-32_602, $error['code']);
        self::assertStringContainsString("Resource not found: {$uri}", $error['message']);
    }
}
