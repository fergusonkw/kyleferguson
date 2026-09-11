<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * The vendored document kits an invoice template styles itself with.
 *
 * A kit is a stylesheet owned by another repository — Tracker Pull's lives in
 * its marketing site, where the same classes dress invoices, reports and
 * letters. Rather than forking that CSS, a copy is vendored here and refreshed
 * by {@see \App\Console\Commands\SyncDocumentKit}, on the same reasoning as the
 * webfonts: upstream is the provenance and the upgrade path, the committed copy
 * is what actually renders, and this repository decides when to take a change.
 *
 * The copy is byte-identical to upstream below its generated header, so drift
 * is a clean diff. Templates must never edit it — a template that needs
 * different colours emits its own `:root` overrides *after* inlining the kit,
 * which is how per-business branding wins without touching the shared file.
 */
final class DocumentKitStore
{
    /**
     * Kit name => vendored filename.
     *
     * @var array<string, string>
     */
    public const KITS = [
        'tracker-pull' => 'tracker-pull-doc.css',
    ];

    /**
     * Invoice template basename => the kit it styles itself with. A template
     * absent from this map brings its own CSS, as the default one does.
     *
     * @var array<string, string>
     */
    private const TEMPLATE_KITS = [
        'tracker-pull' => 'tracker-pull',
    ];

    /**
     * Marks a generated header. Everything above the first `*\/` after it is
     * provenance rather than styling, and is excluded when comparing against
     * upstream so an unrelated commit upstream does not read as drift.
     */
    private const HEADER_SENTINEL = '/* Vendored from';

    /** @var array<string, string> */
    private array $cached = [];

    /**
     * Injectable so a test can point at a fixture directory without the class
     * needing to know about resource_path().
     */
    public function __construct(private readonly ?string $directory = null) {}

    /**
     * The kit CSS a template renders with, ready to inline.
     *
     * Returns an empty string when the template names no kit or the file has
     * not been vendored — an unstyled invoice is a poor document, but it still
     * carries the numbers, and failing to render one is worse.
     */
    public function cssFor(?string $templateView): string
    {
        if ($templateView === null) {
            return '';
        }

        $name = str($templateView)->afterLast('.')->toString();
        $kit = self::TEMPLATE_KITS[$name] ?? null;

        return $kit === null ? '' : $this->css($kit);
    }

    /**
     * One kit's CSS, header and all. Cached for the instance: the store is a
     * singleton, so a nightly run reads each kit once per process rather than
     * once per invoice.
     */
    public function css(string $kit): string
    {
        if (isset($this->cached[$kit])) {
            return $this->cached[$kit];
        }

        $path = $this->pathFor($kit);

        if ($path === null || ! is_file($path)) {
            return $this->cached[$kit] = '';
        }

        $contents = file_get_contents($path);

        return $this->cached[$kit] = $contents === false ? '' : $contents;
    }

    /**
     * The styling alone, with any generated provenance header removed. What
     * the sync command compares, so re-syncing an unchanged kit is a no-op.
     */
    public function body(string $contents): string
    {
        if (! str_starts_with(ltrim($contents), self::HEADER_SENTINEL)) {
            return trim($contents);
        }

        $end = strpos($contents, '*/');

        return $end === false ? trim($contents) : trim(substr($contents, $end + 2));
    }

    public function directory(): string
    {
        return $this->directory ?? resource_path('views/admin-v2/billing/invoices/templates/kit');
    }

    public function pathFor(string $kit): ?string
    {
        $filename = self::KITS[$kit] ?? null;

        return $filename === null ? null : $this->directory().DIRECTORY_SEPARATOR.$filename;
    }

    public function isVendored(string $kit): bool
    {
        $path = $this->pathFor($kit);

        return $path !== null && is_file($path);
    }

    /**
     * Clears the cache. Only needed when the files change underneath a
     * long-running process — the sync command, and tests.
     */
    public function flush(): void
    {
        $this->cached = [];
    }
}
