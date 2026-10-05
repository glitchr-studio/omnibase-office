<?php

namespace Base\Office\DependencyInjection\Compiler;

use Omnimeet\Direct\DirectGatewayFactory;
use Omnimeet\Direct\SignalStoreInterface;
use Omnimeet\Registry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * An application that has not registered glitchr/omnimeet's bundle
 * (Omnimeet\Bridge\Symfony\OmnimeetBundle) still has its video room: the
 * registry is made here with the direct gateway alone, its relay read from
 * office.visio's own keys - what the bundle did before the family existed.
 * Any other gateway is declared under omnimeet.gateways, with the bundle.
 */
final class VisioGatewayPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->has(Registry::class)) {
            return;
        }
        $container->register(DirectGatewayFactory::class, DirectGatewayFactory::class)
            ->setArguments([new Reference(SignalStoreInterface::class)])
            ->addTag('omnimeet.gateway_factory');
        $container->register(Registry::class, Registry::class)
            ->setArguments([[new Reference(DirectGatewayFactory::class)], ['direct' => ['factory' => 'direct', 'options' => [
                'turn_secret' => '%office.visio.turn_secret%',
                'turn_urls' => '%office.visio.turn_urls%',
                'stun_urls' => '%office.visio.stun_urls%',
                'turn_ttl' => '%office.visio.turn_ttl%',
            ]]]]);
    }
}
