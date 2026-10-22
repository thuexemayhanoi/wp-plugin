<?php
/** Standalone deterministic test: does not require WordPress or model download. */
define('ABSPATH', __DIR__);
require __DIR__ . '/../includes/class-quality.php';
function test_true(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
$sections = [];
for ($i = 1; $i <= 8; $i++) {
    $sections[] = '## Chủ đề phần ' . $i . "\n\n" .
        implode(' ', array_map(static fn ($n) => "Nội dung thực tế mục $i phát triển ý $n theo ngữ cảnh và nhu cầu độc giả.", range(1, 20)));
}
$long = \QLW\Quality::assess(implode("\n\n", $sections));
test_true($long['word_count'] >= 1200, 'Fixture must be a real long document');
test_true($long['ready_for_review'] && $long['score'] >= 70, 'Long structured document must meet technical QA');
$short = \QLW\Quality::assess('## Mục một'."\n".'Bài thiếu nội dung.');
test_true(!$short['ready_for_review'], 'Short content with no H2 depth must not pass');
$unsafe = \QLW\Quality::assess(implode("\n\n", $sections) . "\n<script>alert(1)</script>");
test_true(!$unsafe['ready_for_review'], 'Unsafe script must block technical QA');
$h1 = \QLW\Quality::assess("# Title\n\n" . implode("\n\n", $sections));
test_true($h1['score'] < $long['score'], 'Extra H1 must reduce score');
echo "Qwen Local Writer QA tests passed (5 checks)\n";
