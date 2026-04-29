<?php

/**
 * PluginSmartreportReportqueue
 *
 * Represents a single job in the SmartReport execution queue.
 * Each row is created by the scheduler cron task (cronScheduleReports) and
 * consumed by a worker cron task (cronWorker1 / cronWorker2 / cronWorker3).
 *
 * TABLE: glpi_plugin_smartreport_queue
 *
 * STATUS LIFECYCLE
 * ────────────────
 *   pending  → The job has been enqueued and is waiting for a free worker.
 *   running  → A worker has claimed this job and is currently executing it.
 *   done     → Execution succeeded. The CSV is in generatedreports.
 *   failed   → All retry attempts exhausted. Manual intervention required.
 *
 * CONCURRENCY SAFETY
 * ──────────────────
 * The claim step uses a blind UPDATE … WHERE status='pending' LIMIT 1 followed
 * by SELECT WHERE worker_id=<mine>. This avoids the SELECT-then-UPDATE TOCTOU
 * race: if two workers execute simultaneously, only one UPDATE wins the row
 * because MySQL's row lock is held for the duration of the UPDATE statement.
 * The loser's UPDATE affects 0 rows; its subsequent SELECT finds nothing and
 * it exits cleanly.
 */

namespace GlpiPlugin\Smartreport;

use Session;
use GlpiPlugin\Smartreport\Glpiversion;

class Reportqueue extends \CommonDBTM
{
    public static $rightname = 'plugin_smartreport';

    // ── Status constants ───────────────────────────────────────────────────────
    const STATUS_PENDING = 'pending';
    const STATUS_RUNNING = 'running';
    const STATUS_DONE    = 'done';
    const STATUS_FAILED  = 'failed';

    // A job locked_at older than this many seconds is considered stuck and
    // will be reset to pending by the scheduler on its next tick.
    const STUCK_TIMEOUT_SECONDS = 1800; // 30 minutes

    const DEFAULT_MAX_ATTEMPTS = 3;

    public static function getTypeName($nb = 0): string
    {
        return __('SmartReport Queue', 'smartreport');
    }

    // ── Schema ─────────────────────────────────────────────────────────────────

    public static function createTable(): void
    {
        global $DB;

        $charset   = \DBConnection::getDefaultCharset();
        $collation = \DBConnection::getDefaultCollation();
        $key_sign  = \DBConnection::getDefaultPrimaryKeySignOption();
        $table     = self::getTable();

        if ($DB->tableExists($table)) {
            return;
        }

        GlpiVersion::dbQuery("CREATE TABLE `{$table}` (
            `id`           INT {$key_sign} NOT NULL AUTO_INCREMENT,
            `report_id`    INT {$key_sign} NOT NULL,
            `status`       ENUM('pending','running','done','failed')
                               NOT NULL DEFAULT 'pending',
            `worker_id`    VARCHAR(64)  NOT NULL DEFAULT '',
            `locked_at`    DATETIME     NULL DEFAULT NULL,
            `attempts`     TINYINT      NOT NULL DEFAULT 0,
            `max_attempts` TINYINT      NOT NULL DEFAULT " . self::DEFAULT_MAX_ATTEMPTS . ",
            `error`        TEXT         NULL DEFAULT NULL,
            `date_creation` DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod`      DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP
                                           ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            -- Prevent duplicate pending/running jobs for the same report.
            -- A report can appear multiple times in the table (old done/failed
            -- rows are kept for audit), but only one active job per report.
            UNIQUE KEY `uniq_active_report`  (`report_id`, `status`),
            KEY `idx_status_created` (`status`, `date_creation`),
            KEY `idx_report_id`      (`report_id`)
        ) ENGINE=InnoDB
          DEFAULT CHARSET={$charset}
          COLLATE={$collation}
          ROW_FORMAT=DYNAMIC;");
    }

    public static function dropTable(): void
    {
        GlpiVersion::dbQuery('DROP TABLE IF EXISTS `' . self::getTable() . '`');
    }

    // ── Scheduler: enqueue due reports ────────────────────────────────────────

    /**
     * Enqueue every report that is due to run and not already active in the queue.
     *
     * Called by cronScheduleReports() once per scheduler tick.
     *
     * A report is considered "already active" if the queue contains a pending or
     * running row for it — done/failed rows do not block re-scheduling.
     *
     * @return int  Number of new jobs enqueued.
     */
    public static function enqueueDueReports(): int
    {
        global $DB;

        // Reports that are enabled and due
        $reports = $DB->request([
            'FROM'  => Reportdefination::getTable(),
            'WHERE' => [
                'status' => Reportdefination::STATE_WAITING,
            ],
        ]);

        // Single query to get all already-active report IDs (pending or running)
        $active_ids = [];
        $active_rows = $DB->request([
            'SELECT' => ['report_id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['status' => [self::STATUS_PENDING, self::STATUS_RUNNING]],
        ]);
        foreach ($active_rows as $row) {
            $active_ids[(int)$row['report_id']] = true;
        }

        $enqueued = 0;

        foreach ($reports as $report) {
            if (!Reportdefination::isTimeToRun($report)) {
                continue;
            }

            $rid = (int)$report['id'];

            if (isset($active_ids[$rid])) {
                // Already pending or running — do not create a duplicate job
                continue;
            }

            // INSERT … ON DUPLICATE KEY is not appropriate here because the
            // UNIQUE KEY covers (report_id, status) — we only want to block
            // duplicates among active jobs, not across all historical rows.
            // A plain INSERT is safe because we already checked above.
            $DB->insert(self::getTable(), [
                'report_id'    => $rid,
                'status'       => self::STATUS_PENDING,
                'worker_id'    => '',
                'locked_at'    => null,
                'attempts'     => 0,
                'max_attempts' => self::DEFAULT_MAX_ATTEMPTS,
                'error'        => null,
            ]);

            \Toolbox::logInFile(
                'smartreport',
                "[SmartReport Queue] Enqueued report id=$rid name={$report['name']}\n"
            );

            $enqueued++;
        }

        return $enqueued;
    }

    // ── Scheduler: reset stuck jobs ───────────────────────────────────────────

    /**
     * Find jobs that have been in 'running' status for longer than STUCK_TIMEOUT_SECONDS
     * and reset them to 'pending' so they will be retried by the next free worker.
     *
     * This handles the case where a worker process was killed (OOM, server restart,
     * PHP fatal error) before it could mark its job as done or failed.
     *
     * @return int  Number of jobs reset.
     */
    public static function resetStuckJobs(): int
    {
        global $DB;

        $cutoff = date('Y-m-d H:i:s', time() - self::STUCK_TIMEOUT_SECONDS);

        $stuck = $DB->request([
            'SELECT' => ['id', 'report_id', 'worker_id', 'locked_at', 'attempts', 'max_attempts'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'status' => self::STATUS_RUNNING,
                ['locked_at' => ['<', $cutoff]],
            ],
        ]);

        $reset = 0;

        foreach ($stuck as $job) {
            $jid = (int)$job['id'];

            if ((int)$job['attempts'] >= (int)$job['max_attempts']) {
                // Exhausted retries — mark permanently failed
                $DB->update(self::getTable(), [
                    'status'    => self::STATUS_FAILED,
                    'worker_id' => '',
                    'locked_at' => null,
                    'error'     => 'Exceeded max_attempts after being stuck (worker_id=' . $job['worker_id'] . ')',
                ], ['id' => $jid]);

                \Toolbox::logInFile(
                    'smartreport',
                    "[SmartReport Queue] Job id=$jid (report {$job['report_id']}) stuck and out of retries — marked FAILED.\n"
                );
            } else {
                // Still has retries — put back to pending
                $DB->update(self::getTable(), [
                    'status'    => self::STATUS_PENDING,
                    'worker_id' => '',
                    'locked_at' => null,
                    'error'     => 'Reset from stuck running state (worker_id=' . $job['worker_id'] . ', locked_at=' . $job['locked_at'] . ')',
                ], ['id' => $jid]);

                \Toolbox::logInFile(
                    'smartreport',
                    "[SmartReport Queue] Job id=$jid (report {$job['report_id']}) was stuck — reset to pending for retry.\n"
                );

                $reset++;
            }
        }

        return $reset;
    }

    // ── Worker: claim one job ─────────────────────────────────────────────────

    /**
     * Atomically claim the oldest pending job from the queue.
     *
     * CONCURRENCY DESIGN
     * ──────────────────
     * We use a two-step approach that is safe under concurrent workers:
     *
     *   Step 1 — Blind UPDATE (the race-safe lock):
     *     UPDATE … SET status='running', worker_id=<mine>, locked_at=NOW()
     *     WHERE status='pending'
     *     ORDER BY date_creation ASC
     *     LIMIT 1
     *
     *     MySQL acquires a row lock for the duration of this statement.
     *     If two workers run simultaneously, one wins (affected_rows=1) and
     *     the other loses (affected_rows=0). The loser exits without doing work.
     *
     *   Step 2 — Read back by worker_id:
     *     SELECT … WHERE worker_id=<mine> AND status='running'
     *
     *     The winner reads its own claimed row to get the report_id and other
     *     fields needed for execution. Reading by worker_id (rather than re-
     *     selecting by status=pending) guarantees we read our own row even if
     *     another worker claimed a different job in the same millisecond.
     *
     * @param string $worker_id  Unique identifier for this worker (cron slot name)
     * @return array|null  The claimed queue row, or null if no pending jobs exist
     */
    public static function claimNextJob(string $worker_id): ?array
    {
        global $DB;

        // Step 1: blind atomic claim — the only race-safe approach
        $worker_id_escaped = $DB->escape($worker_id);
        $now               = date('Y-m-d H:i:s');

        $result = GlpiVersion::dbQuery(
            "UPDATE `" . self::getTable() . "`
             SET    `status`    = '" . self::STATUS_RUNNING . "',
                    `worker_id` = '" . $worker_id_escaped . "',
                    `locked_at` = '" . $now . "',
                    `attempts`  = `attempts` + 1
             WHERE  `status` = '" . self::STATUS_PENDING . "'
             ORDER BY `date_creation` ASC
             LIMIT 1"
        );

        // If no row was updated, all jobs are taken (or queue is empty)
        if (!$result || $DB->affectedRows() === 0) {
            return null;
        }

        // Step 2: read back the row we just claimed, identified by our worker_id
        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'worker_id' => $worker_id,
                'status'    => self::STATUS_RUNNING,
            ],
            'LIMIT' => 1,
        ]);

        foreach ($rows as $row) {
            return $row;
        }

        // Should not happen — we just wrote this row — but be defensive
        return null;
    }

    // ── Worker: complete or fail a job ────────────────────────────────────────

    /**
     * Mark a job as successfully completed.
     *
     * @param int $job_id  Queue row ID
     */
    public static function markDone(int $job_id): void
    {
        global $DB;

        $DB->update(self::getTable(), [
            'status'    => self::STATUS_DONE,
            'worker_id' => '',
            'locked_at' => null,
            'error'     => null,
        ], ['id' => $job_id]);
    }

    /**
     * Mark a job as failed after an exception.
     * If attempts < max_attempts the job is returned to pending for retry;
     * otherwise it is marked permanently failed.
     *
     * @param int    $job_id       Queue row ID
     * @param int    $attempts     Current attempt count (already incremented by claimNextJob)
     * @param int    $max_attempts Maximum allowed attempts
     * @param string $error        Exception message to record
     */
    public static function markFailed(int $job_id, int $attempts, int $max_attempts, string $error): void
    {
        global $DB;

        if ($attempts < $max_attempts) {
            // Return to pending for retry — next worker tick will pick it up
            $DB->update(self::getTable(), [
                'status'    => self::STATUS_PENDING,
                'worker_id' => '',
                'locked_at' => null,
                'error'     => $error,
            ], ['id' => $job_id]);
        } else {
            // All retries exhausted
            $DB->update(self::getTable(), [
                'status'    => self::STATUS_FAILED,
                'worker_id' => '',
                'locked_at' => null,
                'error'     => $error,
            ], ['id' => $job_id]);
        }
    }

    // ── Queue housekeeping ─────────────────────────────────────────────────────

    /**
     * Delete done/failed rows older than $days days.
     * Keeps the queue table small without losing recent audit history.
     *
     * @param int $days  Rows older than this many days are removed (0 = keep all)
     */
    public static function purgeOldEntries(int $days = 7): void
    {
        global $DB;

        if ($days <= 0) {
            return;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $DB->delete(self::getTable(), [
            'status' => [self::STATUS_DONE, self::STATUS_FAILED],
            ['date_mod' => ['<', $cutoff]],
        ]);
    }

    
    // ── GLPI Search engine integration ─────────────────────────────────────────

    /**
     * Allow the Search engine to list queue items.
     * Reuses the same right as the rest of the plugin.
     */
    public static function canView(): bool
    {
        return Session::haveRight(static::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return false; // Queue entries are created by the cron only
    }

    /**
     * Search columns exposed in the GLPI list view.
     *
     * Column IDs must be unique within this class.
     * The JOIN to glpi_plugin_smartreport_reportdefinations is declared via
     * joinparams so GLPI's Search engine builds the LEFT JOIN automatically.
     */
    public function rawSearchOptions(): array
    {
        $tab = [];

        // ── Queue ID ──────────────────────────────────────────────────────────
        $tab[] = [
            'id'       => '1',
            'table'    => self::getTable(),
            'field'    => 'id',
            'name'     => __('ID'),
            'datatype' => 'number',
        ];

        $tab[] = [
            'id'         => '2',
            'table'      => Reportdefination::getTable(),
            'field'      => 'name',
            'name'       => __('Report Name', 'smartreport'),
            'datatype'   => 'itemlink',
            'itemtype'   => Reportdefination::class,
            'linkfield'  => 'report_id',
            'joinparams' => [
                'jointype'  => 'parent',
            ],
        ];

        // ── Status ────────────────────────────────────────────────────────────
        $tab[] = [
            'id'            => '3',
            'table'         => self::getTable(),
            'field'         => 'status',
            'name'          => __('Status', 'smartreport'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
        ];

        // ── Attempt count ─────────────────────────────────────────────────────
        $tab[] = [
            'id'       => '4',
            'table'    => self::getTable(),
            'field'    => 'attempts',
            'name'     => __('Attempts', 'smartreport'),
            'datatype' => 'number',
        ];

        // ── Max attempts ──────────────────────────────────────────────────────
        $tab[] = [
            'id'       => '5',
            'table'    => self::getTable(),
            'field'    => 'max_attempts',
            'name'     => __('Max Attempts', 'smartreport'),
            'datatype' => 'number',
        ];

        // ── Worker ID ─────────────────────────────────────────────────────────
        $tab[] = [
            'id'    => '6',
            'table' => self::getTable(),
            'field' => 'worker_id',
            'name'  => __('Worker', 'smartreport'),
        ];

        // ── Locked at (running duration anchor) ───────────────────────────────
        $tab[] = [
            'id'       => '7',
            'table'    => self::getTable(),
            'field'    => 'locked_at',
            'name'     => __('Locked At', 'smartreport'),
            'datatype' => 'datetime',
        ];

        // ── Queued at ─────────────────────────────────────────────────────────
        $tab[] = [
            'id'       => '8',
            'table'    => self::getTable(),
            'field'    => 'date_creation',
            'name'     => __('Queued At', 'smartreport'),
            'datatype' => 'datetime',
        ];

        // ── Last updated ──────────────────────────────────────────────────────
        $tab[] = [
            'id'       => '9',
            'table'    => self::getTable(),
            'field'    => 'date_mod',
            'name'     => __('Last Updated', 'smartreport'),
            'datatype' => 'datetime',
        ];

        // ── Error message ─────────────────────────────────────────────────────
        $tab[] = [
            'id'       => '10',
            'table'    => self::getTable(),
            'field'    => 'error',
            'name'     => __('Error', 'smartreport'),
            'datatype' => 'text',
        ];

        return $tab;
    }

    /**
     * Render the status field value in the Search results list.
     * Called by GLPI's Search engine for datatype=>'specific' fields.
     *
     * @param string $field   Column field name
     * @param array  $values  Row data array
     * @param array  $options Display options
     * @return string  HTML
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = []): string
    {
        if ($field === 'status') {
            $status = is_array($values) ? ($values['status'] ?? '') : $values;
            return match ($status) {
                self::STATUS_RUNNING => '<span class="badge" style="background:#0ea5e9;color:#fff">'
                    . '<i class="ti ti-player-play me-1"></i>' . __('Running', 'smartreport') . '</span>',
                self::STATUS_PENDING => '<span class="badge" style="background:#f59e0b;color:#fff">'
                    . '<i class="ti ti-clock me-1"></i>' . __('Pending', 'smartreport') . '</span>',
                self::STATUS_DONE    => '<span class="badge" style="background:#16a34a;color:#fff">'
                    . '<i class="ti ti-check me-1"></i>' . __('Done', 'smartreport') . '</span>',
                self::STATUS_FAILED  => '<span class="badge" style="background:#dc2626;color:#fff">'
                    . '<i class="ti ti-x me-1"></i>' . __('Failed', 'smartreport') . '</span>',
                default              => htmlspecialchars($status),
            };
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Populate the dropdown values for filtering by status in the Search bar.
     * Called by GLPI's Search engine for searchtype=>'equals' on specific fields.
     *
     * @param string $field   Column field name
     * @param array  $options Options passed by Search engine
     * @return array  value => label pairs
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = []): string
    {
        if ($field === 'status') {
            $status_options = [
                self::STATUS_PENDING => __('Pending', 'smartreport'),
                self::STATUS_RUNNING => __('Running', 'smartreport'),
                self::STATUS_DONE    => __('Done', 'smartreport'),
                self::STATUS_FAILED  => __('Failed', 'smartreport'),
            ];
            return \Dropdown::showFromArray($name, $status_options, [
                'value'               => $values,
                'display_emptychoice' => true,
                'display'             => false,
            ]);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

}