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

/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_SMARTREPORT_VERSION', '0.0.1');

// Minimal GLPI version, inclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_SMARTREPORT_MIN_GLPI_VERSION", "10.0.0");

// Maximum GLPI version, exclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_SMARTREPORT_MAX_GLPI_VERSION", "11.0.99");

if (!defined('GLPI_SMARTREPORT_PLUGIN_DOC_DIR')) {
    define('GLPI_SMARTREPORT_PLUGIN_DOC_DIR', GLPI_PLUGIN_DOC_DIR . '/smartreport/');
}

if (!defined('PLUGINSMARTREPORT_DIR')) {
    define('PLUGINSMARTREPORT_DIR', Plugin::getPhpDir('smartreport'));
}

/**
 * Init hooks of the plugin.
 * REQUIRED
 */
function plugin_init_smartreport(): void {
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['smartreport'] = true;

    // Plugin::isPluginActive() exists in both GLPI 10 and 11
    if ((Session::getLoginUserID() || isCommandLine()) && Plugin::isPluginActive('smartreport')) {
        if (Session::haveRight('config', READ)) {
            $PLUGIN_HOOKS['menu_toadd']['smartreport'] = ['config' => PluginSmartreportMenu::class];
        }

        // Plugin::registerClass() works in both GLPI 10 and 11
        // (deprecated in 11 but still functional — harmless warning at worst)
        Plugin::registerClass(PluginSmartreportProfile::class, ['addtabon' => ['Profile']]);
        Plugin::registerClass(PluginSmartreportGeneratedreport::class);
        Plugin::registerClass(\GlpiPlugin\SmartReport\Config::class, ['addtabon' => ['Config']]);
    }

    

    CronTask::register(
        'PluginSmartreportReportdefination',
        'runSmartReports',
        MINUTE_TIMESTAMP * 5,
        [
            'mode'    => CronTask::MODE_EXTERNAL,
            'comment' => __('Run automatic reports', 'smartreport'),
        ]
    );

    // Load compatibility layer
    require_once __DIR__ . '/inc/glpiversion.class.php';
    require_once __DIR__ . '/src/Config.php';
}

/**
 * Get the name and the version of the plugin
 * REQUIRED
 *
 * @return array{
 *      name: string,
 *      version: string,
 *      author: string,
 *      license: string,
 *      homepage: string,
 *      requirements: array{
 *          glpi: array{
 *              min: string,
 *              max: string,
 *          }
 *      }
 * }
 */
function plugin_version_smartreport(): array
{
    return [
        'name'           => 'SmartReport',
        'version'        => PLUGIN_SMARTREPORT_VERSION,
        'author'         => '<a href="http://www.altnix.com">Altnix</a>',
        'license'        => '',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_SMARTREPORT_MIN_GLPI_VERSION,
                'max' => PLUGIN_SMARTREPORT_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

/**
 * Check pre-requisites before install
 * OPTIONAL
 */
function plugin_smartreport_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, '10.0.0', '<')) {
        if (method_exists('Plugin', 'messageIncompatible')) {
            Plugin::messageIncompatible('glpi', '10.0.0');
        }
        return false;
    }
    return true;
}

/**
 * Check configuration process
 * OPTIONAL
 *
 * @param bool $verbose Whether to display message on failure. Defaults to false.
 */
function plugin_smartreport_check_config(bool $verbose = false): bool
{
    if (true) { // Your configuration check
        return true;
    }

    if ($verbose) {
        echo __s('Installed / not configured', 'example');
    }
    return false;
}
