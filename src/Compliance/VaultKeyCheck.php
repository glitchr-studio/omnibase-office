<?php

namespace Base\Office\Compliance;

use Base\Office\Share\Cipher;

final class VaultKeyCheck implements ComplianceCheckInterface
{
    public function __construct(private readonly Cipher $cipher)
    {
    }

    public function check(): ComplianceResult
    {
        return $this->cipher->isConfigured()
            ? ComplianceResult::ok('compliance.vault_key')
            : new ComplianceResult('compliance.vault_key', ComplianceResult::MISSING, 'compliance.vault_key_advice');
    }
}
