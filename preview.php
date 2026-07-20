<?php
require_once __DIR__ . '/vendor/autoload.php';

use CH\CartridgeParser;
use CH\CourseHubBuilder;

set_time_limit(300);
ini_set('memory_limit', '512M');

const MAX_UPLOAD_BYTES        = 500 * 1024 * 1024;
const MIN_PREVIEW_PAGE_LENGTH = 500;
const MAX_PREVIEW_PAGES       = 5;
const MAX_PREVIEW_IMAGE_BYTES = 2 * 1024 * 1024;
const IMAGE_EXTENSIONS        = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];

header('Content-Type: application/json');

// ── Upload validation ──────────────────────────────────────────────────────

if (empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
    sendError(400, 'The server rejected the upload — the file likely exceeds the server post_max_size limit.');
}

$fileError = $_FILES['imscc']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($fileError !== UPLOAD_ERR_OK) {
    sendError(400, 'No file was uploaded.');
}

if ($_FILES['imscc']['size'] > MAX_UPLOAD_BYTES) {
    sendError(400, 'File exceeds the 500 MB limit.');
}

$uploadExt = strtolower(pathinfo($_FILES['imscc']['name'], PATHINFO_EXTENSION));
if ($uploadExt !== 'imscc' && $uploadExt !== 'zip') {
    sendError(400, 'Please upload a .imscc or .zip file exported from Canvas or another LMS.');
}

// ── Extract to a temp directory — CartridgeParser reads from disk, not a string ──

$skipFiles           = !empty($_POST['skip_files']);
$skipImageDownload   = !empty($_POST['skip_image_download']);
$stripTitleNumbering = !empty($_POST['strip_title_numbering']);
$includeEssentials   = !empty($_POST['include_essentials']);
$includeResources    = !empty($_POST['include_resources']);
$includeSyllabus     = !empty($_POST['include_syllabus']);

$tmpDir = sys_get_temp_dir() . '/cc_preview_' . uniqid();
mkdir($tmpDir, 0700, true);

$zip = new \ZipArchive();
if ($zip->open($_FILES['imscc']['tmp_name']) !== true) {
    cleanupDir($tmpDir);
    sendError(400, 'The uploaded file does not appear to be a valid .imscc (ZIP) file.');
}

$toExtract = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $entryName = $zip->getNameIndex($i);
    if (shouldExtractZipEntry($entryName, $skipFiles)) {
        $toExtract[] = $entryName;
    }
}
$zip->extractTo($tmpDir, $toExtract);
$zip->close();

// ── Parse and build ────────────────────────────────────────────────────────

try {
    $parser = new CartridgeParser($tmpDir);
    if (!$parser->isValid()) {
        throw new \Exception('No supported content found in this cartridge.');
    }

    $slugOverride = trim($_POST['course_slug'] ?? '');
    if ($slugOverride) {
        $parser->courseSlug = preg_replace('/[^a-z0-9-]/', '-', strtolower($slugOverride));
    }

    $builder = new CourseHubBuilder(
        $parser, $skipFiles, $skipImageDownload, $stripTitleNumbering,
        $includeEssentials, $includeResources, $includeSyllabus
    );
    $zipPath = $builder->build();
    @unlink($zipPath); // preview only needs the in-memory pages, not the zip file itself
} catch (\Throwable $e) {
    cleanupDir($tmpDir);
    sendError(422, $e->getMessage());
}

cleanupDir($tmpDir);

$imageData = $builder->getImageData();
$pages     = pickPreviewPages($builder->getFiles(), $imageData);
if (!$pages) {
    sendError(404, 'No substantial content page was found to preview.');
}

echo json_encode(['pages' => array_map(fn($page) => formatPreviewPage($page, $imageData), $pages)]);
exit;

// ── Helpers ──────────────────────────────────────────────────────────────────

function sendError(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    echo json_encode(['error' => $message]);
    exit;
}

function cleanupDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $f) {
        $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
    }
    rmdir($dir);
}

// Mirrors convert.php: always extract course images and page images, only extract
// other attachments (PDFs, videos, etc.) when "Skip attached files" is off.
function shouldExtractZipEntry(string $entryName, bool $skipFiles): bool
{
    if (strpos($entryName, 'web_resources/') !== 0) {
        return true; // not a web_resources/ entry — part of the core course structure
    }
    $isCourseImage = strpos($entryName, 'web_resources/course_image/') === 0;
    $entryExt      = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
    $isImage       = in_array($entryExt, IMAGE_EXTENSIONS, true);
    return $isCourseImage || $isImage || !$skipFiles;
}

/**
 * Samples up to MAX_PREVIEW_PAGES content pages. The Home page goes first when it has
 * real content (see homePageCandidate()), followed by one page per module from under
 * 30.modules/ — the only other folder that holds real, wiki-page-derived course content.
 * Essentials, Resources, and Syllabus are always excluded, since they're synthetic stub
 * pages, never real converted content (see CourseHubBuilder's buildEssentials(),
 * buildResources(), buildSyllabus()).
 *
 * Multi-module courses nest each module in its own subfolder
 * (30.modules/01.module-a/course-page.md, plus child pages one level deeper);
 * a single-module course is flattened so its landing page sits directly at
 * 30.modules/course-page.md instead. moduleGroupFromPath() below handles
 * both shapes: it groups by the module subfolder when one exists, and falls
 * back to a fixed group for the flattened landing page.
 */
function pickPreviewPages(array $files, array $imageData): array
{
    $selected = [];

    $home = homePageCandidate($files);
    if ($home) {
        $selected[] = $home;
    }

    $candidatesByModule = [];
    foreach ($files as $path => $content) {
        if (!str_contains($path, '/30.modules/') || !str_ends_with($path, 'course-page.md')) {
            continue;
        }

        $body = cleanPageBody($content);
        if (strlen($body) < MIN_PREVIEW_PAGE_LENGTH) {
            continue; // skip thin/stub pages
        }

        $module = moduleGroupFromPath($path);
        $candidatesByModule[$module][] = ['path' => $path, 'content' => $content, 'body' => $body];
    }

    foreach ($candidatesByModule as $candidates) {
        $selected[] = pickBestCandidate($candidates, $imageData);
    }

    return array_slice($selected, 0, MAX_PREVIEW_PAGES);
}

// The course Home page only has real content when the first module's title was detected as
// an intro (see CourseHubBuilder::buildModules()'s $introKeywords check) — in that case it's
// the site's actual landing page and worth showing first. Otherwise it's just the bare
// conversion notice with nothing else, and not worth including at all.
function homePageCandidate(array $files): ?array
{
    foreach ($files as $path => $content) {
        if (!str_contains($path, '/10.home/') || !str_ends_with($path, 'course-page.md')) {
            continue;
        }
        $body = cleanPageBody($content);
        if (strlen($body) < MIN_PREVIEW_PAGE_LENGTH) {
            return null; // no intro module detected — nothing but the conversion notice
        }
        return ['path' => $path, 'content' => $content, 'body' => $body];
    }
    return null;
}

// Prefers the first candidate in a module that has at least one embeddable image, so someone
// browsing the preview is more likely to see real course content, not just text — falls back
// to the first substantial candidate (the previous default) if none of them have one.
function pickBestCandidate(array $candidates, array $imageData): array
{
    foreach ($candidates as $candidate) {
        if (matchedImageFilenames($candidate['path'], $candidate['body'], $imageData)) {
            return $candidate;
        }
    }
    return $candidates[0];
}

function formatPreviewPage(array $page, array $imageData): array
{
    preg_match("/title:\s*'(.+?)'/", $page['content'], $titleMatch);
    $markdown = cleanPageBody($page['content']);
    return [
        'path'     => $page['path'],
        'title'    => $titleMatch[1] ?? 'Untitled',
        'markdown' => $markdown,
        'images'   => embeddedImagesForPage($page['path'], $markdown, $imageData),
    ];
}

// Local (non-URL) image filenames referenced in $markdown that have matching binary data
// already downloaded by build(), small enough to embed inline. Shared by pickBestCandidate()
// (just needs to know if any exist) and embeddedImagesForPage() (needs the actual bytes).
function matchedImageFilenames(string $pagePath, string $markdown, array $imageData): array
{
    preg_match_all('/!\[[^\]]*\]\(([^)\s]+)\)/', $markdown, $imageRefs);
    $pageFolder = dirname($pagePath);

    $matched = [];
    foreach (array_unique($imageRefs[1]) as $filename) {
        if (preg_match('#^https?://#i', $filename)) {
            continue; // already a full URL — the browser can load it directly
        }
        $zipPath = $pageFolder . '/' . $filename;
        if (!isset($imageData[$zipPath])) {
            continue; // no matching downloaded binary — left for the placeholder to handle
        }
        if (strlen($imageData[$zipPath]) > MAX_PREVIEW_IMAGE_BYTES) {
            continue; // too large to embed inline
        }
        $matched[] = $filename;
    }
    return $matched;
}

// filename => "data:image/...;base64,..." for every image matchedImageFilenames() found —
// lets the preview show real images instead of a placeholder, no extra network request needed.
function embeddedImagesForPage(string $pagePath, string $markdown, array $imageData): array
{
    $pageFolder = dirname($pagePath);
    $images = [];
    foreach (matchedImageFilenames($pagePath, $markdown, $imageData) as $filename) {
        $bytes = $imageData[$pageFolder . '/' . $filename];
        $images[$filename] = 'data:' . imageMimeType($filename) . ';base64,' . base64_encode($bytes);
    }
    return $images;
}

function imageMimeType(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png'         => 'image/png',
        'gif'         => 'image/gif',
        'webp'        => 'image/webp',
        'svg'         => 'image/svg+xml',
        'bmp'         => 'image/bmp',
        default       => 'application/octet-stream',
    };
}

// Removes the leading "--- ... ---" YAML block every page starts with, plus the
// auto-generated "this course was automatically converted..." notice CourseHubBuilder
// prepends to the Home page — real, useful context in the actual download, but redundant
// in a preview that's already labeled "Preview sketch" and explains its own limitations
// elsewhere. Used everywhere a page's real content length or display text is needed, so
// the notice's ~280 characters never inflates a substantiality check or shows up on screen.
function cleanPageBody(string $pageContent): string
{
    $body = stripFrontmatter($pageContent);
    return preg_replace_callback(
        '/^> \[!IMPORTANT\]\n(?:>[^\n]*\n)*\n*/m',
        // Match on the notice's specific wording, not just its [!IMPORTANT] type, so real
        // course content that happens to use the same callout is never hidden by mistake.
        fn($m) => str_contains($m[0], 'automatically converted from a Common Cartridge') ? '' : $m[0],
        $body
    );
}

// Removes the leading "--- ... ---" YAML block every page starts with.
function stripFrontmatter(string $pageContent): string
{
    return preg_replace('/^\s*---.*?---\s*/s', '', $pageContent);
}

// e.g. "pages/course/30.modules/01.module-a/course-page.md"          -> "01.module-a"
//      "pages/course/30.modules/01.module-a/01.child/course-page.md" -> "01.module-a" (same group as its landing page)
//      "pages/course/30.modules/course-page.md"                      -> "_flat_landing" (single-module courses, no subfolder)
function moduleGroupFromPath(string $path): string
{
    preg_match('#/30\.modules/([^/]+)/#', $path, $moduleMatch);
    return $moduleMatch[1] ?? '_flat_landing';
}
