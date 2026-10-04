<?php

namespace Base\Office\Booking;

use Base\Office\Entity\Booking\Appointment;
use Base\Office\Event\AppointmentEvent;
use Base\Office\Repository\OfficeRepository;
use Base\Service\Calendar\GoogleCalendarLink;
use Base\Service\Calendar\Ics;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The client's e-mails: booked (with the .ics and the Google Calendar
 * link), request received, confirmed, moved, cancelled, reminded. They say
 * when, with whom, where and how to cancel - never why: the reason and the
 * type's name stay in the site.
 */
final class BookingMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly CalendarEntries $entries,
        private readonly Ics $ics,
        private readonly GoogleCalendarLink $google,
        private readonly OfficeRepository $offices,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        #[Autowire('%office.sender%')] private readonly string $sender,
        #[Autowire('%office.timezone%')] private readonly string $timezone = 'Europe/Paris',
        #[Autowire('%office.booking.space_route%')] private readonly string $spaceRoute = 'office_space_appointments',
    ) {
    }

    #[AsEventListener(event: AppointmentEvent::BOOKED)]
    public function onBooked(AppointmentEvent $event): void
    {
        $this->send($event, 'booked');
    }

    #[AsEventListener(event: AppointmentEvent::REQUESTED)]
    public function onRequested(AppointmentEvent $event): void
    {
        $this->send($event, 'requested');
    }

    #[AsEventListener(event: AppointmentEvent::CONFIRMED)]
    public function onConfirmed(AppointmentEvent $event): void
    {
        $this->send($event, 'confirmed');
    }

    #[AsEventListener(event: AppointmentEvent::MOVED)]
    public function onMoved(AppointmentEvent $event): void
    {
        $this->send($event, 'moved');
    }

    #[AsEventListener(event: AppointmentEvent::CANCELLED)]
    public function onCancelled(AppointmentEvent $event): void
    {
        $this->send($event, 'cancelled');
    }

    #[AsEventListener(event: AppointmentEvent::REMINDER)]
    public function onReminder(AppointmentEvent $event): void
    {
        $this->send($event, 'reminder');
    }

    private function send(AppointmentEvent $event, string $kind): void
    {
        $appointment = $event->appointment;
        $to = $appointment->getClientEmail();
        if (!$to) {
            return;
        }
        $appointments = $event->series ?: [$appointment];
        $entries = array_values(array_filter(array_map(fn (Appointment $a) => $this->entries->for($a), $appointments)));

        $email = (new TemplatedEmail())
            ->from($this->sender)
            ->to($to)
            ->subject($this->translator->trans('booking.email.'.$kind.'.subject', ['date' => $this->date($appointment)], 'office'))
            ->htmlTemplate('@Office/email/appointment.html.twig')
            ->context([
                'kind' => $kind,
                'appointment' => $appointment,
                'series' => \count($appointments) > 1 ? $appointments : [],
                'office' => $appointment->getOffice() ?? $this->offices->findMain(),
                'timezone' => $this->timezone,
                'google' => 1 === \count($entries) && 'cancelled' !== $kind ? $this->google->for($entries[0]) : null,
                'cancel_url' => $event->cancelToken && 'cancelled' !== $kind ? $this->urls->generate('office_booking_cancel', ['token' => $event->cancelToken], UrlGeneratorInterface::ABSOLUTE_URL) : null,
                'space_url' => $this->space(),
                'hours_before' => $event->hoursBefore,
            ]);
        if ([] !== $entries && 'requested' !== $kind) {
            $email->attach($this->ics->calendar($entries, $this->translator->trans('calendar.name', [], 'office')), 'rendez-vous.ics', 'text/calendar; charset=utf-8; method='.('cancelled' === $kind ? 'CANCEL' : 'PUBLISH'));
        }

        $this->mailer->send($email);
    }

    private function date(Appointment $appointment): string
    {
        if (null === $appointment->getStartsAt()) {
            return '';
        }
        $formatter = new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::FULL, \IntlDateFormatter::SHORT, $this->timezone);

        return (string) $formatter->format($appointment->getStartsAt());
    }

    private function space(): ?string
    {
        try {
            return $this->urls->generate($this->spaceRoute, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return null;
        }
    }
}
