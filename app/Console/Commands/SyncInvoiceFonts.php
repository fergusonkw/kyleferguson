<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\InvoiceFontStore;
use Illuminate\Console\Command;

/**
 * Copies the invoice webfonts out of the `@fontsource` packages and into
 * `resources/fonts`, where they are committed.
 *
 * npm is the provenance and the upgrade path; the vendored copy is what the
 * renderer reads, because `node_modules` is absent on any host that prunes dev
 * dependencies and a missing font would degrade invoices silently. Run this
 * after upgrading either font package.
 */
final class SyncInvoiceFonts extends Command
{
    /**
     * Package directory per family, relative to node_modules.
     *
     * @var array<string, string>
     */
    private const PACKAGES = [
        'Archivo' => '@fontsource/archivo',
        'Space Mono' => '@fontsource/space-mono',
    ];

    protected $signature = 'billing:sync-invoice-fonts';

    protected $description = 'Vendor the invoice webfonts from @fontsource into resources/fonts';

    public function handle(InvoiceFontStore $fonts): int
    {
        if (! is_dir($fonts->directory()) && ! mkdir($fonts->directory(), 0755, true) && ! is_dir($fonts->directory())) {
            $this->error('Could not create '.$fonts->directory());

            return self::FAILURE;
        }

        $copied = 0;
        $missing = [];

        foreach (InvoiceFontStore::FACES as $family => $weights) {
            $source = base_path('node_modules/'.self::PACKAGES[$family].'/files');

            foreach ($weights as $filename) {
                $from = $source.DIRECTORY_SEPARATOR.$filename;

                if (! is_file($from)) {
                    $missing[] = $filename;

                    continue;
                }

                copy($from, $fonts->path($filename));
                $copied++;
            }
        }

        $fonts->flush();

        if ($missing !== []) {
            $this->error('Missing from node_modules: '.implode(', ', $missing));
            $this->line('Run `npm install` first.');

            return self::FAILURE;
        }

        $this->info("Vendored {$copied} font file(s) into ".$fonts->directory());

        return self::SUCCESS;
    }
}
