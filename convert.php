<?php
require_once __DIR__ . '/vendor/autoload.php';

use CH\CartridgeParser;
use CH\CourseHubBuilder;

set_time_limit(300);
ini_set('memory_limit', '512M');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html');
    exit;
}

// ── Upload validation ──────────────────────────────────────────────────────

$uploadErrors = [
    UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload size limit.',
    UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form size limit.',
    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded — please try again.',
    UPLOAD_ERR_NO_FILE    => 'No file was selected.',
    UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary directory.',
    UPLOAD_ERR_CANT_WRITE => 'Server failed to write the upload to disk.',
];

// When post_max_size is exceeded, PHP empties $_FILES and $_POST entirely
if (empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
    $serverMax = ini_get('post_max_size');
    showError('The server rejected the upload — the file likely exceeds the server post_max_size limit (' . $serverMax . '). Contact your host to increase it.');
}

$fileError = $_FILES['imscc']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($fileError !== UPLOAD_ERR_OK) {
    showError($uploadErrors[$fileError] ?? 'Upload error (code ' . $fileError . ').');
}

$maxBytes = 500 * 1024 * 1024;
if ($_FILES['imscc']['size'] > $maxBytes) {
    showError('File exceeds the 500 MB limit.');
}

$ext = strtolower(pathinfo($_FILES['imscc']['name'], PATHINFO_EXTENSION));
if ($ext !== 'imscc' && $ext !== 'zip') {
    showError('Please upload a .imscc or .zip file exported from Canvas or another LMS.');
}

// ── Extract to temp dir ────────────────────────────────────────────────────

$uploaded            = $_FILES['imscc']['tmp_name'];
$skipFiles           = !empty($_POST['skip_files']);
$skipImageDownload   = !empty($_POST['skip_image_download']);
$stripTitleNumbering = !empty($_POST['strip_title_numbering']);
$includeEssentials   = !empty($_POST['include_essentials']);
$includeResources    = !empty($_POST['include_resources']);
$includeSyllabus     = !empty($_POST['include_syllabus']);
$tmpDir            = sys_get_temp_dir() . '/cc_' . uniqid();
mkdir($tmpDir, 0700, true);

$zip = new ZipArchive();
if ($zip->open($uploaded) !== true) {
    rmdir($tmpDir);
    showError('The uploaded file does not appear to be a valid .imscc (ZIP) file.');
}

// Selectively extract files from web_resources/:
//   - course_image/: always extracted
//   - image files (jpg/png/gif/webp/svg): always extracted for embedded page content
//   - other files (PDFs, videos, etc.): only when "Skip attached files" is off
$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];
$toExtract = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (strpos($name, 'web_resources/') === 0) {
        $isCourseImage = strpos($name, 'web_resources/course_image/') === 0;
        $ext           = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $isImage       = in_array($ext, $imageExts, true);
        if (!$isCourseImage && !$isImage && $skipFiles) continue;
    }
    $toExtract[] = $name;
}
$zip->extractTo($tmpDir, $toExtract);
$zip->close();

// ── Parse and build ────────────────────────────────────────────────────────

try {
    $parser = new CartridgeParser($tmpDir);
} catch (\Exception $e) {
    cleanup($tmpDir);
    showError('Could not parse the course cartridge: ' . htmlspecialchars($e->getMessage()));
}

if (!$parser->isValid()) {
    cleanup($tmpDir);
    showError('No supported content found in this cartridge. The file may not be a standard Common Cartridge export, or it contains only quizzes and discussions.');
}

// Allow course slug override
$slugOverride = trim($_POST['course_slug'] ?? '');
if ($slugOverride) {
    $parser->courseSlug = preg_replace('/[^a-z0-9-]/', '-', strtolower($slugOverride));
}
$builder = new CourseHubBuilder($parser, $skipFiles, $skipImageDownload, $stripTitleNumbering, $includeEssentials, $includeResources, $includeSyllabus);
$zipPath = $builder->build();

cleanup($tmpDir);

// ── Stream ZIP to browser ──────────────────────────────────────────────────

$filename = 'course-hub-' . $parser->courseSlug . '.zip';

$downloadToken = preg_replace('/[^a-z0-9]/', '', $_POST['download_token'] ?? '');
if ($downloadToken) {
    setcookie('download_ready_' . $downloadToken, '1', time() + 60, '/', '', false, false);
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: no-cache');

readfile($zipPath);
unlink($zipPath);
exit;

// ── Helpers ────────────────────────────────────────────────────────────────

function showError(string $msg): void
{
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title>'
       . '<style>body{font-family:sans-serif;max-width:600px;margin:60px auto;padding:0 20px}'
       . 'h1{color:#dc2626}.back{margin-top:20px}</style></head><body>'
       . '<h1>Conversion Error</h1><p>' . htmlspecialchars($msg) . '</p>'
       . '<p class="back"><a href="index.html">← Try again</a></p>'
       . '</body></html>';
    exit;
}

function cleanup(string $dir): void
{
    if (!is_dir($dir)) return;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $f) {
        $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
    }
    rmdir($dir);
}
