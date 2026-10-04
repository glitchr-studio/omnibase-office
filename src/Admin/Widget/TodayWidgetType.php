<?php

namespace Base\Office\Admin\Widget;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\DashboardWidgetTypeInterface;
use Base\Office\Repository\Booking\AppointmentRepository;
use Base\Office\Repository\MemberRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The dashboard's "Today": the day's appointments, of the member signed in
 * or of everyone, and the requests waiting for a time.
 */
final class TodayWidgetType implements DashboardWidgetTypeInterface
{
    public function __construct(private readonly AppointmentRepository $appointments, private readonly MemberRepository $members, private readonly Security $security)
    {
    }

    public static function getName(): string
    {
        return 'office_today';
    }

    public function getTemplate(): string
    {
        return '@Office/admin/widget/today.html.twig';
    }

    public function getTemplateVars(MenuItem $widget): array
    {
        $today = new \DateTimeImmutable('today');
        $mine = $this->members->findOneByUser($this->security->getUser());

        return [
            'appointments' => $this->appointments->findBetween($today, $today->modify('+1 day'), $mine),
            'requests' => $this->appointments->findRequested(5),
            'mine' => $mine,
        ];
    }
}
