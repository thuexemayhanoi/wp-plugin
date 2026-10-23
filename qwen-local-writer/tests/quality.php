<?php
/** Offline deterministic tests for QLW\Quality. No WordPress or model download needed. */
require __DIR__ . '/stubs.php';
require __DIR__ . '/../includes/class-quality.php';

use QLW\Quality;

function vi_article(int $sections = 8, int $sentences = 7, string $extra = ''): string {
    $topics = ['giấy tờ', 'hợp đồng', 'đặt cọc', 'kiểm tra xe', 'lộ trình', 'an toàn', 'gửi xe', 'trả xe'];
    $out = ["Bài viết giúp người mới chuẩn bị kỹ trước khi thuê xe máy ở Hà Nội, từ giấy tờ đến lúc trả xe. " . $extra];
    for ($i = 1; $i <= $sections; $i++) {
        $topic = $topics[($i - 1) % count($topics)];
        $lines = [];
        for ($n = 1; $n <= $sentences; $n++) {
            $lines[] = "Ở bước $i, ý thứ $n về $topic là ghi chú rõ ràng điều cần hỏi cửa hàng để tránh hiểu lầm khi nhận xe.";
        }
        $out[] = "## Phần $i: lưu ý về $topic\n\n" . implode(' ', $lines);
    }
    return implode("\n\n", $out);
}

function en_article(int $sections = 8, int $sentences = 7): string {
    $out = ['This guide helps first-time riders prepare before renting a motorbike in Hanoi.'];
    for ($i = 1; $i <= $sections; $i++) {
        $lines = [];
        for ($n = 1; $n <= $sentences; $n++) {
            $lines[] = "In step $i, point $n explains which question to ask the shop so that the handover stays clear and calm.";
        }
        $out[] = "## Part $i of the checklist\n\n" . implode(' ', $lines);
    }
    return implode("\n\n", $out);
}

$long = Quality::assess(vi_article(), 1500, 'vi');
check($long['word_count'] >= 1300 && $long['word_count'] <= 1800, 'fixture is a realistic ~1,500-word article (' . $long['word_count'] . ')');
check($long['ready_for_review'] && $long['score'] >= 70, 'long structured Vietnamese article passes (score ' . $long['score'] . ')');
check($long['h2_count'] === 8, 'counts H2 headings');
check(Quality::assess(vi_article(), 1500, 'vi') === $long, 'scoring is deterministic');

$short = Quality::assess("## Mục một\nBài thiếu nội dung.", 1500, 'vi');
check(!$short['ready_for_review'], 'short content with no H2 depth fails');

$unsafe = Quality::assess(vi_article() . "\n<script>alert(1)</script>", 1500, 'vi');
check(!$unsafe['ready_for_review'], 'script tag blocks QA');
check(Quality::is_unsafe('<img src=x onerror="alert(1)">'), 'inline event handler is unsafe');
check(Quality::is_unsafe('[x](javascript:alert(1))'), 'javascript: URL is unsafe');
check(!Quality::is_unsafe('Dùng **chữ đậm** và danh sách - mục'), 'plain Markdown is safe');

$h1 = Quality::assess("# Tiêu đề\n\n" . vi_article(), 1500, 'vi');
check($h1['score'] < $long['score'], 'extra H1 reduces score');

$han = Quality::assess(vi_article(8, 7, '这是中文内容这是中文内容。'), 1500, 'vi');
check($han['score'] <= $long['score'] - 25, 'Chinese characters are penalised');

$wrong_lang = Quality::assess(en_article(), 1500, 'vi');
check(!$wrong_lang['ready_for_review'] || $wrong_lang['score'] <= 75, 'English text marked as Vietnamese is penalised');
$en = Quality::assess(en_article(), 1500, 'en');
check($en['ready_for_review'], 'English article passes as English (score ' . $en['score'] . ')');
$vi_as_en = Quality::assess(vi_article(), 1500, 'en');
check($vi_as_en['score'] <= $long['score'] - 25, 'Vietnamese text marked as English is penalised');

$brief = "Honda Vision 200.000đ/ngày, cọc 4–5 triệu. Điện thoại/Zalo: 0942 467 674. Website https://thuexemaynguyentu.com/";
$priced = vi_article(8, 7, 'Giá Honda Vision 200.000đ mỗi ngày, gọi 0942 467 674 hoặc xem https://thuexemaynguyentu.com/ để biết thêm.');
$ok_prices = Quality::assess($priced, 1500, 'vi', $brief);
check($ok_prices['unverified'] === [], 'prices/phone/link present in brief are not flagged: ' . implode(', ', $ok_prices['unverified']));
$intl = Quality::assess(vi_article(8, 7, 'Gọi +84 942 467 674.'), 1500, 'vi', $brief);
check($intl['unverified'] === [], '+84 format matches 0-prefixed phone in brief');
$invented = Quality::assess(vi_article(8, 7, 'Giá thuê chỉ 99.000đ, giảm 30% nếu gọi 0912 345 678, xem www.example.com.'), 1500, 'vi', $brief);
check(count($invented['unverified']) === 4, 'invented price, %, phone and link are flagged: ' . implode(' | ', $invented['unverified']));
check($invented['score'] === $long['score'] - 20, 'unverified specifics cost 5 points each, capped at 20');

$repeat = Quality::assess(vi_article() . "\n\n" . str_repeat("Câu này bị mô hình lặp đi lặp lại nhiều lần trong bài viết. ", 30), 1500, 'vi');
check($repeat['score'] < $long['score'], 'repeated sentences are penalised');

$experience = Quality::assess(vi_article(8, 7, 'Tôi đã thuê xe ở đây nhiều lần.'), 1500, 'vi');
check($experience['score'] === $long['score'] - 10, 'invented first-person experience is penalised');

$thin = Quality::assess(vi_article() . "\n\n## Phần trống\n\nMột câu.", 1500, 'vi');
check($thin['score'] < $long['score'], 'an H2 with almost no content is penalised');

$kw_missing = Quality::assess(vi_article(), 1500, 'vi', '', 'thuê xe máy Long Biên');
$kw_ok = Quality::assess(vi_article(8, 7, 'Thuê xe máy ở Hà Nội cần chuẩn bị.'), 1500, 'vi', '', 'thuê xe máy');
check($kw_missing['score'] === $long['score'] - 5 && $kw_ok['score'] === $long['score'], 'keyword presence check');

$think = Quality::assess(vi_article(8, 7, '<think>suy nghĩ</think>'), 1500, 'vi');
check($think['score'] < $long['score'], 'leftover <think> tags are penalised');

$stamp = Quality::assess(vi_article(8, 7, 'Giá cả: Từ 1792728377936, bạn có thể tham khảo thêm.'), 1500, 'vi', $brief);
check(in_array('1792728377936', $stamp['unverified'], true), 'long bare numbers copied by the model are flagged');

// Real Qwen3-0.6B output from CI run 39897057193 (before the 0.2.0 prompt/cleanup fixes):
// "*H2:" headings, sections written into each other, meta sentences about the conclusion.
$real_brief = 'Cho Thuê Xe Máy Nguyễn Tú. Địa chỉ: 112 Nguyễn Văn Cừ, Bồ Đề, Long Biên, Hà Nội. Honda Air Blade 200.000đ/ngày, cọc 3 triệu. Honda Vision cọc 4–5 triệu. Xe tay ga 50cc 200.000đ/ngày, cọc 4 triệu. Tiền cọc từ 2.000.000đ đến 5.000.000đ.';
$real = Quality::assess(file_get_contents(__DIR__ . '/fixtures/qwen-real-vi-v020-flawed.md'), 1500, 'vi', $real_brief, 'thuê xe máy ở Hà Nội');
$failed = array_column(array_filter($real['checks'], static fn ($c) => !$c['pass']), 'id');
check(!$real['ready_for_review'], 'flawed real Qwen article is NOT ready for review (score ' . $real['score'] . ')');
check(in_array('heading-format', $failed, true) && in_array('bleed', $failed, true) && in_array('meta', $failed, true), 'flags heading artefacts, section bleed and meta text: ' . implode(',', $failed));
$dup = Quality::assess(vi_article() . "\n\n## Phần 1: lưu ý về giấy tờ\n\n" . str_repeat('Một câu mới khác biệt hoàn toàn để đủ độ dài cho phần này. ', 8), 1500, 'vi');
check(in_array('dup-heading', array_column(array_filter($dup['checks'], static fn ($c) => !$c['pass']), 'id'), true), 'duplicate H2 headings are flagged');

finish('Quality');
