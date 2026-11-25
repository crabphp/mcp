<?php

declare(strict_types=1);

namespace Crab\Mcp;

/**
 * How far a tool has got, yielded by a handle() that is a generator. The
 * generator's return value is the tool's result. Each is sent to the
 * client as it is yielded, when the client asked to be told; the progress
 * must grow from one to the next.
 *
 *     public function handle(ImportInput $input): \Generator
 *     {
 *         foreach ($input->files as $i => $file) {
 *             yield new Progress($i, count($input->files), "Importing {$file}");
 *             ...
 *         }
 *         return 'Imported ' . count($input->files) . ' files.';
 *     }
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/patterns/progress
 */
final readonly class Progress
{
    /**
     * @param int|float $progress How much is done so far.
     * @param int|float|null $total How much there is to do, when that is known.
     */
    public function __construct(
        public int|float $progress,
        public int|float|null $total = null,
        public ?string $message = null,
    ) {}
}
