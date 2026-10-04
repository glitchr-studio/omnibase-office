<?php

namespace Base\Office\Booking;

use App\Entity\User;
use Base\Office\Entity\Booking\Appointment;
use Base\Office\Entity\Booking\AppointmentType;
use Base\Office\Entity\Member;
use Base\Office\Entity\Office;
use Base\Office\Enum\AppointmentSource;
use Base\Office\Enum\AppointmentStatus;
use Base\Office\Event\AppointmentEvent;
use Base\Office\Exception\BookingException;
use Base\Office\Repository\Booking\AppointmentRepository;
use Base\Office\Share\Cipher;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Booking, moving, cancelling. A slot is taken under a lock on the member's
 * day, after asking the SlotFinder again; the unique slot key is the last
 * word if two servers race. The client's reason is sealed by the Cipher
 * before it is stored.
 */
class Booker
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AppointmentRepository $appointments,
        private readonly SlotFinder $slots,
        private readonly BookingPolicy $policy,
        private readonly LockFactory $locks,
        private readonly Cipher $cipher,
        private readonly EventDispatcherInterface $dispatcher,
        #[Autowire('%office.booking.cancel_until%')] private readonly int $cancelUntil = 24,
    ) {
    }

    /**
     * A slot booked. Online, it must be one of the free slots and the type
     * open to that client; by the staff, it must only not overlap another.
     *
     * @param array{name?: ?string, email?: ?string, phone?: ?string, beneficiary?: ?string} $details
     */
    public function book(
        AppointmentType $type,
        Member $member,
        \DateTimeInterface $start,
        ?User $client,
        AppointmentSource $source = AppointmentSource::ONLINE,
        ?string $reason = null,
        ?Office $office = null,
        ?string $address = null,
        array $details = [],
        ?User $by = null,
        array $meta = [],
    ): Appointment {
        $staff = AppointmentSource::ONLINE !== $source;
        if (!$staff && !$this->policy->canBook($type, $member, $client)) {
            throw new BookingException('booking.error.not_bookable');
        }
        if ($type->isRequest() && !$staff) {
            throw new BookingException('booking.error.request_only');
        }

        $lock = $this->locks->createLock($this->lockKey($member, $start), 30);
        $lock->acquire(true);
        try {
            $this->assertFree($type, $member, $start, $office, $staff);

            $appointment = new Appointment($member, $type);
            $appointment->setStatus($type->isManualConfirmation() && !$staff ? AppointmentStatus::REQUESTED : AppointmentStatus::CONFIRMED)
                ->setSource($source)
                ->setStartsAt($start)
                ->setOffice(\Base\Office\Enum\Channel::IN_PERSON === $type->getChannel() ? (($office ?? $type->getOffice() ?? $member->getOffices()->first()) ?: null) : $office)
                ->setAddress($address)
                ->setClient($client)
                ->setCreatedBy($by ?? $client);
            $this->fill($appointment, $reason, $details, $meta);
            $token = $this->issueCancelToken($appointment);

            $this->save($appointment);
        } finally {
            $lock->release();
        }

        $this->dispatcher->dispatch(new AppointmentEvent($appointment, $token, byStaff: $staff), AppointmentStatus::REQUESTED === $appointment->getStatus() ? AppointmentEvent::REQUESTED : AppointmentEvent::BOOKED);

        return $appointment;
    }

    /**
     * A request without a time: the office fixes it later (confirm()). With
     * no member chosen, the first who offers the type.
     *
     * @param array{name?: ?string, email?: ?string, phone?: ?string, beneficiary?: ?string} $details
     */
    public function request(AppointmentType $type, ?Member $member, ?User $client, ?string $message = null, ?string $address = null, array $details = [], array $meta = []): Appointment
    {
        $member ??= $type->getMembers()->first() ?: null;
        if (null === $member || !$this->policy->canBook($type, $member, $client)) {
            throw new BookingException('booking.error.not_bookable');
        }

        $appointment = new Appointment($member, $type);
        $appointment->setStatus(AppointmentStatus::REQUESTED)
            ->setSource(AppointmentSource::ONLINE)
            ->setClient($client)
            ->setAddress($address)
            ->setOffice($type->getOffice())
            ->setCreatedBy($client);
        $this->fill($appointment, $message, $details, $meta);
        $token = $this->issueCancelToken($appointment);
        $this->save($appointment);

        $this->dispatcher->dispatch(new AppointmentEvent($appointment, $token), AppointmentEvent::REQUESTED);

        return $appointment;
    }

    /** A request accepted, at the time the office fixes (or the slot it already had). */
    public function confirm(Appointment $appointment, ?\DateTimeInterface $start = null, ?Member $member = null): Appointment
    {
        if (null !== $member) {
            $appointment->setMember($member);
        }
        $start ??= $appointment->getStartsAt();
        if (null === $start) {
            throw new BookingException('booking.error.no_time');
        }
        $lock = $this->locks->createLock($this->lockKey($appointment->getMember(), $start), 30);
        $lock->acquire(true);
        try {
            $this->assertFree($appointment->getType(), $appointment->getMember(), $start, $appointment->getOffice(), true, $appointment);
            $appointment->setStartsAt($start)->setStatus(AppointmentStatus::CONFIRMED);
            $this->save($appointment);
        } finally {
            $lock->release();
        }
        $this->dispatcher->dispatch(new AppointmentEvent($appointment, byStaff: true), AppointmentEvent::CONFIRMED);

        return $appointment;
    }

    /** Moved by the staff: only another appointment can stop it. */
    public function move(Appointment $appointment, \DateTimeInterface $start, ?Member $member = null): Appointment
    {
        $member ??= $appointment->getMember();
        $lock = $this->locks->createLock($this->lockKey($member, $start), 30);
        $lock->acquire(true);
        try {
            $this->assertFree($appointment->getType(), $member, $start, $appointment->getOffice(), true, $appointment);
            $appointment->setMember($member)->setStartsAt($start)->resetReminders();
            $this->save($appointment);
        } finally {
            $lock->release();
        }
        $this->dispatcher->dispatch(new AppointmentEvent($appointment, byStaff: true), AppointmentEvent::MOVED);

        return $appointment;
    }

    /**
     * Cancelled: by the client up to office.booking.cancel_until hours
     * before, by the staff at any time.
     */
    public function cancel(Appointment $appointment, bool $byStaff = false, ?\DateTimeInterface $now = null): void
    {
        if (!$appointment->isActive()) {
            throw new BookingException('booking.error.not_active');
        }
        $now ??= new \DateTimeImmutable();
        if (!$byStaff && null !== $appointment->getStartsAt() && $appointment->getStartsAt()->getTimestamp() - $now->getTimestamp() < $this->cancelUntil * 3600) {
            throw new BookingException('booking.error.too_late', ['hours' => $this->cancelUntil]);
        }
        $appointment->setStatus(AppointmentStatus::CANCELLED);
        $this->entityManager->flush();
        $this->dispatcher->dispatch(new AppointmentEvent($appointment, byStaff: $byStaff), AppointmentEvent::CANCELLED);
    }

    /**
     * A run of appointments booked together by the staff - care every day at
     * 8:00 for ten days: all or none, none overlapping another.
     *
     * @param array{name?: ?string, email?: ?string, phone?: ?string, beneficiary?: ?string} $details
     *
     * @return list<Appointment>
     */
    public function series(AppointmentType $type, Member $member, \DateTimeInterface $first, int $count, \DateInterval $every, ?User $client, ?string $address = null, array $details = [], ?User $by = null, array $meta = []): array
    {
        if ($count < 1 || $count > 120) {
            throw new BookingException('booking.error.series_length');
        }
        $series = Uuid::v4()->toRfc4122();
        $starts = [];
        $start = \DateTimeImmutable::createFromInterface($first);
        for ($i = 0; $i < $count; ++$i) {
            $starts[] = $start;
            $start = $start->add($every);
        }

        $locks = [];
        foreach (array_unique(array_map(fn (\DateTimeImmutable $s) => $this->lockKey($member, $s), $starts)) as $key) {
            $lock = $this->locks->createLock($key, 60);
            $lock->acquire(true);
            $locks[] = $lock;
        }
        try {
            foreach ($starts as $s) {
                $this->assertFree($type, $member, $s, null, true);
            }
            $appointments = [];
            foreach ($starts as $s) {
                $appointment = new Appointment($member, $type);
                $appointment->setStatus(AppointmentStatus::CONFIRMED)->setSource(AppointmentSource::STAFF)->setStartsAt($s)
                    ->setClient($client)->setAddress($address)->setSeries($series)->setCreatedBy($by);
                $this->fill($appointment, null, $details, $meta);
                $this->issueCancelToken($appointment);
                $this->entityManager->persist($appointment);
                $appointments[] = $appointment;
            }
            $this->save(null);
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
        $this->dispatcher->dispatch(new AppointmentEvent($appointments[0], series: $appointments, byStaff: true), AppointmentEvent::BOOKED);

        return $appointments;
    }

    public function reason(Appointment $appointment): ?string
    {
        return $this->cipher->decryptText($appointment->getReasonCipher());
    }

    private function assertFree(AppointmentType $type, Member $member, \DateTimeInterface $start, ?Office $office, bool $staff, ?Appointment $ignore = null): void
    {
        if (!$staff) {
            if (!$this->slots->isFree($member, $type, $start, $office)) {
                throw new BookingException('booking.error.slot_taken');
            }

            return;
        }
        $begin = \DateTimeImmutable::createFromInterface($start);
        $end = $begin->modify(sprintf('+%d minutes', $type->getSpan()));
        foreach ($this->appointments->findActiveBetween($member, $begin->modify('-1 day'), $end->modify('+1 day')) as $other) {
            if ($ignore && $other->getId() === $ignore->getId()) {
                continue;
            }
            $otherEnd = $other->getEndsAt()->modify(sprintf('+%d minutes', $other->getType()?->getBuffer() ?? 0));
            if ($other->getStartsAt() < $end && $otherEnd > $begin) {
                throw new BookingException('booking.error.overlap', ['at' => $other->getStartsAt()->setTimezone($this->slots->timezone())->format('d/m H:i')]);
            }
        }
    }

    private function fill(Appointment $appointment, ?string $reason, array $details, array $meta): void
    {
        $reason = null !== $reason ? trim($reason) : null;
        // The Cipher refuses without a key: a reason is never stored in clear.
        $appointment->setReasonCipher('' !== (string) $reason ? $this->cipher->encryptText(mb_substr($reason, 0, 2000)) : null)
            ->setClientName($details['name'] ?? null)
            ->setClientEmail($details['email'] ?? null)
            ->setClientPhone($details['phone'] ?? null)
            ->setBeneficiary($details['beneficiary'] ?? null);
        foreach ($meta as $key => $value) {
            $appointment->setMetaValue($key, $value);
        }
    }

    private function issueCancelToken(Appointment $appointment): string
    {
        $token = bin2hex(random_bytes(24));
        $appointment->setCancelTokenHash(hash('sha256', $token));

        return $token;
    }

    private function save(?Appointment $appointment): void
    {
        if ($appointment) {
            $this->entityManager->persist($appointment);
        }
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new BookingException('booking.error.slot_taken');
        }
    }

    private function lockKey(Member $member, \DateTimeInterface $start): string
    {
        return sprintf('office.booking.%d.%s', $member->getId(), (new \DateTimeImmutable('@'.$start->getTimestamp()))->setTimezone($this->slots->timezone())->format('Ymd'));
    }
}
