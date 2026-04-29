<?php

/**
 * QueueMonitor
 *
 * Provides two surfaces:
 *
 *  1.  A tab on every Reportdefination form item — shows that specific
 *      report's pending / running queue entry (if any).
 *
 *  2.  A static method showGlobalQueue() used by front/queue.php to render
 *      the full plugin-wide queue dashboard (all pending + running jobs,
 *      sorted by execution order / date_creation).
 *
 * Only STATUS_PENDING and STATUS_RUNNING rows are ever shown.
 * Completed (done / failed) rows are intentionally excluded.
 */

namespace GlpiPlugin\Smartreport;

use CommonGLPI;
use Html;
use Session;
use GlpiPlugin\Smartreport\Reportdefination;
use GlpiPlugin\Smartreport\Reportqueue;

class QueueMonitor extends \CommonGLPI
{
    public static $rightname = 'plugin_smartreport';

    public static function getTypeName($nb = 0): string
    {
        return __('Execution Queue', 'smartreport');
    }

    // ── Tab registration ───────────────────────────────────────────────────────

    /**
     * Tab label shown on the Reportdefination form.
     * Shows a badge with the count of active queue entries for this report.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof Reportdefination)) {
            return '';
        }

        $count = self::countActiveForReport($item->getID());

        return self::createTabEntry(
            __('Queue', 'smartreport'),
            $count
        );
    }

    /**
     * Renders the tab content inside the Reportdefination form.
     */
    public static function displayTabContentForItem(
        CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ): bool {
        if (!($item instanceof Reportdefination)) {
            return false;
        }

        self::showForReport($item->getID());
        return true;
    }

    // ── Per-report tab view ────────────────────────────────────────────────────

    /**
     * Renders queue status for a single report (used in the tab).
     */
    public static function showForReport(int $report_id): void
    {
        global $DB;

        $rows = iterator_to_array($DB->request([
            'FROM'  => Reportqueue::getTable(),
            'WHERE' => [
                'report_id' => $report_id,
                'status'    => [Reportqueue::STATUS_PENDING, Reportqueue::STATUS_RUNNING, Reportqueue::STATUS_FAILED],
            ],
            'ORDER' => ['date_creation ASC'],
        ]));

        echo "<div class='spaced'>";

        if (empty($rows)) {
            echo "<p class='tab_bg_1' style='padding:12px;text-align:center;'>";
            echo "<i class='ti ti-checks' style='margin-right:6px;'></i>";
            echo __('No pending or running queue entries for this report.', 'smartreport');
            echo "</p>";
            echo "</div>";
            return;
        }

        echo "<table class='tab_cadre_fixehov'>";
        echo "<thead><tr>";
        echo "<th>" . __('Queue ID', 'smartreport') . "</th>";
        echo "<th>" . __('Status', 'smartreport') . "</th>";
        echo "<th>" . __('Attempt', 'smartreport') . "</th>";
        echo "<th>" . __('Worker', 'smartreport') . "</th>";
        echo "<th>" . __('Locked At', 'smartreport') . "</th>";
        echo "<th>" . __('Queued At', 'smartreport') . "</th>";
        echo "</tr></thead><tbody>";

        foreach ($rows as $row) {
            self::renderJobRow($row, false);
        }

        echo "</tbody></table>";
        echo "</div>";
    }

    // ── Global queue dashboard (front/queue.php) ───────────────────────────────

    /**
     * Renders the full plugin-wide queue page — all pending + running jobs
     * across every report, in execution order (oldest first).
     *
     * Intended to be called from front/queue.php after Html::header().
     */
    public static function showGlobalQueue(): void
    {
        global $DB;

        // Fetch pending + running rows joined with report name, ordered by
        // execution sequence: running first, then pending by date_creation.
        $queue_table  = Reportqueue::getTable();
        $report_table = Reportdefination::getTable();

        $rows = iterator_to_array($DB->request([
            'SELECT' => [
                "$queue_table.id",
                "$queue_table.report_id",
                "$queue_table.status",
                "$queue_table.worker_id",
                "$queue_table.locked_at",
                "$queue_table.attempts",
                "$queue_table.max_attempts",
                "$queue_table.date_creation",
                "$report_table.name AS report_name",
            ],
            'FROM'       => $queue_table,
            'LEFT JOIN'  => [
                $report_table => [
                    'ON' => [
                        $queue_table  => 'report_id',
                        $report_table => 'id',
                    ],
                ],
            ],
            'WHERE'  => [
                "$queue_table.status" => [
                    Reportqueue::STATUS_PENDING,
                    Reportqueue::STATUS_RUNNING,
                    Reportqueue::STATUS_FAILED,
                ],
            ],
            // 'ORDER'  => [
            //     "FIELD($queue_table.status, 'running', 'pending'),
            //     $queue_table.date_creation ASC",
            // ],
        ]));

        // ── Page header ──────────────────────────────────────────────────────
        echo "<div class='center' style='margin-bottom:20px'>";
        echo "<h2 style='display:inline-flex;align-items:center;gap:8px'>";
        echo "<i class='ti ti-list-check' style='font-size:1.3em'></i>";
        echo __('Report Execution Queue', 'smartreport');
        echo "</h2>";
        echo "</div>";

        // ── Stats bar ────────────────────────────────────────────────────────
        $pending_count = 0;
        $running_count = 0;
        foreach ($rows as $row) {
            if ($row['status'] === Reportqueue::STATUS_RUNNING) {
                $running_count++;
            } elseif($row['status'] === Reportqueue::STATUS_PENDING) {
                $pending_count++;
            }
        }
        \Toolbox::logInFile(
                        'smartreport',
                        "queued " . print_r($rows, true) . "\n"
                    );

        echo "<div style='display:flex;gap:16px;justify-content:center;margin-bottom:20px'>";

        // Running badge
        $running_color = $running_count > 0 ? '#0ea5e9' : '#94a3b8';
        echo "<div style='background:{$running_color};color:#fff;border-radius:8px;padding:12px 24px;min-width:140px;text-align:center'>";
        echo "<div style='font-size:2em;font-weight:700'>{$running_count}</div>";
        echo "<div style='font-size:0.85em;opacity:0.9'>" . __('Running', 'smartreport') . "</div>";
        echo "</div>";

        // Pending badge
        $pending_color = $pending_count > 0 ? '#f59e0b' : '#94a3b8';
        echo "<div style='background:{$pending_color};color:#fff;border-radius:8px;padding:12px 24px;min-width:140px;text-align:center'>";
        echo "<div style='font-size:2em;font-weight:700'>{$pending_count}</div>";
        echo "<div style='font-size:0.85em;opacity:0.9'>" . __('Pending', 'smartreport') . "</div>";
        echo "</div>";

        echo "</div>";

        // ── Auto-refresh notice ──────────────────────────────────────────────
        echo "<p style='text-align:center;color:#64748b;font-size:0.85em;margin-bottom:16px'>";
        echo "<i class='ti ti-refresh' style='margin-right:4px'></i>";
        echo __('This page shows a snapshot. Refresh to see the latest state.', 'smartreport');
        echo "&nbsp;&nbsp;<a href='" . htmlspecialchars($_SERVER['REQUEST_URI']) . "' class='vsubmit' style='padding:3px 10px'>";
        echo __('Refresh', 'smartreport');
        echo "</a>";
        echo "</p>";

        // ── Empty state ──────────────────────────────────────────────────────
        if (empty($rows)) {
            echo "<div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:32px;text-align:center;max-width:560px;margin:0 auto'>";
            echo "<i class='ti ti-circle-check' style='font-size:2.5em;color:#16a34a;display:block;margin-bottom:12px'></i>";
            echo "<strong style='font-size:1.1em'>" . __('Queue is empty', 'smartreport') . "</strong><br>";
            echo "<span style='color:#64748b'>" . __('No reports are currently pending or running.', 'smartreport') . "</span>";
            echo "</div>";
            return;
        }

        // ── Queue table ──────────────────────────────────────────────────────
        echo "<table class='tab_cadre_fixehov'>";
        echo "<thead><tr>";
        echo "<th style='width:50px'>#</th>";
        echo "<th>" . __('Order', 'smartreport') . "</th>";
        echo "<th>" . __('Report Name', 'smartreport') . "</th>";
        echo "<th>" . __('Status', 'smartreport') . "</th>";
        echo "<th>" . __('Attempt', 'smartreport') . "</th>";
        echo "<th>" . __('Worker', 'smartreport') . "</th>";
        echo "<th>" . __('Locked At', 'smartreport') . "</th>";
        echo "<th>" . __('Queued At', 'smartreport') . "</th>";
        echo "</tr></thead><tbody>";

        $seq = 1;
        foreach ($rows as $row) {
            self::renderJobRow($row, true, $seq);
            $seq++;
        }

        echo "</tbody></table>";
    }

    // ── Shared row renderer ────────────────────────────────────────────────────

    /**
     * Renders a single <tr> for a queue job.
     *
     * @param array $row         Queue row data (may include report_name from join)
     * @param bool  $show_report Whether to include the report name column
     * @param int   $seq         Sequence number (1-based); 0 = omit the order column
     */
    private static function renderJobRow(array $row, bool $show_report, int $seq = 0): void
    {
        $is_running = ($row['status'] === Reportqueue::STATUS_RUNNING);

        $row_style = $is_running
            ? "background:rgba(14,165,233,0.07)"
            : "";

        echo "<tr style='{$row_style}'>";

        // Queue ID
        echo "<td style='text-align:center;font-family:monospace;color:#64748b'>#{$row['id']}</td>";

        // Sequence order (global view only)
        if ($show_report) {
            $order_label = $is_running
                ? "<span style='color:#0ea5e9;font-weight:700'><i class='ti ti-player-play'></i> " . __('Now', 'smartreport') . "</span>"
                : "<span style='color:#92400e;font-weight:600'>#{$seq}</span>";
            echo "<td style='text-align:center'>{$order_label}</td>";

            // Report name with link
            $report_id   = (int)$row['report_id'];
            $report_name = htmlspecialchars($row['report_name'] ?? "#{$report_id}");
            $report_url  = Reportdefination::getFormURLWithID($report_id);
            echo "<td><a href='{$report_url}'>{$report_name}</a></td>";
        }

        // Status pill
        if ($is_running) {
            echo "<td><span style='background:#0ea5e9;color:#fff;border-radius:12px;padding:3px 10px;font-size:0.82em;font-weight:600'>";
            echo "<i class='ti ti-loader-2' style='margin-right:4px'></i>" . __('Running', 'smartreport');
            echo "</span></td>";
        } else {
            echo "<td><span style='background:#f59e0b;color:#fff;border-radius:12px;padding:3px 10px;font-size:0.82em;font-weight:600'>";
            echo "<i class='ti ti-clock' style='margin-right:4px'></i>" . __('Pending', 'smartreport');
            echo "</span></td>";
        }

        // Attempts
        $attempts     = (int)$row['attempts'];
        $max_attempts = (int)$row['max_attempts'];
        $retry_style  = ($attempts > 1) ? "color:#dc2626;font-weight:600" : "";
        echo "<td style='text-align:center;{$retry_style}'>{$attempts} / {$max_attempts}</td>";

        // Worker ID
        $worker = $row['worker_id'] ?? '';
        echo "<td style='font-family:monospace;font-size:0.85em;color:#475569'>" . htmlspecialchars($worker ?: '—') . "</td>";

        // Locked at (time since lock acquired = running duration)
        $locked_at = $row['locked_at'] ?? null;
        if ($locked_at && $is_running) {
            $locked_ts  = strtotime($locked_at);
            $elapsed    = max(0, time() - $locked_ts);
            $elapsed_str = self::formatElapsed($elapsed);
            echo "<td title='" . htmlspecialchars($locked_at) . "' style='color:#0369a1'>";
            echo "<i class='ti ti-stopwatch' style='margin-right:3px'></i>{$elapsed_str}";
            echo "</td>";
        } else {
            echo "<td style='color:#94a3b8'>—</td>";
        }

        // Queued at
        $queued_at = $row['date_creation'] ?? '';
        echo "<td title='" . htmlspecialchars($queued_at) . "'>" . Html::convDateTime($queued_at) . "</td>";

        echo "</tr>";
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static function countActiveForReport(int $report_id): int
    {
        return countElementsInTable(
            Reportqueue::getTable(),
            [
                'report_id' => $report_id,
                'status'    => [Reportqueue::STATUS_PENDING, Reportqueue::STATUS_RUNNING, Reportqueue::STATUS_FAILED],
            ]
        );
    }

    /**
     * Formats seconds into a human-friendly elapsed string: "2m 34s", "1h 05m", etc.
     */
    private static function formatElapsed(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            $m = (int)($seconds / 60);
            $s = $seconds % 60;
            return sprintf('%dm %02ds', $m, $s);
        }
        $h = (int)($seconds / 3600);
        $m = (int)(($seconds % 3600) / 60);
        return sprintf('%dh %02dm', $h, $m);
    }
}
