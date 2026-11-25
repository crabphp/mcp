<?php

declare(strict_types=1);

namespace Crab\Mcp\Skills;

use Crab\Mcp\Protocol\McpError;

/**
 * The skills of one server: a directory of skill folders, each a SKILL.md
 * and the files beside it, read whole when the application is defined.
 * What is served is what was read then, so each manifest's digests and
 * sizes are of the very bytes resources/read sends, for as long as the
 * process lives; a change on disk is served by the next deployment.
 *
 * Skill files are named skill://{name}/{path}. A directory URI has no
 * trailing slash; the skill's own is skill://{name}.
 *
 * @see https://modelcontextprotocol.io/extensions/skills/overview
 * @internal
 */
final readonly class SkillLibrary
{
    /**
     * The largest skill this server will serve, in files and in bytes:
     * what every host must accept.
     */
    public const int MAX_FILES = 512;
    public const int MAX_BYTES = 16 * 1024 * 1024;

    /**
     * @param array<string, Skill> $skills By name, in order of name.
     * @param array<string, SkillFile> $files Every file, by URI.
     * @param array<string, list<array{uri: string, name: string, mimeType: string}>> $directories Every directory's direct children, by URI.
     */
    private function __construct(
        public string $directory,
        public array $skills,
        private array $files,
        private array $directories,
    ) {}

    /**
     * Reads every skill in the directory, and throws a LogicException
     * naming the skill and what is wrong when one cannot be served.
     */
    public static function load(string $directory): self
    {
        if (!is_dir($directory)) {
            throw new \LogicException(
                "The skills directory {$directory} is not there. Make it, with a folder for each skill holding its SKILL.md.",
            );
        }
        if (is_link($directory)) {
            throw new \LogicException("The skills directory {$directory} is a link; name the directory itself.");
        }
        $skills = [];
        $files = [];
        $directories = [];
        foreach (self::entries($directory) as $name) {
            $path = "{$directory}/{$name}";
            if (!is_dir($path) || is_link($path)) {
                throw new \LogicException(
                    "{$path} is in the skills directory and is not a skill's folder. Keep only skill folders there.",
                );
            }
            [$skill, $skillFiles, $skillDirectories] = self::skill($path, $name);
            $skills[$name] = $skill;
            $files += $skillFiles;
            $directories += $skillDirectories;
        }
        return new self($directory, $skills, $files, $directories);
    }

    /**
     * The skill whose SKILL.md this is.
     *
     * @throws McpError When the server has no such skill.
     */
    public function get(string $uri): Skill
    {
        foreach ($this->skills as $skill) {
            if ($skill->uri === $uri) {
                return $skill;
            }
        }
        throw McpError::invalidParams("Unknown skill: {$uri}.");
    }

    public function file(string $uri): ?SkillFile
    {
        return $this->files[$uri] ?? null;
    }

    /**
     * A directory's direct children, or null when it is not one of the skills'.
     *
     * @return list<array{uri: string, name: string, mimeType: string}>|null
     */
    public function directory(string $uri): ?array
    {
        return $this->directories[$uri] ?? null;
    }

    /**
     * @return array{Skill, array<string, SkillFile>, array<string, list<array{uri: string, name: string, mimeType: string}>>}
     */
    private static function skill(string $folder, string $name): array
    {
        $markdown = "{$folder}/SKILL.md";
        if (!is_file($markdown) || is_link($markdown)) {
            throw new \LogicException("The skill {$folder} has no SKILL.md; every skill's folder has one.");
        }
        $contents = file_get_contents($markdown);
        if ($contents === false) {
            throw new \LogicException("{$markdown} cannot be read.");
        }
        try {
            $frontmatter = Frontmatter::read($contents, $markdown);
        } catch (\InvalidArgumentException $error) {
            throw new \LogicException($error->getMessage(), previous: $error);
        }
        $declared = $frontmatter['name'] ?? null;
        if (!is_string($declared) || $declared !== $name) {
            throw new \LogicException(
                "{$markdown} has the name "
                . var_export($declared, true)
                . "; a skill's name is its folder's, \"{$name}\".",
            );
        }
        if (preg_match('/^(?!-)(?!.*--)[a-z0-9-]{1,64}(?<!-)$/', $name) !== 1) {
            throw new \LogicException(
                "The skill {$name} has a name a skill cannot have: 1 to 64 lowercase letters, digits and single hyphens, not at either end.",
            );
        }
        $description = $frontmatter['description'] ?? null;
        if (!is_string($description) || trim($description) === '' || mb_strlen($description) > 1024) {
            throw new \LogicException(
                "{$markdown} needs a description of 1 to 1024 characters: what the skill does and when to use it.",
            );
        }
        $root = "skill://{$name}";
        $files = [];
        $directories = [$root => []];
        $bytes = 0;
        foreach (self::walk($folder, '') as [$relative, $isDirectory]) {
            $uri = $root . '/' . implode('/', array_map(rawurlencode(...), explode('/', $relative)));
            $parent = str_contains($relative, '/')
                ? $root . '/' . implode('/', array_map(rawurlencode(...), explode('/', dirname($relative))))
                : $root;
            $child = ['uri' => $uri, 'name' => basename($relative)];
            if ($isDirectory) {
                $directories[$uri] = [];
                $directories[$parent][] = [...$child, 'mimeType' => 'inode/directory'];
                continue;
            }
            $data = file_get_contents("{$folder}/{$relative}");
            if ($data === false) {
                throw new \LogicException("{$folder}/{$relative} cannot be read.");
            }
            $bytes += strlen($data);
            $file = new SkillFile($uri, $data, MimeTypes::of($relative, $data));
            $files[$uri] = $file;
            $directories[$parent][] = [...$child, 'mimeType' => $file->mimeType];
        }
        if (count($files) > self::MAX_FILES || $bytes > self::MAX_BYTES) {
            throw new \LogicException(
                "The skill {$name} has "
                . count($files)
                . " files of {$bytes} bytes; a skill keeps to "
                . self::MAX_FILES
                . ' files and 16 MiB, so that every host can load it.',
            );
        }
        // SKILL.md first, then the rest by path: one order for every listing.
        $manifest = [$files["{$root}/SKILL.md"]];
        foreach ($files as $uri => $file) {
            if ($uri === "{$root}/SKILL.md") {
                continue;
            }

            $manifest[] = $file;
        }
        return [new Skill($name, "{$root}/SKILL.md", $frontmatter, $manifest), $files, $directories];
    }

    /**
     * A folder's files and folders below it, by path from the skill's
     * folder, each folder before what it holds. Hidden names are left
     * out, and a link is refused: a skill holds its own files only.
     *
     * @return \Generator<int, array{string, bool}>
     */
    private static function walk(string $folder, string $relative): \Generator
    {
        $path = $relative === '' ? $folder : "{$folder}/{$relative}";
        foreach (self::entries($path) as $name) {
            $child = $relative === '' ? $name : "{$relative}/{$name}";
            $full = "{$folder}/{$child}";
            if (is_link($full)) {
                throw new \LogicException("{$full} is a link; a skill holds its own files, not links to others.");
            }
            if (is_dir($full)) {
                yield [$child, true];
                yield from self::walk($folder, $child);
            } elseif (is_file($full)) {
                yield [$child, false];
            }
        }
    }

    /**
     * A directory's entries by name, hidden ones left out.
     *
     * @return list<string>
     */
    private static function entries(string $directory): array
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new \LogicException("{$directory} cannot be read.");
        }
        $names = array_values(array_filter($entries, static fn(string $name): bool => !str_starts_with($name, '.')));
        sort($names, SORT_STRING);
        return $names;
    }
}
