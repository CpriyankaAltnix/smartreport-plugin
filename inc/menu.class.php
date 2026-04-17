<?php

class PluginSmartreportMenu extends CommonGLPI
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
                'search' => PluginSmartreportReportdefination::getSearchURL(false),
                'add'    => PluginSmartreportReportdefination::getFormURL(false),
            ],
        ];

        if (GlpiVersion::isGlpi11()) {
            $menu['icon'] = 'ti ti-layout-kanban';
        } else {
            $menu['icon'] = 'fa-fw ti ti-layout-kanban';
        }

        return $menu;
    }
}
