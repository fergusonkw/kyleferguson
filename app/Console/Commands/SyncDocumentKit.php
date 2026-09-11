<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\DocumentKitStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Vendors a document kit — the stylesheet an invoice template dresses itself
 * with — out of the repository that owns it and into `resources/views`.
 *
 * The direction is deliberate. The owning repository could push its kit here
 * from its own build, but then a commit in a marketing site would change how
 * invoices render without this application's tests ever running, and the copy
 * would carry no record of where it came from. Pulling keeps the timing, the
 * verification and the provenance on this side, exactly as
 * {@see SyncInvoiceFonts} does for the webfonts.
 *
 * Only the CSS crosses the boundary. The markup does not: a Blade template
 * interleaves conditionals through the very elements a generated block would
 * have to own, so the upstream HTML stays a reference implementation that the
 * Blade reimplements against the same class names.
 */
final class SyncDocumentKit extends Command
{
    /**
     * Where each kit comes from: the sibling checkout, the file within it, and
     * the remote it is published as.
     *
     * @var array<string, array{repo: string, file: string, origin: string}>
     */
    private const SOURCES = [
        'tracker-pull' => [
            'repo' => 'tracker-pull-marketing-site',
            'file' => 'templates/kit/tracker-pull-doc.css',
            'origin' => 'fergusonkw/tracker-pull-marketing-site',
        ],
    ];

    protected $signature = 'billing:sync-document-kit
                            {kit? : The kit to sync; all of them when omitted}
                            {--from= : Path to the source checkout, if it is not a sibling of this one}
                            {--check : Report whether the vendored copy is current, changing nothing}';

    protected $description = 'Vendor the document kit stylesheets an invoice template renders with';

    public function handle(DocumentKitStore $kits): int
    {
        $requested = $this->argument('kit');

        if ($requested !== null && ! array_key_exists($requested, self::SOURCES)) {
            $this->error("Unknown kit [{$requested}]. Known: ".implode(', ', array_keys(self::SOURCES)).'.');

            return self::FAILURE;
        }

        $names = $requested !== null ? [$requested] : array_keys(self::SOURCES);
        $stale = [];

        foreach ($names as $name) {
            $result = $this->syncKit($kits, $name);

            if ($result === self::FAILURE) {
                return self::FAILURE;
            }

            if ($result === self::INVALID) {
                $stale[] = $name;
            }
        }

        $kits->flush();

        if ($stale !== []) {
            $this->newLine();
            $this->error('Out of date: '.implode(', ', $stale).'.');
            $this->line('Run `php artisan billing:sync-document-kit` to refresh.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Returns INVALID — rather than FAILURE — when `--check` finds drift, so
     * the caller can report every stale kit instead of only the first.
     */
    private function syncKit(DocumentKitStore $kits, string $name): int
    {
        $source = self::SOURCES[$name];
        $repo = $this->repositoryPath($source['repo']);
        $from = $repo.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $source['file']);

        if (! is_file($from)) {
            $this->error("Could not read {$from}.");
            $this->line('Pass --from=<path> if the source checkout is not beside this one.');

            return self::FAILURE;
        }

        $upstream = file_get_contents($from);

        if ($upstream === false || trim($upstream) === '') {
            $this->error("{$from} is empty.");

            return self::FAILURE;
        }

        $destination = $kits->pathFor($name);

        if ($destination === null) {
            $this->error("No vendored filename is registered for kit [{$name}].");

            return self::FAILURE;
        }

        $current = is_file($destination) ? (string) file_get_contents($destination) : '';
        $isCurrent = $kits->body($current) === $kits->body($upstream);

        if ($this->option('check')) {
            $this->line(($isCurrent ? '<info>current</info>  ' : '<comment>stale</comment>    ').$name);

            return $isCurrent ? self::SUCCESS : self::INVALID;
        }

        if ($isCurrent) {
            $this->line("<info>unchanged</info> {$name}");

            return self::SUCCESS;
        }

        if (! is_dir($kits->directory()) && ! mkdir($kits->directory(), 0755, true) && ! is_dir($kits->directory())) {
            $this->error('Could not create '.$kits->directory());

            return self::FAILURE;
        }

        file_put_contents($destination, $this->header($source, $repo).trim($upstream)."\n");

        $this->info("Vendored {$name} into {$destination}");

        return self::SUCCESS;
    }

    private function repositoryPath(string $repo): string
    {
        $from = $this->option('from');

        return is_string($from) && $from !== ''
            ? rtrim($from, '/\\')
            : dirname(base_path()).DIRECTORY_SEPARATOR.$repo;
    }

    /**
     * Provenance, not styling — {@see DocumentKitStore::body()} strips it back
     * off before comparing, so recording the commit here cannot make an
     * otherwise-identical kit look stale.
     *
     * @param  array{repo: string, file: string, origin: string}  $source
     */
    private function header(array $source, string $repo): string
    {
        $commit = $this->commitAt($repo);

        return sprintf(
            "/* Vendored from %s\n   %s%s\n   Synced %s by `php artisan billing:sync-document-kit`.\n   Do not edit here — change it upstream and re-run that command. */\n\n",
            $source['origin'],
            $source['file'],
            $commit === null ? '' : ' @ '.$commit,
            now()->toDateString(),
        );
    }

    private function commitAt(string $repo): ?string
    {
        $result = Process::path($repo)->run('git rev-parse --short HEAD');

        return $result->successful() ? trim($result->output()) : null;
    }
}
