<?php

/**
 * Smart Report — Execution Queue
 *
 * Standard GLPI list view for the report execution queue.
 * Accessible from: Plugins → Smart Report → Queue
 *
 * Requires READ right on plugin_smartreport.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Smartreport\Reportqueue;
use GlpiPlugin\Smartreport\Menu;

Session::checkRight(Reportqueue::$rightname, READ);

Html::header(
    Reportqueue::getTypeName(0),
    $_SERVER['PHP_SELF'],
    'config',
    Menu::class,
    'Reportqueue'
);

Search::show(Reportqueue::class);

Html::footer();
