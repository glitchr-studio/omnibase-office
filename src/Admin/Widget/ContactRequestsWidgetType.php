<?php

namespace Base\Office\Admin\Widget;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\DashboardWidgetTypeInterface;
use Base\Office\Repository\ContactRequestRepository;

/**
 * The dashboard's "Messages from the site": the contact requests not handled yet.
 */
final class ContactRequestsWidgetType implements DashboardWidgetTypeInterface
{
    public function __construct(private readonly ContactRequestRepository $requests)
    {
    }

    public static function getName(): string
    {
        return 'office_contact';
    }

    public function getTemplate(): string
    {
        return '@Office/admin/widget/contact.html.twig';
    }

    public function getTemplateVars(MenuItem $widget): array
    {
        return ['count' => $this->requests->countOpen(), 'requests' => $this->requests->findOpen(5)];
    }
}
