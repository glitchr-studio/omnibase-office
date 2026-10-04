<?php

namespace Base\Office\Event;

use Base\Office\Entity\Booking\Appointment;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Something happened to an appointment. Base\Office\Booking\BookingMailer
 * mails the client (never the reason, never the type's name); an
 * application may add a push, an SMS.
 */
final class AppointmentEvent extends Event
{
    public const BOOKED = 'office.appointment.booked';
    public const REQUESTED = 'office.appointment.requested';
    public const CONFIRMED = 'office.appointment.confirmed';
    public const MOVED = 'office.appointment.moved';
    public const CANCELLED = 'office.appointment.cancelled';
    public const REMINDER = 'office.appointment.reminder';

    /** @param list<Appointment> $series the others booked with it */
    public function __construct(
        public readonly Appointment $appointment,
        public readonly ?string $cancelToken = null,
        public readonly array $series = [],
        public readonly ?int $hoursBefore = null,
        public readonly bool $byStaff = false,
    ) {
    }
}
