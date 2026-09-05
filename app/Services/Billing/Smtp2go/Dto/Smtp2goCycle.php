<?php

declare(strict_types=1);

namespace App\Services\Billing\Smtp2go\Dto;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The `/stats/email_cycle` response: where the account sits in its current
 * billing cycle. SMTP2GO exposes no cost figure, so this is usage only.
 */
final readonly class Smtp2goCycle
{
    public function __construct(
        public ?Carbon $cycleStart,
        public ?Carbon $cycleEnd,
        public int $used,
        public int $remaining,
        public int $max,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the `data` object of the response
     */
    public static function fromApiPayload(array $payload): self
    {
        if (! array_key_exists('cycle_used', $payload)) {
            throw new InvalidArgumentException('SMTP2GO cycle payload is missing cycle_used.');
        }

        return new self(
            cycleStart: self::parseDate($payload['cycle_start'] ?? null),
            cycleEnd: self::parseDate($payload['cycle_end'] ?? null),
            used: (int) $payload['cycle_used'],
            remaining: (int) ($payload['cycle_remaining'] ?? 0),
            max: (int) ($payload['cycle_max'] ?? 0),
        );
    }

    /**
     * Whether the reported cycle actually overlaps the billing period being
     * synced. SMTP2GO only ever returns the *current* cycle, so a backfill of
     * an older period carries usage that does not describe it.
     */
    public function coversPeriod(string $period): bool
    {
        if ($this->cycleStart === null || $this->cycleEnd === null) {
            return false;
        }

        $periodStart = Carbon::createFromFormat('Y-m', $period)->startOfMonth();

        return $this->cycleStart->lessThanOrEqualTo($periodStart->copy()->endOfMonth())
            && $this->cycleEnd->greaterThanOrEqualTo($periodStart);
    }

    public function isOverQuota(): bool
    {
        return $this->max > 0 && $this->used > $this->max;
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        return [
            'cycle_start' => $this->cycleStart?->toIso8601String(),
            'cycle_end' => $this->cycleEnd?->toIso8601String(),
            'cycle_used' => $this->used,
            'cycle_remaining' => $this->remaining,
            'cycle_max' => $this->max,
        ];
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
