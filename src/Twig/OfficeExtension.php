<?php

namespace Base\Office\Twig;

use Base\Office\Booking\SlotFinder;
use Base\Office\Compliance\Compliance;
use Base\Office\Entity\Booking\Appointment;
use Base\Office\Entity\Booking\AppointmentType;
use Base\Office\Entity\Member;
use Base\Office\Entity\Office;
use Base\Office\Entity\Share\Document;
use Base\Office\Entity\Visio\Room;
use Base\Office\Repository\MemberRepository;
use Base\Office\Repository\OfficeRepository;
use Base\Office\Repository\Share\DocumentRepository;
use Base\Office\Repository\WalkInRepository;
use Base\Office\Share\Cipher;
use Base\Office\Share\DocumentVault;
use Base\Office\Visio\Rooms;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The office in templates: office_main() (the main office), office_team(),
 * office_walk_ins(), office_next_slots(member, type), office_room(appointment)
 * (its video room, made when first asked), office_unread_documents(),
 * office_layout(); the filters |office_document_title, |office_reason.
 */
class OfficeExtension extends AbstractExtension
{
    public function __construct(
        private readonly OfficeRepository $offices,
        private readonly MemberRepository $members,
        private readonly WalkInRepository $walkIns,
        private readonly DocumentRepository $documents,
        private readonly SlotFinder $slots,
        private readonly DocumentVault $vault,
        private readonly Cipher $cipher,
        private readonly Rooms $rooms,
        private readonly Compliance $compliance,
        #[Autowire('%office.templates.layout%')] private readonly string $layout = 'base.html.twig',
        #[Autowire('%office.templates.space_nav%')] private readonly ?string $spaceNav = null,
        #[Autowire('%office.timezone%')] private readonly string $timezone = 'Europe/Paris',
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('office_main', fn (): ?Office => $this->offices->findMain()),
            new TwigFunction('office_offices', fn (): array => $this->offices->findOrdered()),
            new TwigFunction('office_team', fn (): array => $this->members->findVisible()),
            new TwigFunction('office_walk_ins', fn (): array => $this->walkIns->findActive()),
            new TwigFunction('office_next_slots', fn (Member $member, AppointmentType $type, int $count = 3): array => $this->slots->next($member, $type, $count)),
            new TwigFunction('office_room', fn (Appointment $appointment): ?Room => $appointment->isVideo() && null !== $appointment->getStartsAt() && $appointment->isActive() ? $this->rooms->forSubject($appointment) : null),
            new TwigFunction('office_unread_documents', fn (?object $user): int => null === $user ? 0 : $this->documents->countUnread($user)),
            new TwigFunction('office_compliance', fn (): array => $this->compliance->run()),
            new TwigFunction('office_person', fn (?object $user): string => null === $user ? '' : ($this->members->findOneByUser($user)?->getDisplayName() ?? (string) $user)),
            new TwigFunction('office_layout', fn (): string => $this->layout),
            new TwigFunction('office_space_nav', fn (): ?string => $this->spaceNav),
            new TwigFunction('office_timezone', fn (): string => $this->timezone),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('office_document_title', fn (Document $document): string => $this->vault->title($document)),
            new TwigFilter('office_document_filename', fn (Document $document): string => $this->vault->filename($document)),
            new TwigFilter('office_reason', fn (Appointment $appointment): ?string => $this->cipher->decryptText($appointment->getReasonCipher())),
            new TwigFilter('office_decrypt', fn (?string $sealed): ?string => $this->cipher->decryptText($sealed)),
            new TwigFilter('office_local', fn (?\DateTimeInterface $at): ?\DateTimeImmutable => $at ? \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone($this->timezone)) : null),
        ];
    }
}
