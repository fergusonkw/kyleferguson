<?php

declare(strict_types=1);

namespace App\Services\Billing\Dto;

/**
 * Outcome of an attribution pass over one business/period.
 */
final readonly class AttributionResult
{
    public function __construct(
        public int $processed = 0,
        public int $attributed = 0,
        public int $unattributed = 0,
        public int $overhead = 0,
        public int $changed = 0,
    ) {}

    public function withAttributed(bool $changed): self
    {
        return new self(
            processed: $this->processed + 1,
            attributed: $this->attributed + 1,
            unattributed: $this->unattributed,
            overhead: $this->overhead,
            changed: $this->changed + ($changed ? 1 : 0),
        );
    }

    public function withUnattributed(bool $changed): self
    {
        return new self(
            processed: $this->processed + 1,
            attributed: $this->attributed,
            unattributed: $this->unattributed + 1,
            overhead: $this->overhead,
            changed: $this->changed + ($changed ? 1 : 0),
        );
    }

    public function withOverhead(bool $changed): self
    {
        return new self(
            processed: $this->processed + 1,
            attributed: $this->attributed,
            unattributed: $this->unattributed,
            overhead: $this->overhead + 1,
            changed: $this->changed + ($changed ? 1 : 0),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'processed' => $this->processed,
            'attributed' => $this->attributed,
            'unattributed' => $this->unattributed,
            'overhead' => $this->overhead,
            'changed' => $this->changed,
        ];
    }
}
