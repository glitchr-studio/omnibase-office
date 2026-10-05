<?php

namespace Base\Office;

use Base\Bundle\AbstractBaseBundle;
use Base\Office\DependencyInjection\Compiler\VisioGatewayPass;
use Base\Traits\SingletonTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A professional practice on its own site, whatever the profession: the
 * office (address, access, team, hours, contact - the core), appointments
 * by slot or by request (Booking), an encrypted document vault per
 * recipient with its access log (Share), and a video room with a waiting
 * room (Visio), its calls carried by a gateway of glitchr/omnimeet - from
 * browser to browser by default. Nothing here knows health, law or payment: a
 * regime (omnibase/health, later omnibase/notary, omnibase/lawyer) puts its
 * trade on top - its templates, its compliance checks, who may read a
 * document.
 */
class OfficeBundle extends AbstractBaseBundle
{
    use SingletonTrait;

    public function __construct()
    {
        parent::__construct();
    }

    /** Modern layout: the class lives in src/, the bundle root is the package root. */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $this->setMapping($this->getPath().'/src/Entity', 'Base\Office\Entity', 'App\Entity\Office');
        $this->setMapping($this->getPath().'/src/Repository', 'Base\Office\Repository', 'App\Repository\Office');

        // The video gateways are glitchr/omnimeet's; without its bundle, the direct one alone, declared here.
        $container->addCompilerPass(new VisioGatewayPass());
    }
}
