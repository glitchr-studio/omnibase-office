<?php

namespace Base\Office\Compliance;

/**
 * One thing the back office's "Compliance" widget checks: the vault's key
 * is set, the legal notice names the host, the fees are displayed... The
 * office has its own; each regime adds its trade's (autoconfigured, tag
 * office.compliance_check).
 */
interface ComplianceCheckInterface
{
    public function check(): ComplianceResult;
}
