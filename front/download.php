<?php

/**
 * SmartReport - Secure CSV download handler
 *
 * Requires:
 * - Valid session + CSRF token
 * - READ access to the parent report
 *
 * Used by UI and email download links.
 * Increments download count only after successful transfer.
 */

include('../../../inc/includes.php');
include_once(__DIR__ . '/../inc/glpiversion.class.php');

// Session & CSRF validation 
Session::checkLoginUser();
Session::checkCSRF($_REQUEST);

// Request params
$id = (int)($_GET['id'] ?? 0);
$external = $_GET['external'] ?? '';

if ($id <= 0) {
    Html::displayErrorAndDie(__('Invalid request', 'smartreport'), true);
}

// Load generated report
$available = new PluginSmartreportGeneratedreport();
if (!$available->getFromDB($id)) {
    Html::displayErrorAndDie(__('Report not found', 'smartreport'), true);
}

// Check access on parent report
$parent = new PluginSmartreportReportdefination();
if (!$parent->getFromDB($available->fields['reports_id'])) {
    Html::displayErrorAndDie(__('Report not found', 'smartreport'), true);
}
if (!$parent->canViewItem()) {
    Html::displayErrorAndDie(__('Access denied', 'smartreport'), true);
}

// Validate the file path
$filepath = $available->fields['file_path'];
$filename = $available->fields['file_name'];

if (empty($filepath) || !file_exists($filepath) || !is_readable($filepath)) {
    Html::displayErrorAndDie(__('File not found or not readable', 'smartreport'), true);
}

// Ensure file is within allowed directory (prevent path traversal)
$real_path     = realpath($filepath);
$expected_base = realpath(GLPI_SMARTREPORT_PLUGIN_DOC_DIR);

if ($real_path === false || $expected_base === false || strpos($real_path, $expected_base) !== 0) {
    Html::displayErrorAndDie(__('Access denied', 'smartreport'), true);
}

// Stream the file
ignore_user_abort(true);   // Continue if client disconnects
ob_end_clean();            // Clear output buffer


header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . addslashes(basename($filename)) . '"');
header('Content-Length: ' . filesize($real_path));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($real_path);


// Update download count if completed
if ($external == '') {
    if (!connection_aborted()) {
        PluginSmartreportReportdefination::incrementDownloadCount(
            $id,
            (int)$available->fields['reports_id']
        );
    }
}

exit;
