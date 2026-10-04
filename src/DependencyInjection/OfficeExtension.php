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

    /** The UTC moment type, and the private storage of the vault when the application has not declared it. */
    public function prepend(ContainerBuilder $container): void
    {
        // Moments in UTC, whatever timezone PHP was put in for the visitor (Base\Office\Database\UtcDateTimeImmutableType).
        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', ['dbal' => ['types' => [\Base\Office\Database\UtcDateTimeImmutableType::NAME => \Base\Office\Database\UtcDateTimeImmutableType::class]]]);
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
