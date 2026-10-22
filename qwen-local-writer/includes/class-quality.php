<?php
/**
 * Deterministic technical checks; these scores do NOT verify factual accuracy.
 * @package QwenLocalWriter
 */
namespace QLW;

defined('ABSPATH') || exit;

final class Quality {
    public static function assess(string $markdown, int $target = 1500): array {
        $target = max(500, min(2500, $target));
        preg_match_all("/[\p{L}\p{N}]+(?:[’'-][\p{L}\p{N}]+)*/u", $markdown, $matches);
        $words = count($matches[0]);
        $score = 100;
        $notes = [];
        $penalize = static function (int $points, string $message) use (&$score, &$notes): void {
            $score -= $points;
            $notes[] = $message;
        };
        if ($words < (int) ($target * 0.50)) {
            $penalize(35, 'Bài ngắn hơn một nửa độ dài mục tiêu.');
        } elseif ($words < (int) ($target * 0.80)) {
            $penalize(20, 'Bài chưa đạt 80% độ dài mục tiêu.');
        } elseif ($words > (int) ($target * 1.40)) {
            $penalize(10, 'Bài dài hơn đáng kể so với mục tiêu.');
        }
        $h2 = preg_match_all('/^##\s+\S/mu', $markdown);
        if ($h2 < 4) {
            $penalize(20, 'Cần ít nhất bốn H2 có nội dung hữu ích.');
        }
        if (preg_match('/^#(?!#)\s+\S/mu', $markdown)) {
            $penalize(15, 'Không thêm H1 vào thân bài, theme WordPress đảm nhiệm H1.');
        }
        if (preg_match('/\b(?:TODO|TBD|lorem ipsum|as an ai language model)\b|\[(?:điền|insert|placeholder)/iu', $markdown)) {
            $penalize(25, 'Có nội dung placeholder hoặc câu trả lời khuôn mẫu.');
        }
        if (preg_match('/<\s*(?:script|iframe|style|form|svg)\b|<\?php|javascript\s*:/iu', $markdown)) {
            $penalize(70, 'Phát hiện mã hoặc HTML không được phép.');
        }
        $paragraphs = preg_split('/\R\s*\R/u', $markdown) ?: [];
        $seen = [];
        foreach ($paragraphs as $paragraph) {
            $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $paragraph)));
            if (mb_strlen($normalized) < 100) {
                continue;
            }
            if (isset($seen[$normalized])) {
                $penalize(20, 'Phát hiện đoạn văn lặp nguyên văn.');
                break;
            }
            $seen[$normalized] = true;
        }
        $score = max(0, min(100, $score));
        $notes[] = 'QA kỹ thuật không xác minh sự thật, nguồn dẫn, luật pháp hoặc chính sách; phải biên tập thủ công.';
        return [
            'score' => $score,
            'threshold' => 70,
            'ready_for_review' => $score >= 70,
            'word_count' => $words,
            'h2_count' => $h2,
            'notes' => $notes,
        ];
    }
}
