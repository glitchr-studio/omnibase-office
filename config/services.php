<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Base\Office\Compliance\ComplianceCheckInterface;
use Base\Office\Repository\Visio\SignalRepository;
use Base\Office\Share\AudienceResolverInterface;
use Base\Office\Visio\Gateways;
use Base\Office\Visio\TurnCredentials;
use Omnimeet\Direct\Signaling;
use Omnimeet\Direct\SignalStoreInterface;

/*
 * Everything in src/ is a plain autowired service, the way an application's
 * own src/ is. The back office (CRUD controllers, the agenda, the widgets)
 * is loaded only when omnibase/admin is installed.
 */
return function (ContainerConfigurator $configurator, \Symfony\Component\DependencyInjection\ContainerBuilder $container) {
    $src = dirname(__DIR__).'/src';

    $container->registerForAutoconfiguration(ComplianceCheckInterface::class)->addTag('office.compliance_check');
    $container->registerForAutoconfiguration(AudienceResolverInterface::class)->addTag('office.share_audience');

    $services = $configurator->services();
    $services->defaults()
        ->autowire(true)
        ->autoconfigure(true)
        ->public(false);

    $services->load('Base\\Office\\', $src.'/')
        ->exclude([
            $src.'/DependencyInjection/',
            $src.'/Entity/',
            $src.'/Enum/',
            $src.'/Event/',
            $src.'/Exception/',
            $src.'/Model/',
            $src.'/Form/Model/',
            $src.'/Controller/Admin/',
            $src.'/Admin/',
            $src.'/Compliance/ComplianceResult.php',
            $src.'/OfficeBundle.php',
        ]);

    $services->load('Base\\Office\\Controller\\Client\\', $src.'/Controller/Client/')
        ->tag('controller.service_arguments');

    // The video room on glitchr/omnimeet: the handshake of omnimeet/direct kept in the Signal rows (its factory, built
    // by Omnimeet\Bridge\Symfony\OmnimeetBundle or by VisioGatewayPass, asks for this store), and its rules on them.
    $services->alias(SignalStoreInterface::class, SignalRepository::class);
    $services->set(Signaling::class)->args([service(SignalRepository::class)]);
    // The relay's credentials under their former name, for one version.
    $services->set(TurnCredentials::class)->factory([service(Gateways::class), 'formerTurnCredentials']);

    if (class_exists('Base\\Admin\\Controller\\AbstractCrudController')) {
        $services->load('Base\\Office\\Controller\\Admin\\', $src.'/Controller/Admin/')
            ->tag('controller.service_arguments');
        $services->load('Base\\Office\\Admin\\', $src.'/Admin/');
    }
};
