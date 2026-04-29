<?php

namespace GlpiPlugin\Smartreport;

use GlpiPlugin\Smartreport\Glpiversion;

class Menu extends \CommonGLPI
{
    public static function getMenuName()
    {
        return __('Smart Report');
    }

    public static function getMenuContent()
    {
        $menu = [
            'title' => self::getMenuName(),
            'page'  => '/plugins/smartreport/front/reportdefination.php',
            'links' => [
                'search' => Reportdefination::getSearchURL(false),
                'add'    => Reportdefination::getFormURL(false),
                __('Queue', 'smartreport') => \Plugin::getWebDir('smartreport', false) . '/front/reportqueue.php',
            ],
        ];

        if (GlpiVersion::isGlpi11()) {
            $menu['icon'] = 'ti ti-layout-kanban';
        } else {
            $menu['icon'] = 'fa-fw ti ti-layout-kanban';
        }

        $itemtypes = [Reportqueue::class => 'Reportqueue'];

        foreach ($itemtypes as $itemtype => $option) {
            $menu['options'][$option] = [
                'title' => $itemtype::getTypeName(2),
                'page'  => $itemtype::getSearchURL(false),
                'links' => [
                    'search' => $itemtype::getSearchURL(false)
                ]
            ];

            if (GlpiVersion::isGlpi11()) {
                $menu['options'][$option]['icon'] = 'ti ti-layout-kanban';
            } else {
                $menu['options'][$option]['icon'] = 'fa-fw ti ti-layout-kanban';
            }

            if ($itemtype::canCreate()) {
                $menu['options'][$option]['links']['add'] = $itemtype::getFormURL(false);
            }
        }

        return $menu;
    }
}