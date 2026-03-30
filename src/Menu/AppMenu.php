<?php

namespace App\Menu;

use App\Controller\Admin\MeiliDashboardController;
use App\Entity\Img;
use App\Entity\Inst;
use App\Entity\Media;
use App\Entity\Obj;
use App\Entity\Thumb;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Service\MenuService;
use Survos\TablerBundle\Traits\KnpMenuHelperInterface;
use Survos\TablerBundle\Traits\KnpMenuHelperTrait;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

// events are
/*
// #[AsEventListener(event: MenuEvent::NAVBAR_MENU2)]
#[AsEventListener(event: MenuEvent::SIDEBAR_MENU, method: 'sidebarMenu')]
#[AsEventListener(event: MenuEvent::PAGE_MENU, method: 'pageMenu')]
#[AsEventListener(event: MenuEvent::FOOTER_MENU, method: 'footerMenu')]
#[AsEventListener(event: MenuEvent::AUTH_MENU, method: 'appAuthMenu')]
*/

final class AppMenu implements KnpMenuHelperInterface
{
    use KnpMenuHelperTrait;

    public function __construct(
        #[Autowire('%kernel.environment%')] protected string $env,
        private MenuService $menuService,
        private Security $security,
        private ?AuthorizationCheckerInterface $authorizationChecker = null
    ) {
    }

    public function appAuthMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();
        $this->menuService->addAuthMenu($menu);
    }

    #[AsEventListener(event: MenuEvent::NAVBAR_MENU)]
    public function navbarMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();
        $options = $event->options;
        $this->add($menu, 'app_homepage');
//        $this->add($menu, 'asset_browse', label: 'Assets');
        $this->add($menu, 'meili_insta', ['indexName' => 'asset'], label: 'Search Assets');
        $this->add($menu, 'app_browse_assets', label: 'Browse Assets');
        $this->add($menu, 'asset_task_registry', label: 'AI Tasks');
        $this->add($menu, 'survos_state_workflow_dashboard', label: 'Summary');
        $subMenu = $this->addSubmenu($menu, 'dispatch');
        $this->add($subMenu, 'app_dispatch_process_ui');
        $this->add($subMenu, 'app_account_setup_ui');
        $this->add($menu, 'meili_admin', label: 'ez');
            $this->add($menu, 'zenstruck_messenger_monitor_dashboard', label: '*msg');
        //$this->add($menu, 'app_media');
        $this->add($menu,MeiliDashboardController::MEILI_ROUTE . "_asset_index",label: "Asset");
        //$this->add($menu, 'app_thumbs');
//        $this->add($menu, MeiliDashboardController::MEILI_ROUTE . '_variant_index', label: 'Variant');
//        $this->add($menu, 'admin_user_index', label: 'Accounts');

//        $this->add($menu, uri: '/db.svg', external: true, label: 'db.svg');

        // easyadmin should provide us what we need, a simple filter
        if (0) {
            $subMenu = $this->addSubmenu($menu, 'meili');
            $this->add($subMenu, 'media_meili');
            $this->add($subMenu, 'media_index');
        }
        // @todo: add admin for production
        if ($this->env === 'dev') {
            $this->add($menu, 'survos_storage_zones');
        }



        if ($this->isEnv('dev')) {

            $subMenu = $this->addSubmenu($menu, 'workflows');
            $this->add($subMenu, 'survos_workflows');
            $this->add($subMenu, 'survos_workflow_entities');

            $subMenu = $this->addSubmenu($menu, 'survos_commands');
            $this->add($subMenu, 'survos_commands', label: 'All');
            foreach (['workflow:iterate', 'storage:iterate'] as $commandName) {
                $this->add($subMenu, 'survos_command', ['commandName' => $commandName], $commandName);
            }
            $subMenu = $this->addSubmenu($menu, 'workflow:iterate');
            foreach ([Media::class, Thumb::class] as $className) {
                $className = str_replace("\\", "\\\\", $className);
                $this->add($subMenu, 'survos_command', ['commandName' => 'workflow:iterate', 'className' => $className], $className);
            }
            $this->add($subMenu, 'survos_workflows', label: 'Workflows');

        }

        //        $this->add($menu, 'app_homepage');
        // for nested menus, don't add a route, just a label, then use it for the argument to addMenuItem

        $nestedMenu = $this->addSubmenu($menu, 'git', icon: 'tabler:brand-github');

        foreach ([''=>'repo', '/issues'=>'issues'] as $path => $label) {
            $this->add($nestedMenu, uri: 'https://github.com/tacman/image-server' . $path, label: $label);

        }
    }
}
