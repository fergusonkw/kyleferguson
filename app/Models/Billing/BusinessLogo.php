<?php

declare(strict_types=1);

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One uploaded logo, kept in the database rather than on disk.
 *
 * Write-once: a rebrand adds a row instead of changing one, because invoices
 * snapshot the id of the logo they were issued with. Stored base64 so the
 * same column type works on every database the app runs on.
 *
 * @property int $id
 * @property string $mime_type
 * @property string $contents_base64
 * @property string $sha256
 * @property int $byte_size
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Database\Factories\Billing\BusinessLogoFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class BusinessLogo extends Model
{
    /** @use HasFactory<\Database\Factories\Billing\BusinessLogoFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'mime_type',
        'contents_base64',
        'sha256',
        'byte_size',
    ];

    /** @var list<string> */
    protected $hidden = [
        'contents_base64',
    ];

    public static function fromContents(string $contents, string $mimeType): self
    {
        return self::create([
            'mime_type' => $mimeType,
            'contents_base64' => base64_encode($contents),
            'sha256' => hash('sha256', $contents),
            'byte_size' => mb_strlen($contents, '8bit'),
        ]);
    }

    public function dataUri(): string
    {
        return 'data:'.$this->mime_type.';base64,'.$this->contents_base64;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
        ];
    }
}
