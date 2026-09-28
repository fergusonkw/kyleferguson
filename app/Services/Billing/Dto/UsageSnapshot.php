<?php

declare(strict_types=1);

namespace App\Services\Billing\Dto;

/**
 * What a metered service reported: how much of an allowance was consumed.
 *
 * A provider like SMTP2GO charges a flat fee for an allowance rather than for
 * consumption, so usage is not a price — it is the evidence of what the fee
 * bought, and a client reading "$30.00" deserves to see it. An invoice keeps
 * its own copy, because the live figure keeps moving long after the month it
 * describes has closed.
 */
final readonly class UsageSnapshot
{
    public function __construct(
        public string $label,
        public float $used,
        public ?float $included,
        public string $unit,
    ) {}

    /**
     * Rebuild from stored metadata. Anything without a usable figure is not a
     * usage reading at all, so it comes back as null rather than as a zero.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): ?self
    {
        $used = $attributes['used'] ?? null;

        if (! is_numeric($used)) {
            return null;
        }

        $included = $attributes['included'] ?? null;

        return new self(
            label: trim((string) ($attributes['label'] ?? '')),
            used: (float) $used,
            included: is_numeric($included) ? (float) $included : null,
            unit: trim((string) ($attributes['unit'] ?? '')),
        );
    }

    /**
     * How the usage reads to a client — "7,608 of 50,000 emails".
     */
    public function summary(): string
    {
        $figures = $this->included !== null
            ? self::number($this->used).' of '.self::number($this->included)
            : self::number($this->used);

        return $this->unit === '' ? $figures : $figures.' '.$this->unit;
    }

    public function exceedsIncluded(): bool
    {
        return $this->included !== null && $this->used > $this->included;
    }

    /**
     * @return array{label: string, used: float, included: float|null, unit: string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'used' => $this->used,
            'included' => $this->included,
            'unit' => $this->unit,
        ];
    }

    /**
     * Whole counts read as whole counts; a fractional measure keeps its
     * decimals so 1.5 GB is not reported as 2 GB.
     */
    private static function number(float $value): string
    {
        return $value === floor($value)
            ? number_format($value)
            : number_format($value, 2);
    }
}
