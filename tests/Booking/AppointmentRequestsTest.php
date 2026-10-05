<?php

namespace Base\Office\Tests\Booking;

use Base\Office\Booking\AppointmentRequests;
use Base\Office\Booking\Booker;
use Base\Office\Booking\BookingPolicy;
use Base\Office\Entity\Booking\AppointmentType;
use Base\Office\Entity\Member;
use Base\Office\Exception\BookingException;
use Base\Office\Form\Model\AppointmentRequest;
use Base\Office\Repository\Booking\AppointmentTypeRepository;
use Base\Office\Repository\MemberRepository;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

/** A first appointment asked without an account: what is offered, how the form opens, who takes the request. */
final class AppointmentRequestsTest extends TestCase
{
    private function member(int $id, string $slug): Member
    {
        $member = $this->createStub(Member::class);
        $member->method('getId')->willReturn($id);
        $member->method('getSlug')->willReturn($slug);

        return $member;
    }

    /** @param list<Member> $members */
    private function type(int $id, int $position, bool $request, array $members): AppointmentType
    {
        $type = $this->createStub(AppointmentType::class);
        $type->method('getId')->willReturn($id);
        $type->method('getPosition')->willReturn($position);
        $type->method('isRequest')->willReturn($request);
        $type->method('getMembers')->willReturn(new ArrayCollection($members));

        return $type;
    }

    /**
     * @param list<Member>                     $bookable
     * @param array<int, list<AppointmentType>> $typesOf  by member id
     * @param list<array{AppointmentType, Member}> $refused pairs nobody may book without an account
     */
    private function requests(array $bookable, array $typesOf, array $refused = [], ?Booker $booker = null): AppointmentRequests
    {
        $members = $this->createStub(MemberRepository::class);
        $members->method('findBookable')->willReturn($bookable);
        $types = $this->createStub(AppointmentTypeRepository::class);
        $types->method('findForMember')->willReturnCallback(fn (Member $m) => $typesOf[$m->getId()] ?? []);
        $policy = $this->createStub(BookingPolicy::class);
        $policy->method('canBook')->willReturnCallback(fn ($type, $member) => !\in_array([$type, $member], $refused, true));

        return new AppointmentRequests($members, $types, $policy, $booker ?? $this->createStub(Booker::class));
    }

    public function testWhatIsOfferedIsTheTypesInRequestModeAndWhoOffersThem(): void
    {
        $anne = $this->member(1, 'anne');
        $paul = $this->member(2, 'paul');
        $slot = $this->type(10, 0, false, [$anne]);                 // booked by slot: not a request
        $first = $this->type(11, 2, true, [$anne, $paul]);
        $estate = $this->type(12, 1, true, [$paul]);
        $known = $this->type(13, 3, true, [$anne]);                 // for known clients only

        $offer = $this->requests([$anne, $paul], [1 => [$slot, $first, $known], 2 => [$first, $estate]], [[$known, $anne]])->offer();

        self::assertSame([$estate, $first], $offer['types'], 'in their order, each once');
        self::assertSame([$anne, $paul], $offer['members']);
    }

    public function testTheFormOpensOnTheMemberNamedAndTheOnlyType(): void
    {
        $anne = $this->member(1, 'anne');
        $paul = $this->member(2, 'paul');
        $first = $this->type(11, 0, true, [$anne, $paul]);
        $requests = $this->requests([$anne, $paul], [1 => [$first], 2 => [$first]]);

        $model = $requests->prepare($requests->offer(), null, 'paul');
        self::assertSame($paul, $model->member);
        self::assertSame($first, $model->type, 'the only one that can be asked for');
        self::assertNull($model->name);

        self::assertNull($requests->prepare($requests->offer(), null, 'nobody')->member);
    }

    public function testWhoTakesTheRequest(): void
    {
        $anne = $this->member(1, 'anne');
        $paul = $this->member(2, 'paul');
        $first = $this->type(11, 0, true, [$anne, $paul]);
        $requests = $this->requests([$anne, $paul], [], [[$first, $anne]]);

        $model = new AppointmentRequest();
        self::assertNull($requests->memberFor($model), 'nothing asked for yet');
        $model->type = $first;
        self::assertSame($paul, $requests->memberFor($model), 'the first who takes that type online');
        $model->member = $anne;
        self::assertSame($paul, $requests->memberFor($model), 'the member asked for does not: the first who does');
        $model->member = $paul;
        self::assertSame($paul, $requests->memberFor($model));
    }

    public function testTheRequestIsRegisteredUnderWhatWasGiven(): void
    {
        $anne = $this->member(1, 'anne');
        $first = $this->type(11, 0, true, [$anne]);
        $booker = $this->createMock(Booker::class);
        $booker->expects(self::once())->method('request')
            ->with($first, $anne, null, 'Une succession', null, ['name' => 'Jeanne Martin', 'email' => 'jeanne@example.org', 'phone' => '06 00 00 00 00']);

        $model = new AppointmentRequest();
        $model->type = $first;
        $model->name = 'Jeanne Martin';
        $model->email = 'Jeanne@Example.org';
        $model->phone = '06 00 00 00 00';
        $model->message = 'Une succession';
        $this->requests([$anne], [], [], $booker)->send($model);
    }

    public function testNobodyToTakeItIsABookingError(): void
    {
        $anne = $this->member(1, 'anne');
        $first = $this->type(11, 0, true, [$anne]);
        $model = new AppointmentRequest();
        $model->type = $first;

        $this->expectException(BookingException::class);
        $this->requests([$anne], [], [[$first, $anne]])->send($model);
    }
}
