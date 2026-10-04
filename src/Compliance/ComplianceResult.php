<?php

namespace Base\Office\Compliance;

final readonly class ComplianceResult
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const MISSING = 'missing';

    public function __construct(
        /** A translation key of the domain given (its label). */
        public string $label,
        public string $status,
        /** What to do about it, a translation key too; null when all is well. */
        public ?string $advice = null,
        public string $domain = 'office',
        public array $parameters = [],
    ) {
    }

    public static function ok(string $label, string $domain = 'office'): self
    {
        return new self($label, self::OK, null, $domain);
    }

    public function isOk(): bool
    {
        return self::OK === $this->status;
    }
}
