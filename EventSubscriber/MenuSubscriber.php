<?php

/*
 * This file is part of the "DemoBundle" for Kimai.
 * All rights reserved by Kevin Papst (www.kevinpapst.de).
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\DemoBundle\EventSubscriber;

use App\Event\ConfigureMainMenuEvent;
use App\Utils\MenuItemModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class MenuSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_PLANNING_ROLES = ['ROLE_TEAMLEAD', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    public function __construct(private readonly AuthorizationCheckerInterface $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConfigureMainMenuEvent::class => ['onMenuConfigure', 100],
        ];
    }

    public function onMenuConfigure(ConfigureMainMenuEvent $event): void
    {
        $auth = $this->security;

        if (!$auth->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
            return;
        }

        if ($auth->isGranted('demo') && $this->isPlanningAccessGranted()) {
            $menu = $event->getMenu();
            $menu->addChild(
                new MenuItemModel('demo', 'Менеджмент затрат', 'demo', [], 'fas fa-snowman')
            );
            $menu->addChild(
                new MenuItemModel('demo_resource_plan', 'Ресурсный план', 'demo_resource_plan', [], 'fas fa-calendar-alt')
            );
        }
    }

    private function isPlanningAccessGranted(): bool
    {
        foreach (self::ALLOWED_PLANNING_ROLES as $role) {
            if ($this->security->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
