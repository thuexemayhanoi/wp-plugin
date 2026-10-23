<?php
/**
 * Deterministic technical QA (0–100). It measures structure and risk signals only;
 * it does NOT verify facts, sources, legal accuracy or ranking potential.
 *
 * @package QwenLocalWriter
 */
namespace QLW;

defined('ABSPATH') || exit;

final class Quality {
    public const THRESHOLD = 70;
    private const WORD_RE = "/[\p{L}\p{N}]+(?:[’'-][\p{L}\p{N}]+)*/u";
    private const VI_CHARS = 'àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ';
    private const FOREIGN_RE = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Cyrillic}\p{Thai}\p{Arabic}]/u';
    private const UNSAFE_RE = '/<\s*(?:script|iframe|style|form|svg|object|embed)\b|<\?php|javascript\s*:|\bon[a-z]+\s*=\s*["\']/iu';

    /** Mirrors META_LINE_RE in assets/qlw-core.js. */
    private const META_RE = '/^(?:đoạn|phần)\s+(?:văn|mở bài|mở đầu|giới thiệu|kết luận|kết|này|tiếp theo)\b.{0,160}\b(?:như sau|sau đây|tóm tắt|nhấn mạnh|gồm có|sẽ (?:giới thiệu|trình bày|nói))\b|(?:có thể|được|sẽ) (?:được )?viết (?:lại )?như sau\s*[:：]?\s*$|^\(?\s*(?:[^()\n]{0,40}\s)?\d+\s*(?:từ|words?)\b[^()\n]{0,30}\)?\.?$|^(?:đề xuất|gợi ý)\s+thêm\s+(?:một\s+)?danh sách|^(?:this|the)\s+(?:section|paragraph|introduction|conclusion|article)\s+(?:will|should|can|summari[sz]es|highlights)\b|^(?:here is|here\'s|below is|sure[,!])\b|^(?:word count|note)\s*[:：]|(?:^|[,;]\s*)(?:đây là|sau đây là|dưới đây là)\s+(?:nội dung|đoạn|phần|bài)\b[^\n]{0,80}[:：]\s*$|(?:^|[,;]\s*)here (?:is|are) (?:the|a|an|my)\s[^\n]{0,80}[:：]\s*$/iu';

    public static function strip_markers(string $line): string {
        $value = str_replace(['**', '__', '`'], '', $line);
        do {
            $previous = $value;
            $value = preg_replace(['/^\s*(?:#{1,6}|[-*+•>]|\(?\d{1,2}[.):]|\(?[ivx]{1,4}[.)])\s*/iu', '/^\s*(?:h[1-6]|tiêu đề|heading|title)\s*[:.-]\s*/iu', '/^\s*(?:phần|mục|section|part)\s+\d+\s*[:.-]\s*/iu'], '', $value);
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

    /**
     * @param string $brief Verified facts supplied by the editor; specifics not found here are flagged.
     */
    public static function assess(string $markdown, int $target = 1500, string $language = 'vi', string $brief = '', string $keyword = ''): array {
        $target = max(500, min(3000, $target));
        $language = $language === 'en' ? 'en' : 'vi';
        $words = self::words($markdown);
        $count = count($words);
        $score = 100;
        $checks = [];
        $check = static function (string $id, bool $pass, int $penalty, string $message) use (&$score, &$checks): void {
            if (!$pass) {
                $score -= $penalty;
            }
            $checks[] = ['id' => $id, 'pass' => $pass, 'penalty' => $pass ? 0 : $penalty, 'message' => $message];
        };

        // 1. Length.
        if ($count < $target * 0.5) {
            $check('length', false, 35, sprintf('Chỉ %d từ, chưa bằng một nửa mục tiêu %d.', $count, $target));
        } elseif ($count < $target * 0.8) {
            $check('length', false, 20, sprintf('%d từ, chưa đạt 80%% mục tiêu %d.', $count, $target));
        } elseif ($count > $target * 1.4) {
            $check('length', false, 10, sprintf('%d từ, dài hơn đáng kể mục tiêu %d.', $count, $target));
        } else {
            $check('length', true, 0, sprintf('%d từ (mục tiêu ~%d).', $count, $target));
        }

        // 2. Structure: H2 sections, no H1, no empty sections.
        $h2 = preg_match_all('/^##\s+\S/mu', $markdown);
        $check('h2', $h2 >= 4, 20, sprintf('%d tiêu đề H2 (cần ≥ 4).', $h2));
        $check('no-h1', !preg_match('/^#(?!#)\s+\S/mu', $markdown), 15, 'Không có H1 trong thân bài (theme hiển thị tiêu đề bài là H1).');
        $thin = 0;
        foreach (preg_split('/^##\s+.*$/mu', $markdown) ?: [] as $i => $body) {
            if ($i > 0 && count(self::words($body)) < 60) {
                $thin++;
            }
        }
        $check('thin-sections', $thin === 0, min(15, 5 * $thin), $thin ? sprintf('%d phần H2 có dưới 60 từ.', $thin) : 'Mỗi phần H2 có nội dung.');

        // Model artifacts in headings, repeated headings, and sections bleeding into each other.
        preg_match_all('/^(#{2,3})\s+(.+)$/mu', $markdown, $hm);
        $bad_headings = array_filter($hm[2], static fn (string $h): bool => (bool) preg_match('/\*|_{2}|\bH[1-6]\s*[:.-]|[:：]\s*$|^\d+(?:st|nd|rd|th)\s+(?:section|part)\b|^(?:phần|mục)\s+(?:thứ\s+)?\S+\s*[:：]/iu', $h));
        $check('heading-format', !$bad_headings, 10, $bad_headings ? 'Tiêu đề còn ký tự rác của model (*, "H2:", dấu hai chấm cuối): ' . implode(' | ', array_slice($bad_headings, 0, 3)) : 'Tiêu đề sạch.');
        $heading_keys = array_map([self::class, 'key'], $hm[2]);
        $dup_headings = count($heading_keys) - count(array_unique($heading_keys));
        $check('dup-heading', $dup_headings === 0, 10, $dup_headings ? sprintf('%d tiêu đề bị lặp.', $dup_headings) : 'Không lặp tiêu đề.');
        $h2_keys = [];
        foreach ($hm[1] as $i => $level) {
            if ($level === '##' && mb_strlen($heading_keys[$i]) >= 12) {
                $h2_keys[] = $heading_keys[$i];
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
            $pseudo = preg_match('/^(?:\d{1,2}[.)]\s+)?\*\*[^*]{3,120}\*\*\s*[:：]?$|^\**\s*H[1-6]\s*[:.-]/iu', $line);
            if ($pseudo) {
                $key = self::key($plain);
                foreach ($h2_keys as $h) {
                    if ($key === $h || (mb_strlen($key) >= 12 && (str_contains($key, $h) || str_contains($h, $key)))) {
                        $bleed++;
                        break;
                    }
                }
            }
        }
        $check('bleed', $bleed === 0, 15, $bleed ? sprintf('%d dòng lặp lại tiêu đề mục khác trong thân bài (các mục bị trộn lẫn).', $bleed) : 'Các mục không bị trộn lẫn.');
        $check('meta', $meta === 0, 15, $meta ? sprintf('%d câu model tự mô tả việc viết ("đoạn kết luận có thể viết như sau", "(… từ)", "here is…") cần xóa.', $meta) : 'Không có câu mô tả yêu cầu/số từ.');

        // 3. Safety and leftovers.
        $check('safe', !self::is_unsafe($markdown), 70, 'Không có HTML/mã nguy hiểm.');
        $check('placeholder', !preg_match('/\b(?:TODO|TBD|lorem ipsum|as an ai(?: language model)?|let me know if|feel free to ask|i hope this helps|tôi là (?:một )?(?:mô hình|trợ lý) (?:ngôn ngữ|ai))\b|\[(?:điền|insert|placeholder|tên)[^\]]{0,40}\]|<\/?think>/iu', $markdown), 25, 'Không có placeholder, câu trả lời khuôn mẫu hoặc thẻ <think>.');

        // 4. Repetition: identical long paragraphs and repeated sentences.
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
        $check('dup-paragraph', !$dup_para, 20, 'Không lặp nguyên văn đoạn văn.');
        $sentences = array_filter(array_map(static function (string $s): string {
            return mb_strtolower(trim(preg_replace('/[^\p{L}\p{N} ]+/u', '', $s)));
        }, preg_split('/(?<=[.!?…])\s+|\R+/u', $markdown) ?: []), static fn (string $s): bool => count(explode(' ', $s)) >= 6);
        $total_sentences = count($sentences);
        $repeated = $total_sentences - count(array_unique($sentences));
        $ratio = $total_sentences ? $repeated / $total_sentences : 0;
        $check('dup-sentence', $ratio <= 0.05, $ratio > 0.10 ? 15 : 8, sprintf('Câu lặp lại: %d/%d.', $repeated, $total_sentences));

        // 5. Language: Qwen small models can drift into Chinese or the wrong language.
        preg_match_all(self::FOREIGN_RE, $markdown, $foreign);
        $foreign_count = count($foreign[0]);
        $check('script', $foreign_count === 0, $foreign_count > 5 ? 25 : 10, $foreign_count ? sprintf('%d ký tự chữ Hán/ngôn ngữ khác.', $foreign_count) : 'Không lẫn chữ Hán hoặc ngôn ngữ khác.');
        $vi_words = 0;
        foreach ($words as $word) {
            if (preg_match('/[' . self::VI_CHARS . ']/iu', $word)) {
                $vi_words++;
            }
        }
        $vi_ratio = $count ? $vi_words / $count : 0;
        if ($language === 'vi') {
            $check('language', $count === 0 || $vi_ratio >= 0.25, 25, sprintf('Tỷ lệ từ có dấu tiếng Việt %d%% (cần ≥ 25%%).', (int) round($vi_ratio * 100)));
        } else {
            $check('language', $vi_ratio <= 0.03, 25, sprintf('Tỷ lệ từ tiếng Việt có dấu %d%% (bài tiếng Anh cần ≤ 3%%).', (int) round($vi_ratio * 100)));
        }

        // 6. Specifics that are not in the verified brief: prices, phone numbers, percentages, links, emails.
        $unverified = self::unverified_specifics($markdown, $brief);
        $check('unverified', !$unverified, min(20, 5 * count($unverified)), $unverified
            ? 'Chi tiết cụ thể không có trong brief, cần kiểm tra/xóa: ' . implode(', ', array_slice($unverified, 0, 8))
            : 'Không có giá, số điện thoại, %, link hoặc email ngoài brief.');

        // 7. First-person experience claims: a model has no real experience to report.
        $personal = preg_match_all('/\b(?:tôi đã|chúng tôi đã|theo kinh nghiệm của tôi|bản thân tôi|i have (?:tried|rented|used|visited)|in my (?:own )?experience|we (?:have )?tested)\b/iu', $markdown);
        $check('experience', $personal === 0, 10, $personal ? sprintf('%d câu kể trải nghiệm cá nhân (AI không có trải nghiệm thật) — sửa hoặc xóa.', $personal) : 'Không bịa trải nghiệm cá nhân.');

        // 8. Keyword presence and stuffing (only when a keyword is given).
        if ($keyword !== '') {
            $needle = mb_strtolower(trim($keyword));
            $hits = $needle === '' ? 0 : mb_substr_count(mb_strtolower($markdown), $needle);
            $density = $count ? $hits * max(1, count(self::words($needle))) / $count : 0;
            if ($hits === 0) {
                $check('keyword', false, 5, 'Từ khóa chính chưa xuất hiện.');
            } elseif ($density > 0.03) {
                $check('keyword', false, 10, sprintf('Từ khóa lặp quá dày (%d lần).', $hits));
            } else {
                $check('keyword', true, 0, sprintf('Từ khóa xuất hiện %d lần.', $hits));
            }
        }

        $score = max(0, min(100, $score));
        $notes = [];
        foreach ($checks as $item) {
            if (!$item['pass']) {
                $notes[] = $item['message'];
            }
        }
        $notes[] = 'QA kỹ thuật không xác minh sự thật, nguồn dẫn, luật pháp hoặc chính sách; phải biên tập thủ công.';
        return [
            'score' => $score,
            'threshold' => self::THRESHOLD,
            'ready_for_review' => $score >= self::THRESHOLD,
            'word_count' => $count,
            'h2_count' => $h2,
            'checks' => $checks,
            'unverified' => $unverified,
            'notes' => $notes,
        ];
    }

    /** @return list<string> */
    public static function unverified_specifics(string $markdown, string $brief): array {
        $patterns = [
            '/(?<![\p{L}\p{N}])\d[\d.,]*\s*(?:đ|đồng|vnđ|vnd|k|nghìn|ngàn|triệu|tr|tỷ|usd|\$|€)(?![\p{L}\p{N}])/iu',
            '/(?:\$|€|£)\s?\d[\d.,]*/u',
            '/(?<![\p{N}])(?:\+?84|0)(?:[\s.-]?\d){8,10}(?![\p{N}])/u',
            '/\d+(?:[.,]\d+)?\s?%/u',
            '/https?:\/\/[^\s)\]]+|www\.[^\s)\]]+/iu',
            '/[\w.+-]+@[\w-]+\.[\w.-]+/u',
            // Long bare numbers (ids, timestamps, account numbers) copied or invented by the model.
            '/(?<![\p{N}.,])\d{9,}(?![\p{N}])/u',
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
