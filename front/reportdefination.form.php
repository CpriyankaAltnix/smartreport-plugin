<?php

include('../../../inc/includes.php');
include_once(__DIR__ . '/../inc/glpiversion.class.php');

Session::checkRight('plugin_smartreport', READ);

$report = new PluginSmartreportReportdefination();

// ── Save / Update ─────────────────────────────────────────────────────────────
if (isset($_POST['add'])) {
    $report->check(-1, CREATE, $_POST);
    $new_id = $report->add($_POST);
    Html::redirect(Plugin::getWebDir('smartreport') . '/front/reportdefination.form.php?id=' . $new_id);
}

if (isset($_POST['update'])) {
    $report->check($_POST['id'], UPDATE, $_POST);
    $report->update($_POST);
    Html::back();
}

if (isset($_POST['delete'])) {
    $report->check($_POST['id'], DELETE, $_POST);
    $report->delete($_POST);
    Html::redirect(Plugin::getWebDir('smartreport') . '/front/reportdefination.php');
}

if (isset($_POST['purge'])) {
    $report->check($_POST['id'], PURGE, $_POST);
    $report->purge($_POST);
    Html::redirect(Plugin::getWebDir('smartreport') . '/front/reportdefination.php');
}

// ── Reset a stuck RUNNING state ───────────────────────────────────────────────
if (isset($_POST['resetstate'])) {
    $id = (int)($_POST['id'] ?? 0);
    $report->check($id, UPDATE, $_POST);
    $report->getFromDB($id);
    if ((int)$report->fields['status'] === PluginSmartreportReportdefination::STATE_RUNNING) {
        $report->update([
            'id'     => $id,
            'status' => PluginSmartreportReportdefination::STATE_WAITING,
        ]);
    }
    Html::back();
}

// ── Execute a single report directly ─────────────────────────────────────────
// Calls executeReportById() synchronously — no background process, no cron.
// The cron (cronRunSmartReports) handles scheduled runs; this handler is only
// for the manual "Execute" button on the report detail page.
if (isset($_POST['execute'])) {
    $id = (int)($_POST['id'] ?? 0);

    // Execute requires the dedicated Execute right, not just UPDATE
    if (!Session::haveRight(PluginSmartreportReportdefination::$rightname, PluginSmartreportReportdefination::EXECUTE)) {
        Session::addMessageAfterRedirect(
            __('You do not have permission to execute reports.', 'smartreport'),
            false,
            ERROR
        );
        Html::back();
    }
    $report->check($id, READ, $_POST);

    if (!$report->getFromDB($id)) {
        Session::addMessageAfterRedirect(__('Report not found.', 'smartreport'), false, ERROR);
        Html::back();
    }

    if ((int)$report->fields['status'] === PluginSmartreportReportdefination::STATE_RUNNING) {
        Session::addMessageAfterRedirect(
            __('This report is already running. Please wait for it to complete.', 'smartreport'),
            false,
            WARNING
        );
        Html::back();
    }

    try {
        PluginSmartreportReportdefination::executeReportById($id);
        Session::addMessageAfterRedirect(
            sprintf(__('Report "%s" generated successfully.', 'smartreport'), $report->fields['name']),
            false,
            INFO
        );
    } catch (\Throwable $e) {
        Toolbox::logInFile('smartreport', '[SmartReport] Execute button error: ' . $e->getMessage() . "\n");
        Session::addMessageAfterRedirect(
            sprintf(__('Report execution failed: %s', 'smartreport'), $e->getMessage()),
            false,
            ERROR
        );
    }

    Html::back();
}

// ── Display ───────────────────────────────────────────────────────────────────
Html::header(
    __('Smart Report', 'smartreport'),
    $_SERVER['PHP_SELF'],
    'config',
    'PluginSmartreportMenu'
);

if (!isset($_GET['id']) || $_GET['id'] <= 0) {
    Session::checkRight('plugin_smartreport', CREATE);
}

$report->display(['id' => $_GET['id'] ?? 0]);

Html::footer();
