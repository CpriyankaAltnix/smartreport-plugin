<?php

namespace GlpiPlugin\SmartReport;

use CommonGLPI;
use Config as GlpiConfig;
use Session;
use Html;
use Toolbox;

/**
 * Smart Report configuration tab under Setup → General.
 *
 * Values are stored in GLPI's native glpi_configs table under the context
 * 'plugin:smartreport', using Config::getConfigurationValues() and
 * Config::setConfigurationValues(). No custom table is needed.
 *
 * Keys stored:
 *   smartreport_from_email       — sender address for report emails
 *   smartreport_file_size_limit  — max attachment size in MB (0 = always attach)
 */
class Config extends CommonGLPI
{
    const DEFAULT_FILE_SIZE_LIMIT = 5;

    /** Config context key used in glpi_configs */
    const CONFIG_CONTEXT = 'plugin:smartreport';

    const CONFIG_KEY_FROM_EMAIL        = 'smartreport_from_email';
    const CONFIG_KEY_FILE_SIZE_LIMIT   = 'smartreport_file_size_limit';

    public static function getTypeName($nb = 0)
    {
        return __('Smart Report', 'smartreport');
    }

    public static function getIcon()
    {
        return 'ti ti-layout-kanban';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$withtemplate && $item->getType() === 'Config') {
            return self::createTabEntry(self::getTypeName(), 0, self::class, self::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(
        CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ) {
        if ($item->getType() !== 'Config') {
            return false;
        }
        if (!Session::haveRight('config', UPDATE)) {
            return false;
        }
        self::showConfigForm();
        return true;
    }

    public static function showConfigForm(): void
    {
        // Read current values from GLPI's config store
        $values = GlpiConfig::getConfigurationValues(self::CONFIG_CONTEXT);

        $from_email        = $values[self::CONFIG_KEY_FROM_EMAIL]      ?? '';
        $file_size_limit   = isset($values[self::CONFIG_KEY_FILE_SIZE_LIMIT])
            ? (int)$values[self::CONFIG_KEY_FILE_SIZE_LIMIT]
            : self::DEFAULT_FILE_SIZE_LIMIT;

        echo "<form name='form' action=\"" . \Toolbox::getItemTypeFormURL('Config') . "\" method='post'>";
        echo \Html::hidden('config_class',   ['value' => addslashes(self::class)]);
        echo \Html::hidden('config_context', ['value' => self::CONFIG_CONTEXT]);
        echo \Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        echo "<div class='center' id='tabsbody'>";
        echo "<table class='tab_cadre_fixe'>";

        echo "<tr class='headerRow'>";
        echo "<th colspan='2'>" . __('Smart Report Configuration', 'smartreport') . "</th>";
        echo "</tr>";

        // ── from_email ────────────────────────────────────────────────────────
        echo "<tr class='tab_bg_1'>";
        echo "<td style='width:30%'>";
        echo "<label for='sr_from_email'><strong>" . __('From Email Address', 'smartreport') . "</strong></label>";
        echo "<br/><span class='text-muted' style='font-size:0.85em'>";
        echo __('Sender address used when emailing generated reports. Leave blank to use the GLPI administrator email.', 'smartreport');
        echo "</span>";
        echo "</td>";
        echo "<td>";
        echo "<input type='email' id='sr_from_email' name='" . self::CONFIG_KEY_FROM_EMAIL . "'"
            . " style='width:100%;max-width:400px'"
            . " value='" . htmlspecialchars($from_email) . "'"
            . " placeholder='reports@example.com' />";
        echo "</td>";
        echo "</tr>";

        // ── file_size_limit ───────────────────────────────────────────────────
        echo "<tr class='tab_bg_2'>";
        echo "<td>";
        echo "<label for='sr_file_size_limit'><strong>" . __('File Size Limit (MB)', 'smartreport') . "</strong></label>";
        echo "<br/><span class='text-muted' style='font-size:0.85em'>";
        echo __('Maximum CSV file size (in MB) to attach to the email. Files larger than this limit will not be attached — only a download link is included. Set to 0 to always attach.', 'smartreport');
        echo "</span>";
        echo "</td>";
        echo "<td>";
        echo "<input type='number' id='sr_file_size_limit' name='" . self::CONFIG_KEY_FILE_SIZE_LIMIT . "'"
            . " style='width:120px'"
            . " value='" . $file_size_limit . "'"
            . " min='0' step='1' />";
        echo "&nbsp;" . __('MB', 'smartreport');
        echo "</td>";
        echo "</tr>";

        // ── Save ──────────────────────────────────────────────────────────────
        echo "<tr class='tab_bg_1'>";
        echo "<td colspan='2' class='center' style='padding:12px'>";
        echo "<input type='submit' name='update' class='submit' value=\"" . _sx('button', 'Save') . "\">";
        echo "</td>";
        echo "</tr>";

        echo "</table></div>";
        Html::closeForm();
    }

    /**
     * Return all Smart Report config values as an array.
     *
     * @return array{smartreport_from_email: string, smartreport_file_size_limit: int}
     */
    public static function getConfig(): array
    {
        $values = GlpiConfig::getConfigurationValues(self::CONFIG_CONTEXT);

        return [
            self::CONFIG_KEY_FROM_EMAIL      => trim($values[self::CONFIG_KEY_FROM_EMAIL] ?? ''),
            self::CONFIG_KEY_FILE_SIZE_LIMIT => max(
                0,
                (int)($values[self::CONFIG_KEY_FILE_SIZE_LIMIT] ?? self::DEFAULT_FILE_SIZE_LIMIT)
            ),
        ];
    }

    /**
     * Persist config after form submission.
     * Called by GLPI's Config::update() handler — no custom save route needed.
     *
     * @param array $input  The $_POST data from showConfigForm()
     */
    public function prepareInputForUpdate($input)
    {
        $values = [];

        if (isset($input[self::CONFIG_KEY_FROM_EMAIL])) {
            $email = trim($input[self::CONFIG_KEY_FROM_EMAIL]);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Session::addMessageAfterRedirect(
                    __('Invalid From Email address — not saved.', 'smartreport'),
                    false,
                    ERROR
                );
                $email = '';
            }
            $values[self::CONFIG_KEY_FROM_EMAIL] = $email;
        }

        if (isset($input[self::CONFIG_KEY_FILE_SIZE_LIMIT])) {
            $values[self::CONFIG_KEY_FILE_SIZE_LIMIT] = max(0, (int)$input[self::CONFIG_KEY_FILE_SIZE_LIMIT]);
        }

        if (!empty($values)) {
            GlpiConfig::setConfigurationValues(self::CONFIG_CONTEXT, $values);
            Toolbox::logInFile('smartreport', "[SmartReport] Configuration saved: " . json_encode($values) . "\n");
        }

        return false; // prevent CommonDBTM from trying to update a non-existent row
    }
}
