<?php

class GlpiVersion
{
    /** @var int|null Cached major version — computed once per request. */
    private static $major = null;

    public static function getMajor(): int
    {
        if (self::$major === null) {
            self::$major = (int)explode('.', GLPI_VERSION)[0];
        }
        return self::$major;
    }

    public static function isGlpi11(): bool
    {
        return self::getMajor() >= 11;
    }

    public static function isGlpi10(): bool
    {
        return self::getMajor() === 10;
    }

    /**
     * Return ['email' => string, 'name' => string] for the configured sender.
     *
     * GLPI 11 exposes Config::getEmailSender(); fall back to $CFG_GLPI for GLPI 10.
     */
    public static function getEmailSender(): array
    {
        global $CFG_GLPI;

        if (method_exists('Config', 'getEmailSender')) {
            $sender = Config::getEmailSender();
            return [
                'email' => trim($sender['email'] ?? ''),
                'name'  => trim($sender['name']  ?? ''),
            ];
        }

        return [
            'email' => trim($CFG_GLPI['admin_email']      ?? ''),
            'name'  => trim($CFG_GLPI['admin_email_name'] ?? ''),
        ];
    }

    /**
     * Absolute filesystem path to the plugin's document storage directory.
     * GLPI_PLUGIN_DOC_DIR, so this is just a convenience wrapper.
     */
    public static function getPluginDocDir(): string
    {
        return rtrim(GLPI_SMARTREPORT_PLUGIN_DOC_DIR, '/') . '/';
    }

    /**
     * Web-accessible URL base for the plugin's front/ directory.
     *
     * Plugin::getWebDir() exists in both GLPI 10 and 11.
     */
    public static function getPluginWebDir(): string
    {
        return Plugin::getWebDir('smartreport');
    }

    /**
     * Filesystem path to the plugin root directory.
     *
     * Plugin::getPhpDir() exists in both GLPI 10 and 11.
     */
    public static function getPluginPhpDir(): string
    {
        return Plugin::getPhpDir('smartreport');
    }

    /**
     * Render the report form body.
     *
     * GLPI 11: uses TemplateRenderer + Twig (the existing template).
     * GLPI 10: TemplateRenderer exists but some Twig macros used in the template
     *          (fields.dropdownField, fields.dropdownYesNo, formatted_datetime
     *          filter) are GLPI 11 additions. We render the form in plain PHP
     *          using GLPI 10's Html/Dropdown helpers instead.
     *
     * @param PluginSmartreportReportdefination $item
     * @param array $widgets   Pre-rendered dropdown HTML strings
     * @param array $meta      ['next_run_display' => string]
     */
    public static function renderForm(
        PluginSmartreportReportdefination $item,
        array $widgets,
        array $meta
    ): void {
        if (self::isGlpi11()) {
            \Glpi\Application\View\TemplateRenderer::getInstance()->display(
                '@smartreport/smartreport_form.html.twig',
                [
                    'item'               => $item,
                    'widgets'            => $widgets,
                    'current_users_id'   => Session::getLoginUserID(),
                    'display_actortypes' => ['requester'],
                    'item_meta'          => $meta,
                    // Pass Execute right check to Twig — avoids calling PHP statics from template
                    'can_execute'        => Session::haveRight(
                        PluginSmartreportReportdefination::$rightname,
                        PluginSmartreportReportdefination::EXECUTE
                    ),
                ]
            );
            return;
        }

        \Glpi\Application\View\TemplateRenderer::getInstance()->display(
            '@smartreport/smartreport_form_glpi10.html.twig',
            [
                'item'               => $item,
                'widgets'            => $widgets,
                'current_users_id'   => Session::getLoginUserID(),
                'display_actortypes' => ['requester'],
                'item_meta'          => $meta,
                // Pass Execute right check to Twig — avoids calling PHP statics from template
                'can_execute'        => Session::haveRight(
                    PluginSmartreportReportdefination::$rightname,
                    PluginSmartreportReportdefination::EXECUTE
                ),
            ]
        );
        return;
    }

    /**
     * Execute a raw SQL query, compatible with both GLPI 10 and 11.
     *
     * GLPI 11 introduced DB::doQuery().
     * GLPI 10 uses DB::query() (the method that has always existed).
     *
     * @param  string $sql
     * @return mixed        Query result resource / bool (same as the underlying call)
     */
    public static function dbQuery(string $sql)
    {
        global $DB;

        if (self::isGlpi11() && method_exists($DB, 'doQuery')) {
            return $DB->doQuery($sql);
        }

        // GLPI 10 fallback — DB::query() has existed since GLPI 0.x
        return $DB->query($sql);
    }
}
