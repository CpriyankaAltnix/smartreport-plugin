<?php

/**
 * -------------------------------------------------------------------------
 * SmartReport plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the SmartReport plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/pluginsGLPI/smartreport
 * -------------------------------------------------------------------------
 */

include_once(__DIR__ . '/inc/glpiversion.class.php');
include_once(__DIR__ . '/inc/reportdefination.class.php');
include_once(__DIR__ . '/inc/generatedreport.class.php');

/**
 * Plugin install process
 */
function plugin_smartreport_install(): bool
{
    $memory_limit       = (int)Toolbox::getMemoryLimit();
    $max_execution_time = ini_get('max_execution_time');
    if ($memory_limit > 0 && $memory_limit < (512 * 1024 * 1024)) {
        ini_set('memory_limit', '512M');
    }
    if ($max_execution_time > 0 && $max_execution_time < 300) {
        ini_set('max_execution_time', '300');
    }

    $plugin_fields = new Plugin();
    $plugin_fields->getFromDBbyDir('smartreport');
    $version = $plugin_fields->fields['version'];

    $migration = new Migration($version);

    ProfileRight::addProfileRights(['plugin_smartreport' => 'plugin_smartreport']);

    if (isCommandLine()) {
        echo "MySQL tables installation \n";
    } else {
        echo "<center>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th> MySQL tables installation <th></tr>";
        echo "<tr class='tab_bg_1'>";
        echo "<td align='center'>";
    }

    PluginSmartreportReportdefination::installData($migration, $version);

    if (!is_dir(GLPI_SMARTREPORT_PLUGIN_DOC_DIR)) {
        @mkdir(GLPI_SMARTREPORT_PLUGIN_DOC_DIR, 0755, true);
    }

    $migration->executeMigration();

    if (!isCommandLine()) {
        echo "</td></tr></table></center>";
    }

    return true;
}

/**
 * Plugin uninstall process
 */
function plugin_smartreport_uninstall(): bool
{
    $_SESSION['uninstall_smartreport'] = true;

    echo "<center>";
    echo "<table class='tab_cadre_fixe'>";
    echo "<tr><th>" . __("MySQL tables uninstallation", "fields") . "<th></tr>";
    echo "<tr class='tab_bg_1'>";
    echo "<td align='center'>";

    $class = PluginSmartreportReportdefination::class;

    if ($plug = isPluginItemType($class)) {
        $dir  = PLUGINSMARTREPORT_DIR . "/inc/";
        $item = strtolower($plug['class']);

        if (file_exists("$dir$item.class.php")) {
            include_once("$dir$item.class.php");
            if (!call_user_func([$class, 'uninstall'])) {
                return false;
            }
        }
    }

    echo "</td></tr></table></center>";

    unset($_SESSION['uninstall_smartreport']);

    ProfileRight::deleteProfileRights(['plugin_smartreport']);
    Config::deleteConfigurationValues('plugin:smartreport', ['smartreport_file_size_limit', 'smartreport_from_email', 'config_class']);

    $pref = new DisplayPreference();
    $pref->deleteByCriteria(['itemtype' => ['LIKE', 'PluginSmartreport%']]);

    return true;
}
