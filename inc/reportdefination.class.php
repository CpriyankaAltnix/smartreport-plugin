<?php

class PluginSmartreportReportdefination extends CommonDBTM
{
    public static $rightname = 'plugin_smartreport';

    /** The smart report is disabled (will not run via cron) */
    public const STATE_DISABLE = 0;
    /** Active and scheduled */
    public const STATE_WAITING = 1;
    /** Currently executing — prevents concurrent runs */
    public const STATE_RUNNING = 2;

    public const SEARCH_PAGE_SIZE = 500;

    /**
     * Execute permission bit — controls visibility of the Execute button.
     * Value 1024 is the next available power-of-2 after standard GLPI rights
     * (READ=1, UPDATE=4, CREATE=16, DELETE=8, PURGE=32, ALLSTANDARDRIGHT=63).
     */
    public const EXECUTE = 1024;

    // ── File uniqueness modes ─────────────────────────────────────────────────
    /** One file per report per calendar day — same-day runs overwrite */
    public const UNIQUENESS_DAILY     = 0;
    /** One file per report per calendar month — same-month runs overwrite */
    public const UNIQUENESS_MONTHLY   = 1;
    /** Every run creates a new file — nothing is overwritten */
    public const UNIQUENESS_DUPLICATE = 2;

    const DEFAULT_FILE_SIZE_LIMIT = 5;

    public static function getTypeName($nb = 0)
    {
        return __('Smart Report', 'smartreport');
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab('PluginSmartreportGeneratedreport', $tabs, $options);
        return $tabs;
    }

    public function prepareInputForAdd($input)
    {
        if (!empty($input['user_email'])) {
            $input['user_email'] = implode("|", $input['user_email']);
        } else {
            $input['user_email'] = null;
        }

        $input['hourmin'] = 0;
        $input['hourmax'] = 24;


        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        unset(
            $input['saved_search_id'],
        );

        if (!empty($input['user_email'])) {
            $input['user_email'] = implode("|", $input['user_email']);
        } else {
            $input['user_email'] = null;
        }

        return parent::prepareInputForUpdate($input);
    }

    public static function installData(Migration $migration, string $version): bool
    {
        global $DB;

        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $migration->displayMessage(sprintf(__("Installing %s"), $table));

            if (!GlpiVersion::dbQuery("CREATE TABLE IF NOT EXISTS `$table` (
                `id`               INT {$key_sign} NOT NULL AUTO_INCREMENT,
                `name`             VARCHAR(255)   DEFAULT NULL,
                `saved_search_id`  INT {$key_sign} NOT NULL DEFAULT '0',
                `desc`             TEXT           DEFAULT NULL,
                `frequency`        INT {$key_sign} NOT NULL DEFAULT '0',
                `status`           TINYINT        NOT NULL DEFAULT '1',
                `hourmin`          TINYINT        NOT NULL DEFAULT '0',
                `hourmax`          TINYINT        NOT NULL DEFAULT '24',
                `lastrun`          TIMESTAMP      NULL DEFAULT NULL,
                `next_run`         INT {$key_sign} NOT NULL DEFAULT '0',
                `retention_period` INT {$key_sign} NOT NULL DEFAULT '0',
                `user_email`       TEXT           DEFAULT NULL,
                `send_user_email`  TINYINT        NOT NULL DEFAULT '0',
                `entities_id`      INT {$key_sign} NOT NULL DEFAULT '0',
                `is_recursive`     TINYINT        NOT NULL DEFAULT '0',
                `users_id`         INT {$key_sign} NOT NULL DEFAULT '0',
                `uniqueness`       TINYINT        NOT NULL DEFAULT '0',
                `date_creation`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `date_mod`         TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;")) {
                throw new RuntimeException('Error creating ' . $table . ': ' . $DB->error());
            }
        }

        $avail_table = 'glpi_plugin_smartreport_generatedreports';

        if (!$DB->tableExists($avail_table)) {
            $migration->displayMessage(sprintf(__("Installing %s"), $avail_table));

            if (!GlpiVersion::dbQuery("CREATE TABLE IF NOT EXISTS `$avail_table` (
                `id`            INT {$key_sign} NOT NULL AUTO_INCREMENT,
                `reports_id`    INT {$key_sign} NOT NULL,
                `period_key`    VARCHAR(30)   NOT NULL DEFAULT '',
                `report_date`   DATE          NOT NULL DEFAULT '0001-01-01',
                `file_name`     VARCHAR(255)  DEFAULT NULL,
                `file_path`     VARCHAR(512)  DEFAULT NULL,
                `generated_at`  TIMESTAMP     NULL DEFAULT NULL,
                `users_id`      INT {$key_sign} NOT NULL DEFAULT 0,
                `entities_id`   INT {$key_sign} NOT NULL DEFAULT 0,
                `download_count` INT {$key_sign} NOT NULL DEFAULT '0',
                `date_creation` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `date_mod`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_period_key` (`reports_id`, `period_key`),
                KEY `reports_id` (`reports_id`),
                CONSTRAINT `fk_smartreport_generatedreport`
                    FOREIGN KEY (`reports_id`) REFERENCES `$table`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;")) {
                throw new RuntimeException('Error creating ' . $avail_table . ': ' . $DB->error());
            }
        }

        // Default display preferences
        $d_pref = new DisplayPreference();
        if (count($d_pref->find(['itemtype' => __CLASS__])) === 0) {
            for ($i = 2; $i <= 7; $i++) {
                $DB->updateOrInsert(
                    DisplayPreference::getTable(),
                    ['itemtype' => __CLASS__, 'num' => $i, 'rank' => $i - 1, 'users_id' => 0],
                    ['itemtype' => __CLASS__, 'num' => $i, 'users_id' => 0]
                );
            }
        }

        return true;
    }

    public static function uninstall(): bool
    {
        global $DB;

        // Delete all CSV files from disk before the tables are dropped.
        self::purgeAllFiles();

        $obj = new self();
        foreach ($obj->find() as $row) {
            $obj->delete(['id' => $row['id']], true);
        }

        GlpiVersion::dbQuery('DROP TABLE IF EXISTS `glpi_plugin_smartreport_generatedreports`');
        GlpiVersion::dbQuery('DROP TABLE IF EXISTS `' . self::getTable() . '`');
        // PluginSmartreportConfig::uninstallConfigTable();

        (new CronTask())->deleteByCriteria(['itemtype' => self::class, 'name' => 'runSmartReports']);
        (new DisplayPreference())->deleteByCriteria(['itemtype' => __CLASS__]);

        return true;
    }

    public function rawSearchOptions(): array
    {
        $tab = [];

        $tab[] = [
            'id' => '1',
            'table' => self::getTable(),
            'field' => 'name',
            'name' => __('Name'),
            'datatype' => 'itemlink',
            'itemlink_type' => self::getType()
        ];
        $tab[] = [
            'id' => '2',
            'table' => self::getTable(),
            'field' => 'desc',
            'name' => __('Description')
        ];
        $tab[] = [
            'id' => '3',
            'table' => self::getTable(),
            'field' => 'frequency',
            'name' => __('Run frequency')
        ];
        $tab[] = [
            'id' => '4',
            'table' => self::getTable(),
            'field' => 'status',
            'name' => __('Status'),
            'datatype' => 'specific'
        ];
        $tab[] = [
            'id' => '5',
            'table' => self::getTable(),
            'field' => 'lastrun',
            'name' => __('Last Run')
        ];
        $tab[] = [
            'id' => '6',
            'table' => self::getTable(),
            'field' => 'next_run',
            'name' => __('Next Run')
        ];
        $tab[] = [
            'id' => '7',
            'table' => self::getTable(),
            'field' => 'retention_period',
            'name' => __('Retention Period')
        ];
        $tab[] = [
            'id'       => '10',
            'table'    => self::getTable(),
            'field'    => 'uniqueness',
            'name'     => __('File Uniqueness', 'smartreport'),
            'datatype' => 'specific',
        ];

        return $tab;
    }

    public function showForm($ID, $options = []): bool
    {
        global $DB;
        
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        if (empty($this->fields['lastrun'])) {
            $next_run_display = __('As soon as possible');
        } else {
            $next    = strtotime($this->fields['lastrun']) + (int)$this->fields['frequency'];
            $h       = (int)date('H', $next);
            $hourmin = (int)$this->fields['hourmin'];
            $hourmax = (int)$this->fields['hourmax'];

            if ($hourmin < $hourmax && $h < $hourmin) {
                $next = mktime($hourmin, 0, 0, (int)date('n', $next), (int)date('j', $next), (int)date('Y', $next));
            } elseif ($hourmin < $hourmax && $h >= $hourmax) {
                $next = mktime($hourmin, 0, 0, (int)date('n', $next), (int)date('j', $next) + 1, (int)date('Y', $next));
            } elseif ($hourmin > $hourmax && $h < $hourmin && $h >= $hourmax) {
                $next = mktime($hourmin, 0, 0, (int)date('n', $next), (int)date('j', $next), (int)date('Y', $next));
            }

            $next_run_display = $next < time()
                ? __('As soon as possible') . ' (' . Html::convDateTime(date('Y-m-d H:i:s', $next)) . ')'
                : Html::convDateTime(date('Y-m-d H:i:s', $next));

            $result = $DB->update(
                'glpi_plugin_smartreport_reportdefinations',
                [
                    'next_run'    => $next
                ],
                [
                    'id'  => $ID
                ]
            );
        }

        GlpiVersion::renderForm(
            $this,
            [
                'saved_search_id'       => self::renderSavedSearchDropdown($this->fields['saved_search_id']),
                'retention_period'      => self::renderRetentionPeriodDropdown($this->fields['retention_period']),
                'user_email'            => self::renderUserGroupDropdown(explode("|",$this->fields['user_email'])),
                'frequency_dropdown'    => self::renderFrequencyDropdown($this->fields['frequency']),
                'uniqueness_dropdown'   => self::renderUniquenessDropdown($this->fields['uniqueness'] ?? self::UNIQUENESS_DAILY),
            ],
            ['next_run_display' => $next_run_display]
        );

        $this->showFormButtons($options);
        return true;
    }

    // ── Dropdown helpers ──────────────────────────────────────────────────────

    public static function renderSavedSearchDropdown($selected = '')
    {
        ob_start();
        SavedSearch::dropdown([
            'name'                => 'saved_search_id',
            'display_emptychoice' => true,
            'condition'           => [
                'is_private'  => 0,
                'entities_id' => $_SESSION['glpiactive_entity'],
            ],
            'value'    => $selected,
            'width'    => 'auto',
            'required' => true,
        ]);
        return ob_get_clean();
    }

    // Alias kept for backwards compatibility with the twig template
    public static function renderRetention_PeriodDropdown($selected = '')
    {
        return self::renderRetentionPeriodDropdown($selected);
    }

    public static function getRetentionArray()
    {
        $tab = [];
        foreach ([7, 15, 30] as $d) {
            $tab[$d * DAY_TIMESTAMP] = sprintf(_n('%d day', '%d days', $d), $d);
        }

        return $tab;
    }

    public static function renderRetentionPeriodDropdown($selected = '')
    {
        $tab = self::getRetentionArray();
        ob_start();
        Dropdown::showFromArray('retention_period', $tab, ['width' => '100%', 'value' => $selected]);
        return ob_get_clean();
    }

    public static function getFrequencyArray()
    {
        $tab = [];

        $tab[DAY_TIMESTAMP] = __('Each day');
        $tab[WEEK_TIMESTAMP]  = __('Each week');
        $tab[MONTH_TIMESTAMP] = __('Each month');
        $tab[3 * MONTH_TIMESTAMP] = __('Quarterly');

        return $tab;
    }

    public static function renderFrequencyDropdown($selected = '')
    {
        $tab = self::getFrequencyArray();

        ob_start();
        Dropdown::showFromArray('frequency', $tab, ['width' => '100%', 'value' => $selected, 'required' => true]);
        return ob_get_clean();
    }

    public static function getUniquenessArray(): array
    {
        return [
            self::UNIQUENESS_DAILY     => __('Daily unique',      'smartreport'),
            self::UNIQUENESS_MONTHLY   => __('Monthly unique',    'smartreport'),
            self::UNIQUENESS_DUPLICATE => __('Allowed duplicate', 'smartreport'),
        ];
    }

    public static function renderUniquenessDropdown($selected = ''): string
    {
        ob_start();
        Dropdown::showFromArray('uniqueness', self::getUniquenessArray(), [
            'width'    => '100%',
            'value'    => $selected !== '' ? (int)$selected : self::UNIQUENESS_DAILY,
            'required' => true,
        ]);
        return ob_get_clean();
    }

    /**
     * Render the "Email this report to" field as a Select2 AJAX multi-select.
     *
     * Instead of dumping every user/group into the page HTML, we render an
     * empty <select> pre-populated only with the already-selected tokens and
     * wire Select2 to fetch search results on demand from front/user_search.php.
     *
     * This keeps page load time constant regardless of the number of users.
     *
     * @param array $selected  Already-selected tokens, e.g. ['user_3', 'group_7']
     * @return string          HTML string ready to be echoed into the form
     */
    public static function renderUserGroupDropdown($selected = [])
    {
        global $DB;

        // Resolve display labels for already-selected tokens so the field
        // shows meaningful text on page load (not just blank selected options).
        $preloaded = self::resolveTokenLabels((array)$selected);

        $ajax_url = Plugin::getWebDir('smartreport') . '/front/user_search.php';

        // Build a unique HTML id so multiple instances on the same page don't clash
        $field_id = 'smartreport_user_email_' . mt_rand(1000, 9999);

        ob_start();

        // The <select multiple> that Select2 will enhance.
        // Only pre-selected options are rendered — all other results come via AJAX.
        echo "<select id='" . $field_id . "' name='user_email[]' multiple='multiple' "
            . "style='width:100%' class='smartreport-user-select'>";

        foreach ($preloaded as $token => $label) {
            echo "<option value='" . htmlspecialchars($token) . "' selected='selected'>"
                . htmlspecialchars($label)
                . "</option>";
        }

        echo "</select>";

        // Inline JS: initialise Select2 with AJAX on this specific field.
        // Select2 is already loaded globally by both GLPI 10 and GLPI 11.
        $ajax_url_js = addslashes($ajax_url);
        $placeholder = addslashes(__("Search users or groups…", "smartreport"));

        echo <<<JS
        <script>
        (function() {
            function initSelect2() {
                var el = document.getElementById("{$field_id}");
                if (!el) { return; }
                if (typeof jQuery === "undefined" || typeof jQuery.fn.select2 === "undefined") {
                    setTimeout(initSelect2, 100);
                    return;
                }

                jQuery("#{$field_id}").select2({
                    placeholder        : "{$placeholder}",
                    allowClear         : true,
                    width              : "100%",
                    minimumInputLength : 0,

                    ajax: {
                        url      : "{$ajax_url_js}",
                        dataType : "json",
                        delay    : 300,

                        data: function (params) {
                            return {
                                q    : params.term  || "",   // search term (empty on open/scroll)
                                page : params.page  || 1     // Select2 increments this on scroll
                            };
                        },

                        processResults: function (data, params) {
                            params.page = params.page || 1;
                            return {
                                results    : data.results    || [],
                                pagination : data.pagination || { more: false }
                            };
                        },
                        cache: true
                    },
                    escapeMarkup     : function (m) { return m; },
                    templateResult   : function (item) {
                        // Optgroup headers have no id — return text as-is
                        if (!item.id) { return item.text; }
                        return item.text;
                    },
                    templateSelection: function (item) {
                        // In the tag pill, strip the email hint to keep it short
                        if (!item.id) { return item.text; }
                        var label = item.text || "";
                        var bracket = label.indexOf(" <");
                        return bracket > -1 ? label.substring(0, bracket) : label;
                    }
                });
            }

            if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", initSelect2);
            } else {
                initSelect2();
            }
        })();
        </script>
        JS;

        return ob_get_clean();
    }

    /**
     * Resolve a list of user_/group_ tokens to their display labels.
     * Only fetches DB rows for the tokens actually present — used to pre-populate
     * the Select2 field on edit without loading all users.
     *
     * @param  array $tokens  e.g. ['user_3', 'group_7', 'user_12']
     * @return array          [token => label]  e.g. ['user_3' => 'John Doe']
     */
    private static function resolveTokenLabels(array $tokens): array
    {
        global $DB;

        $labels    = [];
        $user_ids  = [];
        $group_ids = [];

        foreach ($tokens as $token) {
            $token = trim((string)$token);
            if ($token === '') {
                continue;
            }
            if (strncmp($token, 'user_', 5) === 0) {
                $user_ids[(int)substr($token, 5)] = $token;
            } elseif (strncmp($token, 'group_', 6) === 0) {
                $group_ids[(int)substr($token, 6)] = $token;
            }
        }

        if (!empty($user_ids)) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'name', 'realname', 'firstname'],
                    'FROM'   => 'glpi_users',
                    'WHERE'  => ['id' => array_keys($user_ids)],
                ]) as $row
            ) {
                $display = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
                if ($display === '') {
                    $display = $row['name'];
                }
                $labels['user_' . $row['id']] = $display;
            }
        }

        if (!empty($group_ids)) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'name'],
                    'FROM'   => 'glpi_groups',
                    'WHERE'  => ['id' => array_keys($group_ids)],
                ]) as $row
            ) {
                $labels['group_' . $row['id']] = $row['name'];
            }
        }

        return $labels;
    }

    // =========================================================================
    // Cron — scheduled execution of all due reports
    // =========================================================================

    public static function cronInfo(string $name): array
    {
        if ($name === 'runSmartReports') {
            return ['description' => __('Check each smart-report schedule and generate CSV files as due', 'smartreport')];
        }
        return [];
    }

    /**
     * GLPI cron callback.
     *
     * Loops all WAITING reports, checks each one's individual schedule
     * (frequency + hourmin/hourmax window), and calls executeReportById()
     * directly for every report that is due.
     *
     * This is the ONLY place the cron is involved. The manual "Execute" button
     * on the report form calls executeReportById() directly from the web handler
     * — it does not go through this method and does not spawn any process.
     */
    public static function cronRunSmartReports(CronTask $task): int
    {
        global $DB;

        Toolbox::logInFile(
            'smartreport',
            '[SmartReport] Cron started at ' . date('Y-m-d H:i:s')
                . ' | PHP: ' . phpversion()
                . ' | memory_limit: ' . ini_get('memory_limit')
                . ' | max_execution_time: ' . ini_get('max_execution_time')
                . "\n"
        );

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['status' => self::STATE_WAITING],
        ]);

        $count = 0;
        foreach ($iterator as $report) {
            if (!self::isTimeToRun($report)) {
                continue;
            }

            Toolbox::logInFile(
                'smartreport',
                "[SmartReport] Running report id={$report['id']} name={$report['name']}\n"
            );

            $t_start = microtime(true);

            try {
                self::executeReportById((int)$report['id']);
                $count++;
                
                $elapsed = round(microtime(true) - $t_start, 2);
                Toolbox::logInFile(
                    'smartreport',
                    "[SmartReport] Report id={$report['id']} completed in {$elapsed}s.\n"
                );
            } catch (\Throwable $e) {
                $elapsed = round(microtime(true) - $t_start, 2);
                Toolbox::logInFile(
                    'smartreport',
                    "[SmartReport] ERROR on report id={$report['id']} after {$elapsed}s: Message: "
                        . $e->getMessage() . "\n" . "File: " . $e->getFile() . "\n" . "Line: " . $e->getLine() . "\n" .  $e->getCode() . "\n"
                );
            }
        }

        $task->addVolume($count);
        Toolbox::logInFile('smartreport', "[SmartReport] Cron finished. Ran $count report(s).\n");
        return 1;
    }

    // =========================================================================
    // Public execution API — used by both the cron and the Execute button
    // =========================================================================

    /**
     * Execute a single report by ID.
     *
     * Called from:
     *   - cronRunSmartReports()  for scheduled execution
     *   - reportdefination.form.php  for manual Execute button clicks
     *
     * Handles the full pipeline: SavedSearch → CSV → generatedreport record
     * → cleanup old files → update lastrun.
     */
    public static function executeReportById(int $id): void
    {
        $report_obj = new self();
        if (!$report_obj->getFromDB($id)) {
            throw new \RuntimeException("[SmartReport] Report id=$id not found.");
        }

        self::executeReport($report_obj->fields);
    }

    /**
     * Determine whether a report is due to run right now.
     */
    public static function isTimeToRun(array $report): bool
    {
        if ((int)$report['status'] === self::STATE_DISABLE) {
            return false;
        }
        if ((int)$report['status'] === self::STATE_RUNNING) {
            return false;
        }

        $hour    = (int)date('H');
        $hourmin = (int)$report['hourmin'];
        $hourmax = (int)$report['hourmax'];

        if ($hourmin !== $hourmax && ($hour < $hourmin || $hour >= $hourmax)) {
            return false;
        }

        if (empty($report['lastrun'])) {
            return true;
        }

        $frequency = (int)$report['frequency'];
        return $frequency > 0 && (time() - strtotime($report['lastrun'])) >= $frequency;
    }

    /**
     * Full execution pipeline for one report.
     * Sets STATE_RUNNING at start, always restores STATE_WAITING in finally.
     */
    private static function executeReport(array $report): void
    {
        self::setStatus((int)$report['id'], self::STATE_RUNNING);

        // ── Resource limits ───────────────────────────────────────────────────
        // Large datasets (thousands of records) can exceed default PHP limits.
        // We raise limits here so the cron does not time out or run out of memory.
        // The original limits are restored in the finally block.
        $prev_time_limit   = (int)ini_get('max_execution_time');
        $prev_memory_limit = ini_get('memory_limit');

        // 0 = no time limit; safe for CLI cron and GLPI's automatic action runner
        set_time_limit(900);

        // Raise memory to at least 512 MB if the current limit is lower.
        // Uses bytes for comparison so "128M", "256M", "-1" are all handled.
        $current_memory = self::parseMemoryLimit($prev_memory_limit);
        if ($current_memory !== -1 && $current_memory < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }

        try {
            $saved = new SavedSearch();
            if (!$saved->getFromDB($report['saved_search_id'])) {
                throw new \RuntimeException(
                    "[SmartReport] SavedSearch id={$report['saved_search_id']} not found."
                );
            }

            // Stream the search results directly to a CSV file page-by-page.
            // This keeps memory proportional to one page (SEARCH_PAGE_SIZE rows)
            // rather than the entire dataset.
            $file = self::streamCSV($report, $saved);

            self::upsertGeneratedReportEntry($report, $file);

            self::purgeExpiredFiles((int)$report['id'], (int)$report['retention_period']);

            self::updateLastRun((int)$report['id']);

            // Send the CSV by email to all configured recipients (non-fatal)
            if (!empty($report['user_email'])) {
                try {
                    self::sendReportByEmail($report, $file);
                } catch (\Throwable $e) {
                    // Email failure must never abort the report run
                    Toolbox::logInFile(
                        'smartreport',
                        "[SmartReport] Email delivery failed for report id={$report['id']}: "
                            . $e->getMessage() . "\n"
                    );
                }
            }

            Toolbox::logInFile(
                'smartreport',
                "[SmartReport] Report id={$report['id']} done. "
                    . $file['row_count'] . " row(s) written.\n"
            );
        } finally {
            // Always reset to WAITING — even on exception
            self::setStatus((int)$report['id'], self::STATE_WAITING);

            // Restore original PHP resource limits
            if ($prev_time_limit > 0) {
                set_time_limit($prev_time_limit);
            }
            ini_set('memory_limit', $prev_memory_limit);

        }
    }

    /**
     * Parse a PHP memory_limit string to bytes.
     * Returns -1 for unlimited ("-1").
     */
    private static function parseMemoryLimit(string $val): int
    {
        $val = trim($val);
        if ($val === '-1') {
            return -1;
        }
        $last = strtolower($val[strlen($val) - 1]);
        $num  = (int)$val;
        switch ($last) {
            case 'g': return $num * 1024 * 1024 * 1024;
            case 'm': return $num * 1024 * 1024;
            case 'k': return $num * 1024;
        }
        return $num;
    }

    /**
     * Build base params and forcedisplay from a SavedSearch.
     * Shared by all pages in streamCSV().
     *
     * @return array{itemtype: string, params: array, forcedisplay: array}
     */
    private static function buildSearchParams(SavedSearch $saved): array
    {
        $itemtype     = $saved->fields['itemtype'];
        $query_string = $saved->fields['query'] ?? '';
        $params       = [];

        if (is_string($query_string) && $query_string !== '') {
            parse_str($query_string, $params);
        } elseif (is_array($query_string)) {
            $params = $query_string;
        }

        $forcedisplay = [];
        if (!empty($params['forcedisplay']) && is_array($params['forcedisplay'])) {
            $forcedisplay = array_map('intval', array_values($params['forcedisplay']));
        }

        if (empty($forcedisplay)) {
            $displaypref  = DisplayPreference::getForTypeUser($itemtype, 2);
            $forcedisplay = array_values($displaypref);
        }

        $params['itemtype']   = $itemtype;
        $params['start']      = 0;
        $params['export_all'] = 1;
        $params['sort'][0]    = 19;
        // Do NOT set export_all — we page through results manually so we
        // control memory usage rather than letting Search load everything at once.
        // unset($params['export_all']);

        return [
            'itemtype'     => $itemtype,
            'params'       => $params,
            'forcedisplay' => $forcedisplay,
        ];
    }

    /**
     * Extract column headers from a Search result data structure.
     * Handles meta-itemtype prefixing exactly as the old runSearch() did.
     */
    private static function extractHeaders(array $data): array
    {
        $headers   = [];
        $metanames = [];
        if (empty($data['data']['cols'])) {
            return $headers;
        }
        foreach ($data['data']['cols'] as $col) {
            $name = $col['name'] ?? '';
            if (isset($col['groupname'])) {
                $groupname = is_array($col['groupname'])
                    ? $col['groupname']['name']
                    : $col['groupname'];
                $name = "$groupname - $name";
            }
            if ($data['itemtype'] !== $col['itemtype']) {
                if (!isset($metanames[$col['itemtype']])) {
                    $metaitem = getItemForItemtype($col['itemtype']);
                    if ($metaitem) {
                        $metanames[$col['itemtype']] = $metaitem->getTypeName();
                    }
                }
                $name = sprintf(__('%1\$s - %2\$s'), $metanames[$col['itemtype']] ?? $col['itemtype'], $col['name']);
            }
            $headers[] = strtoupper($name);
        }
        return $headers;
    }

     /**
     * Stream the SavedSearch results page-by-page, writing each page directly
     * to an open CSV file handle, then discarding it.
     *
     * Memory usage: O(SEARCH_PAGE_SIZE) rather than O(total_rows).
     * The file is never fully buffered in RAM.
     *
     * @return array{name: string, path: string, period_key: string, report_date: string, row_count: int}
     */
    private static function streamCSV(array $report, SavedSearch $saved): array
    {
        // ── Page size ─────────────────────────────────────────────────────────
        // 500 rows per page is a safe balance:
        //    Small enough to stay comfortably within the raised memory limit
        //    Large enough to avoid excessive Search::getDatas() round-trips
        // (9000 rows = 18 pages vs 9000 SQL calls for 1-at-a-time)

        $dir = rtrim(GLPI_SMARTREPORT_PLUGIN_DOC_DIR, '/') . '/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException("[SmartReport] Cannot create directory: $dir");
        }

        $uniqueness  = (int)($report['uniqueness'] ?? self::UNIQUENESS_DAILY);
        $period_key  = self::getPeriodKey($uniqueness);
        $safe_name   = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace(' ', '_', $report['name']));
        $filename    = $safe_name . '_' . $period_key . '.csv';
        $filepath    = $dir . $filename;

        $fp = fopen($filepath, 'w');
        if ($fp === false) {
            throw new \RuntimeException("[SmartReport] Cannot write file: $filepath");
        }

        // Initialise before try so finally can always call restoreCronSession()
        // even if an exception is thrown before initCronSession() is reached.
        $saved_session = $_SESSION;
        
        try {
            fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM — Excel needs this to detect UTF-8

            ['itemtype' => $itemtype, 'params' => $base_params, 'forcedisplay' => $forcedisplay]
                = self::buildSearchParams($saved);

            // ── Cron session bootstrap ────────────────────────────────────────
            // Search::getDatas() in GLPI 10 reads $_SESSION['glpiname'] and
            // $_SESSION['glpigroups'] unconditionally inside addDefaultJoin() to
            // build entity/group visibility JOINs. During a web request these are
            // populated by Session::init(). During cron execution GLPI only starts
            // the DB and config — no user session exists — so both keys are
            // undefined, causing PHP warnings and making the JOIN return no rows.
            //
            // We populate the minimum required session state from the report's
            // stored users_id, then restore everything afterwards.
            // self::initCronSession((int)$report['users_id']);
            self::initCronSession();

            

            // Session::initEntityProfiles(2);
            // Session::changeProfile(4);
            // Session::changeActiveEntities(0);

            $glpicronuserrunning = $_SESSION["glpicronuserrunning"] ?? null;
            // unset($_SESSION['glpicronuserid']);
            unset($_SESSION['glpicronuserrunning']);
            
            // $_SESSION['glpilist_limit'] = self::SEARCH_PAGE_SIZE;

            $headers_written = false;
            $offset          = 0;
            $row_count       = 0;
            $cols            = null;   // populated from the first page only

            do {
                $params          = $base_params;
                $params['start'] = $offset;
                // $params['list_limit'] = self::SEARCH_PAGE_SIZE;

                // Page size is controlled via $_SESSION['glpilist_limit']
                // (set in initCronSession). The 'list_limit' key in $params is
                // ignored by GLPI 10's Search::getDatas().


                // Toolbox::logInFile(
                //         'smartreport_11',
                //         " itemtype - " . $itemtype . "<pre>" . print_r($params, true) . "</pre> forcedisplay - " . print_r($forcedisplay, true) ." \n"
                // );

                $data = Search::getDatas($itemtype, $params, $forcedisplay);
                
                // Toolbox::logInFile(
                //         'smartreport_11',
                //         "get datas all records -----<pre>" . print_r($data, true) . "</pre> \n"
                // );

                // Write column headers once from the first page's col metadata
                if (!$headers_written) {
                    $headers = self::extractHeaders($data);
                    if (!empty($headers)) {
                        fputcsv($fp, $headers);
                    }
                    $headers_written = true;
                    $cols            = $data['data']['cols'] ?? [];
                }

                $page_rows = $data['data']['rows'] ?? [];

                // Log what Search returned on the first page — essential for
                // diagnosing future session/entity/rights issues without guessing
                if (!$headers_written || $offset === 0) {
                    $total_count = $data['data']['totalcount'] ?? 'unknown';
                    Toolbox::logInFile(
                        'smartreport',
                        "[SmartReport] Report id={$report['id']}: Search returned "
                            . count($page_rows) . " row(s) on page 1 of ~$total_count total.\n"
                    );
                }

                if (empty($page_rows)) {
                    break;   // no rows returned — we have consumed all results
                }

                // Write this page's rows immediately and discard them
                foreach ($page_rows as $row) {
                    $line = [];
                    foreach ($cols as $col) {
                        $colkey = "{$col['itemtype']}_{$col['id']}";
                        $line[] = \Glpi\Toolbox\DataExport::normalizeValueForTextExport(
                            (string)($row[$colkey]['displayname'] ?? '')
                        );
                    }
                    fputcsv($fp, $line);
                    $row_count++;
                }

                // Log progress every 10 pages to confirm liveness in the cron log
                if (($offset / self::SEARCH_PAGE_SIZE) % 10 === 0 && $offset > 0) {
                    Toolbox::logInFile(
                        'smartreport',
                        "[SmartReport] Report id={$report['id']}: $row_count row(s) written so far...\n"
                    );
                    // Yield CPU briefly so other processes aren't starved
                    // (usleep is a no-op during CLI cron but harmless there too)
                    usleep(1000);
                }

                $page_count = count($page_rows);
                $offset    += $page_count;

                // If the page returned fewer rows than requested, we are done
                if ($page_count < self::SEARCH_PAGE_SIZE) {
                    break;
                }

                // Free memory explicitly — the Search result can be large
                unset($data, $page_rows);

            } while (true);

        } finally {
            // Always close the file, even on exception, to avoid handle leaks
            fclose($fp);
            // Restore the original session state

            if ($glpicronuserrunning !== null) {
                $_SESSION['glpicronuserrunning'] = $glpicronuserrunning;
            }

            

            // self::restoreCronSession($saved_session);
        }

        return [
            'name'        => $filename,
            'path'        => $filepath,
            'period_key'  => $period_key,
            'report_date' => date('Y-m-d'),
            'row_count'   => $row_count,
        ];
    }

    /**
     * Compute the period_key that identifies uniqueness scope for a run.
     *
     *   DAILY     → 'YYYY-MM-DD'        e.g. 2026-04-09
     *   MONTHLY   → 'YYYY-MM'           e.g. 2026-04
     *   DUPLICATE → 'YYYY-MM-DD_HHiiss' e.g. 2026-04-09_143022  (always unique)
     */
    private static function getPeriodKey(int $uniqueness): string
    {
        switch ($uniqueness) {
            case self::UNIQUENESS_MONTHLY:
                return date('Y-m');
            case self::UNIQUENESS_DUPLICATE:
                return date('Y-m-d_His');
            case self::UNIQUENESS_DAILY:
            default:
                return date('Y-m-d');
        }
    }

    /**
     * Persist the generated report record according to the uniqueness mode.
     *
     * UNIQUENESS_DAILY / MONTHLY
     *   UNIQUE KEY (reports_id, period_key) ensures at most one row per period.
     *   Same-period re-run  → UPDATE file metadata, preserve download_count.
     *   New period          → INSERT fresh row, download_count = 0.
     *
     * UNIQUENESS_DUPLICATE
     *   period_key = 'YYYY-MM-DD_HHiiss' (always unique), so every run INSERTs.
     *   All historical rows and files are retained (subject to retention policy).
     *
     * report_date always stores the real calendar date so retention-period
     * cleanup (purgeExpiredFiles) can compare against it correctly regardless
     * of which uniqueness mode is active.
     */
    private static function upsertGeneratedReportEntry(array $report, array $file): void
    {
        global $DB;

        $avail_table = 'glpi_plugin_smartreport_generatedreports';
        $reports_id  = (int)$report['id'];

        $uniqueness  = (int)($report['uniqueness'] ?? self::UNIQUENESS_DAILY);
        $period_key  = $file['period_key']  ?? self::getPeriodKey($uniqueness);
        $report_date = $file['report_date'] ?? date('Y-m-d');

        // For DUPLICATE mode every period_key is unique, so we always INSERT.
        // For DAILY/MONTHLY we check whether a row already exists for this period.
        $existing = $DB->request([
            'FROM'  => $avail_table,
            'WHERE' => [
                'reports_id' => $reports_id,
                'period_key' => $period_key,
            ],
        ]);
        
        if ($existing->count() > 0 && $uniqueness !== self::UNIQUENESS_DUPLICATE) {
            // Same period, overwrite mode: UPDATE file metadata, preserve download_count.
            // The old file on disk is already overwritten by streamCSV() because the
            // filename is deterministic for the period (same name = same path).
            $result = $DB->update(
                $avail_table,
                [
                    'file_name'    => $file['name'],
                    'file_path'    => $file['path'],
                    'report_date'  => $report_date,
                    'generated_at' => date('Y-m-d H:i:s'),
                    'users_id'     => Session::getLoginUserID() ?: 0,
                ],
                [
                    'reports_id' => $reports_id,
                    'period_key' => $period_key,
                ]
            );

            if (!$result) {
                throw new \RuntimeException(
                    "[SmartReport] Failed to update entry (period=$period_key) for report id=$reports_id"
                );
            }

            $mode_label = ($uniqueness === self::UNIQUENESS_MONTHLY) ? 'monthly' : 'daily';
            Toolbox::logInFile(
                'smartreport',
                "[SmartReport] {$mode_label} entry updated for report id=$reports_id ".
                "(period=$period_key) — download_count preserved.\n"
            );
        } else {
            // INSERT — either a genuinely new period, or DUPLICATE mode.
            $obj = new PluginSmartreportGeneratedreport();
            $id  = $obj->add([
                'reports_id'     => $reports_id,
                'period_key'     => $period_key,
                'report_date'    => $report_date,
                'file_name'      => $file['name'],
                'file_path'      => $file['path'],
                'generated_at'   => date('Y-m-d H:i:s'),
                'download_count' => 0,
                'users_id'       => Session::getLoginUserID() ?: 0,
                'entities_id'    => (int)$report['entities_id'],
            ]);

            if (!$id) {
                throw new \RuntimeException(
                    "[SmartReport] Failed to insert entry (period=$period_key) for report id=$reports_id"
                );
            }

            $mode_label = match($uniqueness) {
                self::UNIQUENESS_MONTHLY   => 'monthly',
                self::UNIQUENESS_DUPLICATE => 'duplicate',
                default                    => 'daily',
            };
            Toolbox::logInFile(
                'smartreport',
                "[SmartReport] New {$mode_label} entry created for report id=$reports_id ".
                "(period=$period_key).\n"
            );
        }
    }

    /**
     * Increment download_count on both the generatedreport row and the parent
     * reportdefination row.  Called from front/download.php after the file is
     * streamed successfully.
     */
    public static function incrementDownloadCount(int $available_id, int $report_id): void
    {
        global $DB;

        // Increment on the generatedreport row (per-generation count)
        GlpiVersion::dbQuery(
            "UPDATE `glpi_plugin_smartreport_generatedreports`
             SET `download_count` = `download_count` + 1
             WHERE `id` = " . (int)$available_id
        );

        Toolbox::logInFile(
            'smartreport',
            "[SmartReport] Download counted — available_id=$available_id report_id=$report_id\n"
        );
    }


    /**
     * Decode the stored user emails back to an array of recipient tokens.
     * Returns an empty array if nothing is stored or the value is malformed.
     *
     * Each token is either:
     *   "user_{id}"   — a specific GLPI user
     *   "group_{id}"  — every active member of a GLPI group
     */
    public static function decodeUserEmail(?string $raw): array
    {
        if (empty($raw)) {
            return [];
        }
        $decoded = explode("|", $raw);
        return is_array($decoded) ? $decoded : [];
    }

    private static function resolveEmailRecipients(string $raw): array
    {
        global $DB;

        $tokens     = self::decodeUserEmail($raw);
        $recipients = [];

        if (empty($tokens)) {
            return $recipients;
        }

        $user_ids  = [];
        $group_ids = [];

        foreach ($tokens as $token) {
            if (strncmp($token, 'user_', 5) === 0) {
                $user_ids[] = (int)substr($token, 5);
            } elseif (strncmp($token, 'group_', 6) === 0) {
                $group_ids[] = (int)substr($token, 6);
            }
        }

        // Resolve individual users — join glpi_useremails on is_default = 1
        if (!empty($user_ids)) {
            $rows = $DB->request([
                'SELECT' => [
                    'glpi_users.id',
                    'glpi_users.name',
                    'glpi_users.realname',
                    'glpi_users.firstname',
                    'glpi_useremails.email',
                ],
                'FROM'      => 'glpi_users',
                'LEFT JOIN' => [
                    'glpi_useremails' => [
                        'ON' => [
                            'glpi_users'      => 'id',
                            'glpi_useremails' => 'users_id',
                            ['AND' => ['glpi_useremails.is_default' => 1]],
                        ],
                    ],
                ],
                'WHERE' => [
                    'glpi_users.id'       => $user_ids,
                    'glpi_users.is_active' => 1,
                ],
            ]);
            foreach ($rows as $row) {
                $email = trim($row['email'] ?? '');
                if ($email !== '') {
                    $display = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
                    $display = $display !== '' ? $display : $row['name'];
                    $recipients[$email] = $display;
                }
            }
        }

        // Resolve groups — expand to every active member with a default email
        if (!empty($group_ids)) {
            $members = $DB->request([
                'SELECT' => [
                    'glpi_users.id',
                    'glpi_users.name',
                    'glpi_users.realname',
                    'glpi_users.firstname',
                    'glpi_useremails.email',
                ],
                'FROM'      => 'glpi_groups_users',
                'LEFT JOIN' => [
                    'glpi_users' => [
                        'ON' => [
                            'glpi_groups_users' => 'users_id',
                            'glpi_users'        => 'id',
                        ],
                    ],
                    'glpi_useremails' => [
                        'ON' => [
                            'glpi_users'      => 'id',
                            'glpi_useremails' => 'users_id',
                            ['AND' => ['glpi_useremails.is_default' => 1]],
                        ],
                    ],
                ],
                'WHERE' => [
                    'glpi_groups_users.groups_id' => $group_ids,
                    'glpi_users.is_active'        => 1,
                ],
            ]);
            foreach ($members as $row) {
                $email = trim($row['email'] ?? '');
                // Deduplicate — a user in multiple selected groups gets one entry
                if ($email !== '' && !isset($recipients[$email])) {
                    $display = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
                    $display = $display !== '' ? $display : $row['name'];
                    $recipients[$email] = $display;
                }
            }
        }

        return $recipients;
    }

    /**
     * Send ONE email per report execution.
     *
     * Behaviour controlled by Smart Report configuration (Setup → General → Smart Report):
     *
     *   file_size_limit (MB):
     *     0         → always attach the CSV regardless of size
     *     N > 0     → attach if file ≤ N MB; otherwise send link-only email
     *
     *   from_email:
     *     Set       → use this address as the From header
     *     Empty     → fall back to GLPI's configured admin / notification email
     *
     * One SMTP transaction is used regardless of recipient count.
     * First recipient → To; remaining → BCC (addresses not exposed to each other).
     */
    private static function sendReportByEmail(array $report, array $file): void
    {
        $recipients = self::resolveEmailRecipients($report['user_email'] ?? '');

        if (empty($recipients)) {
            Toolbox::logInFile(
                'smartreport',
                "[SmartReport] No valid email recipients for report id={$report['id']}. Skipping email.\n"
            );
            return;
        }

        if (!file_exists($file['path']) || !is_readable($file['path'])) {
            throw new \RuntimeException(
                "[SmartReport] CSV file not readable for email attachment: {$file['path']}"
            );
        }

        // ── Read plugin configuration ─────────────────────────────────────────
        
        $sr_config      = \GlpiPlugin\SmartReport\Config::getConfig();
        $from_email_cfg = trim($sr_config[\GlpiPlugin\SmartReport\Config::CONFIG_KEY_FROM_EMAIL]      ?? '');
        $size_limit_mb  = max(0, (int)($sr_config[\GlpiPlugin\SmartReport\Config::CONFIG_KEY_FILE_SIZE_LIMIT]
            ?? \GlpiPlugin\SmartReport\Config::DEFAULT_FILE_SIZE_LIMIT));


        // ── Determine whether to attach the file ─────────────────────────────
        $file_bytes    = (int)filesize($file['path']);
        $limit_bytes   = $size_limit_mb * 1024 * 1024;
        $attach_file   = ($size_limit_mb === 0) || ($file_bytes <= $limit_bytes);

        // ── Build absolute download link for the email ───────────────────────
        // The link points to email_download.php which requires a GLPI session.
        // Recipients who are not logged in will be prompted to log in first.
        $generated_id  = self::getGeneratedReportId((int)$report['id']);
        $download_link = self::buildAbsoluteDownloadUrl($generated_id);


        $report_name_safe = htmlspecialchars($report['name']);
        $generated_at     = date('Y-m-d H:i:s');

        if ($attach_file) {
            // ── Full attachment email ─────────────────────────────────────────
            $body_html = sprintf(
                '<p>%s</p><p>%s</p><p><a href="%s">%s</a></p>',
                htmlspecialchars(sprintf(
                    __("Please find attached the smart report \"%s\" generated on %s.", 'smartreport'),
                    $report['name'], $generated_at
                )),
                htmlspecialchars(__("You can also download it via the link below.", 'smartreport')),
                htmlspecialchars($download_link),
                htmlspecialchars(__("Download Report", 'smartreport'))
            );
            $body_text = sprintf(
                "%s\n\n%s\n%s",
                sprintf(
                    __("Please find attached the smart report \"%s\" generated on %s.", 'smartreport'),
                    $report['name'], $generated_at
                ),
                __("You can also download it via the link below:", 'smartreport'),
                $download_link
            );

            Toolbox::logInFile(
                'smartreport',
                "[SmartReport] Email mode: ATTACH ({$file_bytes} bytes <= limit {$limit_bytes} bytes) "
                    . "for report id={$report['id']}.\n"
            );
        } else {
            // ── Link-only email (file exceeds size limit) ─────────────────────
            $file_size_display = PluginSmartreportGeneratedreport::formatFileSizePublic($file_bytes);
            $limit_display     = $size_limit_mb . ' MB';

            $body_html = sprintf(
                '<p>%s</p><p>%s</p><p><a href="%s">%s</a></p>',
                htmlspecialchars(sprintf(
                    __("The smart report \"%s\" was generated on %s.", 'smartreport'),
                    $report['name'], $generated_at
                )),
                htmlspecialchars(sprintf(
                    __("The file (%s) exceeds the configured attachment limit (%s) and has not been attached. Please download it using the link below.", 'smartreport'),
                    $file_size_display, $limit_display
                )),
                htmlspecialchars($download_link),
                htmlspecialchars(__("Download Report", 'smartreport'))
            );
            $body_text = sprintf(
                "%s\n\n%s\n%s",
                sprintf(
                    __("The smart report \"%s\" was generated on %s.", 'smartreport'),
                    $report['name'], $generated_at
                ),
                sprintf(
                    __("The file (%s) exceeds the configured attachment limit (%s). Download it here:", 'smartreport'),
                    $file_size_display, $limit_display
                ),
                $download_link
            );

            Toolbox::logInFile(
                'smartreport',
                "[SmartReport] Email mode: LINK-ONLY ({$file_bytes} bytes > limit {$limit_bytes} bytes) "
                    . "for report id={$report['id']}.\n"
            );
        }


        $subject = sprintf(
            __("Smart Report: %s - %s", 'smartreport'),
            $report['name'],
            date('Y-m-d H:i')
        );

        // Build a flat list so index 0 is To and the rest are BCC
        $emails = array_keys($recipients);

        $mailer = new GLPIMailer();

        // Resolve From address: plugin config → GLPI notification config → fallback
        $from_email = $from_email_cfg;
        $from_name  = 'GLPI Smart Report';


        // Set the From address from GLPI's notification configuration
        // (Setup → General → Notifications → Administrator email).
        // GLPIMailer does not populate From automatically — without it
        // PHPMailer refuses to send with "An email must have a From header".

        if ($from_email === '') {
            $sender     = GlpiVersion::getEmailSender();
            $from_email = $sender['email'];
            $from_name  = $sender['name'] ?: 'GLPI Smart Report';
        }


        if ($from_email === '') {
            global $CFG_GLPI;
            $from_email = trim($CFG_GLPI['admin_email'] ?? '');
            $from_name  = trim($CFG_GLPI['admin_email_name'] ?? 'GLPI Smart Report');
        }

        if ($from_email !== '') {
            $mailer->setFrom($from_email, $from_name ?: 'GLPI Smart Report');
        }


        $mailer->Subject = $subject;
        $mailer->Body    = $body_html;
        $mailer->AltBody = $body_text;
        $mailer->IsHTML(true);

        // Conditionally attach the file
        if ($attach_file) {
            $mailer->AddAttachment($file['path'], $file['name'], 'base64', 'text/csv');
        }

        // First address → To; rest → BCC
        $to_email = $emails[0];
        $mailer->AddAddress($to_email, $recipients[$to_email]);

        $bcc_emails = array_slice($emails, 1);
        foreach ($bcc_emails as $bcc_email) {
            $mailer->AddBCC($bcc_email, $recipients[$bcc_email]);
        }

        Toolbox::logInFile(
                    'smartreport',
                    "inside 1 \n"

                );
        $bcc_count = count($bcc_emails);

        try {
            if ($mailer->Send()) {
                Toolbox::logInFile(
                    'smartreport',
                    "[SmartReport] Email sent for report id={$report['id']}. "
                        . "To: $to_email"
                        . ($bcc_count > 0 ? ", BCC: $bcc_count recipient(s)." : ".")
                        . " Mode: " . ($attach_file ? "attachment" : "link-only") . "\n"

                );
            } else {
                Toolbox::logInFile(
                    'smartreport',
                    "[SmartReport] Email FAILED for report id={$report['id']}: " . $mailer->ErrorInfo . "\n"
                );
            }
        } catch (\Throwable $e) {
            Toolbox::logInFile(
                'smartreport',
                "[SmartReport] Email exception for report id={$report['id']}: " . $e->getMessage() . "\n"
            );
        }
    }

    /**
     * Look up the generatedreport DB row ID for today's entry of a report.
     * Used to generate the download link for link-only emails.
     * Returns 0 if no row found (edge case — link will be a no-op).
     */
    private static function getGeneratedReportId(int $report_id): int
    {
        global $DB;

        $rows = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_smartreport_generatedreports',
            'WHERE'  => [
                'reports_id'  => $report_id,
                'report_date' => date('Y-m-d'),
            ],
            'LIMIT' => 1,
        ]);

        foreach ($rows as $row) {
            return (int)$row['id'];
        }
        return 0;
    }

    /**
     * Delete CSV files and their DB rows whose report_date is older than the
     * configured retention_period for this report.
     *
     * Called automatically after every report execution (manual or cron) so
     * that expired files are pruned as new ones are generated — no separate
     * scheduled job is required.
     *
     * @param int $report_id        Primary key of the parent smart-report
     * @param int $retention_days   Number of days to keep files (0 = keep forever)
     */
    private static function purgeExpiredFiles(int $report_id, int $retention_days): void
    {
        global $DB;

        // retention_period = 0 means "keep forever" — nothing to do
        if ($retention_days <= 0) {
            return;
        }

        $cutoff = date('Y-m-d', strtotime("-{$retention_days} days"));

        $expired = $DB->request([
            'FROM'  => 'glpi_plugin_smartreport_generatedreports',
            'WHERE' => [
                'reports_id'  => $report_id,
                ['report_date' => ['<', $cutoff]],
            ],
        ]);

        $deleted_files = 0;
        $deleted_rows  = 0;

        foreach ($expired as $row) {
            // Delete the physical CSV file first
            $path = $row['file_path'] ?? '';
            if ($path !== '' && file_exists($path)) {
                if (@unlink($path)) {
                    $deleted_files++;
                } else {
                    Toolbox::logInFile(
                        'smartreport',
                        "[SmartReport] Could not delete expired file: $path\n"
                    );
                }
            }

            // Remove the DB record regardless of whether the file existed
            $DB->delete('glpi_plugin_smartreport_generatedreports', ['id' => (int)$row['id']]);
            $deleted_rows++;
        }

        if ($deleted_rows > 0) {
            Toolbox::logInFile(
                'smartreport',
                "[SmartReport] Retention purge for report id=$report_id "
                    . "(>{$retention_days} days): "
                    . "$deleted_files file(s) deleted, $deleted_rows DB row(s) removed.\n"
            );
        }
    }

    /**
     * Delete every CSV file ever generated for all smart-reports.
     * Called during plugin uninstall to leave no orphaned files on disk.
     *
     * Works by reading file paths from the DB (which is still intact at the
     * point uninstall() runs this), then removing the files and finally the
     * plugin document directory itself.
     */
    public static function purgeAllFiles(): void
    {
        global $DB;

        $rows = $DB->request([
            'SELECT' => ['file_path'],
            'FROM'   => 'glpi_plugin_smartreport_generatedreports',
            'WHERE'  => [['file_path' => ['!=', '']]],
        ]);

        $deleted = 0;
        foreach ($rows as $row) {
            $path = $row['file_path'] ?? '';
            if ($path !== '' && file_exists($path)) {
                if (@unlink($path)) {
                    $deleted++;
                } else {
                    Toolbox::logInFile(
                        'smartreport',
                        "[SmartReport] Uninstall: could not delete file: $path\n"
                    );
                }
            }
        }

        // Remove the plugin document directory if it is now empty
        $doc_dir = rtrim(GLPI_SMARTREPORT_PLUGIN_DOC_DIR, '/');
        if (is_dir($doc_dir)) {
            $remaining = array_diff((array)scandir($doc_dir), ['.', '..']);
            if (empty($remaining)) {
                @rmdir($doc_dir);
                Toolbox::logInFile(
                    'smartreport',
                    "[SmartReport] Uninstall: removed directory $doc_dir\n"
                );
            } else {
                Toolbox::logInFile(
                    'smartreport',
                    "[SmartReport] Uninstall: $doc_dir not empty after file removal "
                        . "(" . count($remaining) . " item(s) remaining), directory kept.\n"
                );
            }
        }

        Toolbox::logInFile(
            'smartreport',
            "[SmartReport] Uninstall file purge complete. $deleted file(s) deleted.\n"
        );
    }

        /**
     * Populate the minimum $_SESSION keys that Search::getDatas() requires in
     * GLPI 10 when running outside a web request (cron / CLI context).
     *
     * WHY THIS IS NEEDED
     * ──────────────────
     * Search::addDefaultJoin() and Search::constructSQL() in GLPI 10 read
     * $_SESSION['glpiname'] and $_SESSION['glpigroups'] unconditionally to build
     * per-user / per-group visibility JOIN and WHERE clauses. In a web session
     * these are populated by Session::init(). In a cron context GLPI only sets up
     * the DB and config — $_SESSION exists but those keys are absent, producing:
     *
     *   PHP Warning: Undefined array key "glpiname"  in Search.php
     *   PHP Warning: Undefined array key "glpigroups" in Search.php
     *
     * The JOIN clause built from those undefined keys is malformed and matches
     * nothing, so Search returns zero data rows — only headers appear in the CSV.
     *
     * THE FIX
     * ───────
     * Before calling Search::getDatas() we populate the missing keys using the
     * report's stored users_id. The full set of keys matches what Session::init()
     * sets for a logged-in user.  After Search returns we restore $_SESSION
     * exactly to its prior state via restoreCronSession().
     *
     * This is the same pattern GLPI itself uses in SavedSearch::cronSavedSearch()
     * and SavedSearch alert processing.
     *
     * @param  int   $users_id   Report's stored creator user ID (0 → first super-admin)
     * @return array             Snapshot of $_SESSION before modification
     */
    // private static function initCronSession(int $users_id): void
    // {
    //     global $DB, $CFG_GLPI;

    //     // If running under a real web session the keys are already populated.
    //     // Nothing to do — the saved_session snapshot in streamCSV() will still
    //     // capture and restore correctly.
    //     if (!isCommandLine() && !empty($_SESSION['glpiname'])) {
    //         return;
    //     }

    //     // ── Resolve which user to run the search as ───────────────────────────
    //     // Prefer the user who created/owns the report. Fall back to the first
    //     // active super-admin user so the search has maximum visibility.
    //     $user_row = null;

    //     if ($users_id > 0) {
    //         $rows = $DB->request([
    //             'SELECT' => ['id', 'name', 'firstname', 'realname', 'language'],
    //             'FROM'   => 'glpi_users',
    //             'WHERE'  => ['id' => $users_id, 'is_active' => 1, 'is_deleted' => 0],
    //             'LIMIT'  => 1,
    //         ]);
    //         foreach ($rows as $r) {
    //             $user_row = $r;
    //         }
    //     }

    //     if ($user_row === null) {
    //         // Fall back: find the first active user with super-admin profile
    //         $admin_profile_id = null;
    //         $profiles = $DB->request([
    //             'SELECT' => ['id'],
    //             'FROM'   => 'glpi_profiles',
    //             'WHERE'  => ['interface' => 'central'],
    //             'LIMIT'  => 1,
    //         ]);
    //         foreach ($profiles as $p) {
    //             $admin_profile_id = (int)$p['id'];
    //         }

    //         if ($admin_profile_id !== null) {
    //             $fallback = $DB->request([
    //                 'SELECT'    => ['glpi_users.id', 'glpi_users.name', 'glpi_users.firstname',
    //                                 'glpi_users.realname', 'glpi_users.language'],
    //                 'FROM'      => 'glpi_users',
    //                 'LEFT JOIN' => [
    //                     'glpi_profiles_users' => [
    //                         'ON' => [
    //                             'glpi_users'          => 'id',
    //                             'glpi_profiles_users' => 'users_id',
    //                         ],
    //                     ],
    //                 ],
    //                 'WHERE' => [
    //                     'glpi_users.is_active'    => 1,
    //                     'glpi_users.is_deleted'   => 0,
    //                     'glpi_profiles_users.profiles_id' => $admin_profile_id,
    //                 ],
    //                 'LIMIT' => 1,
    //             ]);
    //             foreach ($fallback as $r) {
    //                 $user_row = $r;
    //             }
    //         }
    //     }

    //     if ($user_row === null) {
    //         Toolbox::logInFile(
    //             'smartreport',
    //             "[SmartReport] initCronSession: could not resolve a user — search may return no rows.\n"
    //         );
    //         return;
    //     }

    //     $uid = (int)$user_row['id'];

    //     // ── Resolve group memberships ─────────────────────────────────────────
    //     $groups = [];
    //     $gRows  = $DB->request([
    //         'SELECT' => ['groups_id'],
    //         'FROM'   => 'glpi_groups_users',
    //         'WHERE'  => ['users_id' => $uid],
    //     ]);
    //     foreach ($gRows as $g) {
    //         $groups[] = (int)$g['groups_id'];
    //     }

    //     // ── Resolve active profile ────────────────────────────────────────────
    //     $profile_id = 0;
    //     $pRows = $DB->request([
    //         'SELECT' => ['profiles_id'],
    //         'FROM'   => 'glpi_profiles_users',
    //         'WHERE'  => ['users_id' => $uid],
    //         'ORDER'  => 'id ASC',
    //         'LIMIT'  => 1,
    //     ]);
    //     foreach ($pRows as $p) {
    //         $profile_id = (int)$p['profiles_id'];
    //     }

    //     $profile = [];
    //     if ($profile_id > 0) {
    //         $pdata = $DB->request([
    //             'FROM'  => 'glpi_profiles',
    //             'WHERE' => ['id' => $profile_id],
    //             'LIMIT' => 1,
    //         ]);
    //         foreach ($pdata as $pd) {
    //             $profile = $pd;
    //         }
    //     }

    //     // ── Resolve accessible entities ───────────────────────────────────────
    //     $entities = [0];   // entity 0 is always accessible
    //     $eRows = $DB->request([
    //         'SELECT' => ['entities_id'],
    //         'FROM'   => 'glpi_profiles_users',
    //         'WHERE'  => ['users_id' => $uid],
    //     ]);
    //     foreach ($eRows as $e) {
    //         $entities[] = (int)$e['entities_id'];
    //     }
    //     $entities = array_values(array_unique($entities));

    //     // ── Populate $_SESSION keys that Search requires ──────────────────────
    //     $_SESSION['glpiID']            = $uid;
    //     $_SESSION['glpiname']          = $user_row['name']      ?? '';
    //     $_SESSION['glpifirstname']     = $user_row['firstname'] ?? '';
    //     $_SESSION['glpirealname']      = $user_row['realname']  ?? '';
    //     $_SESSION['glpigroups']        = $groups;
    //     $_SESSION['glpiactiveprofile'] = $profile;
    //     $_SESSION['glpiprofiles']      = $profile_id > 0 ? [$profile_id => $profile] : [];

    //     // Entity scope: all entities the user can access
    //     $_SESSION['glpiactiveentities']        = $entities;
    //     $_SESSION['glpiactiveentities_string'] = implode(',', $entities);
    //     $_SESSION['glpiactive_entity']         = $entities[0];
    //     $_SESSION['glpiactive_entity_recursive']= 1;

    //     // Language for translated field names in headers
    //     $_SESSION['glpilanguage'] = $user_row['language']
    //         ?? ($CFG_GLPI['language'] ?? 'en_GB');

    //     // Used by some GLPI 10 helpers
    //     $_SESSION['glpi_use_mode']  = Session::NORMAL_MODE;
    //     $_SESSION['glpiis_ids_visible'] = 0;

    //     Toolbox::logInFile(
    //         'smartreport',
    //         "[SmartReport] initCronSession: running search as user id=$uid name={$_SESSION['glpiname']}"
    //             . " groups=[" . implode(',', $groups) . "]"
    //             . " entities=[" . implode(',', $entities) . "]\n"
    //     );
    // }


        /**
     * Populate the minimum $_SESSION keys that Search::getDatas() requires in
     * GLPI 10 when running outside a web request (cron / CLI context).
     *
     * WHY THIS IS NEEDED
     * ──────────────────
     * Search::addDefaultJoin() and Search::constructSQL() in GLPI 10 read
     * $_SESSION['glpiname'] and $_SESSION['glpigroups'] unconditionally to build
     * per-user / per-group visibility JOIN and WHERE clauses. In a web session
     * these are populated by Session::init(). In a cron context GLPI only sets up
     * the DB and config — $_SESSION exists but those keys are absent, producing:
     *
     *   PHP Warning: Undefined array key "glpiname"  in Search.php
     *   PHP Warning: Undefined array key "glpigroups" in Search.php
     *
     * The JOIN clause built from those undefined keys is malformed and matches
     * nothing, so Search returns zero data rows — only headers appear in the CSV.
     *
     * THE FIX
     * ───────
     * Before calling Search::getDatas() we populate the missing keys using the
     * report's stored users_id. The full set of keys matches what Session::init()
     * sets for a logged-in user.  After Search returns we restore $_SESSION
     * exactly to its prior state via restoreCronSession().
     *
     * This is the same pattern GLPI itself uses in SavedSearch::cronSavedSearch()
     * and SavedSearch alert processing.
     *
     * @param  int   $users_id   Report's stored creator user ID (0 → first super-admin)
     * @return array             Snapshot of $_SESSION before modification
     */

    private static function initCronSession(): void
    {
        global $DB, $CFG_GLPI;

        // ── WHY WE ALWAYS USE A SUPERADMIN ────────────────────────────────────
        // GLPI 10's Search::addDefaultJoin() generates a visibility guard for
        // itemtypes like Ticket:
        //
        //   WHERE (0=1)   ← if user has no tickets and no groups
        //   WHERE (`requester`.`users_id` = X OR `assignee`.`users_id` = X OR ...)
        //
        // A "normal" user (even the report creator) gets (0=1) when they have
        // no assigned tickets and no group memberships — the entire query returns
        // zero rows even though the data exists.
        //
        // A superadmin user (is_superadmin=1 in their profile) bypasses this
        // visibility guard entirely — GLPI omits the (0=1) condition for them.
        //
        // Solution: always run cron searches as a superadmin. This gives the
        // correct behaviour for a scheduled report, which is an admin-level
        // operation that should see all data regardless of ticket assignments.

        // ── Find the first active superadmin user ─────────────────────────────
        // We join glpi_profiles on is_superadmin=1 to guarantee we get a user
        // whose profile causes GLPI to skip the visibility restriction.
        $user_row = null;

        $rows = $DB->request([
            'SELECT'    => [
                'glpi_users.id',
                'glpi_users.name',
                'glpi_users.firstname',
                'glpi_users.realname',
                'glpi_users.language',
                'glpi_profiles.id AS profile_id',
                'glpi_profiles_users.entities_id',
                'glpi_profiles_users.is_recursive',
            ],
            'FROM'      => 'glpi_users',
            'LEFT JOIN' => [
                'glpi_profiles_users' => [
                    'ON' => [
                        'glpi_users'          => 'id',
                        'glpi_profiles_users' => 'users_id',
                    ],
                ],
                'glpi_profiles' => [
                    'ON' => [
                        'glpi_profiles_users' => 'profiles_id',
                        'glpi_profiles'       => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_users.is_active'    => 1,
                'glpi_users.is_deleted'   => 0,
                'glpi_profiles.name' => 'Super-Admin',
            ],
            'ORDER' => 'glpi_users.id ASC',
            'LIMIT' => 1,
        ]);

        foreach ($rows as $r) {
            $user_row = $r;
        }

        if ($user_row === null) {
            Toolbox::logInFile(
                'smartreport',
                "[SmartReport] initCronSession: no superadmin found — search will use empty session.\n"
            );
            return;
        }

        $uid        = (int)$user_row['id'];
        $profile_id = (int)$user_row['profile_id'];

        Session::initEntityProfiles($uid);
        Session::changeProfile($profile_id);
        Session::changeActiveEntities((int)$user_row['entities_id']);
        
        $_SESSION['glpilist_limit'] = self::SEARCH_PAGE_SIZE;

        Toolbox::logInFile(
            'smartreport',
            "[SmartReport] initCronSession: superadmin id=$uid name={$_SESSION['glpiname']}"
                . " profile_id=$profile_id is_superadmin=1 ]\n"
        );
    }

    /**
     * Restore $_SESSION to the snapshot taken before initCronSession() was called.
     *
     * @param array $saved  The snapshot ($saved_session) captured in streamCSV()
     */
    private static function restoreCronSession(array $saved): void
    {
        $_SESSION = $saved;
    }
    
    /**
     * Build a fully-qualified download URL for embedding in emails.
     * Uses GLPI's configured URL root so the link works regardless of server config.
     */
    private static function buildAbsoluteDownloadUrl(int $generated_id): string
    {
        global $CFG_GLPI;

        $base = rtrim($CFG_GLPI['url_base'] ?? '', '/');

        return $base
            . '/plugins/smartreport/front/email_download.php'
            . '?id=' . urlencode((string)$generated_id);
    }

    // =========================================================================
    // DB helpers
    // =========================================================================

    private static function setStatus(int $id, int $status): void
    {
        global $DB;
        $DB->update(self::getTable(), ['status' => $status], ['id' => $id]);
    }

    private static function updateLastRun(int $id): void
    {
        global $DB;
        $DB->update(self::getTable(), ['lastrun' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {       
        switch($field){
            case 'frequency': 
                $tab = self::getFrequencyArray();
                return $tab[$values[$field]];
            case 'status': 
                if($values[$field]){
                    return 'Scheduled';
                } else {
                    return 'Disabled';
                }
            case 'retention_period':
                $tab = self::getRetentionArray();
                return $tab[$values[$field]];
            case 'lastrun':
                if($values[$field] > 0){
                    return $values[$field];
                } else {                    
                    return "Never";
                }
            case 'next_run':
                if($values[$field] > 0){
                    return Html::convDateTime(date('Y-m-d H:i:s', (int)$values[$field]));
                } else {
                    return "As soon as possible";
                }
            case 'uniqueness':
                $tab = self::getUniquenessArray();
                return $tab[(int)$values[$field]] ?? $tab[self::UNIQUENESS_DAILY];  
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }
}
