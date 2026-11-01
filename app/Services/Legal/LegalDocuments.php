<?php

namespace App\Services\Legal;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The legal documents kept as Markdown in resources/legal, and whether the operator details are complete.
 */
class LegalDocuments
{
    private const FRONTMATTER = '/\A---\R(.*?)\R---\R?/s';

    /**
     * The `version:` of the document's frontmatter.
     */
    public static function version(string $name): string
    {
        return self::parsed($name)['version'];
    }

    /**
     * The document body rendered to HTML, with raw HTML (including comments) stripped.
     * `{email}` is replaced with the operator's contact address (LEGAL_EMAIL).
     */
    public static function html(string $name): string
    {
        $email = trim((string) config('legal.email'));

        return str_replace('{email}', e($email !== '' ? $email : '（準備中）'), self::parsed($name)['html']);
    }

    public static function termsVersion(): string
    {
        return self::version('terms');
    }

    /**
     * Whether the 特商法 details are complete enough to accept payments.
     */
    public static function ready(): bool
    {
        $filled = fn (string $key): bool => trim((string) config("legal.{$key}")) !== '';
        $disclose = (bool) config('legal.disclose_on_request');

        return $filled('seller_name')
            && $filled('responsible_person')
            && $filled('email')
            && ($filled('address') || $disclose)
            && ($filled('phone') || $disclose);
    }

    /**
     * @return array{version: string, html: string}
     */
    private static function parsed(string $name): array
    {
        $path = resource_path("legal/{$name}.md");

        if (! preg_match('/\A[a-z]+\z/', $name) || ! is_file($path)) {
            throw new RuntimeException("Unknown legal document [{$name}].");
        }

        // Keyed by mtime, so editing the file is picked up without clearing the cache.
        return Cache::rememberForever("legal:{$name}:".filemtime($path), function () use ($path, $name): array {
            $source = (string) file_get_contents($path);

            if (! preg_match(self::FRONTMATTER, $source, $match) || ! preg_match('/^version:\s*(\S+)\s*$/m', $match[1], $version)) {
                throw new RuntimeException("Legal document [{$name}] has no version.");
            }

            $body = substr($source, strlen($match[0]));

            return [
                'version' => $version[1],
                'html' => Str::markdown($body, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            ];
        });
    }
}
