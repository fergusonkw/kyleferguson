<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Billing\BusinessLogo;
use Illuminate\Http\UploadedFile;

/**
 * Stores business logos and inlines them into rendered invoices.
 *
 * Two decisions worth stating:
 *
 * Uploads never overwrite. Each becomes its own `business_logos` row, so an
 * invoice that snapshotted a logo id keeps rendering the logo it was issued
 * with even after the business rebrands — the same promise the rest of the
 * snapshot makes. Rows live in the database, not on disk, so they survive a
 * host whose filesystem is wiped on every deploy.
 *
 * Rendering inlines the logo as a data URI rather than linking it. Browsershot
 * renders from an HTML string with no document base, so a relative URL would
 * resolve to nothing and the logo would silently vanish from every PDF.
 */
final class BusinessLogoStore
{
    /**
     * @return int the new logo's id, for `businesses.logo_id`
     */
    public function store(UploadedFile $file): int
    {
        return BusinessLogo::fromContents(
            (string) $file->get(),
            $this->mimeFor($file->getClientOriginalExtension()),
        )->id;
    }

    /**
     * The logo as a data URI, or null when there is none. A logo that cannot
     * be found prints an invoice without one rather than failing to print.
     */
    public function dataUri(?int $logoId): ?string
    {
        if ($logoId === null) {
            return null;
        }

        $logo = BusinessLogo::query()->find($logoId);

        if ($logo === null || $logo->contents_base64 === '') {
            return null;
        }

        return $logo->dataUri();
    }

    private function mimeFor(string $extension): string
    {
        return match (mb_strtolower($extension)) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }
}
