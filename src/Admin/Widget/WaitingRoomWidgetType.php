<?php

namespace Base\Office\Admin\Widget;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\DashboardWidgetTypeInterface;
use Base\Office\Repository\Visio\RoomRepository;

/**
 * The dashboard's "Waiting room": video rooms whose guest is there and whose
 * host is not yet.
 */
final class WaitingRoomWidgetType implements DashboardWidgetTypeInterface
{
    public function __construct(private readonly RoomRepository $rooms)
    {
    }

    public static function getName(): string
    {
        return 'office_waiting_room';
    }

    public function getTemplate(): string
    {
        return '@Office/admin/widget/waiting_room.html.twig';
    }

    public function getTemplateVars(MenuItem $widget): array
    {
        return ['rooms' => $this->rooms->findWaiting(30)];
    }
}
