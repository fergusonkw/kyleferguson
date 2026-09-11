<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Logos move off the local disk and into the database.
 *
 * A host with an ephemeral filesystem (Laravel Cloud) loses anything written
 * to `storage/` on the next deploy, and logos were the last thing the billing
 * system kept there. They are small — capped at 512 KB — and rarely written,
 * so a row each costs nothing and rides along in every database backup.
 *
 * Rows are never updated or deleted: an invoice snapshots the id of the logo
 * it was issued with, so a rebrand cannot restyle a document a client holds.
 * Existing files are imported, and every snapshot that pointed at a path now
 * points at the imported row. A file that has already gone stays gone — the
 * invoice prints with its monogram, as it did before.
 */
return new class extends Migration
{
    private const DISK = 'local';

    /** @var array<string, string> */
    private const MIME_BY_EXTENSION = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
    ];

    /** @var array<string, int|null> */
    private array $importedPaths = [];

    public function up(): void
    {
        Schema::create('business_logos', function (Blueprint $table): void {
            $table->id();
            $table->string('mime_type', 64);
            $table->longText('contents_base64');
            $table->char('sha256', 64);
            $table->unsignedInteger('byte_size');
            $table->timestamps();
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->foreignId('logo_id')->nullable()->after('logo_path')
                ->constrained('business_logos')->nullOnDelete();
        });

        foreach (DB::table('businesses')->whereNotNull('logo_path')->get(['id', 'logo_path']) as $business) {
            DB::table('businesses')->where('id', $business->id)->update([
                'logo_id' => $this->import($business->logo_path),
            ]);
        }

        foreach (DB::table('invoices')->whereNotNull('business_snapshot')->get(['id', 'business_snapshot']) as $invoice) {
            $snapshot = json_decode((string) $invoice->business_snapshot, true);

            if (! is_array($snapshot) || ! array_key_exists('logo_path', $snapshot)) {
                continue;
            }

            $snapshot['logo_id'] = $this->import($snapshot['logo_path']);
            unset($snapshot['logo_path']);

            DB::table('invoices')->where('id', $invoice->id)->update([
                'business_snapshot' => json_encode($snapshot),
            ]);
        }

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('logo_path')->nullable()->after('contact_email');
        });

        $pathsById = [];

        foreach (DB::table('business_logos')->get() as $logo) {
            $extension = array_search($logo->mime_type, self::MIME_BY_EXTENSION, true) ?: 'bin';
            $path = "billing/logos/logo-{$logo->id}.{$extension}";

            Storage::disk(self::DISK)->put($path, (string) base64_decode($logo->contents_base64, true));
            $pathsById[$logo->id] = $path;
        }

        foreach (DB::table('businesses')->whereNotNull('logo_id')->get(['id', 'logo_id']) as $business) {
            DB::table('businesses')->where('id', $business->id)->update([
                'logo_path' => $pathsById[$business->logo_id] ?? null,
            ]);
        }

        foreach (DB::table('invoices')->whereNotNull('business_snapshot')->get(['id', 'business_snapshot']) as $invoice) {
            $snapshot = json_decode((string) $invoice->business_snapshot, true);

            if (! is_array($snapshot) || ! array_key_exists('logo_id', $snapshot)) {
                continue;
            }

            $snapshot['logo_path'] = $pathsById[$snapshot['logo_id']] ?? null;
            unset($snapshot['logo_id']);

            DB::table('invoices')->where('id', $invoice->id)->update([
                'business_snapshot' => json_encode($snapshot),
            ]);
        }

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('logo_id');
        });

        Schema::dropIfExists('business_logos');
    }

    /**
     * Copy one stored logo file into a row, once per path.
     */
    private function import(?string $path): ?int
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (array_key_exists($path, $this->importedPaths)) {
            return $this->importedPaths[$path];
        }

        $disk = Storage::disk(self::DISK);
        $contents = $disk->exists($path) ? $disk->get($path) : null;

        if ($contents === null || $contents === '') {
            return $this->importedPaths[$path] = null;
        }

        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $now = now();

        return $this->importedPaths[$path] = DB::table('business_logos')->insertGetId([
            'mime_type' => self::MIME_BY_EXTENSION[$extension] ?? 'application/octet-stream',
            'contents_base64' => base64_encode($contents),
            'sha256' => hash('sha256', $contents),
            'byte_size' => mb_strlen($contents, '8bit'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
