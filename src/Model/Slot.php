<?php

namespace Base\Office\Model;

use Base\Office\Entity\Office;

/** A free time to book: its start and end in the office's timezone, where. */
final readonly class Slot
{
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public ?Office $office = null,
    ) {
    }

    /** "2026-10-12T09:30" in the office's timezone: what a URL carries. */
    public function key(): string
    {
        return $this->start->format('Y-m-d\TH:i');
    }

    public function __toString(): string
    {
        return $this->start->format('H:i');
    }
}
