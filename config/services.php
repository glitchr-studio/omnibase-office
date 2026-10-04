<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Base\Office\Compliance\ComplianceCheckInterface;
use Base\Office\Share\AudienceResolverInterface;

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

    if (class_exists('Base\\Admin\\Controller\\AbstractCrudController')) {
        $services->load('Base\\Office\\Controller\\Admin\\', $src.'/Controller/Admin/')
            ->tag('controller.service_arguments');
        $services->load('Base\\Office\\Admin\\', $src.'/Admin/');
    }
};
