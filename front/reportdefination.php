<?php

include('../../../inc/includes.php');
include_once(__DIR__ . '/../inc/glpiversion.class.php');

Session::checkRight('plugin_smartreport', READ);

Html::header(
   __('Smart Report'),
   $_SERVER['PHP_SELF'],
   'config',
   'PluginSmartreportMenu'
);

Search::show(
   PluginSmartreportReportdefination::class
);

Html::footer();
