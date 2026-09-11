<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * The webfonts an invoice renders with, inlined as `@font-face` rules.
 *
 * Browsershot renders from a bare HTML string with no document base and no
 * guarantee of network access, so a linked stylesheet is not dependable: the
 * fonts either arrive or they silently do not, and the same invoice can print
 * two different ways on two different hosts. Inlining makes the document
 * self-contained, the same reason the logo is a data URI.
 *
 * The files are vendored under `resources/fonts` rather than read from
 * `node_modules`, which is not present on a host that prunes dev dependencies.
 * {@see \App\Console\Commands\SyncInvoiceFonts} refreshes them from the
 * `@fontsource` packages.
 */
final class InvoiceFontStore
{
    /**
     * family => [weight => filename]. Only the weights a template actually
     * uses, since every one of them is carried by every invoice that asks for
     * the family.
     *
     * @var array<string, array<int, string>>
     */
    public const FACES = [
        'Archivo' => [
            400 => 'archivo-latin-400-normal.woff2',
            500 => 'archivo-latin-500-normal.woff2',
            600 => 'archivo-latin-600-normal.woff2',
            700 => 'archivo-latin-700-normal.woff2',
        ],
        'Space Mono' => [
            400 => 'space-mono-latin-400-normal.woff2',
            700 => 'space-mono-latin-700-normal.woff2',
        ],
        'Inter' => [
            400 => 'inter-latin-400-normal.woff2',
            500 => 'inter-latin-500-normal.woff2',
            600 => 'inter-latin-600-normal.woff2',
            700 => 'inter-latin-700-normal.woff2',
        ],
    ];

    /**
     * Template basename => the families it renders with.
     *
     * Scoped per template because the faces are embedded in every document:
     * shipping all three families would put Inter into invoices set in Archivo
     * and Archivo into invoices set in Inter, roughly doubling every PDF to no
     * effect. A template not listed here gets every face — a heavier document
     * beats one whose fonts silently fall back.
     *
     * @var array<string, list<string>>
     */
    private const TEMPLATE_FAMILIES = [
        'default' => ['Archivo', 'Space Mono'],
        'tracker-pull' => ['Inter'],
    ];

    /** @var array<string, string> */
    private array $cached = [];

    /**
     * The directory is injectable so a caller — a test, or a host that keeps
     * its fonts elsewhere — can point somewhere else without the class needing
     * to know about resource_path().
     */
    public function __construct(private readonly ?string $directory = null) {}

    /**
     * `@font-face` declarations for the faces the given template renders with,
     * or for every vendored face when no template is named.
     *
     * Returns an empty string when the fonts have not been vendored, so the
     * template's own fallback stack takes over and an invoice still prints.
     */
    public function faceCss(?string $templateView = null): string
    {
        $families = $this->familiesFor($templateView);
        $key = implode('|', $families);

        // Held for the instance: a nightly run generating many invoices would
        // otherwise re-read and re-encode 90KB of font per document. The store
        // is a singleton, so that is once per process per family set.
        if (isset($this->cached[$key])) {
            return $this->cached[$key];
        }

        $rules = [];

        foreach ($families as $family) {
            foreach (self::FACES[$family] as $weight => $filename) {
                $uri = $this->dataUri($filename);

                if ($uri === null) {
                    continue;
                }

                $rules[] = sprintf(
                    "@font-face{font-family:'%s';font-style:normal;font-weight:%d;font-display:swap;src:url(%s) format('woff2');}",
                    $family,
                    $weight,
                    $uri,
                );
            }
        }

        return $this->cached[$key] = implode("\n", $rules);
    }

    public function directory(): string
    {
        return $this->directory ?? resource_path('fonts');
    }

    public function path(string $filename): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.$filename;
    }

    /**
     * Whether every declared face is present. Used by the sync command to
     * report, and by tests to prove the vendored set matches the template.
     */
    public function isComplete(): bool
    {
        foreach (self::FACES as $weights) {
            foreach ($weights as $filename) {
                if (! is_file($this->path($filename))) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Clears the cache. Only needed when the files change underneath a
     * long-running process — the sync command, and tests.
     */
    public function flush(): void
    {
        $this->cached = [];
    }

    /**
     * The families a template declares, falling back to all of them.
     *
     * @return list<string>
     */
    private function familiesFor(?string $templateView): array
    {
        if ($templateView === null) {
            return array_keys(self::FACES);
        }

        $name = str($templateView)->afterLast('.')->toString();

        return self::TEMPLATE_FAMILIES[$name] ?? array_keys(self::FACES);
    }

    private function dataUri(string $filename): ?string
    {
        $path = $this->path($filename);

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false || $contents === '') {
            return null;
        }

        return 'data:font/woff2;base64,'.base64_encode($contents);
    }
}
