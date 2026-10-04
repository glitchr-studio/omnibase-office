<?php

namespace Base\Office\Compliance;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Every check, run: the widget's list. */
class Compliance
{
    /** @var iterable<ComplianceCheckInterface> */
    private readonly iterable $checks;

    /** @param iterable<ComplianceCheckInterface> $checks */
    public function __construct(#[AutowireIterator('office.compliance_check')] iterable $checks = [])
    {
        $this->checks = $checks;
    }

    /** @return list<ComplianceResult> */
    public function run(): array
    {
        $results = [];
        foreach ($this->checks as $check) {
            try {
                $results[] = $check->check();
            } catch (\Throwable $e) {
                $results[] = new ComplianceResult(\get_class($check), ComplianceResult::WARNING, 'compliance.failed', 'office', ['error' => $e->getMessage()]);
            }
        }

        return $results;
    }
}
