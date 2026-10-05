<?php

namespace Base\Office\Tests\Visio;

use Base\Office\Entity\Visio\Room;
use PHPUnit\Framework\TestCase;

/** When a room is open, and what it keeps of its gateway. (The relay's credentials are tested where their code is: omnimeet/direct.) */
final class RoomTest extends TestCase
{
    public function testARoomOpensALittleBeforeAndClosesAWhileAfter(): void
    {
        $start = new \DateTimeImmutable('2026-10-12 10:00');
        $room = new Room('appointment:1', null, null, $start->modify('-10 minutes'), $start->modify('+50 minutes'));

        self::assertSame(43, \strlen($room->getToken()));
        self::assertFalse($room->isOpen($start->modify('-11 minutes')));
        self::assertTrue($room->isOpen($start->modify('-10 minutes')));
        self::assertTrue($room->isOpen($start->modify('+50 minutes')));
        self::assertFalse($room->isOpen($start->modify('+51 minutes')));
        self::assertFalse($room->end()->isOpen($start), 'ended by the host');
    }

    public function testARoomKeepsTheGatewayThatOpenedItAndItsReferenceThere(): void
    {
        $room = new Room('appointment:1', null, null, new \DateTimeImmutable('-10 minutes'), new \DateTimeImmutable('+50 minutes'));

        self::assertFalse($room->isOpenedOnGateway(), 'a room made before the gateways, or not opened yet');
        self::assertNull($room->getGateway());

        self::assertSame($room, $room->openedOn('direct', $room->getToken()));
        self::assertTrue($room->isOpenedOnGateway());
        self::assertSame(['direct', $room->getToken()], [$room->getGateway(), $room->getReference()]);
    }
}
