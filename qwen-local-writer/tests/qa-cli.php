<?php
/** php tests/qa-cli.php article.md vi|en [brief.txt] [keyword] → QA JSON on stdout (same scorer as the plugin). */
require __DIR__ . '/stubs.php';
require __DIR__ . '/../includes/class-quality.php';

[$script, $file, $language, $brief_file, $keyword] = $argv + [null, null, 'vi', '', ''];
if (!$file || !is_file($file)) {
    fwrite(STDERR, "usage: php qa-cli.php article.md vi|en [brief.txt] [keyword]\n");
    exit(2);
}
$brief = $brief_file && is_file($brief_file) ? file_get_contents($brief_file) : '';
echo json_encode(\QLW\Quality::assess(file_get_contents($file), 1500, $language, $brief, (string) $keyword), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
