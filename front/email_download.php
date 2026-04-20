<?php

/**
 * SmartReport - Email download landing page
 *
 * Prevents email client prefetch from triggering downloads.
 * Shows a page with a button that calls download.php.
 *
 * Requires valid session and report access.
 */

include('../../../inc/includes.php');
include_once(__DIR__ . '/../inc/glpiversion.class.php');

Session::checkLoginUser();

$id    = (int)($_GET['id']    ?? 0);

// Validate parameters
if ($id <= 0) {
    http_response_code(400);
    render_error_page(
        __('Invalid Link', 'smartreport'),
        __('This download link is incomplete or malformed. Please check your email and try again.', 'smartreport')
    );
    exit;
}

// Load report metadata for display
$generated = new PluginSmartreportGeneratedreport();
if (!$generated->getFromDB($id)) {
    http_response_code(404);
    render_error_page(
        __('Report Not Found', 'smartreport'),
        __('The report record could not be found. It may have been deleted.', 'smartreport')
    );
    exit;
}

$report_obj = new PluginSmartreportReportdefination();
if (!$report_obj->getFromDB($generated->fields['reports_id'])) {
    http_response_code(404);
    render_error_page(
        __('Report Not Found', 'smartreport'),
        __('The parent report could not be found.', 'smartreport')
    );
    exit;
}

if (!$report_obj->canViewItem()) {
    http_response_code(403);
    render_error_page(
        __('Access Denied', 'smartreport'),
        __('You do not have permission to download this report.', 'smartreport')
    );
    exit;
}

// Check the file exists on disk
$filepath = $generated->fields['file_path'];
$filename = $generated->fields['file_name'];
$file_ok  = !empty($filepath) && file_exists($filepath) && is_readable($filepath);

$file_size_display = '';
if ($file_ok) {
    $bytes = filesize($filepath);
    if ($bytes !== false) {
        $file_size_display = format_size($bytes);
    }
}

// Build download URL with CSRF token
$download_url = Plugin::getWebDir('smartreport')
    . '/front/download.php'
    . '?id='              . urlencode((string)$id)
    . '&_glpi_csrf_token=' . urlencode(Session::getNewCSRFToken());


// Render the landing page
render_landing_page(
    $report_obj->fields['name']        ?? '',
    $generated->fields['generated_at'] ?? '',
    $filename,
    $file_size_display,
    $file_ok,
    $download_url
);
exit;

function format_size(int $bytes): string
{
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return number_format($bytes / 1048576,    2) . ' MB';
    if ($bytes >= 1024)       return number_format($bytes / 1024,       2) . ' KB';
    return $bytes . ' B';
}

function render_error_page(string $title, string $message): void
{
    echo html_shell(
        htmlspecialchars($title),
        '<div class="card error-card">'
            . '<div class="icon">&#9888;</div>'
            . '<h1>' . htmlspecialchars($title) . '</h1>'
            . '<p>' . htmlspecialchars($message) . '</p>'
            . '</div>'
    );
}

function render_landing_page(
    string $report_name,
    string $generated_date,
    string $filename,
    string $file_size,
    bool   $file_ok,
    string $download_url
): void {
    $title = __('Smart Report Download', 'smartreport');

    if (!$file_ok) {
        echo html_shell(
            $title,
            '<div class="card error-card">'
                . '<div class="icon">&#128462;</div>'
                . '<h1>' . htmlspecialchars(__('File Not Available', 'smartreport')) . '</h1>'
                . '<p>' . htmlspecialchars(__('The CSV file could not be found on the server. It may have been removed by the retention policy.', 'smartreport')) . '</p>'
                . '</div>'
        );

        return;
    }

    $meta = '';
    if ($generated_date) {
        $meta .= '<span class="meta-item">&#128197;&nbsp;'
            . htmlspecialchars(__('Generated', 'smartreport')) . ': '
            . htmlspecialchars($generated_date) . '</span>';
    }
    if ($filename) {
        $meta .= '<span class="meta-item">&#128196;&nbsp;' . htmlspecialchars($filename) . '</span>';
    }
    if ($file_size) {
        $meta .= '<span class="meta-item">&#128190;&nbsp;' . htmlspecialchars($file_size) . '</span>';
    }

    $body = '<div class="card">'
        . '<div class="icon">&#128202;</div>'
        . '<h1>' . htmlspecialchars($report_name ?: __('Smart Report', 'smartreport')) . '</h1>'
        . '<p class="subtitle">' . htmlspecialchars(__('Your report is ready to download.', 'smartreport')) . '</p>'
        . ($meta ? '<div class="meta">' . $meta . '</div>' : '')
        . '<a href="' . htmlspecialchars($download_url, ENT_QUOTES) . '" class="download-btn" download>'
        .   '&#11015;&nbsp;' . htmlspecialchars(__('Download Report', 'smartreport'))
        . '</a>'
        . '</div>';

    echo html_shell($title, $body);
}

function html_shell(string $page_title, string $body_html): string
{
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>{$page_title}</title>
    <style>
                *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
             background:#f0f4f8;min-height:100vh;display:flex;align-items:center;
             justify-content:center;padding:24px;color:#333}
        .card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.10);
              padding:48px 40px;max-width:520px;width:100%;text-align:center}
        .error-card{border-top:4px solid #e53935}
        .icon{font-size:56px;margin-bottom:16px;line-height:1}
        h1{font-size:22px;font-weight:600;margin-bottom:12px;color:#1a1a2e}
        .subtitle{font-size:15px;color:#555;margin-bottom:24px}
        p{font-size:15px;color:#555;margin-bottom:16px;line-height:1.6}
        .meta{display:flex;flex-direction:column;gap:6px;background:#f7f9fc;
              border-radius:8px;padding:14px 18px;margin-bottom:28px;text-align:left}
        .meta-item{font-size:13px;color:#444}
        .download-btn{display:inline-block;background:#1a56a0;color:#fff;text-decoration:none;
                      padding:14px 36px;border-radius:8px;font-size:16px;font-weight:600;
                      letter-spacing:.3px;margin-bottom:20px;transition:background .15s}
        .download-btn:hover{background:#154080;color:#fff}
    </style>
</head>
<body>
    {$body_html}
</body>
</html>
HTML;
}
