<?php

namespace Base\Office\DependencyInjection;

use Base\Bundle\AbstractBaseExtension;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class OfficeExtension extends AbstractBaseExtension implements PrependExtensionInterface
{
    public function getConfiguration(array $config, ContainerBuilder $container): OfficeConfiguration
    {
        return new OfficeConfiguration();
    }

    /** The former places of the direct gateway's options, kept as aliases for one version: office.visio.<key>. */
    public const VISIO_ALIASES = ['turn_secret', 'turn_urls', 'stun_urls', 'turn_ttl'];

    /**
     * The direct gateway's options as office.visio still carries them
     * (turn_secret, turn_urls, stun_urls, turn_ttl): read as they are
     * written, the last file that sets one winning. Their place is now
     * omnimeet.gateways.direct.options.
     *
     * @param list<array<string, mixed>> $configs office's configurations, unprocessed
     *
     * @return array{options: array<string, mixed>, used: list<string>} the options, and the aliases a file still sets
     */
    public static function visioAliases(array $configs): array
    {
        $options = ['turn_secret' => '%env(default::TURN_SECRET)%', 'turn_urls' => [], 'stun_urls' => [], 'turn_ttl' => 3600];
        $used = [];
        foreach ($configs as $config) {
            foreach (self::VISIO_ALIASES as $key) {
                if (\is_array($config['visio'] ?? null) && \array_key_exists($key, $config['visio'])) {
                    $options[$key] = $config['visio'][$key];
                    $used[$key] = true;
                }
            }
        }

        return ['options' => $options, 'used' => array_keys($used)];
    }

    /**
     * The practice's name for the texts, the direct video gateway, and the private storage of the vault when the application has not declared it.
     * (The UTC moment type the entities use, utc_datetime_immutable, is glitchr/omnibase's: Base\Database\Type\UtcDateTimeImmutableType.)
     */
    public function prepend(ContainerBuilder $container): void
    {
        // The gateway rooms open on by default: "direct", declared under omnimeet.gateways for the application - under
        // whatever it declares itself, which wins - with the relay's options still written under office.visio.
        if ($container->hasExtension('omnimeet')) {
            $container->prependExtensionConfig('omnimeet', ['gateways' => ['direct' => ['factory' => 'direct', 'options' => self::visioAliases($container->getExtensionConfig('office'))['options']]]]);
        }

        // The practice's name in the bundle's texts ({the_practice}...), from office.vocabulary - read here as it is
        // written, the last file that sets it winning: the texts need it wherever they are translated.
        if ($container->hasExtension('framework')) {
            $practice = 'cabinet';
            foreach ($container->getExtensionConfig('office') as $config) {
                if (isset($config['vocabulary']['practice'])) {
                    $practice = $config['vocabulary']['practice'];
                }
            }
            $container->prependExtensionConfig('framework', ['translator' => ['globals' => Vocabulary::globals($practice)]]);
        }
        if (!$container->hasExtension('flysystem')) {
            return;
        }
        $declared = false;
        foreach ($container->getExtensionConfig('flysystem') as $config) {
            if (isset($config['storages']['local.share'])) {
                $declared = true;
            }
        }
        if (!$declared) {
            $container->prependExtensionConfig('flysystem', ['storages' => ['local.share' => [
                'adapter' => 'local',
                'options' => ['directory' => '%kernel.project_dir%/var/storage/share'],
            ]]]);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new OfficeConfiguration();
        $config = (new Processor())->processConfiguration($configuration, $configs);

        if ($used = self::visioAliases($configs)['used']) {
            trigger_deprecation('omnibase/office', '1.1', 'The "office.visio.%s" option%s moved to "omnimeet.gateways.direct.options": the relay belongs to glitchr/omnimeet\'s direct gateway. The former place is read for this version only.', implode('", "office.visio.', $used), \count($used) > 1 ? 's' : '');
        }

        // Flat parameters: office.timezone, office.booking.min_notice, office.share.master_key...
        $this->setConfiguration($container, $config, $configuration->getTreeBuilder()->buildTree()->getName());

        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');

        // The vault's storage: a Flysystem service of the application (local.share by default, see prepend()).
        $container->setAlias('office.share.storage', $config['share']['storage']);

        // The register a member is read from, when glitchr/omnistate is installed.
        if (class_exists(\Omnistate\Omnistate::class)) {
            $container->getDefinition(\Base\Office\Service\MemberRegistry::class)
                ->setArgument('$omnistate', new \Symfony\Component\DependencyInjection\Reference(\Omnistate\Omnistate::class, \Symfony\Component\DependencyInjection\ContainerInterface::NULL_ON_INVALID_REFERENCE));
        }
    }
}
