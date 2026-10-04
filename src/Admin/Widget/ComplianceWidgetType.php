<?php

namespace Base\Office\Admin\Widget;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\DashboardWidgetTypeInterface;
use Base\Office\Compliance\Compliance;

/**
 * The dashboard's "Compliance": the office's checks and its regime's, each
 * with what to do when it is not met.
 */
final class ComplianceWidgetType implements DashboardWidgetTypeInterface
{
    public function __construct(private readonly Compliance $compliance)
    {
    }

    public static function getName(): string
    {
        return 'office_compliance';
    }

    public function getTemplate(): string
    {
        return '@Office/admin/widget/compliance.html.twig';
    }

    public function getTemplateVars(MenuItem $widget): array
    {
        return ['results' => $this->compliance->run()];
    }
}
