<?php

namespace Base\Office\Booking;

use Base\Office\Entity\Booking\Appointment;
use Base\Office\Entity\Booking\AppointmentType;
use Base\Office\Entity\Member;
use Base\Office\Exception\BookingException;
use Base\Office\Form\Model\AppointmentRequest;
use Base\Office\Repository\Booking\AppointmentTypeRepository;
use Base\Office\Repository\MemberRepository;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A first appointment asked without an account: a practice's clients are
 * invited, so someone new cannot go through the booking pages, which ask to
 * sign in. The request becomes an appointment in request mode (no time yet:
 * the practice fixes it) under the name, the e-mail and the phone given;
 * the few words are kept encrypted, as every reason is.
 */
class AppointmentRequests
{
    public function __construct(
        private readonly MemberRepository $members,
        private readonly AppointmentTypeRepository $types,
        private readonly BookingPolicy $policy,
        private readonly Booker $booker,
    ) {
    }

    /**
     * What can be asked for, and of whom: the types in request mode open to
     * everyone, in their order, and the members who offer one.
     *
     * @return array{types: list<AppointmentType>, members: list<Member>}
     */
    public function offer(): array
    {
        $types = $members = [];
        foreach ($this->members->findBookable() as $member) {
            foreach ($this->types->findForMember($member) as $type) {
                if ($type->isRequest() && $this->policy->canBook($type, $member, null)) {
                    $types[$type->getId()] = $type;
                    $members[$member->getId()] = $member;
                }
            }
        }
        usort($types, static fn (AppointmentType $a, AppointmentType $b) => $a->getPosition() <=> $b->getPosition());

        return ['types' => $types, 'members' => array_values($members)];
    }

    /**
     * The request as the form opens on it: who is signed in, the member the
     * link named (?avec=their-slug), the type when there is only one.
     *
     * @param array{types: list<AppointmentType>, members: list<Member>} $offer
     */
    public function prepare(array $offer, ?UserInterface $user = null, string $wanted = ''): AppointmentRequest
    {
        $model = new AppointmentRequest();
        if (null !== $user) {
            $model->name = (string) $user;
            $model->email = method_exists($user, 'getEmail') ? $user->getEmail() : null;
        }
        foreach ($offer['members'] as $member) {
            if ('' !== $wanted && $member->getSlug() === $wanted) {
                $model->member = $member;
            }
        }
        if (1 === \count($offer['types'])) {
            $model->type = $offer['types'][0];
        }

        return $model;
    }

    /** The member asked for when they offer that type, else the first who does. */
    public function memberFor(AppointmentRequest $request): ?Member
    {
        if (null === $request->type) {
            return null;
        }
        if (null !== $request->member && $this->policy->canBook($request->type, $request->member, null)) {
            return $request->member;
        }
        foreach ($request->type->getMembers() as $member) {
            if ($this->policy->canBook($request->type, $member, null)) {
                return $member;
            }
        }

        return null;
    }

    /**
     * Registers the request (Booker::request()): an appointment in request
     * mode, its e-mails sent by the booking's own listeners.
     *
     * @throws BookingException when nobody takes that type online
     */
    public function send(AppointmentRequest $request, ?UserInterface $user = null): Appointment
    {
        $member = $this->memberFor($request);
        if (null === $member) {
            throw new BookingException('booking.error.not_bookable');
        }

        return $this->booker->request(
            $request->type,
            $member,
            $user instanceof \App\Entity\User ? $user : null,
            $request->message,
            null,
            ['name' => $request->name, 'email' => mb_strtolower((string) $request->email), 'phone' => $request->phone],
        );
    }
}
