<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\LegalEntityType;
use App\Enums\Billing\ThresholdLevel;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * The person or corporation behind one or more businesses.
 *
 * A business is a trade name; GST/HST is the legal person's. Registration and
 * the small-supplier threshold therefore live here, and every business under
 * the entity counts toward the same threshold.
 *
 * @property int $id
 * @property string $name
 * @property LegalEntityType $entity_type
 * @property \Illuminate\Support\Carbon|null $tax_registered_from
 * @property int $threshold_warning_percent
 * @property ThresholdLevel|null $threshold_alert_level
 * @property \Illuminate\Support\Carbon|null $threshold_alerted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Collection<int, Business> $businesses
 * @property-read Collection<int, LegalEntity> $associates
 *
 * @method static \Database\Factories\Billing\LegalEntityFactory factory($count = null, $state = [])
 *
 * @mixin \Eloquent
 */
final class LegalEntity extends Model
{
    /** @use HasFactory<\Database\Factories\Billing\LegalEntityFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'entity_type',
        'tax_registered_from',
        'threshold_warning_percent',
    ];

    /** @return HasMany<Business, $this> */
    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    /**
     * Entities whose supplies are counted with this one's for the threshold.
     * Stored in both directions, so either side sees the other.
     *
     * @return BelongsToMany<LegalEntity, $this>
     */
    public function associates(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'legal_entity_associations',
            'legal_entity_id',
            'associated_legal_entity_id',
        )->withTimestamps();
    }

    /**
     * Replace this entity's associations, keeping both directions in step.
     *
     * @param  list<int>  $associateIds
     */
    public function syncAssociates(array $associateIds): void
    {
        $associateIds = array_values(array_unique(array_filter(
            array_map('intval', $associateIds),
            fn (int $id): bool => $id !== $this->id,
        )));

        DB::transaction(function () use ($associateIds): void {
            $previous = $this->associates()->pluck('legal_entities.id')->all();

            foreach (array_diff($previous, $associateIds) as $removedId) {
                self::query()->find($removedId)?->associates()->detach($this->id);
            }

            foreach ($associateIds as $associateId) {
                self::query()->find($associateId)?->associates()->syncWithoutDetaching([$this->id]);
            }

            $this->associates()->sync($associateIds);
        });

        $this->unsetRelation('associates');
    }

    /**
     * This entity and everyone it is associated with — the set whose supplies
     * are added together for the small-supplier test.
     *
     * @return Collection<int, LegalEntity>
     */
    public function thresholdGroup(): Collection
    {
        /** @var Collection<int, LegalEntity> $group */
        $group = new Collection([$this, ...$this->associates->all()]);

        return $group->unique('id')->values();
    }

    public function isTaxRegisteredOn(DateTimeInterface $on): bool
    {
        if ($this->tax_registered_from === null) {
            return false;
        }

        return $this->tax_registered_from->lessThanOrEqualTo($on);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity_type' => LegalEntityType::class,
            'tax_registered_from' => 'date',
            'threshold_warning_percent' => 'integer',
            'threshold_alert_level' => ThresholdLevel::class,
            'threshold_alerted_at' => 'datetime',
        ];
    }
}
