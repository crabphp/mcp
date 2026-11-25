<?php

declare(strict_types=1);

namespace Crab\Mcp\Skills;

/**
 * One skill: its name, the URI of its SKILL.md, the fields of its
 * frontmatter as written, and its files, SKILL.md first.
 *
 * @internal
 */
final readonly class Skill
{
    /**
     * @param array<string, mixed> $frontmatter
     * @param list<SkillFile> $files
     */
    public function __construct(
        public string $name,
        public string $uri,
        public array $frontmatter,
        public array $files,
    ) {}

    /**
     * The entry skills/list and skills/get send: the frontmatter
     * unchanged, and the manifest of every file's URI, digest and size.
     *
     * @return array<string, mixed>
     */
    public function entry(): array
    {
        return [
            'uri' => $this->uri,
            'frontmatter' => (object) $this->frontmatter,
            'resources' => array_map(static fn(SkillFile $file): array => [
                'uri' => $file->uri,
                'digest' => $file->digest(),
                'size' => $file->size(),
            ], $this->files),
        ];
    }
}
