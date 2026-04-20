<?php

/**
 * Secure CSV download handler for SmartReport plugin.
 *
 * Access: requires an active GLPI session + CSRF token.
 * The user must have READ rights on the parent Smart Report.
 *
 * This file is called from two places:
 *   1. The "Download" button in the Generated Reports tab (browser UI)
 *   2. The "Download Report" button on the email_download.php landing page
 *
 * Both callers pass ?id=N&_glpi_csrf_token=... in the URL.
 *
 * Download count is incremented ONLY after the file has been fully streamed
 * and the connection is still alive (not aborted by the client).
 */

include('../../../inc/includes.php');
include_once(__DIR__ . '/../inc/glpiversion.class.php');

// ── Session + CSRF — required for all access paths ───────────────────────────
Session::checkLoginUser();
Session::checkCSRF($_REQUEST);

$id = (int)($_GET['id'] ?? 0);
$external = $_GET['external'] ?? '';

if ($id <= 0) {
    Html::displayErrorAndDie(__('Invalid request', 'smartreport'), true);
}

// ── Load the generated report record ─────────────────────────────────────────
$available = new PluginSmartreportGeneratedreport();
if (!$available->getFromDB($id)) {
    Html::displayErrorAndDie(__('Report not found', 'smartreport'), true);
}

// ── Authorisation — READ right on the parent report ──────────────────────────
$parent = new PluginSmartreportReportdefination();
if (!$parent->getFromDB($available->fields['reports_id'])) {
    Html::displayErrorAndDie(__('Report not found', 'smartreport'), true);
}
if (!$parent->canViewItem()) {
    Html::displayErrorAndDie(__('Access denied', 'smartreport'), true);
}

// ── Validate the file path ────────────────────────────────────────────────────
$filepath = $available->fields['file_path'];
$filename = $available->fields['file_name'];

if (empty($filepath) || !file_exists($filepath) || !is_readable($filepath)) {
    Html::displayErrorAndDie(__('File not found or not readable', 'smartreport'), true);
}

// Prevent path-traversal — ensure the resolved path is inside the expected directory
$real_path     = realpath($filepath);
$expected_base = realpath(GLPI_SMARTREPORT_PLUGIN_DOC_DIR);

if ($real_path === false || $expected_base === false || strpos($real_path, $expected_base) !== 0) {
    Html::displayErrorAndDie(__('Access denied', 'smartreport'), true);
}

// ── Stream the file ───────────────────────────────────────────────────────────
ignore_user_abort(true);   // keep running even if browser disconnects
ob_end_clean();            // discard any buffered output before streaming


header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . addslashes(basename($filename)) . '"');
header('Content-Length: ' . filesize($real_path));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($real_path);


// ── Increment download count ONLY on successful transfer ─────────────────────
// connection_aborted() returns 1 if the client closed the connection before
// readfile() finished. Only count when the transfer completed fully.
if ($external == '') {
    if (!connection_aborted()) {
        PluginSmartreportReportdefination::incrementDownloadCount(
            $id,
            (int)$available->fields['reports_id']
        );
    }
}

exit;
