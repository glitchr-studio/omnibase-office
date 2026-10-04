<?php

namespace Base\Office\Controller\Admin;

use Base\Admin\Context\AdminContext;
use Base\Admin\Menu\MenuBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/** A page of the bundle's in the back office's chrome (it extends @Admin/layout.html.twig): its menus built when no CRUD did. */
trait AdminPageTrait
{
    private ?AdminContext $adminContext = null;
    private ?MenuBuilder $menuBuilder = null;

    #[Required]
    public function setAdminChrome(AdminContext $adminContext, MenuBuilder $menuBuilder): void
    {
        $this->adminContext = $adminContext;
        $this->menuBuilder = $menuBuilder;
    }

    private function page(string $template, array $parameters = [], ?Response $response = null): Response
    {
        if ([] === $this->adminContext->getMainMenu()) {
            $this->adminContext->setMainMenu($this->menuBuilder->buildDefault());
        }
        if ([] === $this->adminContext->getUserMenu()) {
            $this->adminContext->setUserMenu($this->menuBuilder->buildUserMenuDefault($this->getUser()));
        }

        return $this->render($template, ['admin_context' => $this->adminContext] + $parameters, $response);
    }

    private function assertToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
    }
}
