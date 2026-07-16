<?php
namespace CH;

class CartridgeParser
{
    public string $courseTitle  = '';
    public string $courseCode   = '';
    public string $courseSlug   = '';
    public string $license      = '';
    public string $licenseUrl   = '';
    public string $syllabusHtml = '';

    // Each module: ['title' => string, 'items' => [...]]
    // Item: ['type', 'title', 'slug', 'url', 'filePath', 'group', 'identifierref', 'indent']
    public array $modules = [];

    // wiki slug => html string
    public array $wikiPages = [];

    // Absolute path to a local course image file (from web_resources/course_image/)
    public string $courseImagePath = '';

    // External course image URL (fallback when image is not a local file)
    public string $courseImageUrl = '';

    public array $warnings = [];

    public string $dir;

    // Staging area for image lookup: set in parseCourseSettings(), resolved in parseManifest()
    private string $pendingImageRef = '';
    private string $pendingImageUrl = '';

    // Maps built from imsmanifest.xml (populated by parseManifest)
    private array $refToWikiSlug       = [];  // resource-id  → wiki filename stem
    private array $refToExternalUrl    = [];  // resource-id  → URL string
    private array $refToAttachmentPath = [];  // resource-id  → relative file path
    private array $itemToResource      = [];  // manifest item-id → resource-id
    private array $assignmentHtmlPaths = [];  // slug → absolute path to assignment HTML file
    private int   $manifestUnsupportedCount = 0; // items skipped by parseModulesFromManifest()

    private static array $licenseMap = [
        'cc_by'         => ['CC BY 4.0',         'https://creativecommons.org/licenses/by/4.0/'],
        'cc_by_sa'      => ['CC BY-SA 4.0',       'https://creativecommons.org/licenses/by-sa/4.0/'],
        'cc_by_nc'      => ['CC BY-NC 4.0',       'https://creativecommons.org/licenses/by-nc/4.0/'],
        'cc_by_nc_sa'   => ['CC BY-NC-SA 4.0',    'https://creativecommons.org/licenses/by-nc-sa/4.0/'],
        'cc_by_nd'      => ['CC BY-ND 4.0',       'https://creativecommons.org/licenses/by-nd/4.0/'],
        'cc_by_nc_nd'   => ['CC BY-NC-ND 4.0',    'https://creativecommons.org/licenses/by-nc-nd/4.0/'],
        'public_domain' => ['Public Domain',       'https://creativecommons.org/publicdomain/zero/1.0/'],
    ];

    public function __construct(string $extractedDir)
    {
        $this->dir = rtrim($extractedDir, '/');
        $this->parseCourseSettings();
        $this->parseManifest();
        $this->computeCourseSlug();
        $this->parseModules();
        $this->loadWikiPages();
        $this->parseSyllabus();
    }

    public function isValid(): bool
    {
        $supported = ['WikiPage', 'ExternalUrl', 'Attachment'];
        foreach ($this->modules as $mod) {
            foreach ($mod['items'] as $item) {
                if (in_array($item['type'], $supported, true)) return true;
            }
        }
        return false;
    }

    private function parseCourseSettings(): void
    {
        $file = $this->dir . '/course_settings/course_settings.xml';
        if (file_exists($file)) {
            $xml = simplexml_load_file($file);
            if ($xml) {
                $this->courseTitle = trim((string)($xml->title ?? ''));
                $this->courseCode  = trim((string)($xml->course_code ?? ''));
                $licenseRaw        = (string)($xml->license ?? '');

                if (isset(self::$licenseMap[$licenseRaw])) {
                    [$this->license, $this->licenseUrl] = self::$licenseMap[$licenseRaw];
                }

                $this->pendingImageRef = (string)($xml->image_identifier_ref ?? '');
                $this->pendingImageUrl = (string)($xml->image_url ?? '');
            }
        } else {
            // Fallback: context.xml (used by some Canvas exports)
            $ctx = $this->dir . '/course_settings/context.xml';
            if (file_exists($ctx)) {
                $xml = simplexml_load_file($ctx);
                if ($xml) {
                    $this->courseTitle = (string)($xml->course_name ?? '');
                }
            }
        }
    }

    private function computeCourseSlug(): void
    {
        $base = preg_replace('/\s*\(.*\)\s*$/', '', $this->courseCode ?: $this->courseTitle);
        $slug = Helpers::slugify(trim($base) ?: $this->courseTitle);
        $slug = $slug ?: 'course';

        // Cap to 3 word segments for a readable URL, regardless of whether the slug came
        // from a short course code or a long title — and regardless of whether it already
        // ends in a digit (a cryptic institutional course code like
        // ZZ-TPLT-TUH24413-C509-060B-202114 already ends in a digit but is still 6 segments
        // long and needs capping just as much as a title-derived slug does).
        $words = explode('-', $slug);
        if (count($words) > 3) {
            $slug = implode('-', array_slice($words, 0, 3));
        }

        // Helios skeleton's version_pattern requires slugs to end with a digit.
        // When the (possibly just-capped) slug has no trailing number, append the
        // current year (e.g. introduction-to-teaching-online → introduction-to-teaching-2026).
        if (!preg_match('/\d$/', $slug)) {
            $slug .= '-' . date('Y');
        }
        $this->courseSlug = $slug;
    }

    private function parseManifest(): void
    {
        $manifest = $this->dir . '/imsmanifest.xml';
        if (!file_exists($manifest)) return;

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->load($manifest);
        libxml_clear_errors();
        if (!$loaded) {
            $this->warnings[] = 'Could not parse imsmanifest.xml — module structure may be incomplete.';
            return;
        }

        // Build manifest item-id → resource-id map (needed for ExternalUrl lookups)
        foreach ($dom->getElementsByTagName('item') as $item) {
            $iid  = $item->getAttribute('identifier');
            $iref = $item->getAttribute('identifierref');
            if ($iid && $iref) {
                $this->itemToResource[$iid] = $iref;
            }
        }

        // Process all resources
        foreach ($dom->getElementsByTagName('resource') as $res) {
            $rid  = $res->getAttribute('identifier');
            $type = $res->getAttribute('type');
            if (!$rid) continue;

            // Resolve href: check resource element first, then child <file> elements
            $href = $res->getAttribute('href');
            if (!$href) {
                foreach ($res->getElementsByTagName('file') as $f) {
                    $href = $f->getAttribute('href');
                    if ($href) break;
                }
            }
            if (!$href) continue;

            if (str_contains($type, 'webcontent')) {
                if (str_starts_with($href, 'wiki_content/')) {
                    $this->refToWikiSlug[$rid] = pathinfo($href, PATHINFO_FILENAME);
                } else {
                    $this->refToAttachmentPath[$rid] = $href;
                }
            } elseif ($type === 'assignment_xmlv1p0') {
                // Find the HTML file in the assignment subdirectory
                $subDir    = dirname($this->dir . '/' . $href);
                $htmlFiles = glob($subDir . '/*.html') ?: [];
                if (!empty($htmlFiles)) {
                    $slug = pathinfo($htmlFiles[0], PATHINFO_FILENAME);
                    $this->refToWikiSlug[$rid]       = $slug;
                    $this->assignmentHtmlPaths[$slug] = $htmlFiles[0];
                }
            } elseif (str_contains($type, 'imswl')) {
                // ExternalUrl: read the webLink XML file for the actual URL
                $xmlFile = $this->dir . '/' . $href;
                if (file_exists($xmlFile)) {
                    $url = $this->readWebLinkUrl($xmlFile);
                    if ($url) {
                        $this->refToExternalUrl[$rid] = $url;
                    }
                }
            }
        }

        // Fallback course title for non-Canvas cartridges: course_settings.xml/context.xml
        // don't exist outside Canvas, but the generic <lom:general><lom:title> block does.
        // Matches CC 1.1 (lom:) and CC 1.3 (lomm:) namespace prefixes via local-name().
        if ($this->courseTitle === '') {
            $xpath = new \DOMXPath($dom);
            $titleNodes = $xpath->query('//*[local-name()="general"]/*[local-name()="title"]/*[local-name()="string"]');
            if ($titleNodes->length > 0) {
                $this->courseTitle = trim($titleNodes->item(0)->textContent);
            }
        }

        // Resolve course image now that resource maps are built
        if ($this->pendingImageRef) {
            $resourceId = $this->itemToResource[$this->pendingImageRef] ?? $this->pendingImageRef;
            $relPath = $this->refToAttachmentPath[$resourceId] ?? '';
            if ($relPath) {
                $fullPath = $this->dir . '/' . $relPath;
                if (file_exists($fullPath)) {
                    $this->courseImagePath = $fullPath;
                }
            }
        } elseif ($this->pendingImageUrl) {
            $this->courseImageUrl = $this->pendingImageUrl;
        }
    }

    private function readWebLinkUrl(string $xmlFile): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->load($xmlFile);
        libxml_clear_errors();
        foreach ($dom->getElementsByTagName('url') as $urlEl) {
            $href = $urlEl->getAttribute('href');
            if ($href) return $href;
        }
        return '';
    }

    private function parseModules(): void
    {
        $this->parseModulesFromModuleMeta();

        // Canvas's module_meta.xml is a proprietary extension — most non-Canvas CC
        // producers (Moodle, generic 1EdTech-conformant exports, ...) never write it.
        // When it's missing, or present but empty/unparseable, fall back to building the
        // module tree from imsmanifest.xml's own <organizations> structure instead.
        if (empty($this->modules)) {
            $this->parseModulesFromManifest();
        }
    }

    private function parseModulesFromModuleMeta(): void
    {
        $file = $this->dir . '/course_settings/module_meta.xml';
        if (!file_exists($file)) return;

        $xml = simplexml_load_file($file);
        if (!$xml) return;

        foreach ($xml->module as $mod) {
            $title = trim((string)$mod->title);
            $items = [];
            $currentGroup       = null;
            $currentGroupIndent = -1; // indent of the SubHeader that set the current group

            foreach ($mod->items->item as $item) {
                $type   = (string)$item->content_type;
                $iTitle = trim((string)$item->title);
                $iRef   = (string)$item->identifierref;
                $indent = (int)(string)$item->indent;

                if ($type === 'ContextModuleSubHeader') {
                    $currentGroup       = $iTitle;
                    $currentGroupIndent = $indent;
                    continue;
                }

                // Clear the group when an item is at the SubHeader's indent level or shallower —
                // the series ended, subsequent items at the same level aren't part of it.
                if ($currentGroup !== null && $indent <= $currentGroupIndent) {
                    $currentGroup       = null;
                    $currentGroupIndent = -1;
                }

                $slug     = null;
                $url      = null;
                $filePath = null;

                if ($type === 'WikiPage' || $type === 'Assignment') {
                    $slug = $this->refToWikiSlug[$iRef] ?? null;
                    if ($slug !== null) $type = 'WikiPage'; // normalize when HTML is found
                } elseif ($type === 'ExternalUrl') {
                    // module_meta identifierref may be a manifest item-id, not resource-id
                    $resourceId = $this->itemToResource[$iRef] ?? $iRef;
                    $url = $this->refToExternalUrl[$resourceId] ?? null;
                } elseif ($type === 'Attachment') {
                    $relPath = $this->refToAttachmentPath[$iRef] ?? null;
                    if ($relPath) {
                        $fullPath = $this->dir . '/' . $relPath;
                        if (file_exists($fullPath)) {
                            $filePath = $fullPath;
                        }
                    }
                }

                $items[] = [
                    'type'          => $type,
                    'title'         => $iTitle,
                    'slug'          => $slug,
                    'url'           => $url,
                    'filePath'      => $filePath,
                    'group'         => ($currentGroup !== null && $indent > $currentGroupIndent) ? $currentGroup : null,
                    'identifierref' => $iRef,
                    'indent'        => $indent,
                ];
            }

            if (!empty($items)) {
                $this->modules[] = ['title' => $title, 'items' => $items];
            }
        }
    }

    // Fallback module-tree builder for cartridges with no module_meta.xml (i.e. not
    // produced by Canvas). Walks imsmanifest.xml's <organizations><item> hierarchy
    // directly — the part of the actual IMS CC spec every conformant producer writes.
    private function parseModulesFromManifest(): void
    {
        $manifest = $this->dir . '/imsmanifest.xml';
        if (!file_exists($manifest)) return;

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->load($manifest);
        libxml_clear_errors();
        if (!$loaded) return;

        $orgs = $dom->getElementsByTagName('organization');
        if ($orgs->length === 0 || !($orgs->item(0) instanceof \DOMElement)) return;

        $topItems = $this->directChildItems($orgs->item(0));

        // IMS packages conventionally wrap everything in a single, title-less "root"
        // item — unwrap it so its children become the top-level modules.
        if (count($topItems) === 1) {
            $only = $topItems[0];
            if ($this->firstChildText($only, 'title') === '' && !$only->getAttribute('identifierref')) {
                $topItems = $this->directChildItems($only);
            }
        }

        foreach ($topItems as $modItem) {
            $title = $this->firstChildText($modItem, 'title') ?: '(untitled)';
            $items = [];
            $this->collectManifestItems($modItem, 0, $items);
            if (!empty($items)) {
                $this->modules[] = ['title' => $title, 'items' => $items];
            }
        }

        if ($this->manifestUnsupportedCount > 0) {
            $this->warnings[] = $this->manifestUnsupportedCount . ' item(s) referenced unsupported '
                . 'resource types (e.g. quizzes, unrecognized content) and were skipped.';
        }
    }

    /** @return \DOMElement[] */
    private function directChildItems(\DOMElement $el): array
    {
        $out = [];
        foreach ($el->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === 'item') {
                $out[] = $child;
            }
        }
        return $out;
    }

    private function firstChildText(\DOMElement $el, string $tag): string
    {
        foreach ($el->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === $tag) {
                return trim($child->textContent);
            }
        }
        return '';
    }

    private function collectManifestItems(\DOMElement $parent, int $indent, array &$items): void
    {
        foreach ($this->directChildItems($parent) as $item) {
            $iRef   = $item->getAttribute('identifierref');
            $iTitle = $this->firstChildText($item, 'title');

            if ($iRef !== '') {
                [$type, $slug, $url, $filePath] = $this->resolveManifestResource($iRef);
                if ($type !== null) {
                    $items[] = [
                        'type'          => $type,
                        'title'         => $iTitle ?: '(untitled)',
                        'slug'          => $slug,
                        'url'           => $url,
                        'filePath'      => $filePath,
                        'group'         => null,
                        'identifierref' => $iRef,
                        'indent'        => $indent,
                    ];
                } else {
                    $this->manifestUnsupportedCount++;
                }
            }

            // Recurse into nested items (sub-folders) at the next indent level
            $this->collectManifestItems($item, $indent + 1, $items);
        }
    }

    // Resolves a resource-id against the ref maps parseManifest() already built —
    // the same maps the Canvas module_meta.xml path uses, so type handling stays in
    // one place. Returns [type, slug, url, filePath], with type null when the
    // resource's kind isn't one this converter supports (quizzes, unknown types, ...).
    private function resolveManifestResource(string $rid): array
    {
        if (isset($this->refToWikiSlug[$rid])) {
            return ['WikiPage', $this->refToWikiSlug[$rid], null, null];
        }
        if (isset($this->refToExternalUrl[$rid])) {
            return ['ExternalUrl', null, $this->refToExternalUrl[$rid], null];
        }
        if (isset($this->refToAttachmentPath[$rid])) {
            $fullPath = $this->dir . '/' . $this->refToAttachmentPath[$rid];
            return ['Attachment', null, null, file_exists($fullPath) ? $fullPath : null];
        }
        return [null, null, null, null];
    }

    private function loadWikiPages(): void
    {
        $wikiDir = $this->dir . '/wiki_content';
        if (!is_dir($wikiDir)) return;

        foreach (glob($wikiDir . '/*.html') ?: [] as $file) {
            $slug = pathinfo($file, PATHINFO_FILENAME);
            $this->wikiPages[$slug] = file_get_contents($file);
        }

        // Also load assignment HTML files (same content format as wiki pages)
        foreach ($this->assignmentHtmlPaths as $slug => $path) {
            $this->wikiPages[$slug] = file_get_contents($path);
        }
    }

    private function parseSyllabus(): void
    {
        $file = $this->dir . '/course_settings/syllabus.html';
        if (!file_exists($file)) return;
        $html = file_get_contents($file);
        // Extract body content only — the <title> in <head> fools a naive strip_tags length check
        if (preg_match('/<body[^>]*>(.*?)<\/body>/si', $html, $m)) {
            $html = trim($m[1]);
        }
        if (strlen(strip_tags($html)) >= 10) {
            $this->syllabusHtml = $html;
        }
    }
}
