<?php

namespace Base\Office\Tests\Visio;

use App\Entity\User;
use Base\Office\Entity\Member;
use Base\Office\Entity\Visio\Room;
use Base\Office\Repository\MemberRepository;
use Base\Office\Repository\Visio\RoomRepository;
use Base\Office\Repository\Visio\SignalRepository;
use Base\Office\Visio\Gateways;
use Base\Office\Visio\Rooms;
use Base\Office\Visio\RoomSubjectInterface;
use Doctrine\ORM\EntityManagerInterface;
use Omnimeet\Action\ActionInterface;
use Omnimeet\Config;
use Omnimeet\Direct\DirectGatewayFactory;
use Omnimeet\Direct\InMemorySignalStore;
use Omnimeet\Exception\InvalidConfigException;
use Omnimeet\GatewayFactory;
use Omnimeet\Model\Access;
use Omnimeet\Model\AccessKind;
use Omnimeet\Model\Capabilities;
use Omnimeet\Model\Role;
use Omnimeet\Model\Status;
use Omnimeet\Registry;
use Omnimeet\Request\Join;
use Omnimeet\Request\Open;
use Omnimeet\Request\Request;
use PHPUnit\Framework\TestCase;

/**
 * The room on glitchr/omnimeet: opened on the practice's gateway, entered
 * the way that gateway says, closed on both sides - and the gateway told as
 * little as it needs.
 */
final class RoomsTest extends TestCase
{
    private InMemorySignalStore $handshake;
    /** @var array<string, Room> the rooms "in the database", by subject */
    private array $stored = [];

    protected function setUp(): void
    {
        if (!class_exists(User::class)) {
            self::markTestSkipped('Needs an application\'s account class (App\\Entity\\User): run inside one.');
        }
        $this->handshake = new InMemorySignalStore();
        $this->stored = [];
    }

    public function testARoomIsOpenedOnThePracticesGatewayAndKeepsItsReference(): void
    {
        $rooms = $this->rooms('direct');
        $room = $rooms->forSubject($this->subject());

        self::assertSame('direct', $room->getGateway());
        self::assertSame($room->getToken(), $room->getReference(), 'the direct gateway\'s room is the key it was given: the token, never what the room is for');
        self::assertTrue($rooms->isDirect($room));
        self::assertSame($room->getReference(), $rooms->forSubject($this->subject())->getReference(), 'asked again: the same room');

        $meeting = $rooms->meeting($room);
        self::assertSame([$room->getToken(), null, Status::OPEN], [$meeting->key, $meeting->title, $meeting->status]);
        self::assertEquals([$room->getOpensAt(), $room->getClosesAt()], [$meeting->startsAt, $meeting->endsAt]);
    }

    public function testEnteringIsWhatTheGatewayAnswers(): void
    {
        $rooms = $this->rooms('direct', ['turn_secret' => 'shared', 'turn_urls' => 'turn:turn.example.org:3478']);
        $room = $rooms->forSubject($this->subject());

        $host = $rooms->access($room, self::user(1));
        self::assertSame(AccessKind::ENGINE, $host->kind);
        self::assertSame(['host', 'u1'], [$host->options['role'], $host->options['participant']]);
        self::assertStringEndsWith(':u1', $host->options['iceServers'][0]['username'], 'the relay\'s credentials, made for this account');
        self::assertSame('guest', $rooms->access($room, self::user(2))->options['role']);
        self::assertFileExists((string) $host->script, 'the engine the page serves');
    }

    public function testAProviderIsToldARoleAnOpaqueIdentifierAndTheMembersNameOnly(): void
    {
        $rooms = $this->rooms('provider');
        $room = $rooms->forSubject($this->subject());
        self::assertSame(['provider', 'frame-'.$room->getToken()], [$room->getGateway(), $room->getReference()]);
        self::assertFalse($rooms->isDirect($room));

        $access = $rooms->access($room, self::user(1), 'fr');
        self::assertSame(AccessKind::FRAME, $access->kind);
        self::assertSame(['https://meet.example.org'], $access->origins);
        self::assertSame(['id' => 'u1', 'role' => 'host', 'name' => 'Dr Claire Tilleul', 'locale' => 'fr'], $access->options['seen'], 'the member\'s public name');
        self::assertSame(['id' => 'u2', 'role' => 'guest', 'name' => null, 'locale' => null], $rooms->access($room, self::user(2))->options['seen'], 'who the client is stays here');

        $none = $this->rooms('provider', names: 'none');
        self::assertNull($none->participant($none->forSubject($this->subject()), self::user(1))->name);
        self::assertSame(Role::HOST, $none->participant($none->forSubject($this->subject()), self::user(1))->role);
    }

    public function testARoomNobodyEnteredFollowsTheConfiguredGatewayAndARoomInUseStays(): void
    {
        $room = $this->rooms('direct')->forSubject($this->subject());
        self::assertSame('direct', $room->getGateway());

        // The practice changes gateway: the appointments to come change with it.
        $rooms = $this->rooms('provider');
        self::assertSame('provider', $rooms->forSubject($this->subject())->getGateway());

        // Someone has been in: it stays where it is.
        $room->seen(self::user(2));
        self::assertSame('provider', $this->rooms('direct')->forSubject($this->subject())->getGateway());
        self::assertSame(AccessKind::FRAME, $this->rooms('direct')->access($room, self::user(2))->kind);
    }

    public function testARoomMadeBeforeTheGatewaysIsOpenedWhenItIsFirstEntered(): void
    {
        $room = new Room('appointment:7', self::user(1), self::user(2), new \DateTimeImmutable('-5 minutes'), new \DateTimeImmutable('+40 minutes'));
        self::assertNull($room->getGateway());

        $rooms = $this->rooms('direct');
        self::assertSame($room->getToken(), $rooms->reference($room));
        self::assertSame('direct', $room->getGateway());
        self::assertSame(AccessKind::ENGINE, $rooms->access($room, self::user(2))->kind);
    }

    public function testClosingEndsTheRoomAndPurgesTheHandshakeOnTheGatewaysSide(): void
    {
        $rooms = $this->rooms('direct');
        $room = $rooms->forSubject($this->subject());
        $this->handshake->append((string) $room->getReference(), 'u2', 'hello', 'null');

        $rooms->close($room);

        self::assertNotNull($room->getEndedAt());
        self::assertFalse($room->isOpen());
        self::assertSame([], $this->handshake->read((string) $room->getReference()), 'the gateway closed its side');
        self::assertSame(Status::ENDED, $rooms->meeting($room)->status);
    }

    public function testAGatewayThatCannotCloseLeavesTheRoomClosedHereAllTheSame(): void
    {
        $rooms = $this->rooms('provider');
        $room = $rooms->forSubject($this->subject());

        $rooms->close($room);

        self::assertNotNull($room->getEndedAt(), 'nobody enters any more, whatever the provider does');
    }

    public function testAGatewayThatIsNotConfiguredIsSaid(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('No "zoom" gateway; configured: direct, provider.');
        $this->rooms('zoom')->forSubject($this->subject());
    }

    /** @param array<string, mixed> $direct the direct gateway's options */
    private function rooms(string $gateway, array $direct = [], string $names = 'host'): Rooms
    {
        $registry = new Registry([new DirectGatewayFactory($this->handshake), new FrameFactory()], [
            'direct' => ['factory' => 'direct', 'options' => $direct],
            'provider' => ['factory' => 'frame'],
        ]);

        $repository = $this->createStub(RoomRepository::class);
        $repository->method('findOneBySubject')->willReturnCallback(fn (string $key) => $this->stored[$key] ?? null);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $room): void { $this->stored[$room->getSubjectKey()] = $room; });
        $members = $this->createStub(MemberRepository::class);
        $members->method('findOneByUser')->willReturnCallback(static function (?object $user): ?Member {
            if (1 !== $user?->getId()) {
                return null;
            }
            $member = (new \ReflectionClass(Member::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty(Member::class, 'displayName'))->setValue($member, 'Dr Claire Tilleul');

            return $member;
        });

        return new Rooms($repository, $this->createStub(SignalRepository::class), $entityManager, new Gateways($registry, $gateway), $members, 10, 30, $names);
    }

    private function subject(): RoomSubjectInterface
    {
        return new class(self::user(1), self::user(2)) implements RoomSubjectInterface {
            public function __construct(private readonly User $host, private readonly User $guest)
            {
            }

            public function getVisioHost(): ?User { return $this->host; }
            public function getVisioGuest(): ?User { return $this->guest; }
            public function getVisioStartsAt(): ?\DateTimeImmutable { return new \DateTimeImmutable('+2 minutes'); }
            public function getVisioEndsAt(): ?\DateTimeImmutable { return new \DateTimeImmutable('+17 minutes'); }
            public function getVisioKey(): string { return 'appointment:12'; }
        };
    }

    /** @var array<int, User> */
    private static array $users = [];

    private static function user(int $id): User
    {
        if (!isset(self::$users[$id])) {
            $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty(\Base\Entity\User::class, 'id'))->setValue($user, $id);
            self::$users[$id] = $user;
        }

        return self::$users[$id];
    }
}

/** A provider's frame, for the tests: it says what it was told of the participant. */
final class FrameFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnimeet.factory_name' => 'frame',
            'omnimeet.factory_title' => 'Frame',
            'omnimeet.capabilities' => new Capabilities(thirdParty: true, access: [AccessKind::FRAME]),
            'omnimeet.action.all' => new class implements ActionInterface {
                public function supports(Request $request): bool
                {
                    return $request instanceof Open || $request instanceof Join;
                }

                public function execute(Request $request): void
                {
                    if ($request instanceof Open) {
                        $request->setResult($request->meeting->with('frame-'.$request->meeting->key, Status::OPEN));
                    } elseif ($request instanceof Join) {
                        $p = $request->participant;
                        $request->setResult(Access::frame('https://meet.example.org/'.$request->meeting->reference(), options: ['seen' => ['id' => $p->id, 'role' => $p->role->value, 'name' => $p->name, 'locale' => $p->locale]]));
                    }
                }
            },
        ]);
    }
}
