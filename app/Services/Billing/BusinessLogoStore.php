<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores business logos and inlines them into rendered invoices.
 *
 * Two decisions worth stating:
 *
 * Uploads never overwrite. Each gets its own filename, so an invoice that
 * snapshotted a path keeps rendering the logo it was issued with even after
 * the business rebrands — which is the same promise the rest of the snapshot
 * makes.
 *
 * Rendering inlines the file as a data URI rather than linking it. Browsershot
 * renders from an HTML string with no document base, so a relative URL would
 * resolve to nothing and the logo would silently vanish from every PDF.
 */
final class BusinessLogoStore
{
    private const DISK = 'local';

    private const DIRECTORY = 'billing/logos';

    /**
     * @return string the stored path, for `businesses.logo_path`
     */
    public function store(UploadedFile $file): string
    {
        $name = Str::uuid()->toString().'.'.mb_strtolower($file->getClientOriginalExtension());
        $path = self::DIRECTORY.'/'.$name;

        Storage::disk(self::DISK)->put($path, $file->get());

        return $path;
    }

    /**
     * The logo as a data URI, or null when there is no logo or the file has
     * gone. A missing logo prints an invoice without one rather than failing
     * to print at all.
     */
    public function dataUri(?string $path): ?string
    {
        if (blank($path) || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $contents = Storage::disk(self::DISK)->get($path);

        if ($contents === null || $contents === '') {
            return null;
        }

        return 'data:'.$this->mimeFor($path).';base64,'.base64_encode($contents);
    }

    public function delete(?string $path): void
    {
        if (filled($path) && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    private function mimeFor(string $path): string
    {
        return match (mb_strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }
}
