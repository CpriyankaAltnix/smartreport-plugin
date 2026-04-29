<?php

/**
 * Redirects the legacy queue dashboard URL to the standard GLPI list view.
 */

include('../../../inc/includes.php');

Html::redirect(Plugin::getWebDir('smartreport') . '/front/reportqueue.php');
