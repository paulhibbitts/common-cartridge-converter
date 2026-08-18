<?php
namespace CH;

class Helpers
{
    public static function slugify(string $text): string
    {
        // Transliterate Unicode characters to nearest ASCII equivalents
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($ascii !== false && $ascii !== '') $text = $ascii;
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        $text = preg_replace('/[\s-]+/', '-', trim($text));
        return trim($text, '-');
    }

    public static function yamlEscape(string $text): string
    {
        return str_replace(["'", "\n"], ["''", ' '], $text);
    }

    // Humanize a file/resource slug into a title when no better source is available
    // (e.g. "our-purpose" -> "Our Purpose")
    public static function titleFromSlug(string $slug): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    public static function numberedFolder(int $n, string $slug): string
    {
        return sprintf('%02d', $n) . '.' . $slug;
    }

    // Derive a short description from plain text (first sentence, ≤120 chars)
    public static function shortDescription(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (strlen($text) <= 120) return $text;
        $pos = strpos($text, '. ');
        if ($pos !== false && $pos < 120) return substr($text, 0, $pos + 1);
        return substr($text, 0, 117) . '...';
    }

    // Strip an instructor's own manual ordering prefix from a module/page title
    // (e.g. "1 Introduction" → "Introduction", "Module 2 - Basics" → "Basics",
    // "Unit 1.1: Foundations" → "Foundations") — redundant once Grav's own numbered
    // folders (10.home, 20.essentials, ...) provide the ordering. Requires a hard
    // boundary (separator or whitespace) after the digits so titles that merely start
    // with a number, like "3D Modeling Basics", are left untouched.
    public static function stripLeadingNumbering(string $title): string
    {
        // Strip bare numeric prefixes only (e.g. "1. ", "01 - ", "1.2 | ").
        // Keyword-labeled prefixes like "Module 1 -" or "Unit 3:" are left intact:
        // they often provide meaningful context, especially when a module list also
        // contains non-numbered supplementary sections (e.g. "Additional resources").
        $stripped = preg_replace(
            '/^\s*\d+(?:\.\d+)*(?:\s*[-–—:.)|]\s*|\s+)/u',
            '',
            $title,
            1
        );
        $stripped = trim($stripped ?? $title);
        return $stripped !== '' ? $stripped : $title;
    }
}
