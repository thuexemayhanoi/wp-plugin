<?php
/**
 * Conservative Markdown → WordPress block markup.
 *
 * Supports paragraphs, H2–H3 (H1 is demoted: the theme prints the post title as H1),
 * bullet/numbered lists, **bold**, *italic* and http(s) links. Everything else is
 * escaped text, so model output can never inject HTML or scripts.
 *
 * @package QwenLocalWriter
 */
namespace QLW;

defined('ABSPATH') || exit;

final class Markdown {
    public const MAX_CHARS = 250000;

    /** @return list<array{type:string,level?:int,ordered?:bool,text?:string,items?:list<string>}> */
    public static function parse(string $markdown): array {
        $markdown = str_replace(["\r\n", "\r"], "\n", mb_substr($markdown, 0, self::MAX_CHARS));
        $blocks = [];
        $para = [];
        $list = null;
        $flush_para = static function () use (&$para, &$blocks): void {
            if ($para) {
                $blocks[] = ['type' => 'paragraph', 'text' => implode(' ', $para)];
                $para = [];
            }
        };
        $flush_list = static function () use (&$list, &$blocks): void {
            if ($list) {
                $blocks[] = $list;
                $list = null;
            }
        };
        foreach (explode("\n", $markdown) as $raw) {
            $line = trim($raw);
            if ($line === '' || preg_match('/^(?:```|~~~)/u', $line) || preg_match('/^(?:-{3,}|\*{3,}|_{3,})$/u', $line)) {
                $flush_para();
                $flush_list();
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/u', $line, $m)) {
                $flush_para();
                $flush_list();
                $level = max(2, min(3, strlen($m[1])));
                $blocks[] = ['type' => 'heading', 'level' => $level, 'text' => trim($m[2], " \t*")];
                continue;
            }
            if (preg_match('/^(?:[-*+•]|(\d{1,3})[.)])\s+(.+)$/u', $line, $m)) {
                $flush_para();
                $ordered = $m[1] !== '';
                if ($list && $list['ordered'] !== $ordered) {
                    $flush_list();
                }
                $list = $list ?: ['type' => 'list', 'ordered' => $ordered, 'items' => []];
                $list['items'][] = $m[2];
                continue;
            }
            $flush_list();
            $para[] = preg_replace('/^>\s?/u', '', $line);
        }
        $flush_para();
        $flush_list();
        return $blocks;
    }

    public static function inline(string $text): string {
        $links = [];
        // Links are extracted first so their URL is never touched by emphasis rules.
        $text = preg_replace_callback('/\[([^\]\n]{1,200})\]\((https?:\/\/[^\s)]{1,2000})\)/u', static function (array $m) use (&$links): string {
            $links[] = [$m[1], $m[2]];
            return "\u{E000}" . (count($links) - 1) . "\u{E001}";
        }, $text);
        $html = self::emphasis(esc_html($text));
        return preg_replace_callback('/\x{E000}(\d+)\x{E001}/u', static function (array $m) use ($links): string {
            [$label, $url] = $links[(int) $m[1]];
            $safe = esc_url($url, ['http', 'https']);
            $label_html = self::emphasis(esc_html($label));
            return $safe ? '<a href="' . esc_attr($safe) . '">' . $label_html . '</a>' : $label_html;
        }, $html);
    }

    private static function emphasis(string $html): string {
        $html = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $html);
        $html = preg_replace('/(?<![\*\w])\*(?=\S)([^*\n]+?)(?<=\S)\*(?![\*\w])/u', '<em>$1</em>', $html);
        return preg_replace('/(?<![_\w])_(?=\S)([^_\n]+?)(?<=\S)_(?![_\w])/u', '<em>$1</em>', $html);
    }

    /** Serialized Gutenberg blocks (core/paragraph, core/heading, core/list). */
    public static function to_blocks(string $markdown): string {
        $out = [];
        foreach (self::parse($markdown) as $block) {
            if ($block['type'] === 'heading') {
                $attrs = $block['level'] === 2 ? '' : ' {"level":' . $block['level'] . '}';
                $tag = 'h' . $block['level'];
                $out[] = "<!-- wp:heading{$attrs} -->\n<{$tag} class=\"wp-block-heading\">" . self::inline($block['text']) . "</{$tag}>\n<!-- /wp:heading -->";
            } elseif ($block['type'] === 'list') {
                $tag = $block['ordered'] ? 'ol' : 'ul';
                $attrs = $block['ordered'] ? ' {"ordered":true}' : '';
                $items = '';
                foreach ($block['items'] as $item) {
                    $items .= "<!-- wp:list-item -->\n<li>" . self::inline($item) . "</li>\n<!-- /wp:list-item -->";
                }
                $out[] = "<!-- wp:list{$attrs} -->\n<{$tag} class=\"wp-block-list\">{$items}</{$tag}>\n<!-- /wp:list -->";
            } else {
                $out[] = "<!-- wp:paragraph -->\n<p>" . self::inline($block['text']) . "</p>\n<!-- /wp:paragraph -->";
            }
        }
        return wp_kses_post(implode("\n\n", $out));
    }

    /** Plain HTML for themes/editors that do not use blocks. */
    public static function to_html(string $markdown): string {
        $out = [];
        foreach (self::parse($markdown) as $block) {
            if ($block['type'] === 'heading') {
                $out[] = '<h' . $block['level'] . '>' . self::inline($block['text']) . '</h' . $block['level'] . '>';
            } elseif ($block['type'] === 'list') {
                $tag = $block['ordered'] ? 'ol' : 'ul';
                $out[] = "<{$tag}><li>" . implode('</li><li>', array_map([self::class, 'inline'], $block['items'])) . "</li></{$tag}>";
            } else {
                $out[] = '<p>' . self::inline($block['text']) . '</p>';
            }
        }
        return wp_kses_post(implode("\n", $out));
    }
}
