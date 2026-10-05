<?php

namespace Base\Office\Visio;

use Base\Office\Visio\TurnCredentials as TurnCredentialsAlias;
use Omnimeet\Direct\TurnCredentials;
use Omnimeet\Exception\InvalidConfigException;
use Omnimeet\GatewayInterface;
use Omnimeet\Registry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The practice's video gateways, from glitchr/omnimeet: the one new rooms
 * open on (office.visio.gateway, "direct" unless said otherwise) and the
 * others configured, which rooms opened earlier may still be on.
 */
class Gateways
{
    public function __construct(
        private readonly Registry $registry,
        #[Autowire('%office.visio.gateway%')] private readonly string $default = 'direct',
    ) {
    }

    /** The name of the gateway new rooms open on. */
    public function name(): string
    {
        return $this->default;
    }

    public function has(?string $name): bool
    {
        return null !== $name && $this->registry->has($name);
    }

    /** @throws InvalidConfigException when it is not configured, or its package not installed */
    public function get(?string $name = null): GatewayInterface
    {
        return $this->registry->get($name ?? $this->default);
    }

    /** The factory behind a gateway's name: "direct", "jitsi"... Null when there is no such gateway. */
    public function factory(?string $name = null): ?string
    {
        $name ??= $this->default;
        try {
            return $this->registry->has($name) ? $this->registry->get($name)->getName() : null;
        } catch (InvalidConfigException) {
            return null;
        }
    }

    /** Whether the call goes from browser to browser, its handshake relayed here. */
    public function isDirect(?string $name = null): bool
    {
        return 'direct' === $this->factory($name);
    }

    /** The relay of a direct gateway, as it is configured; null for any other. */
    public function turn(?string $name = null): ?TurnCredentials
    {
        $name ??= $this->default;

        return $this->isDirect($name) ? TurnCredentials::fromOptions($this->registry->options($name)) : null;
    }

    /**
     * @internal the service behind Base\Office\Visio\TurnCredentials, the relay's former name
     *
     * @deprecated since omnibase/office 1.1: use turn()
     */
    public function formerTurnCredentials(): TurnCredentialsAlias
    {
        return TurnCredentialsAlias::fromOptions($this->isDirect() ? $this->registry->options($this->default) : []);
    }
}
