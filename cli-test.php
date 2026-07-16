<?php
require_once __DIR__ . '/vendor/autoload.php';
use CH\CartridgeParser;
use CH\CourseHubBuilder;

$files = glob('/Users/paulhibbitts/Desktop/cc-test imports/*.{imscc,zip}', GLOB_BRACE);
sort($files);

$pass = 0; $fail = 0;

foreach ($files as $file) {
    $name = basename($file);
    echo "=== $name ===\n";

    $tmpDir = sys_get_temp_dir() . '/cc_test_' . uniqid();
    mkdir($tmpDir, 0700, true);

    try {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) throw new Exception('Not a valid ZIP');
        $zip->extractTo($tmpDir);
        $zip->close();

        $parser = new CartridgeParser($tmpDir);
        echo "  Title:    " . ($parser->courseTitle ?: '(none)') . "\n";
        echo "  Modules:  " . count($parser->modules) . "\n";

        $builder = new CourseHubBuilder($parser, true, true, true); // skipFiles, skipImages, stripNumbering
        $zipPath = $builder->build();

        $shortcodes = [];
        $zip2 = new ZipArchive();
        $zip2->open($zipPath);
        for ($i = 0; $i < $zip2->numFiles; $i++) {
            $content = $zip2->getFromIndex($i);
            if (preg_match_all('/\[(objectives|key-takeaways|reflection)\]/', $content, $m)) {
                foreach ($m[1] as $sc) $shortcodes[$sc] = true;
            }
        }
        $zip2->close();
        unlink($zipPath);

        if ($shortcodes) echo "  Shortcodes: " . implode(', ', array_keys($shortcodes)) . "\n";
        if ($builder->warnings) foreach ($builder->warnings as $w) echo "  [warn] $w\n";
        echo "  OK\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . " (line " . $e->getLine() . " in " . basename($e->getFile()) . ")\n";
        $fail++;
    }

    // cleanup
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
    rmdir($tmpDir);

    echo "\n";
}

echo "Results: $pass passed, $fail failed\n";
