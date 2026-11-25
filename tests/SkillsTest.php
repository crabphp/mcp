<?php

declare(strict_types=1);

namespace Crab\Mcp\Tests;

use Crab\Mcp\Mcp;
use Crab\Mcp\Skills\SkillLibrary;

final class SkillsTest extends McpTestCase
{
    public function testSkillsAreListedWithTheirFrontmatterAndManifest(): void
    {
        $result = self::answer(self::app(), 'skills/list');

        $skill = file_get_contents(self::SKILLS . '/code-review/SKILL.md');
        $checklist = file_get_contents(self::SKILLS . '/code-review/references/checklist.md');
        self::assertSame(
            [
                'uri' => 'skill://code-review/SKILL.md',
                'frontmatter' => [
                    'name' => 'code-review',
                    'description' => "Review code using the team's checklist.",
                    'metadata' => ['owner' => 'platform', 'tags' => ['review', 'quality']],
                ],
                'resources' => [
                    [
                        'uri' => 'skill://code-review/SKILL.md',
                        'digest' => 'sha256:' . hash('sha256', (string) $skill),
                        'size' => strlen((string) $skill),
                    ],
                    [
                        'uri' => 'skill://code-review/references/checklist.md',
                        'digest' => 'sha256:' . hash('sha256', (string) $checklist),
                        'size' => strlen((string) $checklist),
                    ],
                ],
            ],
            $result['skills'][0],
        );
        self::assertSame('pdf-tools', $result['skills'][1]['frontmatter']['name']);
        self::assertSame("Fill and merge PDF forms.\n", $result['skills'][1]['frontmatter']['description']);
        self::assertSame('public', $result['cacheScope']);
        self::assertSame(0, $result['ttlMs']);
    }

    public function testASkillIsGotByItsUri(): void
    {
        $result = self::answer(self::app(), 'skills/get', ['uri' => 'skill://pdf-tools/SKILL.md']);

        self::assertSame('skill://pdf-tools/SKILL.md', $result['skill']['uri']);
        self::assertSame(
            ['skill://pdf-tools/SKILL.md', 'skill://pdf-tools/logo.png'],
            array_column($result['skill']['resources'], 'uri'),
        );
    }

    public function testAnUnknownSkillIsInvalidParams(): void
    {
        $response = self::send(self::app(), 'skills/get', self::params(['uri' => 'skill://nope/SKILL.md']));

        self::assertSame(-32_602, self::decode($response)['error']['code']);
    }

    public function testASkillsFilesAreTheBytesItsDigestIsOf(): void
    {
        $app = self::app();
        $listed = self::answer($app, 'skills/list')['skills'][0]['resources'][1];

        $read = self::answer($app, 'resources/read', ['uri' => 'skill://code-review/references/checklist.md']);

        $contents = $read['contents'][0];
        self::assertSame('text/markdown', $contents['mimeType']);
        self::assertSame($listed['digest'], 'sha256:' . hash('sha256', $contents['text']));
        self::assertSame($listed['size'], strlen($contents['text']));
        self::assertSame('public', $read['cacheScope']);
    }

    public function testBinaryFilesAreSentInBase64(): void
    {
        $read = self::answer(self::app(), 'resources/read', ['uri' => 'skill://pdf-tools/logo.png']);

        self::assertSame('image/png', $read['contents'][0]['mimeType']);
        self::assertSame(
            file_get_contents(self::SKILLS . '/pdf-tools/logo.png'),
            base64_decode($read['contents'][0]['blob'], true),
        );
    }

    public function testDirectoriesListTheirDirectChildren(): void
    {
        $app = self::app();

        $root = self::answer($app, 'resources/directory/read', ['uri' => 'skill://code-review']);
        $references = self::answer($app, 'resources/directory/read', ['uri' => 'skill://code-review/references']);

        self::assertSame(
            [
                ['uri' => 'skill://code-review/SKILL.md', 'name' => 'SKILL.md', 'mimeType' => 'text/markdown'],
                ['uri' => 'skill://code-review/references', 'name' => 'references', 'mimeType' => 'inode/directory'],
            ],
            $root['resources'],
        );
        self::assertSame(
            [[
                'uri' => 'skill://code-review/references/checklist.md',
                'name' => 'checklist.md',
                'mimeType' => 'text/markdown',
            ]],
            $references['resources'],
        );
    }

    public function testADirectoryWithATrailingSlashIsNotOne(): void
    {
        $response = self::send(self::app(), 'resources/directory/read', self::params([
            'uri' => 'skill://code-review/',
        ]));

        self::assertSame(-32_602, self::decode($response)['error']['code']);
    }

    public function testWithoutSkillsTheirMethodsAreNotFound(): void
    {
        $app = self::app(static function (): void {
            Mcp::server('/mcp')->resources(\Crab\Mcp\Tests\Fixtures\Notes::class);
        });

        $response = self::send($app, 'skills/list', self::params());

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function badSkills(): iterable
    {
        yield 'no SKILL.md' => [['a-skill/notes.md' => 'x'], 'has no SKILL.md'];
        yield 'another name' => [
            ['a-skill/SKILL.md' => "---\nname: other\ndescription: d\n---\n"],
            'its folder\'s, "a-skill"',
        ];
        yield 'no description' => [['a-skill/SKILL.md' => "---\nname: a-skill\n---\n"], 'needs a description'];
        yield 'no frontmatter' => [['a-skill/SKILL.md' => "# A skill\n"], 'begins with YAML frontmatter'];
        yield 'a bad name' => [
            ['A_Skill/SKILL.md' => "---\nname: A_Skill\ndescription: d\n---\n"],
            'a name a skill cannot have',
        ];
        yield 'a file beside the skills' => [['README.md' => 'x'], 'not a skill\'s folder'];
    }

    /**
     * @param array<string, string> $files
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badSkills')]
    public function testASkillThatCannotBeServedIsRefusedWhenLoaded(array $files, string $message): void
    {
        $directory = sys_get_temp_dir() . '/crab-mcp-skills-' . bin2hex(random_bytes(6));
        try {
            foreach ($files as $path => $contents) {
                @mkdir(dirname("{$directory}/{$path}"), 0o700, true);
                file_put_contents("{$directory}/{$path}", $contents);
            }
            $error = null;
            try {
                SkillLibrary::load($directory);
            } catch (\LogicException $caught) {
                $error = $caught;
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($directory));
        }

        self::assertNotNull($error);
        self::assertStringContainsString($message, $error->getMessage());
    }

    public function testALinkIsRefused(): void
    {
        $directory = sys_get_temp_dir() . '/crab-mcp-skills-' . bin2hex(random_bytes(6));
        mkdir("{$directory}/a-skill", 0o700, true);
        file_put_contents("{$directory}/a-skill/SKILL.md", "---\nname: a-skill\ndescription: d\n---\n");
        symlink('/etc/hostname', "{$directory}/a-skill/host");
        try {
            $error = null;
            try {
                SkillLibrary::load($directory);
            } catch (\LogicException $caught) {
                $error = $caught;
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($directory));
        }

        self::assertNotNull($error);
        self::assertStringContainsString('is a link', $error->getMessage());
    }
}
