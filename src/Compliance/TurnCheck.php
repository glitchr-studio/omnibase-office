<?php

namespace Base\Office\Compliance;

use Base\Office\Visio\TurnCredentials;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The video's relay: configured, and answering on its first address (a UDP
 * STUN binding request - the same port coturn listens on for TURN).
 */
final class TurnCheck implements ComplianceCheckInterface
{
    public function __construct(
        private readonly TurnCredentials $turn,
        #[Autowire('%office.visio.enabled%')] private readonly bool $enabled = true,
        #[Autowire('%office.visio.turn_probe%')] private readonly ?string $probe = null,
    ) {
    }

    public function check(): ComplianceResult
    {
        if (!$this->enabled) {
            return ComplianceResult::ok('compliance.turn');
        }
        if (!$this->turn->isConfigured()) {
            return new ComplianceResult('compliance.turn', ComplianceResult::MISSING, 'compliance.turn_advice');
        }

        $url = $this->probe ? 'turn:'.$this->probe : ($this->turn->turnUrls()[0] ?? '');

        return self::reachable($url) ? ComplianceResult::ok('compliance.turn') : new ComplianceResult('compliance.turn', ComplianceResult::WARNING, 'compliance.turn_unreachable', 'office', ['url' => $url]);
    }

    /** A STUN binding request (RFC 5389) over UDP; any answer will do. */
    public static function reachable(string $url, float $timeout = 1.0): bool
    {
        if (!preg_match('~^turns?:([^:?]+)(?::(\d+))?~', $url, $m)) {
            return false;
        }
        $host = 'localhost' === $m[1] ? '127.0.0.1' : $m[1];
        $socket = @stream_socket_client(sprintf('udp://%s:%d', $host, (int) ($m[2] ?? 3478)), $errno, $error, $timeout);
        if (false === $socket) {
            return false;
        }
        stream_set_timeout($socket, (int) $timeout, (int) (($timeout - floor($timeout)) * 1e6));
        fwrite($socket, pack('nnN', 0x0001, 0, 0x2112A442).random_bytes(12));
        $answer = fread($socket, 512);
        fclose($socket);

        return \is_string($answer) && \strlen($answer) >= 20;
    }
}
