<?php

namespace Base\Office\Service;

use Base\Office\Entity\Member;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A member read again from their professional register through
 * glitchr/omnistate: an RPPS number for a health professional, a bar
 * number later - this class does not know which, ProfessionalRegistry-
 * Interface does. What the register said is kept on the member
 * (registryData, registryCheckedAt); what the site shows is still the
 * office's to write.
 */
class MemberRegistry
{
    /** @param \Omnistate\Omnistate|null $omnistate wired when glitchr/omnistate is installed */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?object $omnistate = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return null !== $this->omnistate;
    }

    /**
     * Ask the register; null when the member has no number or nobody knows it.
     *
     * @return \Omnistate\Model\Professional|null
     *
     * @throws \Omnistate\Exception\UnavailableException the register did not answer (not "unknown")
     * @throws \Omnistate\Exception\NotSupportedException no register installed knows that kind of number
     */
    public function refresh(Member $member): ?object
    {
        if (null === $this->omnistate || null === $member->getRegistryId()) {
            return null;
        }
        $professional = $this->omnistate->professional($member->getRegistryId());
        $member->setRegistryData(null === $professional ? ['found' => false] : self::snapshot($professional));
        $this->entityManager->flush();

        return $professional;
    }

    /** What is worth keeping of the register's answer: no raw dump. */
    public static function snapshot(object $professional): array
    {
        return [
            'found' => true,
            'source' => $professional->source,
            'identifier' => $professional->identifier,
            'name' => $professional->name(),
            'prefix' => $professional->prefix,
            'profession' => $professional->profession ? ['code' => $professional->profession->code, 'label' => $professional->profession->label] : null,
            'specialties' => array_map(static fn ($q) => ['code' => $q->code, 'label' => $q->label], $professional->specialties),
            'workplaces' => array_map(static fn ($w) => ['name' => $w->name, 'address' => $w->address ? (string) $w->address : null, 'facility' => $w->facility, 'mode' => $w->mode, 'active' => $w->active], $professional->workplaces),
            'secureEmails' => $professional->secureEmails,
            'active' => $professional->active,
        ];
    }
}
