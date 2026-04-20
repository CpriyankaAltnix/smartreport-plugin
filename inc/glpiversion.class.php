<?php

/**
 * SmartReport - GLPI version helper
 *
 * Provides version checks and compatibility helpers for GLPI 10/11.
 */

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

    // Get email sender config
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

    // Plugin document directory
    public static function getPluginDocDir(): string
    {
        return rtrim(GLPI_SMARTREPORT_PLUGIN_DOC_DIR, '/') . '/';
    }

    // Plugin web path
    public static function getPluginWebDir(): string
    {
        return Plugin::getWebDir('smartreport');
    }

    // Plugin filesystem path 
    public static function getPluginPhpDir(): string
    {
        return Plugin::getPhpDir('smartreport');
    }

    // Render report form (GLPI 10/11 compatible)
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

    // DB query wrapper (GLPI 10/11)
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
