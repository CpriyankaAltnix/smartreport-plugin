<?php

class PluginSmartreportGeneratedreport extends CommonDBChild
{
    public static $itemtype = 'PluginSmartreportReportdefination';
    public static $items_id = 'reports_id';

    public static function getTypeName($nb = 0)
    {
        return __('Generated Reports', 'smartreport');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() !== 'PluginSmartreportReportdefination') {
            return '';
        }

        $count = countElementsInTable(
            self::getTable(),
            ['reports_id' => $item->getID()]
        );

        return self::createTabEntry(__('Generated Reports', 'smartreport'), $count);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() !== 'PluginSmartreportReportdefination') {
            return false;
        }

        self::showForReport($item);
        return true;
    }

    /**
     * Show all daily CSV files for this report, newest first.
     * Each row represents one calendar day's generation.
     * Same-day re-runs overwrite the same row, so there is at most one
     * row per report per day.
     */
    public static function showForReport(PluginSmartreportReportdefination $item): void
    {
        global $DB;

        $report_id = $item->getID();

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['reports_id' => $report_id],
            'ORDER' => ['report_date DESC'],
        ]);

        // Cumulative lifetime download count from the parent report
        $lifetime_downloads = (int)($item->fields['download_count'] ?? 0);

        echo "<div class='spaced'>";

        // Summary line
        echo "<p>"
            . sprintf(
                __('%d daily file(s) stored — %d total download(s) across all versions.', 'smartreport'),
                $iterator->count(),
                $lifetime_downloads
            )
            . "</p>";

        if ($iterator->count() === 0) {
            echo "<p class='center b'>" . __('No reports generated yet.', 'smartreport') . "</p>";
            echo "</div>";
            return;
        }

        echo "<table class='tab_cadre_fixehov'>";
        echo "<tr class='noHover'>";
        echo "<th>" . __('Date', 'smartreport') . "</th>";
        echo "<th>" . __('File Name') . "</th>";
        echo "<th>" . __('File Size', 'smartreport') . "</th>";
        echo "<th>" . __('Last Generated') . "</th>";
        echo "<th>" . __('Downloads', 'smartreport') . "</th>";
        echo "<th>" . __('Action') . "</th>";
        echo "</tr>";

        foreach ($iterator as $row) {
            $file_exists  = !empty($row['file_path']) && file_exists($row['file_path']);
            $download_url = Plugin::getWebDir('smartreport') . '/front/download.php'
                . '?id=' . (int)$row['id']
                . '&_glpi_csrf_token=' . Session::getNewCSRFToken();

            // Highlight today's row
            $is_today  = ($row['report_date'] === date('Y-m-d'));
            $row_class = $is_today ? 'tab_bg_2' : 'tab_bg_1';

            // Human-readable file size
            $file_size = '—';
            if ($file_exists) {
                $bytes = filesize($row['file_path']);
                if ($bytes !== false) {
                    $file_size = self::formatFileSize($bytes);
                }
            }

            echo "<tr class='{$row_class}'>";
            echo "<td>"
                . htmlspecialchars($row['report_date'] ?? '')
                . ($is_today ? " <span class='badge " . (GlpiVersion::isGlpi11() ? 'bg-success ms-1' : 'badge-success') . "'>" . __('Today') . "</span>" : '')
                . "</td>";
            echo "<td>" . htmlspecialchars($row['file_name'] ?? '') . "</td>";
            echo "<td>" . $file_size . "</td>";
            echo "<td>" . Html::convDateTime($row['generated_at'] ?? '') . "</td>";
            echo "<td>" . (int)$row['download_count'] . "</td>";
            echo "<td>";
            if ($file_exists) {
                $icon_dl  = GlpiVersion::isGlpi11() ? "ti ti-download" : "fas fa-download";
                echo "<a href='" . htmlspecialchars($download_url) . "' class='btn btn-sm btn-primary'>"
                    . "<i class='" . $icon_dl . " me-1'></i>"
                    . __('Download')
                    . "</a>";
            } else {
                $icon_off = GlpiVersion::isGlpi11() ? "ti ti-file-off" : "fas fa-times-circle";
                echo "<span class='text-muted'>"
                    . "<i class='" . $icon_off . " me-1'></i>"
                    . __('File not found')
                    . "</span>";
            }
            echo "</td>";
            echo "</tr>";
        }

        echo "</table>";
        echo "</div>";
    }

    /**
     * Format a byte count into a human-readable string (KB / MB / GB).
     * Public alias available as formatFileSizePublic() for use outside this class.
     */
    private static function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * Public alias of formatFileSize() — called from sendReportByEmail()
     * in reportdefination.class.php to display the file size in the email body.
     */
    public static function formatFileSizePublic(int $bytes): string
    {
        return self::formatFileSize($bytes);
    }

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'    => '1',
            'table' => self::getTable(),
            'field' => 'file_name',
            'name'  => __('File Name'),
        ];

        $tab[] = [
            'id'       => '2',
            'table'    => self::getTable(),
            'field'    => 'report_date',
            'name'     => __('Report Date', 'smartreport'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => '3',
            'table'    => self::getTable(),
            'field'    => 'generated_at',
            'name'     => __('Last Generated'),
            'datatype' => 'datetime',
        ];

        $tab[] = [
            'id'    => '4',
            'table' => self::getTable(),
            'field' => 'download_count',
            'name'  => __('Download Count'),
        ];

        $tab[] = [
            'id'       => '5',
            'table'    => self::getTable(),
            'field'    => 'reports_id',
            'name'     => __('Smart Report'),
            'datatype' => 'itemlink',
        ];

        return $tab;
    }
}
