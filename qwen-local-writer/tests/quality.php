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

$lead = Quality::assess(vi_article(8, 7, "\n\nDựa trên thông tin được cung cấp, đây là nội dung kết cho bài viết:\n\nKết thúc."), 1500, 'vi');
check(in_array('meta', array_column(array_filter($lead['checks'], static fn ($c) => !$c['pass']), 'id'), true), '"đây là nội dung kết cho bài viết:" lead-in is meta');

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

/* ---------- 0.3.0: grouped QA and fact checks built from real Qwen3-0.6B mistakes ---------- */
$failed_ids = static fn (array $qa): array => array_column(array_filter($qa['checks'], static fn ($c) => !$c['pass']), 'id');
$groups_of = array_unique(array_column($long['checks'], 'group'));
check(array_diff(['technical', 'factual'], $groups_of) === [] && isset($long['groups']['seo'], $long['groups']['editorial']), 'checks are grouped: technical, factual, seo (+ editorial, manual)');
check($long['fact_status'] === 'not_verified' && $long['editorial'] === 'not_reviewed', 'a clean article is "not verified", never "verified"; editorial review is not automatic');

$nguyen_tu = "Thương hiệu: Cho Thuê Xe Máy Nguyễn Tú\nĐịa chỉ: 112 Nguyễn Văn Cừ, Bồ Đề, Long Biên, Hà Nội\nĐiện thoại: 0942 467 674\nWebsite: https://thuexemaynguyentu.com/\nHonda Wave: 150.000đ/ngày\nHonda Vision: 200.000đ/ngày\nHonda Air Blade: 200.000đ/ngày\nXe tay ga 50cc: 200.000đ/ngày\nTiền cọc tùy dòng xe, không tự suy diễn mức cụ thể.";
$prices = Quality::brief_prices($nguyen_tu);
check(($prices['wave'] ?? null) === [150000.0] && ($prices['air blade'] ?? null) === [200000.0] && ($prices['50cc'] ?? null) === [200000.0], 'brief prices are parsed per model: ' . json_encode($prices));
$rental = ['title' => 'Thuê xe máy ở Long Biên: lưu ý trước khi nhận xe'];

// Real runs: "một chiếc xe điện thông minh như Honda Air Blade" (run 39898298326).
$fuel = Quality::assess(vi_article(8, 7, 'Ngoài ra, việc lựa chọn một chiếc xe điện thông minh như Honda Air Blade sẽ giúp bạn tiết kiệm.'), 1500, 'vi', $nguyen_tu, '', $rental);
check(in_array('fuel', $failed_ids($fuel), true) && $fuel['fact_status'] === 'flagged', 'Air Blade described as an electric bike is flagged');
$list = Quality::assess(vi_article(8, 7, 'Cửa hàng có Honda Wave, Vision, Air Blade, xe tay ga 50cc và xe điện. Honda Air Blade không phải xe điện.'), 1500, 'vi', $nguyen_tu, '', $rental);
check(!in_array('fuel', $failed_ids($list), true), 'a list of vehicle types or "không phải xe điện" is not a fuel claim');

// "Trong thị trấn Long Biên", "Trong một thị trấn yên tĩnh, Hà Nội", "In Hanoi, particularly in regions like the Central Highlands".
foreach (['Trong thị trấn Long Biên, người thuê xe rất đông.', 'Trong một thị trấn yên tĩnh, Hà Nội, người xưa đi bộ nhiều.'] as $sentence) {
    check(in_array('geography', $failed_ids(Quality::assess(vi_article(8, 7, $sentence), 1500, 'vi')), true), 'wrong Hanoi geography is flagged: ' . $sentence);
}
check(in_array('geography', $failed_ids(Quality::assess(en_article() . "\n\nIn Hanoi, particularly in regions like the Central Highlands, 50cc scooters are common.", 1500, 'en')), true), 'Hanoi + Central Highlands is flagged');
check(!in_array('geography', $failed_ids(Quality::assess(vi_article(8, 7, 'Cửa hàng ở 112 Nguyễn Văn Cừ, Bồ Đề, Long Biên, Hà Nội.'), 1500, 'vi', $nguyen_tu)), true), 'the real address is not a geography flag');

// "người mua", "trước khi mua xe" in a rental article (runs 39903514077, 39907043073).
$buy = Quality::assess(vi_article(8, 7, 'Người mua cần kiểm tra giá trước khi mua xe. Việc đặt mua xe cần cẩn thận. Giá bán xe thay đổi.'), 1500, 'vi', $nguyen_tu, '', $rental);
check(in_array('rent-buy', $failed_ids($buy), true), 'buying/selling language in a rental article is flagged');
check(!in_array('rent-buy', $failed_ids(Quality::assess(vi_article(8, 7, 'Người mua cần kiểm tra giá.'), 1500, 'vi', '', '', ['title' => 'Kinh nghiệm mua xe máy cũ'])), true), 'buying language is fine in a buying article');

// Swapped prices: Wave is 150.000đ, Vision 200.000đ in the brief.
$swap = Quality::assess(vi_article(8, 7, 'Honda Vision 150.000đ/ngày rất phù hợp cho sinh viên.'), 1500, 'vi', $nguyen_tu, '', $rental);
check(in_array('price-mismatch', $failed_ids($swap), true) && $swap['unverified'] === [], 'a brief price attached to the wrong model is caught although the number exists in the brief');
$right = Quality::assess(vi_article(8, 7, 'Honda Vision 200.000đ/ngày hoặc Honda Wave 150.000 đồng/ngày, Air Blade 200,000 VND/day, xe tay ga 50cc 200.000đ.'), 1500, 'vi', $nguyen_tu, '', $rental);
check(!in_array('price-mismatch', $failed_ids($right), true) && $right['unverified'] === [], 'correct model prices in several formats pass: ' . implode(' | ', $right['unverified']));
$deposit_amount = Quality::assess(vi_article(8, 7, 'Honda Vision 200.000đ/ngày, cọc 4–5 triệu.'), 1500, 'vi', $nguyen_tu, '', $rental);
check(!in_array('price-mismatch', $failed_ids($deposit_amount), true) && in_array('5 triệu', $deposit_amount['unverified'], true), 'an invented deposit is "unverified", not a model-price mismatch');

$no_deposit = Quality::assess(vi_article(8, 7, 'Cửa hàng cho thuê xe không cần đặt cọc.'), 1500, 'vi', $nguyen_tu, '', $rental);
check(in_array('no-deposit', $failed_ids($no_deposit), true), '"không cần đặt cọc" without a brief is flagged');

// "Nguyễn Tú – người đã từng là một nhà thiết kế nổi tiếng" (run 39898298326).
$claim = Quality::assess(vi_article(8, 7, 'Nguyễn Tú – người đã từng là một nhà thiết kế nổi tiếng, thành lập năm 2015, là đơn vị hàng đầu.'), 1500, 'vi', $nguyen_tu);
check(in_array('claims', $failed_ids($claim), true) && in_array('2015', $claim['unverified'], true), 'unverifiable claims and invented years are flagged');
$reviews = Quality::assess(vi_article(8, 7, 'Khách hàng đánh giá dịch vụ 5 sao. Tại sao nên thuê xe?'), 1500, 'vi');
check(in_array('reviews', $failed_ids($reviews), true), 'invented customer reviews/ratings are flagged');
check(!in_array('reviews', $failed_ids(Quality::assess(vi_article(8, 7, 'Tại sao nên kiểm tra phanh? Vì sao cần mũ bảo hiểm?'), 1500, 'vi')), true), '"tại sao"/"vì sao" is not a star rating');
$sources = Quality::assess(en_article() . "\n\nAccording to a recent study, 80% of riders prefer automatic scooters.", 1500, 'en');
check(in_array('sources', $failed_ids($sources), true) && in_array('80%', $sources['unverified'], true), 'invented studies and statistics are flagged');
$me = Quality::assess(vi_article(8, 7, 'Theo kinh nghiệm của mình, xe số dễ đi.'), 1500, 'vi');
check(in_array('experience', $failed_ids($me), true) && $me['fact_check'][0]['type'] === 'experience', 'first-person experience appears in the fact-check list');

// A real-looking article can score 100 on structure; factual flags must still lower it.
check($fuel['score'] < $long['score'] && $swap['score'] <= $long['score'] - 15, 'factual errors lower the score (QA 100 is no longer possible with these errors)');

// SEO: keyword prefix on every H2 (run 39907043073), title length, similarity context.
$prefixed = preg_replace_callback('/^## (.+)$/mu', static fn ($m) => '## Thuê xe máy Long Biên: ' . $m[1], vi_article());
$stuffed = Quality::assess($prefixed, 1500, 'vi', '', 'thuê xe máy Long Biên', $rental);
check(in_array('heading-stuffing', $failed_ids($stuffed), true), 'the same keyword prefix on every H2 is keyword stuffing');
$short_title = Quality::assess(vi_article(), 1500, 'vi', '', '', ['title' => 'Thuê xe']);
check(in_array('title-length', $failed_ids($short_title), true) && $short_title['score'] === $long['score'], 'title length is a warning without penalty');
$similar = Quality::assess(vi_article(), 1500, 'vi', '', '', ['similar' => [['id' => 12, 'title' => 'x', 'title_similarity' => 0.8, 'content_similarity' => 0.62]]]);
check(in_array('similar-content', $failed_ids($similar), true) && in_array('similar-title', $failed_ids($similar), true) && $similar['score'] === $long['score'] - 25, 'near-duplicate posts are flagged (content 62% → -20, title → -5)');
$unique = Quality::assess(vi_article(), 1500, 'vi', '', '', ['similar' => []]);
check(!array_intersect(['similar-content', 'similar-title'], $failed_ids($unique)), 'no similar posts, no flag');

// Run 39910343866: prefix variants on H2s and new meta lead-ins.
$variants = "Mở bài ngắn.\n\n" . implode("\n\n", array_map(static fn ($h) => "## $h\n\n" . str_repeat('Nội dung hữu ích cho người thuê xe máy ở Hà Nội. ', 14), ['Thuê xe máy tại Long Biên: Lưu ý đến giá', 'Thuê xe máy: Tính toán chi phí', 'Thuê xe máy: Đảm bảo độ tin cậy', 'Kiểm tra xe khi nhận', 'Trả xe đúng hẹn']));
check(in_array('heading-stuffing', $failed_ids(Quality::assess($variants, 1500, 'vi')), true), 'H2 prefix variants ("Thuê xe máy:", "Thuê xe máy tại Long Biên:") count as one shared prefix');
foreach (['Bài viết **"Thuê xe máy ở Long Biên"** bắt đầu bằng nội dung này:', 'Đoạn văn này đưa ra các thông tin cơ bản về lịch trình và giá cả, giúp người đọc có được cái nhìn đầy đủ.'] as $line) {
    check(in_array('meta', $failed_ids(Quality::assess(vi_article(8, 7, "\n\n$line\n\nTiếp tục."), 1500, 'vi')), true), 'meta lead-in is flagged: ' . mb_substr($line, 0, 40));
}
check(!in_array('meta', $failed_ids(Quality::assess(vi_article(8, 7, "\n\nDưới đây là một vài lưu ý khi nhận xe:\n\n- Kiểm tra phanh"), 1500, 'vi')), true), 'a normal list introduction is not meta');

// Run 39914553591: correct statements of the difference and plural "deposits" are not errors.
$negated = Quality::assess(vi_article(8, 7, 'Tiết kiệm ngân sách là mục tiêu quan trọng, đặc biệt là khi không phải mua bán xe mà là thuê. Không phải mua xe mới mà là thuê xe, khách hàng sẽ yên tâm.'), 1500, 'vi', '', '', $rental);
check(!in_array('rent-buy', $failed_ids($negated), true), '"không phải mua xe mà là thuê" is not a rent/buy mix-up');
$en_brief = "Honda Vision 200,000 VND/day, deposit 4–5 million VND. Honda Air Blade 200,000 VND/day, deposit 3 million VND. A deposit of 2,000,000–5,000,000 VND applies.";
$plural = Quality::assess(en_article() . "\n\nThe same applies to the Honda Vision 200,000 VND/day and the Honda Air Blade 200,000 VND/day, all with deposits in the range of 2–5 million VND.", 1500, 'en', $en_brief, '', ['title' => 'How to rent a motorbike in Hanoi']);
check(!in_array('price-mismatch', $failed_ids($plural), true), 'amounts after "deposits" are deposits, not a model price');
$zeros = Quality::assess(vi_article(8, 7, 'Tôi chọn Honda Wave 150, giá là 000000000đ/ngày.'), 1500, 'vi', $nguyen_tu, '', $rental);
check(in_array('000000000đ', $zeros['unverified'], true) && in_array('price-mismatch', $failed_ids($zeros), true), 'a garbage "000000000đ" price is flagged');

// Run 39914553591 (browser): the amount comes before the model it belongs to; "mình" as "themselves".
$before = Quality::assess(vi_article(8, 7, 'Giá tham khảo cho các loại xe là: 200.000 đồng/ngày cho xe Honda Vision, và 150.000 đồng/ngày cho xe Honda Wave.'), 1500, 'vi', $nguyen_tu, '', $rental);
check(!in_array('price-mismatch', $failed_ids($before), true), '"200.000 đồng/ngày cho xe Honda Vision, và 150.000 đồng/ngày cho xe Honda Wave" is correct');
$before_swap = Quality::assess(vi_article(8, 7, 'Giá tham khảo: 150.000 đồng/ngày cho xe Honda Vision, và 200.000 đồng/ngày cho xe Honda Wave.'), 1500, 'vi', $nguyen_tu, '', $rental);
check(in_array('price-mismatch', $failed_ids($before_swap), true), 'swapped prices written before the model are still caught');
$en_before = Quality::assess(en_article() . "\n\nExpect 200,000 VND/day for the Honda Vision and 150,000 VND/day for the Honda Wave.", 1500, 'en', $nguyen_tu, '', $rental);
check(!in_array('price-mismatch', $failed_ids($en_before), true), '"200,000 VND/day for the Honda Vision" binds to the following model');
$deposit_before = Quality::assess(vi_article(8, 7, 'Tiền cọc 2.000.000 đồng cho xe Honda Wave.'), 1500, 'vi', $nguyen_tu, '', $rental);
check(!in_array('price-mismatch', $failed_ids($deposit_before), true), 'a deposit written before the model is not a model-price mismatch');
$reflexive = Quality::assess(vi_article(8, 7, 'Các cửa hàng và đại lý chuyên môn nên đảm bảo rằng mình đã cập nhật đầy đủ các thông tin về giá cả.'), 1500, 'vi');
check(!in_array('experience', $failed_ids($reflexive), true), 'reflexive "rằng mình đã cập nhật" is not a first-person experience');
foreach (['Mình đã thuê xe ở đây ba lần.', 'Hôm qua mình đã thử chiếc Vision này.'] as $line) {
    check(in_array('experience', $failed_ids(Quality::assess(vi_article(8, 7, $line), 1500, 'vi')), true), 'first-person experience still flagged: ' . $line);
}

finish('Quality');
