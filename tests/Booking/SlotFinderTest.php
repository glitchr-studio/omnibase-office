<?php

namespace Base\Office\Tests\Booking;

use Base\Office\Booking\SlotFinder;
use Base\Office\Entity\Booking\Absence;
use Base\Office\Entity\Booking\AppointmentType;
use Base\Office\Entity\Booking\Schedule;
use Base\Office\Entity\Booking\ServiceArea;
use Base\Office\Entity\Member;
use Base\Office\Entity\Office;
use Base\Office\Model\Slot;
use Base\Office\Repository\Booking\AbsenceRepository;
use Base\Office\Repository\Booking\AppointmentRepository;
use Base\Office\Repository\Booking\ScheduleRepository;
use PHPUnit\Framework\TestCase;

/**
 * The computation of free slots, on what would have been read from the
 * database: schedules, absences, held times - Europe/Paris, the night the
 * clocks go back (25 October 2026) included.
 */
final class SlotFinderTest extends TestCase
{
    private \DateTimeZone $paris;
    private Member $member;

    protected function setUp(): void
    {
        $this->paris = new \DateTimeZone('Europe/Paris');
        $this->member = new Member('Dr Exemple');
    }

    public function testAMorningOfTwentyMinuteSlots(): void
    {
        $slots = $this->compute([$this->schedule(1, '09:00', '10:00')], type: new AppointmentType('Consultation', 20));

        self::assertSame(['09:00', '09:20', '09:40'], $this->times($slots), 'Monday 12 October 2026');
        self::assertSame('2026-10-12T09:20', $slots[1]->key());
        self::assertSame('09:40', $slots[1]->end->format('H:i'));
    }

    public function testTheBufferSpacesTheSlotsAndKeepsTheLastOneInsideTheSchedule(): void
    {
        $type = (new AppointmentType('Consultation', 20))->setBuffer(10);

        self::assertSame(['09:00', '09:30'], $this->times($this->compute([$this->schedule(1, '09:00', '10:00')], type: $type)), '30 minutes apart; a third would end at 10:20');
    }

    public function testAnAppointmentHeldTakesItsSlotAndItsBuffer(): void
    {
        $type = new AppointmentType('Consultation', 20);
        // 09:20-09:40 held, with ten minutes of buffer after it: 09:40 would start inside the buffer.
        $busy = [[$this->at('2026-10-12 09:20'), $this->at('2026-10-12 09:50')]];

        self::assertSame(['09:00', '10:00'], $this->times($this->compute([$this->schedule(1, '09:00', '10:20')], busy: $busy, type: $type)));
    }

    public function testAnAbsenceRemovesWhatItOverlaps(): void
    {
        $absence = new Absence($this->member, $this->at('2026-10-12 09:30'), $this->at('2026-10-12 12:00'));

        self::assertSame(['09:00'], $this->times($this->compute([$this->schedule(1, '09:00', '10:00')], absences: [$absence], type: new AppointmentType('Consultation', 20))), '09:20 would end at 09:40, inside the absence');
    }

    public function testClientsNeedNoticeAndStayWithinTheHorizonTheStaffDoesNot(): void
    {
        $schedules = [$this->schedule(1, '09:00', '10:00')];
        $type = new AppointmentType('Consultation', 20);
        $now = $this->at('2026-10-12 08:30');

        self::assertSame([], $this->times($this->compute($schedules, type: $type, now: $now)), 'two hours of notice: nothing left that morning');
        self::assertSame(['09:00', '09:20', '09:40'], $this->times($this->compute($schedules, type: $type, now: $now, staff: true)));

        $far = $this->finder()->compute($schedules, [], [], $type, $this->at('2027-03-01 00:00'), $this->at('2027-03-02 00:00'), $now);
        self::assertSame([], $far, 'beyond the 60 days of the horizon');
    }

    public function testAScheduleHoldsOnlyItsTypesAndItsValidity(): void
    {
        $consultation = new AppointmentType('Consultation', 20);
        $vaccination = new AppointmentType('Vaccination', 10);
        $schedule = $this->schedule(1, '09:00', '09:40')->addAppointmentType($consultation);
        $summer = $this->schedule(1, '14:00', '14:40')->setValidFrom(new \DateTimeImmutable('2026-07-01'))->setValidUntil(new \DateTimeImmutable('2026-08-31'));

        self::assertTrue($schedule->accepts($consultation));
        self::assertFalse($schedule->accepts($vaccination));
        self::assertSame(['09:00', '09:20'], $this->times($this->compute([$schedule, $summer], type: $consultation)), 'the summer timetable does not apply in October');
    }

    public function testTheNightTheClocksGoBackHasItsTwoOClockTwice(): void
    {
        // Sunday 25 October 2026: at 3:00 (CEST) it is 2:00 (CET) again. A duty from 1:00 to 4:00 lasts four hours.
        $slots = $this->compute([$this->schedule(7, '01:00', '04:00')], type: new AppointmentType('Garde', 30), from: '2026-10-25 00:00', to: '2026-10-26 00:00', now: '2026-10-20 12:00');

        self::assertSame(['01:00', '01:30', '02:00', '02:30', '02:00', '02:30', '03:00', '03:30'], $this->times($slots));
        self::assertSame(['+02:00', '+02:00', '+02:00', '+02:00', '+01:00', '+01:00', '+01:00', '+01:00'], array_map(static fn (Slot $s) => $s->start->format('P'), $slots));
        self::assertCount(8, array_unique(array_map(static fn (Slot $s) => $s->start->getTimestamp(), $slots)), 'eight different moments');
    }

    public function testAMorningIsTheSameWallClockTimeBeforeAndAfterTheChange(): void
    {
        $schedules = [$this->schedule(6, '08:00', '08:40'), $this->schedule(1, '08:00', '08:40')];
        $type = new AppointmentType('Consultation', 20);
        $saturday = $this->compute($schedules, type: $type, from: '2026-10-24 00:00', to: '2026-10-25 00:00', now: '2026-10-20 12:00');
        $monday = $this->compute($schedules, type: $type, from: '2026-10-26 00:00', to: '2026-10-27 00:00', now: '2026-10-20 12:00');

        self::assertSame(['08:00', '08:20'], $this->times($saturday));
        self::assertSame(['08:00', '08:20'], $this->times($monday));
        self::assertSame('06:00', $saturday[0]->start->setTimezone(new \DateTimeZone('UTC'))->format('H:i'), 'summer time');
        self::assertSame('07:00', $monday[0]->start->setTimezone(new \DateTimeZone('UTC'))->format('H:i'), 'winter time');
    }

    public function testTheSpringChangeSkipsTheHourThatDoesNotExist(): void
    {
        // Sunday 28 March 2027: 2:00 becomes 3:00. A duty from 1:00 to 4:00 lasts two hours.
        $slots = $this->compute([$this->schedule(7, '01:00', '04:00')], type: new AppointmentType('Garde', 30), from: '2027-03-28 00:00', to: '2027-03-29 00:00', now: '2027-03-20 12:00');

        self::assertSame(['01:00', '01:30', '03:00', '03:30'], $this->times($slots));
    }

    public function testAServiceAreaByPostcodeTownOrRadius(): void
    {
        $office = (new Office('Cabinet'))->setLatitude(48.2)->setLongitude(7.45);
        $area = (new ServiceArea('Tournée', ['68320', '67 390'], ['Riedwihr', 'Jebsheim']))->setOffice($office)->setRadiusKm(5.0);

        self::assertTrue($area->contains('68320'));
        self::assertTrue($area->contains('67390'), 'spaces aside');
        self::assertTrue($area->contains('00000', 'JEBSHEIM'), 'by town, case aside');
        self::assertTrue($area->contains('00000', null, 48.22, 7.46), 'two kilometres away');
        self::assertFalse($area->contains('67000', 'Strasbourg', 48.58, 7.75));
    }

    /** @return list<Slot> */
    private function compute(array $schedules, array $absences = [], array $busy = [], ?AppointmentType $type = null, string $from = '2026-10-12 00:00', string $to = '2026-10-13 00:00', \DateTimeInterface|string $now = '2026-10-05 12:00', bool $staff = false): array
    {
        return $this->finder()->compute($schedules, $absences, $busy, $type ?? new AppointmentType('Consultation', 20), $this->at($from), $this->at($to), \is_string($now) ? $this->at($now) : $now, $staff);
    }

    private function finder(): SlotFinder
    {
        return new SlotFinder($this->createStub(ScheduleRepository::class), $this->createStub(AbsenceRepository::class), $this->createStub(AppointmentRepository::class), null, 'Europe/Paris', 120, 60, 0);
    }

    private function schedule(int $day, string $from, string $to): Schedule
    {
        return new Schedule($this->member, $day, $from, $to);
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, $this->paris);
    }

    /** @param list<Slot> $slots */
    private function times(array $slots): array
    {
        return array_map(static fn (Slot $slot) => $slot->start->format('H:i'), $slots);
    }
}
