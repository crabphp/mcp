<?php

declare(strict_types=1);

namespace Crab\Mcp\Skills;

/**
 * The media type of a skill's file, from its extension: what a skill
 * usually holds is instructions, scripts and a few images. A file whose
 * extension is not known is text when it is valid UTF-8 with no control
 * characters, and bytes otherwise.
 *
 * @internal
 */
final class MimeTypes
{
    private const array TYPES = [
        'md' => 'text/markdown',
        'markdown' => 'text/markdown',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'xml' => 'application/xml',
        'json' => 'application/json',
        'yaml' => 'application/yaml',
        'yml' => 'application/yaml',
        'toml' => 'application/toml',
        'js' => 'text/javascript',
        'mjs' => 'text/javascript',
        'ts' => 'text/x-typescript',
        'py' => 'text/x-python',
        'sh' => 'text/x-shellscript',
        'php' => 'text/x-php',
        'rb' => 'text/x-ruby',
        'sql' => 'application/sql',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
    ];

    public static function of(string $path, string $bytes): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (isset(self::TYPES[$extension])) {
            return self::TYPES[$extension];
        }
        return mb_check_encoding($bytes, 'UTF-8') && preg_match('/[\x00-\x08\x0E-\x1F\x7F]/', $bytes) !== 1
            ? 'text/plain'
            : 'application/octet-stream';
    }

    public static function isText(string $type): bool
    {
        return (
            str_starts_with($type, 'text/')
            || in_array(
                $type,
                [
                    'application/json',
                    'application/xml',
                    'application/yaml',
                    'application/toml',
                    'application/sql',
                    'image/svg+xml',
                ],
                true,
            )
        );
    }
}
