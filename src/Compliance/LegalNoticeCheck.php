<?php

namespace Base\Office\Compliance;

use Base\Service\SettingBagInterface;

/** The legal notice names the host (and, in the settings, who to write to about one's data). */
final class LegalNoticeCheck implements ComplianceCheckInterface
{
    public function __construct(private readonly SettingBagInterface $settings)
    {
    }

    public function check(): ComplianceResult
    {
        try {
            $host = trim((string) $this->settings->getScalar('office.legal.host'));
            $dpo = trim((string) $this->settings->getScalar('office.legal.dpo'));
        } catch (\Throwable) {
            $host = $dpo = '';
        }
        if ('' === $host) {
            return new ComplianceResult('compliance.legal_host', ComplianceResult::MISSING, 'compliance.legal_host_advice');
        }

        return '' === $dpo ? new ComplianceResult('compliance.legal_host', ComplianceResult::WARNING, 'compliance.legal_dpo_advice') : ComplianceResult::ok('compliance.legal_host');
    }
}
