<?php
/**
 * Deterministic QA of a generated draft, in three automatic groups plus one human step:
 *
 * - technical: structure, length, leftovers of the model, repetition, language;
 * - factual:   specifics that are not in the verified brief, contradictions with the brief and
 *              claim patterns seen in real Qwen output (petrol bikes called electric, wrong Hanoi
 *              geography, "buy" in a rental article, invented reviews, sources or experience);
 * - seo:       keyword use and stuffing, title, headings, similarity to existing posts;
 * - editorial: never automatic — an editor confirms it in the post editor.
 *
 * Flags mean "check this"; the absence of flags proves nothing. The score never certifies
 * facts, prices, law or ranking potential.
 *
 * @package QwenLocalWriter
 */
namespace QLW;

defined('ABSPATH') || exit;

final class Quality {
    public const THRESHOLD = 70;
    public const GROUPS = [
        'technical' => 'QA kỹ thuật',
        'factual' => 'Kiểm chứng dữ kiện',
        'seo' => 'SEO',
        'editorial' => 'Biên tập (con người)',
    ];
    private const WORD_RE = "/[\p{L}\p{N}]+(?:[’'-][\p{L}\p{N}]+)*/u";
    private const VI_CHARS = 'àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ';
    private const FOREIGN_RE = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Cyrillic}\p{Thai}\p{Arabic}]/u';
    private const UNSAFE_RE = '/<\s*(?:script|iframe|style|form|svg|object|embed)\b|<\?php|javascript\s*:|\bon[a-z]+\s*=\s*["\']/iu';
    /** Unicode-aware word edges (Vietnamese letters are not ASCII). */
    private const WB = '(?<![\p{L}\p{N}])';
    private const WE = '(?![\p{L}\p{N}])';
    private const UNIT = '(?:đ|đồng|vnđ|vnd|k|nghìn|ngàn|triệu|tr|tỷ|million|thousand|usd|\$|€)';

    /** Mirrors META_LINE_RE in assets/qlw-core.js. */
    private const META_RE = '/^(?:đoạn|phần)\s+(?:văn|mở bài|mở đầu|giới thiệu|kết luận|kết|này|tiếp theo)\b.{0,160}\b(?:như sau|sau đây|tóm tắt|nhấn mạnh|gồm có|đưa ra|cung cấp|mô tả|giúp người đọc|sẽ (?:giới thiệu|trình bày|nói))\b|^(?:bài viết|the article|article)\b[^\n]{0,160}\b(?:bắt đầu|begins?|starts?)\b[^\n]{0,80}[:：]\s*$|(?:nội dung|đoạn văn|phần)\s+(?:này|sau|dưới đây)\s*[:：]\s*$|(?:có thể|được|sẽ) (?:được )?viết (?:lại )?như sau\s*[:：]?\s*$|^\(?\s*(?:[^()\n]{0,40}\s)?\d+\s*(?:từ|words?)\b[^()\n]{0,30}\)?\.?$|^(?:đề xuất|gợi ý)\s+thêm\s+(?:một\s+)?danh sách|^(?:this|the)\s+(?:section|paragraph|introduction|conclusion|article)\s+(?:will|should|can|summari[sz]es|highlights)\b|^(?:here is|here\'s|below is|sure[,!])\b|^(?:word count|note)\s*[:：]|(?:^|[,;]\s*)(?:đây là|sau đây là|dưới đây là)\s+(?:nội dung|đoạn|phần|bài)\b[^\n]{0,80}[:：]\s*$|(?:^|[,;]\s*)here (?:is|are) (?:the|a|an|my)\s[^\n]{0,80}[:：]\s*$/iu';

    /**
     * Petrol scooters/motorbikes. Ambiguous English words (vision, lead, future, click, wave)
     * only count with a brand or "xe" in front.
     */
    private const PETROL_RE = '(?:honda|xe)\s+(?:wave(?:\s+(?:alpha|rsx))?|vision|air\s*blade|lead|sh(?:\s+mode)?|future|winner(?:\s+x)?|click|pcx|vario)|air\s*blade|yamaha\s+(?:mio|sirius|exciter|janus|grande|jupiter|nvx|latte|freego)|sirius|exciter|janus|(?:xe\s+(?:tay\s+ga\s+)?)?50\s*cc';
    private const ELECTRIC_RE = 'xe\s+(?:máy\s+|tay\s+ga\s+)?điện|chạy\s+(?:bằng\s+)?(?:điện|pin)|động\s+cơ\s+điện|electric|e-scooter|battery[- ]powered';
    private const HANOI_RE = 'hà\s+nội|hanoi|long\s+biên|hoàn\s+kiếm|ba\s+đình|đống\s+đa|hai\s+bà\s+trưng|cầu\s+giấy|tây\s+hồ|thanh\s+xuân|hoàng\s+mai|bồ\s+đề|gia\s+lâm|từ\s+liêm';

    public static function strip_markers(string $line): string {
        $value = str_replace(['**', '__', '`'], '', $line);
        do {
            $previous = $value;
            $value = preg_replace(['/^\s*(?:#{1,6}|[-*+•>|]|\(?\d{1,2}[.):]|\(?[ivx]{1,4}[.)])\s*/iu', '/^\s*(?:h[1-6]|tiêu đề|heading|title)\s*[:.-]\s*/iu', '/^\s*(?:phần|mục|section|part)\s+\d+\s*[:.-]\s*/iu'], '', $value);
        } while ($value !== $previous);
        return trim(rtrim($value, '*_'));
    }

    public static function key(string $text): string {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N} ]/u', ' ', mb_strtolower(self::strip_markers($text)))));
    }

    public static function words(string $text): array {
        preg_match_all(self::WORD_RE, $text, $m);
        return $m[0];
    }

    public static function is_unsafe(string $markdown): bool {
        return (bool) preg_match(self::UNSAFE_RE, $markdown);
    }

    /** Is the article about renting (so "buy a bike" is a mistake)? */
    public static function is_rental(string $text): bool {
        return (bool) preg_match('/' . self::WB . '(?:thuê|rent(?:al|ing)?|hire)' . self::WE . '/iu', $text);
    }

    /**
     * @param string $brief   Verified facts supplied by the editor; specifics not found here are flagged.
     * @param array  $context Optional: 'title' (post title), 'similar' (from Ajax::similar_posts()).
     */
    public static function assess(string $markdown, int $target = 1500, string $language = 'vi', string $brief = '', string $keyword = '', array $context = []): array {
        $target = max(500, min(3000, $target));
        $language = $language === 'en' ? 'en' : 'vi';
        $title = isset($context['title']) && is_string($context['title']) ? $context['title'] : '';
        $words = self::words($markdown);
        $count = count($words);
        $score = 100;
        $checks = [];
        $check = static function (string $group, string $id, bool $pass, int $penalty, string $message) use (&$score, &$checks): void {
            if (!$pass) {
                $score -= $penalty;
            }
            $checks[] = ['group' => $group, 'id' => $id, 'pass' => $pass, 'penalty' => $pass ? 0 : $penalty, 'message' => $message];
        };

        /* ---------- Technical ---------- */
        if ($count < $target * 0.5) {
            $check('technical', 'length', false, 35, sprintf('Chỉ %d từ, chưa bằng một nửa mục tiêu %d.', $count, $target));
        } elseif ($count < $target * 0.8) {
            $check('technical', 'length', false, 20, sprintf('%d từ, chưa đạt 80%% mục tiêu %d.', $count, $target));
        } elseif ($count > $target * 1.4) {
            $check('technical', 'length', false, 10, sprintf('%d từ, dài hơn đáng kể mục tiêu %d.', $count, $target));
        } else {
            $check('technical', 'length', true, 0, sprintf('%d từ (mục tiêu ~%d).', $count, $target));
        }

        $h2 = preg_match_all('/^##\s+\S/mu', $markdown);
        $check('technical', 'h2', $h2 >= 4, 20, sprintf('%d tiêu đề H2 (cần ≥ 4).', $h2));
        $check('technical', 'no-h1', !preg_match('/^#(?!#)\s+\S/mu', $markdown), 15, 'Không có H1 trong thân bài (theme hiển thị tiêu đề bài là H1).');
        $thin = 0;
        foreach (preg_split('/^##\s+.*$/mu', $markdown) ?: [] as $i => $body) {
            if ($i > 0 && count(self::words($body)) < 60) {
                $thin++;
            }
        }
        $check('technical', 'thin-sections', $thin === 0, min(15, 5 * $thin), $thin ? sprintf('%d phần H2 có dưới 60 từ.', $thin) : 'Mỗi phần H2 có nội dung.');

        // Model artifacts in headings, repeated headings, and sections bleeding into each other.
        preg_match_all('/^(#{2,3})\s+(.+)$/mu', $markdown, $hm);
        $bad_headings = array_filter($hm[2], static fn (string $h): bool => (bool) preg_match('/\*|_{2}|\bH[1-6]\s*[:.-]|[:：]\s*$|^\d+(?:st|nd|rd|th)\s+(?:section|part)\b|^(?:phần|mục)\s+(?:thứ\s+)?\S+\s*[:：]/iu', $h));
        $check('technical', 'heading-format', !$bad_headings, 10, $bad_headings ? 'Tiêu đề còn ký tự rác của model (*, "H2:", dấu hai chấm cuối): ' . implode(' | ', array_slice($bad_headings, 0, 3)) : 'Tiêu đề sạch.');
        $heading_keys = array_map([self::class, 'key'], $hm[2]);
        $dup_headings = count($heading_keys) - count(array_unique($heading_keys));
        $check('technical', 'dup-heading', $dup_headings === 0, 10, $dup_headings ? sprintf('%d tiêu đề bị lặp.', $dup_headings) : 'Không lặp tiêu đề.');
        $h2_titles = [];
        $h2_keys = [];
        foreach ($hm[1] as $i => $level) {
            if ($level === '##') {
                $h2_titles[] = trim($hm[2][$i]);
                if (mb_strlen($heading_keys[$i]) >= 12) {
                    $h2_keys[] = $heading_keys[$i];
                }
            }
        }
        $bleed = 0;
        $meta = 0;
        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^#{1,6}\s/u', $line)) {
                continue;
            }
            $plain = self::strip_markers($line);
            if (preg_match(self::META_RE, $plain)) {
                $meta++;
            }
            if (preg_match('/^(?:\d{1,2}[.)]\s+)?\*\*[^*]{3,120}\*\*\s*[:：]?$|^\**\s*H[1-6]\s*[:.-]/iu', $line)) {
                $key = self::key($plain);
                foreach ($h2_keys as $h) {
                    if ($key === $h || (mb_strlen($key) >= 12 && (str_contains($key, $h) || str_contains($h, $key)))) {
                        $bleed++;
                        break;
                    }
                }
            }
        }
        $check('technical', 'bleed', $bleed === 0, 15, $bleed ? sprintf('%d dòng lặp lại tiêu đề mục khác trong thân bài (các mục bị trộn lẫn).', $bleed) : 'Các mục không bị trộn lẫn.');
        $check('technical', 'meta', $meta === 0, 15, $meta ? sprintf('%d câu model tự mô tả việc viết ("đoạn kết luận có thể viết như sau", "(… từ)", "here is…") cần xóa.', $meta) : 'Không có câu mô tả yêu cầu/số từ.');

        $check('technical', 'safe', !self::is_unsafe($markdown), 70, 'Không có HTML/mã nguy hiểm.');
        $check('technical', 'placeholder', !preg_match('/\b(?:TODO|TBD|lorem ipsum|as an ai(?: language model)?|let me know if|feel free to ask|i hope this helps|tôi là (?:một )?(?:mô hình|trợ lý) (?:ngôn ngữ|ai))\b|\[(?:điền|insert|placeholder|tên)[^\]]{0,40}\]|<\/?think>/iu', $markdown), 25, 'Không có placeholder, câu trả lời khuôn mẫu hoặc thẻ <think>.');

        $seen = [];
        $dup_para = false;
        foreach (preg_split('/\R\s*\R/u', $markdown) ?: [] as $paragraph) {
            $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $paragraph)));
            if (mb_strlen($key) < 100) {
                continue;
            }
            if (isset($seen[$key])) {
                $dup_para = true;
                break;
            }
            $seen[$key] = true;
        }
        $check('technical', 'dup-paragraph', !$dup_para, 20, 'Không lặp nguyên văn đoạn văn.');
        $sentence_keys = array_filter(array_map(static function (string $s): string {
            return mb_strtolower(trim(preg_replace('/[^\p{L}\p{N} ]+/u', '', $s)));
        }, preg_split('/(?<=[.!?…])\s+|\R+/u', $markdown) ?: []), static fn (string $s): bool => count(explode(' ', $s)) >= 6);
        $total_sentences = count($sentence_keys);
        $repeated = $total_sentences - count(array_unique($sentence_keys));
        $ratio = $total_sentences ? $repeated / $total_sentences : 0;
        $check('technical', 'dup-sentence', $ratio <= 0.05, $ratio > 0.10 ? 15 : 8, sprintf('Câu lặp lại: %d/%d.', $repeated, $total_sentences));

        preg_match_all(self::FOREIGN_RE, $markdown, $foreign);
        $foreign_count = count($foreign[0]);
        $check('technical', 'script', $foreign_count === 0, $foreign_count > 5 ? 25 : 10, $foreign_count ? sprintf('%d ký tự chữ Hán/ngôn ngữ khác.', $foreign_count) : 'Không lẫn chữ Hán hoặc ngôn ngữ khác.');
        $vi_words = 0;
        foreach ($words as $word) {
            if (preg_match('/[' . self::VI_CHARS . ']/iu', $word)) {
                $vi_words++;
            }
        }
        $vi_ratio = $count ? $vi_words / $count : 0;
        if ($language === 'vi') {
            $check('technical', 'language', $count === 0 || $vi_ratio >= 0.25, 25, sprintf('Tỷ lệ từ có dấu tiếng Việt %d%% (cần ≥ 25%%).', (int) round($vi_ratio * 100)));
        } else {
            $check('technical', 'language', $vi_ratio <= 0.03, 25, sprintf('Tỷ lệ từ tiếng Việt có dấu %d%% (bài tiếng Anh cần ≤ 3%%).', (int) round($vi_ratio * 100)));
        }

        /* ---------- Factual: flags to verify, never a certification ---------- */
        $sentences = self::sentences($markdown);
        $facts = [];
        $flag = static function (string $type, array $items, string $reason) use (&$facts): void {
            foreach ($items as $text) {
                $facts[] = ['type' => $type, 'text' => $text, 'reason' => $reason];
            }
        };

        $unverified = self::unverified_specifics($markdown, $brief);
        $check('factual', 'unverified', !$unverified, min(20, 5 * count($unverified)), $unverified
            ? 'Chi tiết cụ thể không có trong brief, cần kiểm tra/xóa: ' . implode(', ', array_slice($unverified, 0, 8))
            : 'Không có giá, số điện thoại, %, năm, link hoặc email ngoài brief.');

        $mismatch = self::price_mismatches($sentences, $brief);
        $flag('price', $mismatch, 'Giá gắn với mẫu xe/dịch vụ không khớp brief.');
        $check('factual', 'price-mismatch', !$mismatch, min(30, 15 * count($mismatch)), $mismatch
            ? sprintf('%d câu ghi giá khác với brief cho cùng mẫu xe/dịch vụ.', count($mismatch))
            : 'Giá theo từng mẫu xe khớp brief (chỉ kiểm tra được mẫu có trong brief).');

        $fuel = self::petrol_called_electric($sentences);
        $flag('fuel', $fuel, 'Xe xăng (Wave, Vision, Air Blade, 50cc…) bị mô tả là xe điện.');
        $check('factual', 'fuel', !$fuel, min(20, 10 * count($fuel)), $fuel
            ? sprintf('%d câu mô tả xe xăng là xe điện.', count($fuel))
            : 'Không thấy xe xăng bị gọi là xe điện.');

        $geo = self::matching($sentences, static function (string $s): bool {
            $hanoi = '(?:' . self::HANOI_RE . ')';
            return (bool) preg_match('/' . self::WB . $hanoi . self::WE . '[^.!?]{0,40}' . self::WB . 'thị\s+trấn|' . self::WB . 'thị\s+trấn' . self::WE . '[^.!?]{0,40}' . self::WB . $hanoi . self::WE . '/iu', $s)
                || (preg_match('/' . self::WB . $hanoi . self::WE . '/iu', $s)
                    && preg_match('/' . self::WB . '(?:tây\s+nguyên|central\s+highlands|mekong|đồng\s+bằng\s+sông\s+cửu\s+long|miền\s+tây|(?:small|quiet|little|sleepy|rural|mountain|coastal)\s+town|làng\s+chài|fishing\s+village)' . self::WE . '/iu', $s));
        });
        $flag('geography', $geo, 'Địa lý Hà Nội có thể sai (thị trấn, Tây Nguyên, miền Tây…).');
        $check('factual', 'geography', !$geo, min(20, 10 * count($geo)), $geo
            ? sprintf('%d câu có thông tin địa lý Hà Nội cần kiểm tra.', count($geo))
            : 'Không thấy mẫu sai địa lý Hà Nội đã biết.');

        if (self::is_rental($title . ' ' . $keyword)) {
            $buy = self::matching($sentences, static function (string $s): bool {
                preg_match_all('/' . self::WB . '(?:(?:mua|bán)\s+xe|người\s+mua|đặt\s+mua|giá\s+bán|trả\s+góp|buy(?:ing)?\s+(?:a\s+|the\s+|your\s+)?(?:new\s+|used\s+)?(?:bike|motorbike|scooter|motorcycle)|purchas(?:e|ing)\s+(?:a\s+|the\s+)?(?:bike|motorbike|scooter|motorcycle)|buyers?)' . self::WE . '/iu', $s, $m, PREG_OFFSET_CAPTURE);
                foreach ($m[0] as [$text, $offset]) {
                    // "không phải mua xe mà là thuê", "instead of buying a bike" state the difference correctly (run 39914553591).
                    // Last 16 characters before the match (byte slicing could split a Vietnamese letter).
                    $before = mb_substr(substr($s, 0, $offset), -16);
                    // "không bắt buộc phải mua xe" (run 39949306000): the negation is further away behind modal words.
                    $modal = preg_match('/' . self::WB . '(?:không|chẳng)(?:\s+(?:cần|phải|bắt\s+buộc|nhất\s+thiết)){1,3}\s+$/iu', mb_substr(substr($s, 0, $offset), -32));
                    if (!$modal && !preg_match('/' . self::WB . '(?:không|chẳng|thay\s+vì|instead\s+of|rather\s+than|not|no\s+need|without)' . self::WE . '/iu', $before)) {
                        return true;
                    }
                }
                return false;
            });
            $flag('rent-buy', array_slice($buy, 0, 3), 'Bài về THUÊ xe nhưng câu nói về mua/bán xe.');
            $check('factual', 'rent-buy', !$buy, count($buy) >= 3 ? 10 : 5, $buy
                ? sprintf('%d câu lẫn mua/bán xe trong bài về thuê xe.', count($buy))
                : 'Không lẫn mua/bán với thuê xe.');
        }

        $deposit = self::matching($sentences, static fn (string $s): bool => (bool) preg_match('/' . self::WB . '(?:(?:không\s+cần|miễn(?:\s+phí)?|không\s+phải|không\s+yêu\s+cầu|không\s+mất)\s+(?:tiền\s+)?(?:đặt\s+)?cọc|no\s+deposit|without\s+(?:a\s+)?deposit|deposit[- ]free)' . self::WE . '/iu', $s));
        // Only an affirmative brief ("Xe X không cần đặt cọc") allows it; "Không quảng cáo … không cần đặt cọc" does not.
        $no_deposit_re = '(?:không\s+cần\s+(?:đặt\s+)?cọc|miễn\s+cọc|no\s+deposit)';
        if ($deposit && preg_match('/' . $no_deposit_re . '/iu', $brief)
            && !preg_match('/(?:không|đừng|chớ|do\s+not|don\'t|never)\s+(?:được\s+)?(?:quảng\s+cáo|hứa|advertise|promise)[^.\n]{0,40}' . $no_deposit_re . '/iu', $brief)) {
            $deposit = [];
        }
        $flag('deposit', $deposit, 'Hứa "không cần cọc" khi brief không nói vậy.');
        $check('factual', 'no-deposit', !$deposit, 15, $deposit ? 'Bài hứa "không cần đặt cọc" nhưng brief không xác nhận.' : 'Không hứa miễn đặt cọc.');

        $brief_lower = mb_strtolower($brief);
        $claims = self::matching($sentences, static function (string $s) use ($brief_lower): bool {
            if (!preg_match('/' . self::WB . '(?:nổi\s+tiếng|famous|renowned|well-known|hàng\s+đầu|số\s+(?:1|một)|top\s*1|number\s+one|leading|giải\s+thưởng|award(?:-winning)?|thành\s+lập\s+(?:từ\s+|vào\s+)?năm|founded\s+in|since\s+(?:19|20)\d{2}|\d+\+?\s+năm\s+kinh\s+nghiệm|\d+\+?\s+years?\s+of\s+experience|hàng\s+(?:nghìn|ngàn|trăm|vạn)\s+khách|thousands\s+of\s+(?:customers|clients|riders)|(?:uy\s+tín|rẻ)\s+nhất|tốt\s+nhất\s+(?:hà\s+nội|việt\s+nam)|best\s+in\s+(?:hanoi|vietnam|town)|(?:cheapest|most\s+trusted))' . self::WE . '|#1/iu', $s, $m)) {
                return false;
            }
            return !str_contains($brief_lower, mb_strtolower($m[0]));
        });
        $flag('claim', $claims, 'Khẳng định không kiểm chứng (nổi tiếng, hàng đầu, số 1, thành lập năm, X năm kinh nghiệm…).');
        $check('factual', 'claims', !$claims, min(10, 5 * count($claims)), $claims
            ? sprintf('%d câu khẳng định không có trong brief (nổi tiếng/hàng đầu/số năm…).', count($claims))
            : 'Không có khẳng định quảng cáo không kiểm chứng.');

        $reviews = self::matching($sentences, static fn (string $s): bool => (bool) preg_match('/' . self::WB . '(?:khách\s+hàng\s+(?:đã\s+|thường\s+|luôn\s+)?(?:đánh\s+giá|nhận\s+xét|khen|phản\s+hồi\s+tích\s+cực)|\d(?:[.,]\d)?\s*(?:\/\s*5\s*)?sao|\d(?:\.\d)?\s*(?:\/\s*5\s*)?stars?|five-star|5-star|(?:customers|reviewers|clients|riders|travell?ers)\s+(?:say|said|rave|love|praise|rate)|testimonials?|rated\s+\d)' . self::WE . '/iu', $s));
        $flag('review', $reviews, 'Đánh giá/nhận xét khách hàng không có nguồn — không được tự tạo.');
        $check('factual', 'reviews', !$reviews, 10, $reviews ? sprintf('%d câu nêu đánh giá/sao của khách hàng không có nguồn.', count($reviews)) : 'Không tự tạo đánh giá khách hàng.');

        $sources = self::matching($sentences, static fn (string $s): bool => (bool) preg_match('/' . self::WB . '(?:theo\s+(?:một\s+|các\s+)?(?:nghiên\s+cứu|khảo\s+sát|thống\s+kê|báo\s+cáo|số\s+liệu|chuyên\s+gia)|(?:nghiên\s+cứu|khảo\s+sát|thống\s+kê)\s+(?:cho\s+thấy|chỉ\s+ra)|according\s+to\s+(?:a\s+|the\s+|recent\s+)*(?:study|survey|report|research|statistics|experts?|data)|(?:studies|research|surveys|statistics)\s+(?:show|shows|suggest|suggests|indicate|indicates))' . self::WE . '/iu', $s));
        $flag('source', $sources, 'Dẫn nghiên cứu/thống kê/chuyên gia không có nguồn thật.');
        $check('factual', 'sources', !$sources, 10, $sources ? sprintf('%d câu dẫn nghiên cứu/thống kê không có nguồn.', count($sources)) : 'Không dẫn nguồn không có thật.');

        $personal = self::matching($sentences, static fn (string $s): bool => (bool) preg_match('/' . self::WB . '(?:(?<!chúng\s)tôi\s+đã|(?<!chúng\s)tôi\s+(?:rất\s+|cảm\s+thấy\s+)?hài\s+lòng|chúng\s+tôi\s+đã\s+(?:từng\s+)?(?:thuê|đi|dùng|sử\s+dụng|thử|trải\s+nghiệm|chạy|lái|ghé|đến|tới|kiểm\s+tra)|^\W*mình\s+đã|mình\s+đã\s+(?:từng\s+)?(?:thuê|đi|dùng|sử\s+dụng|thử|trải\s+nghiệm|chạy|lái|ghé|đến|tới)|theo\s+kinh\s+nghiệm\s+của\s+(?:tôi|mình)|bản\s+thân\s+(?:tôi|mình)|i\s+have\s+(?:tried|rented|used|visited)|in\s+my\s+(?:own\s+)?experience|we\s+(?:have\s+)?tested)' . self::WE . '/iu', $s));
        $flag('experience', $personal, 'Kể trải nghiệm cá nhân — AI không có trải nghiệm thật.');
        $check('factual', 'experience', !$personal, 10, $personal ? sprintf('%d câu kể trải nghiệm cá nhân (AI không có trải nghiệm thật) — sửa hoặc xóa.', count($personal)) : 'Không bịa trải nghiệm cá nhân.');

        /* ---------- SEO ---------- */
        $needle = mb_strtolower(trim($keyword));
        if ($needle !== '') {
            $hits = mb_substr_count(mb_strtolower($markdown), $needle);
            $density = $count ? $hits * max(1, count(self::words($needle))) / $count : 0;
            if ($hits === 0) {
                $check('seo', 'keyword', false, 5, 'Từ khóa chính chưa xuất hiện.');
            } elseif ($density > 0.03) {
                $check('seo', 'keyword', false, 10, sprintf('Từ khóa lặp quá dày (%d lần, %.1f%%).', $hits, $density * 100));
            } else {
                $check('seo', 'keyword', true, 0, sprintf('Từ khóa xuất hiện %d lần.', $hits));
            }
            if ($title !== '') {
                $check('seo', 'keyword-title', str_contains(mb_strtolower($title), $needle), 0, str_contains(mb_strtolower($title), $needle) ? 'Tiêu đề chứa từ khóa chính.' : 'Tiêu đề chưa chứa nguyên văn từ khóa chính (nên cân nhắc).');
            }
        }
        if ($title !== '') {
            $length = mb_strlen($title);
            $check('seo', 'title-length', $length >= 20 && $length <= 70, 0, sprintf('Tiêu đề %d ký tự (nên khoảng 30–65 để không bị cắt trên Google).', $length));
        }
        $kw_headings = $needle === '' ? 0 : count(array_filter($h2_titles, static fn (string $h): bool => str_contains(mb_strtolower($h), $needle)));
        $prefixes = [];
        foreach ($h2_titles as $h) {
            // "*H2:" / "Phần 1:" labels are heading-format artefacts, not a keyword prefix.
            if (preg_match('/^([^:：]{3,60})[:：]\s*\S/u', $h, $pm) && !preg_match('/^[*#\s]*(?:h[1-6]|phần|mục|section|part)\b/iu', $pm[1])) {
                // Grouped by the first three words: the model varies the rest ("Thuê xe máy:", "Thuê xe máy tại Long Biên:").
                $p = implode(' ', array_slice(self::words(mb_strtolower(trim($pm[1]))), 0, 3));
                $prefixes[$p] = ($prefixes[$p] ?? 0) + 1;
            }
        }
        $shared_prefix = $prefixes ? max($prefixes) : 0;
        $stuffed = ($kw_headings >= 3 && $kw_headings * 2 > count($h2_titles)) || $shared_prefix >= 3;
        $check('seo', 'heading-stuffing', !$stuffed, 5, $stuffed
            ? sprintf('Tiêu đề H2 lặp từ khóa/tiền tố (%d H2 chứa từ khóa, %d H2 cùng tiền tố) — nhồi từ khóa.', $kw_headings, $shared_prefix)
            : 'H2 không nhồi từ khóa.');
        $similar = isset($context['similar']) && is_array($context['similar']) ? $context['similar'] : [];
        $max_content = 0.0;
        $near_titles = [];
        $close_posts = [];
        foreach ($similar as $row) {
            $content_sim = (float) ($row['content_similarity'] ?? 0);
            $max_content = max($max_content, $content_sim);
            if ($content_sim >= 0.3) {
                $close_posts[] = sprintf('#%d (%d%%)', (int) ($row['id'] ?? 0), (int) round($content_sim * 100));
            }
            if ((float) ($row['title_similarity'] ?? 0) >= 0.75) {
                $near_titles[] = '#' . (int) ($row['id'] ?? 0);
            }
        }
        if (array_key_exists('similar', $context)) {
            $check('seo', 'similar-content', $max_content < 0.3, $max_content >= 0.5 ? 20 : 10, $close_posts
                ? 'Nội dung trùng nhiều với bài đã có: ' . implode(', ', array_slice($close_posts, 0, 5)) . ' — nguy cơ nội dung trùng lặp.'
                : 'Không trùng nội dung đáng kể với bài đã có.');
            $check('seo', 'similar-title', !$near_titles, 5, $near_titles
                ? 'Tiêu đề gần trùng bài ' . implode(', ', array_slice($near_titles, 0, 5)) . ' — nguy cơ hai bài cạnh tranh cùng từ khóa.'
                : 'Không có tiêu đề gần trùng.');
        }

        $score = max(0, min(100, $score));
        $notes = [];
        $groups = [];
        foreach (array_keys(self::GROUPS) as $group) {
            $groups[$group] = ['penalty' => 0, 'failed' => 0, 'checks' => 0];
        }
        foreach ($checks as $item) {
            $groups[$item['group']]['checks']++;
            if (!$item['pass']) {
                $notes[] = $item['message'];
                $groups[$item['group']]['penalty'] += $item['penalty'];
                $groups[$item['group']]['failed']++;
            }
        }
        $notes[] = 'Điểm QA không xác minh sự thật, nguồn dẫn, luật pháp hoặc chính sách; mọi bài phải được biên tập viên kiểm chứng.';
        return [
            'score' => $score,
            'threshold' => self::THRESHOLD,
            'ready_for_review' => $score >= self::THRESHOLD,
            'word_count' => $count,
            'h2_count' => $h2,
            'checks' => $checks,
            'groups' => $groups,
            'unverified' => $unverified,
            'fact_check' => array_slice($facts, 0, 30),
            // Never "verified": the tool can only find suspicious statements, not prove the rest.
            'fact_status' => ($facts || $unverified) ? 'flagged' : 'not_verified',
            'editorial' => 'not_reviewed',
            'notes' => $notes,
        ];
    }

    /** Sentences (headings included) with list/heading markers removed. @return list<string> */
    public static function sentences(string $markdown): array {
        $out = [];
        foreach (preg_split('/(?<=[.!?…])\s+|\R+/u', $markdown) ?: [] as $sentence) {
            $sentence = trim(self::strip_markers($sentence));
            if ($sentence !== '') {
                $out[] = $sentence;
            }
        }
        return $out;
    }

    /** @return list<string> excerpts of the sentences the test accepts */
    private static function matching(array $sentences, callable $test): array {
        $out = [];
        foreach ($sentences as $sentence) {
            if ($test($sentence)) {
                $out[] = self::excerpt($sentence);
            }
        }
        return array_values(array_unique($out));
    }

    private static function excerpt(string $sentence): string {
        return mb_strlen($sentence) > 180 ? mb_substr($sentence, 0, 177) . '…' : $sentence;
    }

    /**
     * Petrol models described as electric. A list ("Wave, Vision và xe điện") or a contrast
     * ("không phải xe điện", "thay vì xe điện") is not a claim.
     *
     * @return list<string>
     */
    public static function petrol_called_electric(array $sentences): array {
        return self::matching($sentences, static function (string $s): bool {
            preg_match_all('/' . self::WB . '(?:' . self::PETROL_RE . ')' . self::WE . '/iu', $s, $petrol, PREG_OFFSET_CAPTURE);
            preg_match_all('/' . self::WB . '(?:' . self::ELECTRIC_RE . ')' . self::WE . '/iu', $s, $electric, PREG_OFFSET_CAPTURE);
            foreach ($petrol[0] as [$p, $po]) {
                foreach ($electric[0] as [$e, $eo]) {
                    $from = min($po + strlen($p), $eo + strlen($e));
                    $to = max($po, $eo);
                    $between = $to > $from ? substr($s, $from, $to - $from) : '';
                    if (strlen($between) > 80) {
                        continue;
                    }
                    // "50cc Scooters & Electric Bikes" (run 39962993674) lists two kinds of vehicle.
                    if (!preg_match('/[,;\/&+]|' . self::WB . '(?:và|hoặc|cùng|lẫn|đến|tới|sang|so\s+với|thay\s+vì|khác\s+với|không\s+phải|chứ\s+không|cũng\s+như|and|or|to|from|versus|vs|than|instead\s+of|unlike|not|as\s+well\s+as|plus)' . self::WE . '/iu', $between)) {
                        return true;
                    }
                }
            }
            return false;
        });
    }

    /** Money amount in VND-like units, or null. */
    private static function amount(string $number, string $unit): ?float {
        $unit = mb_strtolower(trim($unit));
        $multiplier = match ($unit) {
            'k', 'nghìn', 'ngàn', 'thousand' => 1000.0,
            'triệu', 'tr', 'million' => 1000000.0,
            'tỷ' => 1000000000.0,
            default => 1.0,
        };
        $number = trim($number, '.,');
        if ($multiplier > 1 && preg_match('/^\d+[.,]\d{1,2}$/', $number)) {
            $value = (float) str_replace(',', '.', $number); // "1,5 triệu"
        } else {
            $value = (float) preg_replace('/[^\d]/', '', $number);
        }
        return $number === '' ? null : $value * $multiplier;
    }

    /**
     * Amounts with their byte offsets. Ranges such as "2–3 triệu" give both ends the unit.
     *
     * @return list<array{0:float,1:int,2:int}> value, start offset, end offset of the number
     */
    private static function amounts(string $text, bool $require_unit): array {
        $re = '/(?<![\p{L}\p{N}.,])(\d[\d.,]*)(?:\s*[–-]\s*(\d[\d.,]*))?\s*(' . self::UNIT . ')?(?![\p{L}\p{N}])/iu';
        preg_match_all($re, $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($m as $match) {
            $unit = isset($match[3]) && $match[3][1] >= 0 ? $match[3][0] : '';
            if ($require_unit && $unit === '') {
                continue;
            }
            foreach ([1, 2] as $i) {
                if (isset($match[$i]) && $match[$i][1] >= 0 && $match[$i][0] !== '') {
                    $value = self::amount($match[$i][0], $unit);
                    if ($value !== null && ($unit !== '' || $value >= 1000)) {
                        $out[] = [$value, $match[$i][1], $match[$i][1] + strlen($match[$i][0])];
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Brief prices per item: "Honda Wave: 150.000đ/ngày, cọc 2–3 triệu" → ['wave' => [150000], 'cọc' => [2e6, 3e6]].
     * The item is the text before the first amount of a sentence (after any "label:"), minus
     * brand/generic words; amounts after "cọc"/"deposit" are deposits.
     *
     * @return array<string,list<float>>
     */
    public static function brief_prices(string $brief): array {
        $deposit_re = '/' . self::WB . '(?:cọc|deposits?)' . self::WE . '/iu';
        $map = [];
        foreach (preg_split('/(?<=[.!?;])\s+(?=[\p{Lu}\d])|\R/u', $brief) ?: [] as $segment) {
            $amounts = self::amounts($segment, false);
            if (!$amounts) {
                continue;
            }
            $names = self::item_names(substr($segment, 0, $amounts[0][1]));
            $deposit = false;
            $previous = 0;
            foreach ($amounts as [$value, $start, $end]) {
                $between = substr($segment, $previous, $start - $previous);
                if (preg_match($deposit_re, $between)) {
                    $deposit = true;
                } elseif ($previous > 0 && preg_match('/\p{L}/u', (string) preg_replace('/' . self::WB . '(?:' . trim(self::UNIT, '()?:') . '|ngày|tuần|tháng|day|week|month|từ|đến|tới|to|from|khoảng|và|hoặc|and|or)' . self::WE . '/iu', '', $between))) {
                    // A new item inside the same sentence: "…/day. 50cc scooter 200,000 VND/day".
                    $deposit = false;
                    $names = self::item_names($between) ?: $names;
                }
                foreach ($deposit ? ['cọc'] : $names as $name) {
                    $map[$name][] = $value;
                }
                $previous = $end;
            }
        }
        return $map;
    }

    /** "Giá tham khảo (…): Honda Click / Yamaha Mio" → ['click', 'mio']; brand and generic words are dropped. @return list<string> */
    private static function item_names(string $text): array {
        $generic = ['honda', 'yamaha', 'suzuki', 'piaggio', 'sym', 'xe', 'máy', 'tay', 'ga', 'số', 'giá', 'thuê', 'cho', 'loại', 'dòng', 'mẫu', 'the', 'a', 'an', 'price', 'prices', 'rent', 'rental', 'per', 'day', 'ngày', 'theo', 'từ', 'khoảng', 'tham', 'khảo', 'bike', 'bikes', 'scooter', 'scooters', 'motorbike', 'motorbikes', 'vnd', 'vnđ', 'đ'];
        $head = (string) preg_replace('/^.*[.!?]\s+/su', '', mb_strtolower(self::strip_markers($text)));
        // "Giá tham khảo (…): Honda Wave 150.000đ" → "honda wave"; "Honda Wave: 150.000đ" → "honda wave".
        $after_label = (string) preg_replace('/^.*[:：)]\s*/u', '', $head);
        $head = preg_match('/\p{L}/u', $after_label) ? $after_label : (string) preg_replace('/[\s:：]+$/u', '', $head);
        $names = [];
        foreach (preg_split('/\s*(?:\/|,|;|\||\bvà\b|\bhoặc\b|\bor\b|\band\b)\s*/u', $head) ?: [] as $name) {
            $kept = array_values(array_filter(self::words($name), static fn (string $w): bool => !in_array($w, $generic, true)));
            if ($kept && count($kept) <= 3) {
                $names[] = implode(' ', $kept);
            }
        }
        return $names;
    }

    /** Sentences that tie an item from the brief to an amount the brief does not give for it. @return list<string> */
    public static function price_mismatches(array $sentences, string $brief): array {
        $prices = self::brief_prices($brief);
        unset($prices['cọc']); // Deposit amounts are covered by the "unverified" check.
        if (!$prices) {
            return [];
        }
        $out = [];
        foreach ($sentences as $sentence) {
            $lower = mb_strtolower($sentence);
            $mentions = [];
            foreach (array_keys($prices) as $name) {
                $pattern = '/' . self::WB . preg_replace('/\s+/u', '\s*', preg_quote((string) $name, '/')) . self::WE . '/iu';
                if (preg_match_all($pattern, $lower, $mm, PREG_OFFSET_CAPTURE)) {
                    foreach ($mm[0] as [$text, $offset]) {
                        $mentions[] = [$name, $offset + strlen($text), $offset];
                    }
                }
            }
            if (!$mentions) {
                continue;
            }
            foreach (self::amounts($lower, true) as [$value, $offset, $amount_end]) {
                // "a 3 million VND deposit", "4–5 triệu tiền cọc" (run 39950921303): a deposit, not a model price.
                if (preg_match('/^(?:\s*[–-]\s*\d[\d.,]*)?(?:\s*' . self::UNIT . '){0,2}\s*(?:tiền\s+)?(?:đặt\s+)?(?:cọc|deposits?)' . self::WE . '/iu', substr($lower, $amount_end, 48))) {
                    continue;
                }
                // "200.000 đồng/ngày cho xe Honda Vision": the amount names the item that follows it.
                $best = null;
                foreach ($mentions as [$name, $end, $start]) {
                    if ($start >= $amount_end && $start - $amount_end <= 60
                        && preg_match('/^\s*' . self::UNIT . '?\s*(?:\/\s*(?:ngày|tuần|tháng|day|week|month)\s*)?(?:cho|for)\s+(?:\p{L}+\s+){0,3}$/iu', substr($lower, $amount_end, $start - $amount_end))
                        && ($best === null || $start < $best[2])) {
                        $best = [$name, $start, $start];
                    }
                }
                if ($best !== null && preg_match('/' . self::WB . '(?:cọc|deposits?)' . self::WE . '/iu', mb_substr(substr($lower, 0, $offset), -24))) {
                    continue;
                }
                if ($best === null) {
                    foreach ($mentions as [$name, $end, $start]) {
                        if ($end <= $offset && $offset - $end <= 80 && ($best === null || $end > $best[1])) {
                            $best = [$name, $end, $start];
                        }
                    }
                    if ($best === null) {
                        continue;
                    }
                    $between = substr($lower, $best[1], $offset - $best[1]);
                    if (preg_match('/' . self::WB . '(?:cọc|deposits?)' . self::WE . '/iu', $between)) {
                        continue;
                    }
                }
                $known = false;
                foreach ($prices[$best[0]] as $price) {
                    $known = $known || abs($price - $value) < 1;
                }
                if (!$known) {
                    $out[] = self::excerpt($sentence);
                    break;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** @return list<string> */
    public static function unverified_specifics(string $markdown, string $brief): array {
        $patterns = [
            '/(?<![\p{L}\p{N}])\d[\d.,]*\s*' . self::UNIT . '(?![\p{L}\p{N}])/iu',
            '/(?:\$|€|£)\s?\d[\d.,]*/u',
            '/(?<![\p{N}])(?:\+?84|0)(?:[\s.-]?\d){8,10}(?![\p{N}])/u',
            '/\d+(?:[.,]\d+)?\s?%/u',
            '/https?:\/\/[^\s)\]]+|www\.[^\s)\]]+/iu',
            '/[\w.+-]+@[\w-]+\.[\w.-]+/u',
            // Long bare numbers (ids, timestamps, account numbers) copied or invented by the model.
            '/(?<![\p{N}.,])\d{9,}(?![\p{N}])/u',
            // Years ("thành lập năm 2015", "since 2018").
            '/(?<![\p{N}.,])(?:19|20)\d{2}(?![\p{N}]|[.,]\d)/u',
        ];
        $digits = static function (string $s): string {
            $d = preg_replace('/\D+/', '', $s);
            return strlen($d) >= 10 && str_starts_with($d, '84') ? '0' . substr($d, 2) : $d;
        };
        $brief_lower = mb_strtolower($brief);
        // Numbers as written ("150.000") plus space-grouped phone numbers ("0942 467 674").
        preg_match_all('/\d[\d.,]*/u', $brief, $plain);
        preg_match_all('/\+?\d+(?: \d{2,4}){1,4}/u', $brief, $grouped);
        $brief_numbers = array_flip(array_map($digits, array_merge($plain[0], $grouped[0])));
        $found = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $markdown, $m);
            foreach ($m[0] as $value) {
                $value = trim($value, " .,;:");
                $d = $digits($value);
                $in_brief = $d !== '' && !preg_match('/^(?:https?:|www\.|[\w.+-]+@)/iu', $value)
                    ? isset($brief_numbers[$d])
                    : str_contains($brief_lower, mb_strtolower($value));
                if (!$in_brief) {
                    $found[$value] = true;
                }
            }
        }
        // array_keys() turns numeric-string keys into ints; keep every value a string.
        return array_map('strval', array_keys($found));
    }
}
