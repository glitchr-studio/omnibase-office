<?php

namespace Base\Office\Tests\Visio;

use Base\Office\Entity\Visio\Room;
use Base\Office\Visio\TurnCredentials;
use PHPUnit\Framework\TestCase;

/** The temporary TURN credentials (coturn's use-auth-secret), and when a room is open. */
final class TurnCredentialsTest extends TestCase
{
    public function testCredentialsAreTheHmacCoturnExpects(): void
    {
        $turn = new TurnCredentials('shared-secret', ['turn:turn.example.org:3478'], [], 600);
        $credentials = $turn->issue('u42', 1_800_000_000);

        self::assertSame('1800000600:u42', $credentials['username'], 'expiry:who');
        self::assertSame(base64_encode(hash_hmac('sha1', '1800000600:u42', 'shared-secret', true)), $credentials['credential']);
        self::assertTrue($turn->verify($credentials['username'], $credentials['credential'], 1_800_000_300));
        self::assertFalse($turn->verify($credentials['username'], $credentials['credential'], 1_800_000_601), 'lapsed');
        self::assertFalse($turn->verify('1900000000:u42', $credentials['credential'], 1_800_000_300), 'a later expiry needs another signature');
        self::assertFalse((new TurnCredentials('another', ['turn:x']))->verify($credentials['username'], $credentials['credential'], 1_800_000_300));
    }

    public function testIceServersCarryThemAndNoThirdPartyByDefault(): void
    {
        $servers = (new TurnCredentials('s', ['turn:localhost:3478?transport=udp,turn:localhost:3478?transport=tcp']))->iceServers('u1');

        self::assertCount(1, $servers, 'no STUN server of someone else\'s unless configured');
        self::assertSame(['turn:localhost:3478?transport=udp', 'turn:localhost:3478?transport=tcp'], $servers[0]['urls'], 'one environment variable, split');
        self::assertArrayHasKey('credential', $servers[0]);
        self::assertSame([], (new TurnCredentials(null, []))->iceServers('u1'), 'not configured: direct connections only');
    }

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
}
