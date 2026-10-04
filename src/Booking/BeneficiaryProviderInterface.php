<?php

namespace Base\Office\Booking;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Who else a client may book for: health's dependants (a child, a parent
 * cared for). Each choice has a label - what the appointment shows - and
 * what the appointment keeps in its meta.
 */
#[AutoconfigureTag('office.beneficiary_provider')]
interface BeneficiaryProviderInterface
{
    /** @return array<string, array{label: string, meta: array}> keyed by an id of the provider's */
    public function beneficiaries(object $user): array;
}
