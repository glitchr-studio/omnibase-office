<?php

namespace Base\Office\Booking;

use Base\Office\Entity\Booking\Appointment;
use Base\Office\Enum\Channel;
use Base\Office\Repository\OfficeRepository;
use Base\Service\Calendar\CalendarEntry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An appointment as omnibase's calendar bricks read it (Base\Service\
 * Calendar\Ics, GoogleCalendarLink): "Rendez-vous - <office>", with whom,
 * where. Neither the reason nor the type's name: a calendar is synced to
 * phones and to Google, it is not a place for them.
 */
class CalendarEntries
{
    public function __construct(
        private readonly OfficeRepository $offices,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%office.timezone%')] private readonly string $timezone = 'Europe/Paris',
        #[Autowire('%office.booking.space_route%')] private readonly string $spaceRoute = 'office_space_appointments',
    ) {
    }

    public function for(Appointment $appointment, ?string $host = null): ?CalendarEntry
    {
        if (null === $appointment->getStartsAt()) {
            return null;
        }
        $tz = new \DateTimeZone($this->timezone);
        $office = $appointment->getOffice() ?? $this->offices->findMain();
        $where = match ($appointment->getChannel()) {
            Channel::HOME => $appointment->getAddress(),
            Channel::VIDEO => $this->translator->trans('channel.video', [], 'office'),
            Channel::PHONE => $this->translator->trans('channel.phone', [], 'office'),
            default => $office?->getAddress(),
        };
        try {
            $url = $this->urls->generate($this->spaceRoute, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            $url = null;
        }

        return new CalendarEntry(
            uid: sprintf('office-appointment-%d@%s', $appointment->getId(), $host ?? 'office'),
            title: $this->translator->trans('calendar.title', ['office' => (string) ($office?->getName() ?? '')], 'office'),
            start: $appointment->getStartsAt()->setTimezone($tz),
            end: $appointment->getEndsAt()?->setTimezone($tz),
            description: $this->translator->trans('calendar.description', ['member' => (string) $appointment->getMember(), 'phone' => (string) $office?->getPhone()], 'office'),
            location: $where,
            latitude: Channel::IN_PERSON === $appointment->getChannel() ? $office?->getLatitude() : null,
            longitude: Channel::IN_PERSON === $appointment->getChannel() ? $office?->getLongitude() : null,
            url: $url,
            cancelled: $appointment->isCancelled(),
            updatedAt: new \DateTimeImmutable(),
        );
    }
}
