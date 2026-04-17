<?php
/**
 * Smart Report — GLPI 10 form template.
 *
 * Included by GlpiVersion::renderForm() when running on GLPI 10.
 * Variables available from the calling scope:
 *
 *   $item     PluginSmartreportReportdefination   — the item being displayed
 *   $widgets  array                               — pre-rendered HTML dropdowns
 *   $meta     array                               — ['next_run_display' => string]
 *
 * Layout follows GLPI 10's native tab_cadre_fixe two-column table style,
 * consistent with core pages such as "Create New Rule" and "Create Template".
 */

/** @var PluginSmartreportReportdefination $item */
/** @var array $widgets */
/** @var array $meta */

$is_new   = $item->isNewItem();
$fields   = $item->fields;
$next_run = $meta['next_run_display'] ?? __('As soon as possible');

$can_execute = Session::haveRight(
    PluginSmartreportReportdefination::$rightname,
    PluginSmartreportReportdefination::EXECUTE
);
$is_running = (int)($fields['status'] ?? 0) === PluginSmartreportReportdefination::STATE_RUNNING;
$is_waiting = (int)($fields['status'] ?? 0) === PluginSmartreportReportdefination::STATE_WAITING;
?>
<table class="tab_cadre_fixe">

    <tr class="headerRow">
        <th colspan="2"><?= $is_new ? __('New Smart Report', 'smartreport') : htmlspecialchars($fields['name'] ?? '') ?></th>
    </tr>

    <?php /* ── Report Name ─────────────────────────────────────────────── */ ?>
    <tr class="tab_bg_1">
        <td class="left" style="width:30%">
            <?= __('Report Name') ?> <span class="required">*</span>
        </td>
        <td>
            <?php if ($is_new): ?>
                <?= Html::input('name', ['value' => $fields['name'] ?? '', 'required' => 'required', 'style' => 'width:100%']) ?>
            <?php else: ?>
                <strong><?= htmlspecialchars($fields['name'] ?? '') ?></strong>
                <input type="hidden" name="name" value="<?= htmlspecialchars($fields['name'] ?? '') ?>"/>
            <?php endif ?>
        </td>
    </tr>

    <?php /* ── Saved Search ─────────────────────────────────────────────── */ ?>
    <tr class="tab_bg_2">
        <td><?= __('Saved Search') ?> <span class="required">*</span></td>
        <td><?= $widgets['saved_search_id'] ?></td>
    </tr>

    <?php /* ── Description ──────────────────────────────────────────────── */ ?>
    <tr class="tab_bg_1">
        <td><?= __('Description') ?></td>
        <td>
            <?= Html::textarea([
                'name'    => 'desc',
                'value'   => $fields['desc'] ?? '',
                'display' => false,
                'cols'    => 60,
                'rows'    => 3,
            ]) ?>
        </td>
    </tr>

    <?php /* ── Schedule section header ───────────────────────────────────── */ ?>
    <tr>
        <th colspan="2"><?= __('Schedule') ?></th>
    </tr>

    <?php /* ── Run Frequency ─────────────────────────────────────────────── */ ?>
    <tr class="tab_bg_1">
        <td><?= __('Run frequency') ?> <span class="required">*</span></td>
        <td><?= $widgets['frequency_dropdown'] ?></td>
    </tr>

    <?php /* ── Status ────────────────────────────────────────────────────── */ ?>
    <tr class="tab_bg_2">
        <td><?= __('Status') ?></td>
        <td>
            <?php Dropdown::showFromArray('status', [
                CronTask::STATE_WAITING => __('Scheduled'),
                CronTask::STATE_DISABLE => __('Disabled'),
            ], ['value' => $fields['status'] ?? CronTask::STATE_WAITING]) ?>
        </td>
    </tr>

    <?php /* ── Storage section header ──────────────────────────────────────── */ ?>
    <tr>
        <th colspan="2"><?= __('File Storage') ?></th>
    </tr>

    <?php /* ── Retention period ─────────────────────────────────────────── */ ?>
    <tr class="tab_bg_1">
        <td><?= __('Number of days this reports are stored') ?></td>
        <td><?= $widgets['retention_period'] ?></td>
    </tr>

    <?php /* ── File Uniqueness ──────────────────────────────────────────── */ ?>
    <tr class="tab_bg_2">
        <td><?= __('File Uniqueness', 'smartreport') ?> <span class="required">*</span></td>
        <td><?= $widgets['uniqueness_dropdown'] ?></td>
    </tr>

    <?php /* ── Email section header ─────────────────────────────────────── */ ?>
    <tr>
        <th colspan="2"><?= __('Email Delivery') ?></th>
    </tr>

    <?php /* ── Email recipients ──────────────────────────────────────────── */ ?>
    <tr class="tab_bg_1">
        <td><?= __('Email this report to') ?></td>
        <td><?= $widgets['user_email'] ?></td>
    </tr>

    <?php /* ── Visibility section header ────────────────────────────────── */ ?>
    <tr>
        <th colspan="2"><?= __('Visibility') ?></th>
    </tr>

    <?php /* ── Entity ───────────────────────────────────────────────────── */ ?>
    <tr class="tab_bg_1">
        <td><?= __('Entity') ?></td>
        <td><?php Entity::dropdown(['value' => $fields['entities_id'] ?? 0, 'name' => 'entities_id']) ?></td>
    </tr>

    <?php /* ── Child entities ───────────────────────────────────────────── */ ?>
    <tr class="tab_bg_2">
        <td><?= __('Child entities') ?></td>
        <td><?php Dropdown::showYesNo('is_recursive', $fields['is_recursive'] ?? 0) ?></td>
    </tr>

    <?php if (!$is_new): ?>

        <?php /* ── Run info section header ──────────────────────────────── */ ?>
        <tr>
            <th colspan="2"><?= __('Execution') ?></th>
        </tr>

        <?php /* ── Last run ─────────────────────────────────────────────── */ ?>
        <tr class="tab_bg_1">
            <td><?= __('Last run') ?></td>
            <td>
                <?= empty($fields['lastrun'])
                    ? __('Never')
                    : Html::convDateTime($fields['lastrun']) ?>
            </td>
        </tr>

        <?php /* ── Next run + Execute / Blank buttons ────────────────────── */ ?>
        <tr class="tab_bg_2">
            <td><?= __('Next run') ?></td>
            <td>
                <?= htmlspecialchars($next_run) ?>
                <?php if ($can_execute && $is_waiting && !$is_running): ?>
                    &nbsp;<input type="submit" name="execute"
                                 value="<?= __('Execute') ?>"
                                 class="submit"/>
                <?php endif ?>
                <?php if ($can_execute && $is_running): ?>
                    &nbsp;<input type="submit" name="resetstate"
                                 value="<?= __('Blank') ?>"
                                 class="submit"/>
                <?php endif ?>
                <?php if (!$can_execute): ?>
                    &nbsp;<span class="text-muted" title="<?= __('You need the Execute permission to run this report.', 'smartreport') ?>">
                        <i class="fas fa-lock"></i>
                    </span>
                <?php endif ?>
            </td>
        </tr>

    <?php endif ?>

</table>
