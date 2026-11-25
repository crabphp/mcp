<?php

declare(strict_types=1);

namespace Crab\Mcp;

/**
 * Marks a class with handle() as a tool an MCP server can list. The class
 * is an action like any route's: handle() takes the arguments as one input
 * class or a #[Fields] Data, validated against its types and rules before
 * it runs, and the user and the request by their types.
 *
 *     #[Tool('show_product', 'Shows one product by its id.', readOnly: true)]
 *     final readonly class ShowProduct
 *     {
 *         public function handle(ProductId $input): Product { ... }
 *     }
 *
 * The hints are what a client may show or weigh; they promise nothing.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/server/tools#tool
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Tool
{
    /**
     * @param string $name How the client calls it: 1 to 128 letters, digits, "_", "-" and ".", unique on its server.
     * @param string $description What it does and when to use it, for the model.
     * @param string|null $title A name for people.
     * @param bool|null $readOnly It changes nothing.
     * @param bool|null $destructive What it changes it may delete or overwrite.
     * @param bool|null $idempotent Calling it again with the same arguments changes nothing more.
     * @param bool|null $openWorld It reaches outside the application, as a web search does.
     */
    public function __construct(
        public string $name,
        public string $description,
        public ?string $title = null,
        public ?bool $readOnly = null,
        public ?bool $destructive = null,
        public ?bool $idempotent = null,
        public ?bool $openWorld = null,
    ) {}
}
