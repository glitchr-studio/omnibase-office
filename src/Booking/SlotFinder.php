<?php

namespace Base\Office\Booking;

use Base\Office\Entity\Booking\Absence;
use Base\Office\Entity\Booking\Appointment;
use Base\Office\Entity\Booking\AppointmentType;
use Base\Office\Entity\Booking\Schedule;
use Base\Office\Entity\Member;
use Base\Office\Entity\Office;
use Base\Office\Model\Slot;
use Base\Office\Repository\Booking\AbsenceRepository;
use Base\Office\Repository\Booking\AppointmentRepository;
use Base\Office\Repository\Booking\ScheduleRepository;
use Base\Service\OpeningHours;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The free slots of one member for one appointment type: the weekly
 * schedules, less the member's absences, less the days the office is
 * closed (omnibase's special days), less the appointments already held
 * and the buffer after each, less what is too soon (min_notice) or too far
 * (horizon) - those two only for clients, not for the staff.
 *
 * Times are walked in seconds from the schedule's start written in the
 * office's timezone: on the night the clocks go back, a schedule from 1:00
 * to 4:00 lasts four hours and offers its 2:00 slot twice, as the clock on
 * the wall does.
 *
 * One member at a time: a restaurant's tables are another engine.
 */
class SlotFinder
{
    private readonly \DateTimeZone $timezone;

    public function __construct(
        private readonly ScheduleRepository $schedules,
        private readonly AbsenceRepository $absences,
        private readonly AppointmentRepository $appointments,
        private readonly ?OpeningHours $openingHours = null,
        #[Autowire('%office.timezone%')] string $timezone = 'Europe/Paris',
        #[Autowire('%office.booking.min_notice%')] private readonly int $minNotice = 120,
        #[Autowire('%office.booking.horizon%')] private readonly int $horizon = 60,
        #[Autowire('%office.booking.step%')] private readonly int $step = 0,
    ) {
        $this->timezone = new \DateTimeZone($timezone);
    }

    public function timezone(): \DateTimeZone
    {
        return $this->timezone;
    }

    /**
     * @return list<Slot> the free slots in [from, to), earliest first
     */
    public function find(Member $member, AppointmentType $type, \DateTimeInterface $from, \DateTimeInterface $to, ?Office $office = null, bool $staff = false, ?\DateTimeInterface $now = null, ?Appointment $ignore = null): array
    {
        $busy = [];
        foreach ($this->appointments->findActiveBetween($member, (new \DateTimeImmutable('@'.$from->getTimestamp()))->modify('-1 day'), (new \DateTimeImmutable('@'.$to->getTimestamp()))->modify('+1 day')) as $appointment) {
            if ($ignore && $appointment->getId() === $ignore->getId()) {
                continue;
            }
            $busy[] = [$appointment->getStartsAt(), $appointment->getEndsAt()->modify(sprintf('+%d minutes', $appointment->getType()?->getBuffer() ?? 0))];
        }

        return $this->compute(
            array_values(array_filter($this->schedules->findForMember($member), static fn (Schedule $s) => $s->accepts($type) && (null === $office || null === $s->getOffice() || $s->getOffice() === $office))),
            $this->absences->findOverlapping($member, $from, $to),
            $busy,
            $type,
            $from,
            $to,
            $now ?? new \DateTimeImmutable(),
            $staff,
        );
    }

    /** Whether that start is one of the free slots (the booking checks it again under its lock). */
    public function isFree(Member $member, AppointmentType $type, \DateTimeInterface $start, ?Office $office = null, bool $staff = false, ?\DateTimeInterface $now = null, ?Appointment $ignore = null): bool
    {
        $day = (new \DateTimeImmutable('@'.$start->getTimestamp()))->setTimezone($this->timezone)->setTime(0, 0);
        foreach ($this->find($member, $type, $day, $day->modify('+1 day'), $office, $staff, $now, $ignore) as $slot) {
            if ($slot->start->getTimestamp() === $start->getTimestamp()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Slot> the next free slots, looking up to the horizon */
    public function next(Member $member, AppointmentType $type, int $count = 3, ?Office $office = null, ?\DateTimeInterface $now = null): array
    {
        $now = $now ? \DateTimeImmutable::createFromInterface($now) : new \DateTimeImmutable();
        $from = $now->setTimezone($this->timezone)->setTime(0, 0);
        $found = [];
        for ($week = 0; $week * 7 < $this->horizon && \count($found) < $count; ++$week) {
            $start = $from->modify(sprintf('+%d days', $week * 7));
            foreach ($this->find($member, $type, $start, $start->modify('+7 days'), $office, false, $now) as $slot) {
                $found[] = $slot;
                if (\count($found) >= $count) {
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The computation itself, on what was read: testable without a database.
     *
     * @param list<Schedule> $schedules
     * @param list<Absence> $absences
     * @param list<array{0: \DateTimeInterface, 1: \DateTimeInterface}> $busy held times, buffers included
     *
     * @return list<Slot>
     */
    public function compute(array $schedules, array $absences, array $busy, AppointmentType $type, \DateTimeInterface $from, \DateTimeInterface $to, \DateTimeInterface $now, bool $staff = false): array
    {
        $duration = $type->getDuration() * 60;
        $span = $type->getSpan() * 60;
        $step = ($this->step > 0 ? $this->step : $type->getSpan()) * 60;
        $earliest = $staff ? PHP_INT_MIN : $now->getTimestamp() + $this->minNotice * 60;
        $latest = $staff ? PHP_INT_MAX : $now->getTimestamp() + $this->horizon * 86400;
        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();

        $slots = [];
        $day = (new \DateTimeImmutable('@'.$fromTs))->setTimezone($this->timezone)->setTime(0, 0);
        $lastDay = (new \DateTimeImmutable('@'.$toTs))->setTimezone($this->timezone)->setTime(0, 0);
        for (; $day <= $lastDay; $day = $day->modify('+1 day')->setTime(0, 0)) {
            if ($this->isClosed($day)) {
                continue;
            }
            foreach ($schedules as $schedule) {
                if (!$schedule->appliesOn($day)) {
                    continue;
                }
                [$sh, $sm] = array_map('intval', explode(':', $schedule->getStartsAt()));
                [$eh, $em] = array_map('intval', explode(':', $schedule->getEndsAt()));
                $begin = $day->setTime($sh, $sm)->getTimestamp();
                $end = $day->setTime($eh, $em)->getTimestamp();

                for ($t = $begin; $t + $duration <= $end; $t += $step) {
                    if ($t < $fromTs || $t >= $toTs || $t < $earliest || $t > $latest) {
                        continue;
                    }
                    if ($this->overlapsAbsence($absences, $t, $t + $duration) || $this->overlapsBusy($busy, $t, $t + $span)) {
                        continue;
                    }
                    $start = (new \DateTimeImmutable('@'.$t))->setTimezone($this->timezone);
                    $slots[$t] = new Slot($start, (new \DateTimeImmutable('@'.($t + $duration)))->setTimezone($this->timezone), $schedule->getOffice() ?? $type->getOffice());
                }
            }
        }
        ksort($slots);

        return array_values($slots);
    }

    /** A closed special day of omnibase's opening hours: nobody receives. */
    private function isClosed(\DateTimeImmutable $day): bool
    {
        return true === $this->openingHours?->specialOn($day)?->isClosed();
    }

    /** @param list<Absence> $absences */
    private function overlapsAbsence(array $absences, int $start, int $end): bool
    {
        foreach ($absences as $absence) {
            if ($absence->getStartsAt()->getTimestamp() < $end && $absence->getEndsAt()->getTimestamp() > $start) {
                return true;
            }
        }

        return false;
    }

    private function overlapsBusy(array $busy, int $start, int $end): bool
    {
        foreach ($busy as [$from, $to]) {
            if ($from->getTimestamp() < $end && $to->getTimestamp() > $start) {
                return true;
            }
        }

        return false;
    }
}
