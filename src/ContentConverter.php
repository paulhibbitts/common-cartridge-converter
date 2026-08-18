<?php
namespace CH;

class ContentConverter
{
    public array $warnings      = [];
    public array $pendingImages = []; // ['filename', 'localPath'|null, 'url'|null] — reset each convert()
    public array $pendingFiles  = []; // ['filename', 'localPath'] — inline $IMS-CC-FILEBASE$ link targets, reset each convert()

    private string $cartridgeDir;
    private bool   $skipImageDownload;
    private bool   $skipFiles;
    private bool   $portableMarkdown;
    private array  $imageFilenames = []; // filename => count, for dedup within one convert() call

    public function __construct(string $cartridgeDir = '', bool $skipImageDownload = false, bool $skipFiles = false, bool $portableMarkdown = false)
    {
        $this->cartridgeDir      = rtrim($cartridgeDir, '/');
        $this->skipImageDownload = $skipImageDownload;
        $this->skipFiles         = $skipFiles;
        $this->portableMarkdown  = $portableMarkdown;
    }

    // Convert a Canvas wiki HTML page to Markdown
    public function convert(string $html): string
    {
        if (empty(trim($html))) return '';

        $this->pendingImages  = [];
        $this->pendingFiles   = [];
        $this->imageFilenames = [];

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // Strip Canvas wrapper divs but keep their children
        $this->unwrapElements($dom, ['show-content', 'user_content', 'instructure_file_links_holder']);

        $xpath = new \DOMXPath($dom);

        // Remove screenreader-only spans — Canvas uses these for "Links to an external site."
        // icon text inside <a> tags; they produce spurious text in link anchors when converted.
        foreach ($xpath->query('//*[contains(@class,"screenreader-only")]') as $el) {
            $el->parentNode?->removeChild($el);
        }

        // Remove Canvas media thumbnail links — <a class="youtubed"><img class="media_comment_thumbnail">
        // These are play-overlay icons injected next to YouTube links by the Canvas RCE; they are
        // institution-specific remote images (not course assets) and duplicate the adjacent text link.
        foreach (iterator_to_array($xpath->query('//a[contains(@class,"youtubed") and not(contains(@class,"external"))]')) as $el) {
            $el->parentNode?->removeChild($el);
        }

        // Remove Canvas UI chrome: aria-hidden elements (e.g. file preview icon <a> tags)
        // carry no course content and produce broken image/link output when converted.
        foreach ($xpath->query('//*[@aria-hidden="true"]') as $el) {
            $el->parentNode?->removeChild($el);
        }

        // Remove inline styles
        $this->removeAttributes($dom, 'style');

        // Extract shortcodes for iframes before converting to markdown
        $iframeShortcodes = $this->extractIframeShortcodes($dom);

        $markdown = $this->nodeToMarkdown($dom->documentElement ?? $dom, 0);

        // Re-insert iframe shortcodes at their placeholder positions
        foreach ($iframeShortcodes as $placeholder => $shortcode) {
            $markdown = str_replace($placeholder, "\n\n" . $shortcode . "\n\n", $markdown);
        }

        $markdown = $this->applyEducationalShortcodes($markdown);

        return $this->cleanMarkdown($markdown);
    }

    // Convert just a plain-text summary (strip all tags)
    public function toPlainText(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text); // non-breaking space → regular space
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function unwrapElements(\DOMDocument $dom, array $classes): void
    {
        $xpath = new \DOMXPath($dom);
        foreach ($classes as $cls) {
            $nodes = $xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' $cls ')]");
            if (!$nodes) continue;
            foreach (iterator_to_array($nodes) as $node) {
                $parent = $node->parentNode;
                if (!$parent) continue;
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
            }
        }
    }

    private function removeAttributes(\DOMDocument $dom, string $attr): void
    {
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query("//*[@$attr]");
        if (!$nodes) return;
        foreach (iterator_to_array($nodes) as $node) {
            $node->removeAttribute($attr);
        }
    }

    private function extractIframeShortcodes(\DOMDocument $dom): array
    {
        $shortcodes = [];
        $xpath = new \DOMXPath($dom);
        $iframes = $xpath->query('//iframe');
        if (!$iframes) return [];

        foreach (iterator_to_array($iframes) as $iframe) {
            $src   = $iframe->getAttribute('src');
            $title = $iframe->getAttribute('title') ?: $iframe->getAttribute('name') ?: '';
            if (!$src) continue;

            $placeholder = '%%IFRAME_' . md5($src) . '%%';
            $textNode = $dom->createTextNode($placeholder);
            $iframe->parentNode->replaceChild($textNode, $iframe);

            // Canvas-internal media embeds can't be converted — replace with a warning
            if (str_contains($src, 'CANVAS_COURSE_REFERENCE') || str_contains($src, 'CANVAS_OBJECT_REFERENCE')) {
                $label = $title ?: 'embedded media item';
                $shortcodes[$placeholder] = '> [!WARNING]' . "\n" . '> "' . $label . '" is an embedded Canvas media item that could not be converted and must be accessed in the original course.';
                continue;
            }

            $shortcodes[$placeholder] = $this->iframeToShortcode($src, $title);
        }

        return $shortcodes;
    }

    private function iframeToShortcode(string $src, string $title): string
    {
        // Decode HTML entities for use in Markdown link text and URL — do NOT re-encode
        // via htmlspecialchars, which would produce &amp; visible as raw text in Markdown.
        $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
        $src   = html_entity_decode($src, ENT_QUOTES, 'UTF-8');

        // YouTube — [youtube]url[/youtube] shortcode, same syntax the Pressbooks converter uses.
        // In Standard Markdown mode, embed YouTube's own official <iframe> code instead
        // (no Helios plugin needed to render it).
        if (preg_match('/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/', $src, $m)
            || preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $src, $m)) {
            return $this->portableMarkdown
                ? $this->youtubeIframe($m[1], $title)
                : '[youtube]https://www.youtube.com/watch?v=' . $m[1] . '[/youtube]';
        }

        // Vimeo — link out since no supported embed shortcode
        if (str_contains($src, 'vimeo.com')) {
            $label = $title ?: 'Watch video on Vimeo';
            return '> [' . $label . '](' . $src . ')';
        }

        // Google Slides — link out since no supported embed shortcode
        if (str_contains($src, 'docs.google.com/presentation')) {
            $label = $title ?: 'View Google Slides presentation';
            return '> [' . $label . '](' . $src . ')';
        }

        // Generic iframe (H5P, LTI-lite, etc.) — link out since no supported embed shortcode
        $label = $title ?: 'Open interactive activity';
        return '> [' . $label . '](' . $src . ')';
    }

    // Standard Markdown mode: YouTube's own official embed code (Share → Embed) — no extra
    // script or wrapper needed, since a video's aspect ratio is fixed and the YouTube player
    // handles its own sizing. Public so CourseHubBuilder::externalUrlBody() can reuse it for
    // ExternalUrl module items (a YouTube link, not an embedded iframe) without duplicating
    // the markup here a second time.
    public function youtubeIframe(string $videoId, string $title): string
    {
        $src       = htmlspecialchars('https://www.youtube.com/embed/' . $videoId, ENT_QUOTES, 'UTF-8');
        $titleAttr = htmlspecialchars($title ?: 'YouTube video player', ENT_QUOTES, 'UTF-8');
        return '<iframe width="560" height="315" src="' . $src . '" title="' . $titleAttr . '" '
             . 'frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; '
             . 'gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" '
             . 'allowfullscreen></iframe>';
    }

    private function nodeToMarkdown(\DOMNode $node, int $depth): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = str_replace("\xc2\xa0", ' ', $node->textContent);
            // Escape angle-bracket sequences in text nodes — they are always literal characters
            // (decoded from &lt;ul&gt; etc. in source HTML) and must not be treated as HTML
            // by the markdown renderer. Also handles stray </P> Canvas emits near iframes.
            $text = str_replace(['<', '>'], ['&lt;', '&gt;'], $text);
            return $text;
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $tag = strtolower($node->nodeName);

        // These elements handle their own children — compute nothing eagerly here to
        // avoid double-processing side effects (e.g. handleImage called twice for
        // images inside a table when $children is computed then discarded).
        switch ($tag) {
            case 'img':
                return $this->handleImage(
                    $node->getAttribute('src'),
                    $node->getAttribute('alt') ?: ''
                );
            case 'table':
                return $this->tableToMarkdown($node);
            case 'ul':
                return "\n\n" . $this->listToMarkdown($node, false) . "\n\n";
            case 'ol':
                return "\n\n" . $this->listToMarkdown($node, true) . "\n\n";
            case 'hr':
                return "\n\n---\n\n";
            case 'br':
                return "  \n";
            case 'head':
            case 'script':
            case 'style':
            case 'meta':
            case 'link':
                return '';
        }

        // All remaining elements need their children content
        $children = $this->childrenToMarkdown($node, $depth);

        switch ($tag) {
            case 'html':
            case 'body':
            case 'div':
            case 'section':
            case 'article':
            case 'span':
                return $children;

            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $level = (int)$tag[1];
                // Strip redundant bold/italic markers Canvas often wraps inside headings
                $text = trim(str_replace(['**', '*'], '', $children));
                return "\n\n" . str_repeat('#', $level) . ' ' . $text . "\n\n";

            case 'p':
                $text = trim($children);
                return $text ? "\n\n" . $text . "\n\n" : '';

            case 'strong':
            case 'b':
                $text = trim($children);
                if (!$text) return '';
                // Collapse *A**B* patterns created by adjacent <em>/<i> siblings so the
                // outer ** wrap doesn't produce mismatched ***A**B*** delimiter stacks
                $text = preg_replace('/\*([^*\n]+)\*\*([^*\n]+)\*/u', '*$1 $2*', $text);
                // Move boundary spaces outside ** markers so adjacent <strong> siblings
                // don't collide into a **** junction that drops the space (e.g.
                // <strong>Action </strong><strong>Verb</strong> → "Action Verb" not "ActionVerb")
                $lead  = ($children !== ltrim($children, " \t")) ? ' ' : '';
                $trail = ($children !== rtrim($children, " \t")) ? ' ' : '';
                return $lead . '**' . $text . '**' . $trail;

            case 'em':
            case 'i':
                $text = trim($children);
                if (!$text) return '';
                // Preserve trailing whitespace so adjacent text nodes don't run together
                // (e.g. <em><strong>Label: </strong></em>Text → "***Label:*** Text" not "***Label:***Text")
                $trailWs = ($children !== rtrim($children, " \t")) ? ' ' : '';
                // Collapse adjacent bold siblings before wrapping — two forms:
                // **c****reate** (no space at junction) → **create**
                // **A** **B** (space between)          → **A B**
                $text = preg_replace('/\*\*([^*\n]+)\*\*\*\*([^*\n]+)\*\*/u', '**$1$2**', $text);
                $text = preg_replace('/\*\*([^*\n]+)\*\*\s+\*\*([^*\n]+)\*\*/u', '**$1 $2**', $text);
                // Already bold+italic (e.g. nested <em><em><strong>) — don't double-wrap
                if (preg_match('/^\*\*\*([^*]+)\*\*\*$/u', $text)) {
                    return $text . $trailWs;
                }
                // When the entire child content is a single bold run, emit ***...*** (bold+italic).
                // Use [^*]+ (no crossing asterisks) to avoid greedily matching mixed content
                // like "**Key** text **here**" as if it were a single span.
                if (preg_match('/^\*\*([^*]+)\*\*$/u', $text, $m)) {
                    return '***' . $m[1] . '***' . $trailWs;
                }
                return '*' . $text . '*' . $trailWs;

            case 'code':
                return '`' . $children . '`';

            case 'pre':
                return "\n\n```\n" . trim($children) . "\n```\n\n";

            case 'blockquote':
                $lines = explode("\n", trim($children));
                return "\n\n" . implode("\n", array_map(fn($l) => '> ' . $l, $lines)) . "\n\n";

            case 'a':
                $href = $node->getAttribute('href');
                // Strip "Links to an external site." suffix; trim for use in [text](url) syntax
                $displayText = trim(preg_replace('/\s*\(Links to an external site\.\)\s*$/i', '', trim($children)));
                // Preserve surrounding whitespace when falling back to plain text so adjacent
                // words don't run together (e.g. link text with trailing space before "are found")
                $rawText = preg_replace('/\s*\(Links to an external site\.\)\s*$/i', '', $children);

                // Canvas internal cross-references can't be rewritten — render as plain text.
                // Check this before falling back to $href as display text below: a button-style
                // anchor with no visible text (common around Canvas assignment/discussion links)
                // would otherwise leak the raw $CANVAS_OBJECT_REFERENCE$ placeholder as if it
                // were real content. Prefer the title attribute for a human-readable label.
                if ($href && (str_contains($href, 'CANVAS_OBJECT_REFERENCE') || str_contains($href, 'CANVAS_COURSE_REFERENCE'))) {
                    return $displayText ?: trim($node->getAttribute('title'));
                }

                $text = $displayText ?: $href;
                if (!$href) return $text;
                $resolved = $this->rewriteCanvasLink($href);
                // Return only display text for unresolvable internal links; never expose raw hrefs.
                // Preserve any trailing whitespace from the original link text so that adjacent
                // text nodes don't run together (e.g. "[word.](url)Next" → "[word.](url) Next").
                $trailWs = substr($rawText, strlen(rtrim($rawText)));
                return $resolved !== null ? '[' . $text . '](' . $resolved . ')' . $trailWs : $rawText;

            case 'li':
                return trim($children);

            default:
                return $children;
        }
    }

    private function childrenToMarkdown(\DOMNode $node, int $depth): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $this->nodeToMarkdown($child, $depth);
        }
        return $out;
    }

    private function listToMarkdown(\DOMNode $node, bool $ordered): string
    {
        $lines = [];
        $n = 1;
        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE || strtolower($child->nodeName) !== 'li') continue;
            $text = trim($this->childrenToMarkdown($child, 0));
            if (!$text) continue;
            $prefix = $ordered ? ($n . '. ') : '- ';
            // Collapse double newlines (from block children like nested lists)
            $text = preg_replace('/\n\n+/', "\n", $text);
            // Strip embedded --- lines: Parsedown reads them as setext heading underlines
            $text = preg_replace('/\n-{3,}/', '', $text);
            // Canvas pattern: <li style="list-style-type:none"><ul>...</ul></li> — an invisible
            // container <li> holding only a sub-list. Render the sub-list indented, not as
            // "- - item" (which shows a literal dash in the rendered output).
            if (preg_match('/^(?:[-*]|\d+\.)\s/', $text)) {
                $lines[] = '  ' . str_replace("\n", "\n  ", $text);
                // Don't increment $n: this container emits no list item of its own,
                // so the sequence number must not advance for ordered lists.
                continue;
            }
            // Indent continuation lines by the prefix width so Parsedown treats them as
            // part of this list item, not a new top-level block
            if (str_contains($text, "\n")) {
                $indent = str_repeat(' ', strlen($prefix));
                $text = str_replace("\n", "\n" . $indent, $text);
            }
            $lines[] = $prefix . $text;
            $n++;
        }
        return implode("\n", $lines);
    }

    private function tableToMarkdown(\DOMNode $table): string
    {
        // Canvas layout markup sometimes nests one table inside another (e.g. an outer
        // table for a banner image row, wrapping an inner table for the real content).
        // A plain ".//tr"/".//td" XPath descendant query would match the nested table's
        // rows/cells too, on top of the normal recursive walk that already renders them
        // once when it reaches the nested <table> node — double-rendering that content.
        // directRows() only collects rows that belong to this table itself.
        $rows = $this->directRows($table);

        // Canvas uses tables heavily for layout (no <th>). Render those as content blocks
        // rather than collapsing cell structure into a compressed Markdown table row.
        $hasHeader = false;
        foreach ($rows as $tr) {
            foreach ($tr->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE && strtolower($cell->nodeName) === 'th') {
                    $hasHeader = true;
                    break 2;
                }
            }
        }

        if (!$hasHeader) {
            $blocks = [];
            foreach ($rows as $tr) {
                foreach ($tr->childNodes as $cell) {
                    if ($cell->nodeType !== XML_ELEMENT_NODE) continue;
                    if (strtolower($cell->nodeName) !== 'td') continue;
                    $content = trim($this->childrenToMarkdown($cell, 0));
                    if ($content !== '') $blocks[] = $content;
                }
            }
            return $blocks ? "\n\n" . implode("\n\n", $blocks) . "\n\n" : '';
        }

        // Data table (has <th>): if cells contain block-level content (lists, paragraphs)
        // a compressed Markdown table would destroy that structure — render as content
        // sections instead, one per row, separated by horizontal rules.
        $hasBlockCells = false;
        foreach ($rows as $tr) {
            foreach ($tr->childNodes as $cell) {
                if ($cell->nodeType !== XML_ELEMENT_NODE || strtolower($cell->nodeName) !== 'td') continue;
                $xpath = new \DOMXPath($table->ownerDocument);
                if ($xpath->query('.//ul or .//ol or .//li', $cell)->length > 0
                    || $xpath->query('.//p', $cell)->length > 1) {
                    $hasBlockCells = true;
                    break 2;
                }
            }
        }

        if ($hasBlockCells) {
            $sections = [];
            foreach ($rows as $tr) {
                $parts = [];
                foreach ($tr->childNodes as $cell) {
                    if ($cell->nodeType !== XML_ELEMENT_NODE) continue;
                    $tag = strtolower($cell->nodeName);
                    if ($tag !== 'td' && $tag !== 'th') continue;
                    $content = trim($this->childrenToMarkdown($cell, 0));
                    if ($content === '') continue;
                    $parts[] = $tag === 'th' ? '**' . $content . '**' : $content;
                }
                if ($parts) $sections[] = implode("\n\n", $parts);
            }
            return $sections ? "\n\n" . implode("\n\n---\n\n", $sections) . "\n\n" : '';
        }

        // Simple data table — convert to Markdown table
        $rowCells = [];
        foreach ($rows as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell->nodeType !== XML_ELEMENT_NODE) continue;
                $tag = strtolower($cell->nodeName);
                if ($tag !== 'td' && $tag !== 'th') continue;
                $cells[] = trim(preg_replace('/\s+/', ' ', $this->childrenToMarkdown($cell, 0)));
            }
            if ($cells) $rowCells[] = $cells;
        }
        $rows = $rowCells;

        if (empty($rows)) return '';

        $cols = max(array_map('count', $rows));
        $out  = '';
        foreach ($rows as $i => $row) {
            while (count($row) < $cols) $row[] = '';
            $out .= '| ' . implode(' | ', $row) . " |\n";
            if ($i === 0) $out .= '| ' . implode(' | ', array_fill(0, $cols, '---')) . " |\n";
        }
        return "\n\n" . $out . "\n";
    }

    // Direct <tr> rows of $table only — through an optional <thead>/<tbody>/<tfoot> layer,
    // but never descending into a nested <table> that happens to live inside one of this
    // table's cells. That nested table's own rows get rendered exactly once, via the normal
    // recursive DOM walk when childrenToMarkdown() reaches its <table> node; collecting rows
    // with a plain ".//tr" XPath descendant query would double-count them on top of that.
    private function directRows(\DOMNode $table): array
    {
        $rows = [];
        foreach ($table->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;
            $tag = strtolower($child->nodeName);
            if ($tag === 'tr') {
                $rows[] = $child;
            } elseif (in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                foreach ($child->childNodes as $grandchild) {
                    if ($grandchild->nodeType === XML_ELEMENT_NODE && strtolower($grandchild->nodeName) === 'tr') {
                        $rows[] = $grandchild;
                    }
                }
            }
        }
        return $rows;
    }

    private function handleImage(string $src, string $alt): string
    {
        $src = html_entity_decode($src, ENT_QUOTES, 'UTF-8');

        // Canvas-internal file/media references can't be resolved to a real file — same
        // handling as the <a> case: drop the reference, keep whatever alt text exists.
        if (str_contains($src, 'CANVAS_OBJECT_REFERENCE') || str_contains($src, 'CANVAS_COURSE_REFERENCE')) {
            return $alt;
        }

        // Try to serve from local cartridge files (always included — no remote alternative)
        $localRelPath = $this->filebaseRelPath($src);
        if ($localRelPath !== null && $this->cartridgeDir !== '') {
            $fullPath = $this->cartridgeDir . '/' . $localRelPath;
            if (file_exists($fullPath)) {
                if (!$this->hasImageExtension(basename($fullPath))) return '';
                $filename = $this->uniqueFilename(basename($fullPath));
                $this->pendingImages[] = ['filename' => $filename, 'localPath' => $fullPath, 'url' => null];
                return '![' . $alt . '](' . $filename . ')';
            }
        }

        // Absolute URL
        if (filter_var($src, FILTER_VALIDATE_URL)) {
            if (!$this->skipImageDownload) {
                // Queued regardless of whether the URL's path looks like an image by
                // extension (e.g. a CDN or Canvas file-preview endpoint with none) — the
                // later download attempt validates the actual response content instead of
                // guessing from the filename, and drops the reference to plain alt text if
                // it turns out not to be real image data (see the builder's image download
                // step) rather than leaving a guaranteed-broken reference either way.
                $candidate = $this->filenameFromUrl($src);
                $filename  = $this->uniqueFilename($candidate);
                $this->pendingImages[] = ['filename' => $filename, 'localPath' => null, 'url' => $src];
                return '![' . $alt . '](' . $filename . ')';
            }
            // Skip download: keep the original URL so the image displays from its remote
            // source — there's no way to validate it without fetching it, and this flag
            // means the caller explicitly opted out of that.
            return '![' . $alt . '](' . $src . ')';
        }

        if (!$this->hasImageExtension(basename($src))) return '';
        return '![' . $alt . '](' . $src . ')';
    }

    private function hasImageExtension(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'tiff', 'avif'], true);
    }

    private function uniqueFilename(string $base): string
    {
        // Replace spaces and parentheses/brackets so Markdown image/link syntax doesn't
        // break – a `)` inside the filename (e.g. "photo (1).jpg") closes the "![alt](url)"
        // syntax early, at the first `)` rather than the real end of the filename.
        $base  = preg_replace('/[\s()\[\]]+/', '-', $base);
        $base  = trim($base, '-');
        $count = ($this->imageFilenames[$base] ?? 0) + 1;
        $this->imageFilenames[$base] = $count;
        if ($count === 1) return $base;
        $ext  = pathinfo($base, PATHINFO_EXTENSION);
        $stem = pathinfo($base, PATHINFO_FILENAME);
        return $stem . '-' . $count . ($ext !== '' ? '.' . $ext : '');
    }

    // Resolve a $IMS-CC-FILEBASE$ reference (from an <img src> or <a href>) to its
    // relative path under web_resources/ in the extracted cartridge. Returns null if
    // the string doesn't contain the placeholder.
    private function filebaseRelPath(string $src): ?string
    {
        if (strpos($src, '$IMS-CC-FILEBASE$') === false && strpos($src, '%24IMS-CC-FILEBASE%24') === false) {
            return null;
        }
        $s = str_replace('%24IMS-CC-FILEBASE%24', '$IMS-CC-FILEBASE$', $src);
        $s = preg_replace('|^\$IMS-CC-FILEBASE\$/?|', '', $s);
        $s = preg_replace('/\?.*$/', '', $s);  // strip ?canvas_download=1 etc.
        $s = urldecode(ltrim($s, '/'));          // decode %20 → space, etc.
        return 'web_resources/' . $s;
    }

    // Resolve an inline $IMS-CC-FILEBASE$ link to an attached file. Returns the filename
    // to link to (and queues the file for copying into the page's folder), or null if the
    // file can't be resolved or "skip attached files" is on.
    private function resolveFilebaseLink(string $href): ?string
    {
        if ($this->skipFiles || $this->cartridgeDir === '') return null;

        $relPath = $this->filebaseRelPath($href);
        if ($relPath === null) return null;

        $fullPath = $this->cartridgeDir . '/' . $relPath;
        if (!file_exists($fullPath)) return null;

        $filename = $this->uniqueFilename(basename($fullPath));
        $this->pendingFiles[] = ['filename' => $filename, 'localPath' => $fullPath];
        return $filename;
    }

    private function filenameFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $base = basename($path);
        $base = preg_replace('/[^a-zA-Z0-9._-]/', '-', $base);
        return $base ?: 'image';
    }

    private function rewriteCanvasLink(string $href): ?string
    {
        // $IMS-CC-FILEBASE$ → an attached file stored under web_resources/ in the cartridge
        if (strpos($href, '$IMS-CC-FILEBASE$') !== false || strpos($href, '%24IMS-CC-FILEBASE%24') !== false) {
            return $this->resolveFilebaseLink($href);
        }

        // $WIKI_REFERENCE$/pages/slug → a link to another page in this course. The
        // target's final Grav route isn't known yet while pages are still being built,
        // so defer resolution: emit a placeholder that CourseHubBuilder substitutes once
        // every page's route is known (see CourseHubBuilder::resolveWikiRefLinks()).
        $h = str_replace('%24WIKI_REFERENCE%24', '$WIKI_REFERENCE$', $href);
        if (preg_match('~\$WIKI_REFERENCE\$/pages/([^/?#]+)~', $h, $m)) {
            return '%%WIKIREF:' . urldecode($m[1]) . '%%';
        }

        // Already-absolute URLs and anything else pass through unchanged
        return $href;
    }

    // Standard Markdown mode: GFM alert type + label to use in place of each bracket shortcode tag.
    private static array $portableCalloutMap = [
        'objectives'    => ['TIP', 'Learning Objectives'],
        'key-takeaways' => ['IMPORTANT', 'Key Takeaways'],
        'reflection'    => ['NOTE', 'Reflection'],
    ];

    private function applyEducationalShortcodes(string $md): string
    {
        $map = [
            // heading pattern regex (case-insensitive)                                          => shortcode tag
            '/^#{1,6}\s+(?:(?:Course|Module)\s+(?:\d+\s+)?)?(?:Learning\s+)?Objectives?\s*$/i'   => 'objectives',
            '/^#{1,6}\s+(?:(?:Course|Module)\s+(?:\d+\s+)?)?(?:Learning\s+)?Outcomes?\s*$/i'     => 'objectives',
            '/^#{1,6}\s+(?:(?:Course|Module)\s+(?:\d+\s+)?)?Learning\s+Goals?\s*$/i'             => 'objectives',
            '/^#{1,6}\s+Key\s+Takeaways?\s*$/i'                                                => 'key-takeaways',
            '/^#{1,6}\s+(?:Key\s+)?(?:Points?|Lessons?\s+Learned)\s*$/i'                       => 'key-takeaways',
            '/^#{1,6}\s+Reflections?\s*$/i'                                                     => 'reflection',
        ];

        // Plain-paragraph triggers: "The module learning objectives are:" etc.
        // These lines are swallowed (like headings) and replaced by the shortcode block.
        $paraMap = [
            '/^(?:The\s+)?(?:(?:Course|Module|Week(?:ly)?|Unit)\s+(?:\d+\s+)?)?(?:Learning\s+)?Objectives?\s+(?:are|is|include)\s*:\s*$/i' => 'objectives',
            '/^(?:The\s+)?(?:(?:Course|Module|Week(?:ly)?|Unit)\s+(?:\d+\s+)?)?Learning\s+(?:Objectives?|Goals?|Outcomes?)\s*:\s*$/i'   => 'objectives',
            '/^Key\s+Takeaways?\s*:\s*$/i'                                                                                     => 'key-takeaways',
        ];

        $lines = explode("\n", $md);
        $out   = [];
        $total = count($lines);
        $i     = 0;

        while ($i < $total) {
            $line = $lines[$i];
            $matched = null;
            foreach ($map as $pattern => $tag) {
                if (preg_match($pattern, $line)) { $matched = $tag; break; }
            }
            if ($matched === null) {
                // Strip markdown bold/italic markers so "**The module objectives are:**" matches
                $plain = preg_replace('/\*+/', '', trim($line));
                foreach ($paraMap as $pattern => $tag) {
                    if (preg_match($pattern, $plain)) { $matched = $tag; break; }
                }
            }

            if ($matched !== null) {
                // Skip blank lines after heading
                $j = $i + 1;
                while ($j < $total && trim($lines[$j]) === '') $j++;

                // Allow up to one optional intro sentence before the list
                // (e.g. "By the end of this module, you will be able to:")
                $introLines = [];
                if ($j < $total && trim($lines[$j]) !== ''
                    && !preg_match('/^\s*(?:[-*]|\d+\.)\s/', $lines[$j])
                    && !preg_match('/^#{1,6}\s/', $lines[$j])
                ) {
                    $introLines[] = $lines[$j];
                    $j++;
                    while ($j < $total && trim($lines[$j]) === '') $j++;
                }

                // Collect consecutive list lines
                $listLines = [];
                while ($j < $total && preg_match('/^\s*(?:[-*]|\d+\.)\s/', $lines[$j])) {
                    $listLines[] = $lines[$j];
                    $j++;
                }

                if (!empty($listLines)) {
                    if ($this->portableMarkdown) {
                        [$alertType, $label] = self::$portableCalloutMap[$matched] ?? ['NOTE', ucfirst(str_replace('-', ' ', $matched))];
                        $out[] = '> [!' . $alertType . ']';
                        $out[] = '> **' . $label . '**';
                        foreach ($introLines as $il) { $out[] = $il === '' ? '>' : '> ' . $il; $out[] = '>'; }
                        foreach ($listLines as $ll) $out[] = $ll === '' ? '>' : '> ' . $ll;
                    } else {
                        $out[] = '[' . $matched . ']';
                        foreach ($introLines as $il) { $out[] = $il; $out[] = ''; }
                        foreach ($listLines as $ll) $out[] = $ll;
                        $out[] = '[/' . $matched . ']';
                    }
                    $i = $j;
                    continue;
                }
            }

            $out[] = $line;
            $i++;
        }

        return implode("\n", $out);
    }

    private function cleanMarkdown(string $md): string
    {
        $md = $this->normalizeBlankLines($md);
        // Insert a space before bold/italic markers that are directly adjacent to preceding text
        // (e.g. "building**at least" → "building **at least")
        $md = preg_replace('/(?<=\w)(\*{1,2})(?=\w)/', ' $1', $md);
        // Merge adjacent *em1.**em2.* pairs: Parsedown treats the ** junction as a bold
        // opener rather than em-close + em-open, leaving the first * as a literal asterisk.
        // (?<!\*) avoids firing on **bold** text; (?!\*) avoids firing on ***bold+italic***.
        $md = preg_replace('/(?<!\*)\*([^*\n]+)\*\*([^*\n]+)\*(?!\*)/u', '*$1 $2*', $md);
        // Collapse nested bold: <strong><strong> produces ****text**** — reduce to **text**
        $md = str_replace('****', '**', $md);
        // Canvas uses • (U+2022) in <p> tags as a substitute for list items — convert to Markdown
        $md = preg_replace('/^•\s*/mu', '- ', $md);
        // Canvas tab-navigation lists: a sequence of links pointing to #tab-N or #tabN anchors
        // (with or without hyphen). JavaScript-driven; strip the nav.
        $md = preg_replace('/(?:- \[[^\]]*\]\(#tab-?\d+\)\n)+/', '', $md);
        // Strip blank headings left by Canvas tab-pane structure
        $md = preg_replace('/^#{1,6}\s*$/mu', '', $md);
        $md = $this->breakImageFromFollowingText($md);
        // Runs last: cleanup steps above can delete lines and leave a fresh gap behind, so
        // this has to catch those too, not just whatever gaps existed at the very start.
        $md = preg_replace('/\n{3,}/', "\n\n", $md);
        return trim($md);
    }

    // Normalizes stray \r\n / bare \r line endings and blanks out whitespace-only "spacer"
    // lines (e.g. from an empty "<p>&nbsp;</p>") so cleanMarkdown()'s blank-line collapse
    // can actually recognize and collapse them.
    private function normalizeBlankLines(string $md): string
    {
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        return preg_replace('/^[ \t]+$/m', '', $md);
    }

    // Puts an image on its own line when the source glued it directly to following text
    // with no separator at all (e.g. a floated hero image sharing a <p> with its caption),
    // and promotes a single newline after an image to a full blank-line paragraph break
    // (a bare <img> immediately followed by a sibling <p> with no blank line between them —
    // otherwise Markdown treats the following text as a soft-wrapped continuation of the
    // same paragraph rather than its own block). An image followed by a space on the same
    // line, like an inline icon before a short label, is left alone either way.
    //
    // One exception, protected before the general rule runs: an image that is *entirely*
    // wrapped in its own bold/italic span with nothing else inside, like "**![img]**" (e.g. a
    // table header cell rendered that way) — splitting that would insert a break between the
    // image and its own closing marker, producing invalid Markdown (a bold span can't validly
    // cross a blank line). An image merely followed by a *different*, separate emphasis span
    // glued on with no space — e.g. an <img> immediately followed by a sibling <em>caption</em>
    // — still gets split as before, since that's real glued-on content, not a wrapper.
    private function breakImageFromFollowingText(string $md): string
    {
        $placeholders = [];
        $md = preg_replace_callback(
            '/(\*{1,2})(\!\[[^\]]*\]\([^)]+\))\1/',
            function ($m) use (&$placeholders) {
                $key = "\x00WRAPPEDIMG" . count($placeholders) . "\x00";
                $placeholders[$key] = $m[0];
                return $key;
            },
            $md
        );

        $md = preg_replace('/(\!\[[^\]]*\]\([^)]+\))(?=[^\s\n])/', "$1\n", $md);
        $md = preg_replace('/(\!\[[^\]]*\]\([^)]+\))\n(?!\n)([^\n])/', "$1\n\n$2", $md);

        return $placeholders ? strtr($md, $placeholders) : $md;
    }
}
