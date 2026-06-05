<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\MarkupType;
use App\Enums\Billing\ProjectStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $client_id
 * @property string $name
 * @property string|null $do_project_uuid
 * @property MarkupType|null $markup_type
 * @property string|null $markup_value
 * @property ProjectStatus $status
 * @property \Illuminate\Support\Carbon|null $terminated_at
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Client $client
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ProviderResource> $providerResources
 *
 * @method static \Database\Factories\Billing\ProjectFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class Project extends Model
{
    /** @use HasFactory<\Database\Factories\Billing\ProjectFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'client_id',
        'name',
        'do_project_uuid',
        'markup_type',
        'markup_value',
        'status',
        'terminated_at',
        'notes',
    ];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<ProviderResource, $this> */
    public function providerResources(): HasMany
    {
        return $this->hasMany(ProviderResource::class);
    }

    /**
     * The markup type effective for this project (falls back to client default).
     */
    public function effectiveMarkupType(): MarkupType
    {
        return $this->markup_type ?? $this->client->default_markup_type;
    }

    /**
     * The markup value effective for this project (falls back to client default).
     */
    public function effectiveMarkupValue(): string
    {
        return $this->markup_value ?? $this->client->default_markup_value;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'markup_type' => MarkupType::class,
            'markup_value' => 'decimal:4',
            'status' => ProjectStatus::class,
            'terminated_at' => 'datetime',
        ];
    }
}
