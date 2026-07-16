<?php
namespace CH;

class CourseHubBuilder
{
    private CartridgeParser  $parser;
    private ContentConverter $converter;

    public array $warnings = [];
    public array $errors   = [];
    public int   $pageCount   = 0;
    public int   $droppedCount = 0;
    public array $droppedByType = [];

    private array  $files = [];           // zipPath => string content
    private array  $attachmentFiles = []; // zipPath => local file path
    private bool   $skipFiles;
    private bool   $skipImageDownload;
    private bool   $stripTitleNumbering;
    private bool   $includeEssentials;
    private bool   $includeResources;
    private bool   $includeSyllabus;
    private string $courseBase;           // e.g. "pages/my-course-2025"
    private array $pendingImages   = []; // ['pageFolder', 'filename', 'localPath'|null, 'url'|null]
    private array $imageData       = []; // zipPath => binary string (populated by downloadPendingImages)
    private int   $imageFailures   = 0;
    private int   $externalUrlCount = 0;
    private int   $attachmentCount  = 0;
    private array $slugToRoute      = []; // Canvas wiki-page slug => Grav route, built as pages are created;
                                           // used to resolve $WIKI_REFERENCE$ links at the end of build()

    private static array $introKeywords = ['intro', 'introduction', 'welcome', 'how to use', 'getting started', 'about'];

    public function __construct(
        CartridgeParser $parser,
        bool $skipFiles = true,
        bool $skipImageDownload = false,
        bool $stripTitleNumbering = false,
        bool $includeEssentials = true,
        bool $includeResources = true,
        bool $includeSyllabus = true
    ) {
        $this->parser              = $parser;
        $this->converter           = new ContentConverter($parser->dir, $skipImageDownload, $skipFiles);
        $this->skipFiles           = $skipFiles;
        $this->skipImageDownload   = $skipImageDownload;
        $this->stripTitleNumbering = $stripTitleNumbering;
        $this->includeEssentials   = $includeEssentials;
        $this->includeResources    = $includeResources;
        $this->includeSyllabus     = $includeSyllabus;
        $this->courseBase          = 'pages/' . $parser->courseSlug;
    }

    // Applies the opt-in "clean up numbered titles" setting. See
    // Helpers::stripLeadingNumbering() for what gets stripped and why.
    private function cleanTitle(string $title): string
    {
        return $this->stripTitleNumbering ? Helpers::stripLeadingNumbering($title) : $title;
    }

    public function build(): string
    {
        $this->warnings = array_merge($this->warnings, $this->parser->warnings);

        // Pre-flight: count remote image URLs across all wiki pages.
        // If there are too many for a web request, auto-switch to remote URL mode
        // so the converter never attempts downloads (avoids PHP timeout hangs).
        if (!$this->skipImageDownload) {
            $remoteCount = 0;
            foreach ($this->parser->wikiPages as $html) {
                preg_match_all('/<img[^>]+src=["\']https?:\/\//i', $html, $m);
                $remoteCount += count($m[0]);
            }
            if ($remoteCount > 100) {
                $this->skipImageDownload = true;
                $this->converter         = new ContentConverter($this->parser->dir, true, $this->skipFiles);
                $this->warnings[]        = "Course has $remoteCount remote images — too many to download reliably in a web request. Images will use remote URLs instead. Enable \"Skip image download\" to suppress this message.";
            }
        }

        $this->buildCourseMd();
        $this->buildModules();
        if ($this->includeEssentials) $this->buildEssentials();
        if ($this->includeResources)  $this->buildResources();
        // Always build syllabus when source content exists; flag only suppresses the stub.
        $hasSyllabusContent = $this->parser->syllabusHtml && strlen(strip_tags($this->parser->syllabusHtml)) >= 10;
        if ($this->includeSyllabus || $hasSyllabusContent) $this->buildSyllabus();

        $this->downloadPendingImages();
        $this->resolveWikiRefLinks();
        $this->buildConversionNotes();

        $this->warnings = array_merge($this->warnings, $this->converter->warnings);

        return $this->createZip();
    }

    // ── Page builders ─────────────────────────────────────────────────────────

    private function buildCourseMd(): void
    {
        $desc = $this->parser->courseTitle ? "\ndescription: '" . Helpers::yamlEscape($this->parser->courseTitle) . "'" : '';
        $yaml = "---\npublished: true\nroutable: false$desc\n---\n";
        $this->addFile("$this->courseBase/course.md", $yaml);
    }

    private function buildModules(): void
    {
        $p       = $this->parser;
        $modules = $p->modules;

        if (empty($modules)) {
            $this->buildHomePage(null);
            return;
        }

        // Detect intro module (first module with intro-like title and no child pages needed)
        $introModule = null;
        $contentModules = $modules;

        $firstTitle = strtolower($modules[0]['title'] ?? '');
        foreach (self::$introKeywords as $kw) {
            if (strpos($firstTitle, $kw) !== false) {
                $introModule    = $modules[0];
                $contentModules = array_slice($modules, 1);
                break;
            }
        }

        // Build the home page (always created — uses the intro module's content when
        // one was detected, otherwise just the conversion notice)
        $this->buildHomePage($introModule);

        // Filter out belt-earned checkpoint modules.
        $realModules = array_values(array_filter($contentModules, fn($m) =>
            !$this->isBeltEarned($m)
        ));

        if (count($realModules) === 1) {
            // Single module: flatten child pages directly under 30.modules/ to avoid an
            // extra navigation layer (Modules → Chapter → pages becomes Modules → pages)
            $this->buildSingleModuleFlat($realModules[0]);
        } else {
            $this->buildModuleListing();
            $modN = 1;
            foreach ($realModules as $mod) {
                $this->buildModuleChapter($mod, $modN);
                $modN++;
            }
        }
    }

    private function buildHomePage(?array $mod): void
    {
        $base  = "$this->courseBase/10.home";
        $body  = '';
        $links = [];

        if ($mod) {
            foreach ($mod['items'] as $item) {
                if ($item['type'] === 'WikiPage') {
                    $html = $this->getWikiHtml($item);
                    if ($html) $body .= $this->convertAndCollectImages($html, $base) . "\n\n";
                    $this->registerRoute($item['slug'], "$base/course-page.md");
                    $this->pageCount++;
                } elseif ($item['type'] === 'ExternalUrl') {
                    $url = $item['url'] ?? '';
                    if ($url) $links[] = $this->externalUrlBody($url, $this->cleanTitle($item['title']));
                    $this->externalUrlCount++;
                } elseif ($item['type'] === 'Attachment') {
                    $filePath = $item['filePath'] ?? '';
                    $cleanedItemTitle = $this->cleanTitle($item['title']);
                    if ($filePath && !$this->skipFiles) {
                        $filename = basename($filePath);
                        $zipPath  = "$this->courseBase/files/$filename";
                        $this->attachmentFiles[$zipPath] = $filePath;
                        $links[] = "[$cleanedItemTitle](../files/$filename)";
                    } else {
                        $links[] = "**$cleanedItemTitle** — attached file not included (see conversion-notes.txt)";
                    }
                    $this->attachmentCount++;
                }
            }
        }

        if ($links) {
            $body .= "\n" . implode("\n\n", $links) . "\n";
        }

        $title = $mod ? Helpers::yamlEscape($this->cleanTitle($mod['title'])) : 'Home';
        $fm    = $this->pageFrontmatter($title, [], true);
        $this->addFile("$base/course-page.md", $fm . $this->conversionNotice() . trim($body) . "\n");
        if ($mod) $this->trackDropped($mod);
    }

    // A brief, unmissable notice on the course home page — so anyone who later browses
    // the converted site (not just whoever ran the conversion) has context for why
    // formatting or links might look rough. Worded for two audiences at once: a demo
    // visitor reads it as a neutral fact ("auto-converted, not yet polished"), while the
    // course author gets an explicit next step. Unlike a curated book import, LMS course
    // exports vary too widely in authoring quality for a clean automatic conversion.
    private function conversionNotice(): string
    {
        return "> [!IMPORTANT]\n"
             . "> This course was automatically converted from a Common Cartridge (LMS) export "
             . "and has not yet been adjusted for optimal results. If you're the course author, "
             . "see `conversion-notes.txt` in this download for what was excluded or flagged, then "
             . "remove this notice once you're satisfied with the content.\n\n";
    }

    private function buildModuleListing(): void
    {
        $base = "$this->courseBase/30.modules";
        $fm   = "---\ntitle: Modules\npublished: true\ndescription: 'Below are the modules available for this course.'\ntaxonomy:\n    category: docs\nnavigation:\n    toc_position: hidden\n---\n";
        $this->addFile("$base/module.md", $fm);
    }

    private function buildSingleModuleFlat(array $mod): void
    {
        $base = "$this->courseBase/30.modules";

        // Use the module's landing WikiPage content in course-page.md itself
        $items       = $mod['items'];
        $landingItem = null;
        $childItems  = [];

        [$landingItem, $childItems] = $this->splitItems($mod);

        $landingHtml = $landingItem ? $this->getWikiHtml($landingItem) : '';
        $landingBody = $landingHtml ? $this->convertAndCollectImages($landingHtml, $base) : '';
        $landingBody .= $this->ltiWarning($items);
        if ($landingItem) $this->registerRoute($landingItem['slug'], "$base/course-page.md");

        $title = Helpers::yamlEscape($this->cleanTitle($mod['title']));
        $fm    = $this->pageFrontmatter($title, [], true);
        $this->addFile("$base/course-page.md", $fm . trim($landingBody) . "\n");
        $this->pageCount++;

        $childN = 1;
        foreach ($childItems as $item) {
            $this->buildChildPage($base, $item, $childN, $mod['title']);
            $childN++;
        }

        $this->trackDropped($mod);
    }

    private function buildModuleChapter(array $mod, int $n): void
    {
        $modSlug  = Helpers::slugify($this->cleanTitle($mod['title']));
        $modFolder = Helpers::numberedFolder($n, $modSlug);
        $base      = "$this->courseBase/30.modules/$modFolder";

        $items = $mod['items'];
        [$landingItem, $childItems] = $this->splitItems($mod);

        // Chapter landing page
        $landingHtml = $landingItem ? $this->getWikiHtml($landingItem) : '';
        $landingBody = $landingHtml ? $this->convertAndCollectImages($landingHtml, $base) : '';
        $landingBody .= $this->ltiWarning($items);
        if ($landingItem) $this->registerRoute($landingItem['slug'], "$base/course-page.md");

        // If the landing is blank and there is exactly one child, promote its content
        // to the landing so the module doesn't show an empty page with a single card below it
        if (trim($landingBody) === '' && count($childItems) === 1) {
            $only = $childItems[0];
            if ($only['type'] === 'WikiPage') {
                $childHtml   = $this->getWikiHtml($only);
                $landingBody = $childHtml ? $this->convertAndCollectImages($childHtml, $base) : '';
                $this->registerRoute($only['slug'], "$base/course-page.md");
                $childItems  = [];
            } elseif ($only['type'] === 'ExternalUrl') {
                $url = $only['url'] ?? '';
                $landingBody = $url ? "[{$this->cleanTitle($only['title'])}]($url)\n" : '';
                $childItems  = [];
                $this->externalUrlCount++;
            }
        }

        if (trim($landingBody) === '') {
            if (count($childItems) >= 1) {
                // Has convertible child pages but no landing content — suggest an intro.
                $landingBody = "> [!TIP]\n> Add an introduction or overview for this module here.\n";
            } else {
                // All items were Canvas-specific (discussions, quizzes, etc.) — nothing to convert.
                $landingBody = "> [!NOTE]\n> This module contains Canvas discussions, quizzes, or other activities that could not be exported. Access them in the original course.\n";
            }
        }

        $title    = Helpers::yamlEscape($this->cleanTitle($mod['title']));
        $desc     = $landingBody ? Helpers::yamlEscape(Helpers::shortDescription($this->converter->toPlainText($landingHtml ?: ''))) : '';
        $extras   = $desc ? ["description: '$desc'"] : [];
        $fm       = $this->pageFrontmatter($title, $extras);
        $this->addFile("$base/course-page.md", $fm . trim($landingBody) . "\n");
        $this->pageCount++;

        // Child pages
        $childN = 1;
        foreach ($childItems as $item) {
            $this->buildChildPage($base, $item, $childN, $mod['title']);
            $childN++;
        }

        $this->trackDropped($mod);
    }

    private function buildChildPage(string $moduleBase, array $item, int $n, string $modTitle): void
    {
        $cleanedTitle = $this->cleanTitle($item['title']);

        // Strip file extension from attachment titles (e.g. "Report.pdf" → "Report") for clean slugs
        $titleForSlug = $item['type'] === 'Attachment'
            ? preg_replace('/\.[a-zA-Z0-9]{2,5}$/', '', $cleanedTitle)
            : $cleanedTitle;
        $slug      = Helpers::slugify($titleForSlug);
        $folder    = Helpers::numberedFolder($n, $slug);
        $path      = "$moduleBase/$folder/course-page.md";
        $title   = Helpers::yamlEscape($cleanedTitle);
        $extras  = $item['group'] ? ["group: '" . Helpers::yamlEscape($item['group']) . "'"] : [];
        $fm      = $this->pageFrontmatter($title, $extras);

        if ($item['type'] === 'WikiPage') {
            $this->registerRoute($item['slug'], $path);
            $html  = $this->getWikiHtml($item);
            $pageFolder = dirname($path); // e.g. pages/30.modules/01.mod/01.child
            $body  = $html ? $this->convertAndCollectImages($html, $pageFolder) : '';
        } elseif ($item['type'] === 'ExternalUrl') {
            $url  = $item['url'] ?? '';
            $body = $url ? $this->externalUrlBody($url, $cleanedTitle) : '';
            $this->externalUrlCount++;
        } elseif ($item['type'] === 'Attachment') {
            $filePath = $item['filePath'] ?? '';
            if ($filePath && !$this->skipFiles) {
                $filename = basename($filePath);
                $zipPath  = "$this->courseBase/files/$filename";
                $this->attachmentFiles[$zipPath] = $filePath;
                // Child page is at {courseBase}/30.modules/{mod}/{child}/course-page.md
                // files/ is 3 levels up from child dir
                $body = "[$cleanedTitle](../../../files/$filename)\n";
            } else {
                $body = "> **$cleanedTitle** — attached file not included (see conversion-notes.txt)\n";
            }
            $this->attachmentCount++;
        } else {
            $body = '';
        }

        $this->addFile($path, $fm . trim($body) . "\n");
        $this->pageCount++;
    }

    private function buildEssentials(): void
    {
        $p    = $this->parser;
        $base = "$this->courseBase/20.essentials";
        $fm   = $this->pageFrontmatter('Essentials', [], true);

        $body = '';

        if ($p->courseTitle) {
            $body .= "## " . $p->courseTitle . "\n\n";
        }
        if ($p->courseCode) {
            $body .= "**Course Code:** " . $p->courseCode . "\n\n";
        }
        if ($p->license) {
            $licenseText = $p->licenseUrl
                ? "[{$p->license}]({$p->licenseUrl})"
                : $p->license;
            $body .= "**License:** " . $licenseText . "\n\n";
        }

        $body .= "> [!TIP]\n> Add a course description, instructor name(s), and contact information here.\n";

        $this->addFile("$base/course-page.md", $fm . $body);
    }

    private function buildSyllabus(): void
    {
        $html = $this->parser->syllabusHtml;
        $base = "$this->courseBase/50.syllabus";
        $fm   = $this->pageFrontmatter('Syllabus', [], true);

        if ($html && strlen(strip_tags($html)) >= 10) {
            $body = $this->convertAndCollectImages($html, $base);
            $this->addFile("$base/course-page.md", $fm . trim($body) . "\n");
        } else {
            $this->addFile("$base/course-page.md", $fm . "> [!TIP]\n> Add syllabus content here.\n");
        }
    }

    private function buildResources(): void
    {
        $base = "$this->courseBase/40.resources";
        $fm   = $this->pageFrontmatter('Resources', [], true);
        $this->addFile("$base/course-page.md", $fm . "> [!TIP]\n> Add course resources, links, and reference materials here.\n");
    }

    private function buildConversionNotes(): void
    {
        $p    = $this->parser;
        $lines = [];

        $lines[] = 'Course Cartridge Conversion Notes';
        $lines[] = str_repeat('=', 34);
        $lines[] = '';
        $lines[] = 'COURSE METADATA';
        $lines[] = '---------------';
        $lines[] = 'Title:   ' . ($p->courseTitle ?: '(none)');
        $lines[] = 'Code:    ' . ($p->courseCode  ?: '(none)');
        $lines[] = 'License: ' . ($p->license     ?: '(not specified)');
        if ($p->licenseUrl) $lines[] = 'License URL: ' . $p->licenseUrl;
        if ($p->courseImagePath) {
            $lines[] = 'Course image: ' . $this->courseBase . '/course_image/' . basename($p->courseImagePath);
        } elseif ($p->courseImageUrl) {
            $lines[] = 'Course image: ' . $p->courseImageUrl . ' (external URL — not included in ZIP)';
        }
        $lines[] = '';

        $lines[] = 'STRUCTURE';
        $lines[] = '---------';
        $lines[] = 'Modules:    ' . count($p->modules);
        $lines[] = 'Wiki pages: ' . count($p->wikiPages);
        $lines[] = 'Pages created: ' . $this->pageCount;
        $imageCount = count($this->pendingImages);
        if ($imageCount > 0) {
            if ($this->skipImageDownload) {
                $localCount = count(array_filter($this->pendingImages, fn($i) => $i['localPath'] !== null));
                $lines[] = 'Images: ' . $localCount . ' local (included in ZIP); external images use remote URLs';
            } else {
                $failNote = $this->imageFailures > 0 ? '; ' . $this->imageFailures . ' failed (see warnings)' : '';
                $lines[] = 'Images: ' . count($this->imageData) . ' downloaded and included in ZIP' . $failNote;
            }
        }
        $lines[] = '';

        $lines[] = 'DROPPED CONTENT';
        $lines[] = '---------------';
        if (empty($this->droppedByType)) {
            $lines[] = 'None.';
        } else {
            foreach ($this->droppedByType as $type => $count) {
                $lines[] = sprintf('  %-30s %d', $type, $count);
            }
            $lines[] = '';
            $lines[] = 'Total dropped items: ' . $this->droppedCount;
        }
        $lines[] = '';

        if (!empty($this->warnings)) {
            $lines[] = 'WARNINGS';
            $lines[] = '--------';
            foreach ($this->warnings as $w) {
                $lines[] = '  [warn] ' . $w;
            }
            $lines[] = '';
        }

        if ($this->externalUrlCount > 0) {
            $lines[] = 'External URLs:  ' . $this->externalUrlCount . ' (converted to Markdown links)';
        }
        if ($this->attachmentCount > 0) {
            $included = !$this->skipFiles ? 'included in ZIP under files/' : 'not included (Skip attached files was on)';
            $lines[] = 'Attachments:    ' . $this->attachmentCount . ' (' . $included . ')';
        }
        $lines[] = '';

        $lines[] = 'KNOWN LIMITATIONS';
        $lines[] = '-----------------';
        $lines[] = '- Quizzes and discussions are not supported and have been dropped.';
        $lines[] = '- Internal Canvas page links are rewritten to the converted page when the target is included in this course; otherwise the link points to "#" as a placeholder.';
        $lines[] = '- LTI tool links appear as [iframe] shortcodes; authentication context is not preserved.';
        if ($this->skipFiles) {
            $lines[] = '- Attached files (PDFs etc.) were skipped — copy them manually from your .imscc export.';
        }
        $lines[] = '';

        $this->addFile('conversion-notes.txt', implode("\n", $lines) . "\n");
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function convertAndCollectImages(string $html, string $pageFolder): string
    {
        $md = $this->converter->convert($html);
        foreach ($this->converter->pendingImages as $img) {
            $this->pendingImages[] = ['pageFolder' => $pageFolder] + $img;
        }
        foreach ($this->converter->pendingFiles as $file) {
            $this->attachmentFiles[$pageFolder . '/' . $file['filename']] = $file['localPath'];
        }
        return $md;
    }

    // Record which Grav route a Canvas wiki-page slug ended up at, so inline
    // $WIKI_REFERENCE$/pages/{slug} links can be resolved once every page exists.
    private function registerRoute(?string $slug, string $zipPath): void
    {
        if (!$slug) return;
        $this->slugToRoute[$slug] = $this->zipPathToRoute($zipPath);
    }

    private function zipPathToRoute(string $zipPath): string
    {
        $path = preg_replace('#^pages/[^/]+/#', '', $zipPath);
        $path = preg_replace('#/(?:doc|chapter|course)\.md$#', '', $path);
        $segments = array_map(fn($s) => preg_replace('/^\d+\./', '', $s), explode('/', $path));
        return '/' . implode('/', $segments);
    }

    // Substitute %%WIKIREF:slug%% placeholders (emitted by ContentConverter for
    // $WIKI_REFERENCE$ links) with the target page's route, now that every page's
    // route is known. This replaces only the placeholder token itself (not the
    // surrounding [text](...) markup), since link text can contain nested Markdown
    // (e.g. an image thumbnail used as the link) that isn't safe to bracket-match with
    // a simple regex. Unresolved slugs (page not included in this course) strip the
    // surrounding [text](%%) link syntax to leave plain text — safer than a dead "#" link.
    private function resolveWikiRefLinks(): void
    {
        foreach ($this->files as $path => $content) {
            if (!str_ends_with($path, '.md') || strpos($content, '%%WIKIREF:') === false) continue;
            // First pass: resolve known slugs to their route
            $resolved = preg_replace_callback(
                '/%%WIKIREF:([^%]+)%%/',
                fn($m) => $this->slugToRoute[$m[1]] ?? '%%DEAD%%',
                $content
            );
            // Second pass: strip dead-link markdown — [text](%%DEAD%%) → text.
            // The character class [^\]\n]+ stops at ']', so image-as-link syntax
            // [![alt](img)](%%DEAD%%) won't match. A final str_replace catches any
            // remaining sentinel and converts it to a safe '#' rather than leaving
            // the literal string in the output.
            $resolved = preg_replace('/\[([^\]\n]+)\]\(%%DEAD%%\)/', '$1', $resolved);
            $resolved = str_replace('%%DEAD%%', '#', $resolved);
            $this->files[$path] = $resolved;
        }
    }

    private function downloadPendingImages(): void
    {
        $deadline = microtime(true) + 90; // 90s total budget for remote image downloads

        foreach ($this->pendingImages as $img) {
            $zipPath = $img['pageFolder'] . '/' . $img['filename'];

            if ($img['localPath'] !== null) {
                // Local files: always include, no timeout needed
                $data = @file_get_contents($img['localPath']);
                if ($data !== false) $this->imageData[$zipPath] = $data;
            } elseif ($img['url'] !== null) {
                if (microtime(true) >= $deadline) {
                    $this->imageFailures++;
                    continue; // count remaining as failures silently
                }
                $data = $this->downloadImage($img['url']);
                if ($data !== null) {
                    $this->imageData[$zipPath] = $data;
                } else {
                    $this->imageFailures++;
                    $this->warnings[] = 'Image download failed: ' . $img['url'];
                }
            }
        }

        if ($this->imageFailures > 0 && microtime(true) >= $deadline) {
            $this->warnings[] = 'Image download time limit reached — some remote images were skipped. Use "Skip image download" to keep remote URLs instead.';
        }
    }

    private function downloadImage(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,   // 3s to establish connection
                CURLOPT_TIMEOUT        => 5,   // 5s total transfer
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CourseHubConverter/1.0)',
            ]);
            $data     = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($data && strlen($data) > 0 && $httpCode === 200) ? $data : null;
        }

        // Fallback: file_get_contents (less reliable timeout enforcement)
        $ctx  = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $data = @file_get_contents($url, false, $ctx);
        return ($data !== false && strlen($data) > 0) ? $data : null;
    }

    private function getWikiHtml(array $item): string
    {
        $slug = $item['slug'] ?? null;
        if (!$slug) return '';
        return $this->parser->wikiPages[$slug] ?? '';
    }

    private function isBeltEarned(array $mod): bool
    {
        $title = strtolower($mod['title']);
        // Single-item modules that are just "X Earned" or "X Badge" checkpoints
        $supported = ['WikiPage', 'ExternalUrl', 'Attachment'];
        $contentItems = array_filter($mod['items'], fn($i) => in_array($i['type'], $supported, true));
        return count($contentItems) <= 1
            && (strpos($title, 'earned') !== false || strpos($title, 'badge') !== false);
    }

    private function ltiWarning(array $items): string
    {
        $ltiItems = array_filter($items, fn($i) => $i['type'] === 'ContextExternalTool');
        if (empty($ltiItems)) return '';
        $titles = implode(', ', array_map(fn($i) => '"' . $i['title'] . '"', $ltiItems));
        return "\n\n> [!WARNING]\n> The following LTI tool item(s) could not be converted and must be accessed in the original course: " . $titles . "\n";
    }

    private function trackDropped(array $mod): void
    {
        $droppable = ['Quizzes::Quiz', 'DiscussionTopic', 'Assignment', 'ContextExternalTool'];
        foreach ($mod['items'] as $item) {
            if (in_array($item['type'], $droppable, true)) {
                $this->droppedByType[$item['type']] = ($this->droppedByType[$item['type']] ?? 0) + 1;
                $this->droppedCount++;
            }
        }
    }

    // Render an external URL as an embed or link for known video hosts, or a plain link
    private function externalUrlBody(string $url, string $title): string
    {
        if (preg_match('/youtube\.com\/watch\?.*v=([a-zA-Z0-9_-]+)/', $url, $m)
            || preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $url, $m)) {
            return '[plugin:youtube](https://www.youtube.com/watch?v=' . $m[1] . ")\n";
        }
        if (str_contains($url, 'vimeo.com')) {
            $label = $title ?: 'Watch video on Vimeo';
            return '> [' . $label . '](' . $url . ")\n";
        }
        return "[$title]($url)\n";
    }

    // Split a module's items into a landing WikiPage (first indent-0 WikiPage) and the rest
    private function splitItems(array $mod): array
    {
        $landingItem = null;
        $childItems  = [];
        foreach ($mod['items'] as $item) {
            if (!in_array($item['type'], ['WikiPage', 'ExternalUrl', 'Attachment'], true)) continue;
            if ($item['type'] === 'WikiPage' && $item['indent'] === 0 && $landingItem === null) {
                $landingItem = $item;
            } else {
                $childItems[] = $item;
            }
        }
        return [$landingItem, $childItems];
    }

    // Build a standard Grav page frontmatter block
    private function pageFrontmatter(string $title, array $extras = [], bool $hideToc = false): string
    {
        $lines = ["---", "title: '$title'", "published: true"];
        foreach ($extras as $line) $lines[] = $line;
        $lines[] = "taxonomy:";
        $lines[] = "    category: docs";
        if ($hideToc) {
            $lines[] = "navigation:";
            $lines[] = "    toc_position: hidden";
        }
        $lines[] = "---";
        return implode("\n", $lines) . "\n\n";
    }

    private function addFile(string $path, string $content): void
    {
        $this->files[$path] = $content;
    }

    private function createZip(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cc_zip_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);

        foreach ($this->files as $path => $content) {
            $zip->addFromString($path, $content);
        }

        // Include embedded page images (downloaded in downloadPendingImages)
        foreach ($this->imageData as $path => $data) {
            $zip->addFromString($path, $data);
        }

        // Include attachment files if skip_files is off
        foreach ($this->attachmentFiles as $zipPath => $localPath) {
            if (file_exists($localPath)) {
                $zip->addFile($localPath, $zipPath);
            }
        }

        // Include course image as a local file if available
        $imgPath = $this->parser->courseImagePath;
        if ($imgPath && file_exists($imgPath)) {
            $filename = basename($imgPath);
            $zip->addFile($imgPath, "$this->courseBase/course_image/$filename");
        }

        $zip->close();
        return $tmp;
    }
}
