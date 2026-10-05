<?php

namespace Base\Office\Tests\DependencyInjection;

use Base\Office\DependencyInjection\Compiler\VisioGatewayPass;
use Base\Office\DependencyInjection\OfficeExtension;
use Omnimeet\Direct\DirectGatewayFactory;
use Omnimeet\Direct\InMemorySignalStore;
use Omnimeet\Direct\SignalStoreInterface;
use Omnimeet\Model\Meeting;
use Omnimeet\Model\Participant;
use Omnimeet\Registry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * Where the direct gateway's options are written: under
 * omnimeet.gateways.direct.options, and - for one version more - under
 * office.visio, their former place.
 */
final class VisioGatewayTest extends TestCase
{
    public function testTheFormerKeysAreReadAsTheyAreWrittenTheLastFileWinning(): void
    {
        $aliases = OfficeExtension::visioAliases([
            ['timezone' => 'Europe/Paris'],
            ['visio' => ['turn_secret' => '%env(TURN_SECRET)%', 'turn_urls' => '%env(TURN_URLS)%', 'turn_probe' => 'coturn:3478']],
            ['visio' => ['turn_ttl' => 600]],
            ['visio' => false],
            ['visio' => ['turn_secret' => 'the-last-one']],
        ]);

        self::assertSame(['turn_secret' => 'the-last-one', 'turn_urls' => '%env(TURN_URLS)%', 'stun_urls' => [], 'turn_ttl' => 600], $aliases['options'], 'turn_probe is the compliance check\'s own: it stays');
        self::assertSame(['turn_secret', 'turn_urls', 'turn_ttl'], $aliases['used']);

        $untouched = OfficeExtension::visioAliases([['visio' => ['gateway' => 'jitsi']]]);
        self::assertSame(['turn_secret' => '%env(default::TURN_SECRET)%', 'turn_urls' => [], 'stun_urls' => [], 'turn_ttl' => 3600], $untouched['options'], 'as before: the secret from the environment when there is one, no relay otherwise');
        self::assertSame([], $untouched['used'], 'nothing deprecated is written');
    }

    public function testWithOmnimeetsBundleTheDirectGatewayIsDeclaredUnderTheApplicationsOwn(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function getAlias(): string { return 'omnimeet'; }
            public function load(array $configs, ContainerBuilder $container): void {}
        });
        $container->registerExtension($extension = new OfficeExtension());
        $container->loadFromExtension('office', ['visio' => ['turn_secret' => 's3cret', 'turn_urls' => 'turn:turn.example.org:3478']]);
        $container->loadFromExtension('omnimeet', ['gateways' => ['group' => ['factory' => 'jitsi', 'options' => ['domain' => 'meet.example.org']]]]);

        $extension->prepend($container);

        $configs = $container->getExtensionConfig('omnimeet');
        self::assertSame(['gateways' => ['direct' => ['factory' => 'direct', 'options' => ['turn_secret' => 's3cret', 'turn_urls' => 'turn:turn.example.org:3478', 'stun_urls' => [], 'turn_ttl' => 3600]]]], $configs[0], 'first: whatever the application declares comes after, and wins');
        self::assertSame('jitsi', $configs[1]['gateways']['group']['factory']);
    }

    public function testWithoutItTheRegistryIsMadeHereWithTheDirectGatewayAlone(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('office.visio.turn_secret', 's3cret');
        $container->setParameter('office.visio.turn_urls', ['turn:turn.example.org:3478']);
        $container->setParameter('office.visio.stun_urls', []);
        $container->setParameter('office.visio.turn_ttl', 900);
        $container->register(InMemorySignalStore::class);
        $container->setAlias(SignalStoreInterface::class, InMemorySignalStore::class);

        (new VisioGatewayPass())->process($container);
        $container->getDefinition(Registry::class)->setPublic(true);
        $container->compile();

        $registry = $container->get(Registry::class);
        self::assertSame(['direct'], $registry->names());
        self::assertSame(['turn_secret' => 's3cret', 'turn_urls' => ['turn:turn.example.org:3478'], 'stun_urls' => [], 'turn_ttl' => 900], $registry->options('direct'));
        $gateway = $registry->get('direct');
        $ice = $gateway->join($gateway->open(new Meeting('k')), Participant::guest('u2'))->options['iceServers'];
        self::assertSame(['turn:turn.example.org:3478'], $ice[0]['urls']);
    }

    public function testTheBundlesOwnRegistryIsLeftAlone(): void
    {
        $container = new ContainerBuilder();
        $container->register(Registry::class, Registry::class)->setArguments([[], ['group' => ['factory' => 'jitsi']]])->setPublic(true);

        (new VisioGatewayPass())->process($container);

        self::assertFalse($container->has(DirectGatewayFactory::class));
        self::assertSame(['group'], $container->get(Registry::class)->names());
    }
}
