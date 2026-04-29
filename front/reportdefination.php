<?php

use GlpiPlugin\Smartreport\Reportdefination;
use GlpiPlugin\Smartreport\Menu;

// CS: make it as per latest standards
include('../../../inc/includes.php');

Session::checkRight('plugin_smartreport', READ);

if ($_SESSION['glpiactiveprofile']['interface'] == 'central') {
   Html::header(
      __('Smart Report'),
      $_SERVER['PHP_SELF'],
      'config',
      Menu::class,
      ''
   );
} else {
    Html::helpHeader(__('Smart Report'), $_SERVER['PHP_SELF']);
}

// Html::header(
//    __('Smart Report'),
//    $_SERVER['PHP_SELF'],
//    'config',
//    'PluginSmartreportMenu'
// );

Search::show(
   Reportdefination::class
);

Html::footer();
