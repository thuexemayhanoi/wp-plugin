<?php
/** Offline tests for QLW\Markdown (escaping, H2–H3 clamp, lists, block markup). */
require __DIR__ . '/stubs.php';
require __DIR__ . '/../includes/class-markdown.php';

use QLW\Markdown;

$md = "Mở bài có **chữ đậm** và *nghiêng*.\n\n# H1 lạc vào\n\n## Phần hai\n\nĐoạn một\nnối dòng.\n\n### Ý phụ\n\n#### Quá sâu\n\n- Mục một\n- Mục **hai**\n\n1. Bước một\n2) Bước hai\n\n---\n\n> Trích dẫn\n\n<script>alert(1)</script>\n\n[Website](https://thuexemaynguyentu.com/) và [xấu](javascript:alert(1)) và snake_case_name.";
$blocks = Markdown::to_blocks($md);
$html = Markdown::to_html($md);

check(!str_contains($blocks, '<h1') && !str_contains($html, '<h1'), 'never outputs H1');
check(str_contains($blocks, '<h2 class="wp-block-heading">H1 lạc vào</h2>'), 'H1 is demoted to H2');
check(str_contains($blocks, '<!-- wp:heading {"level":3} -->') && str_contains($blocks, '<h3 class="wp-block-heading">Quá sâu</h3>'), 'H4+ is clamped to H3');
check(substr_count($blocks, '<!-- wp:heading') === 4, 'four heading blocks');
check(str_contains($blocks, '<p>Đoạn một nối dòng.</p>'), 'soft line breaks join a paragraph');
check(str_contains($blocks, '<strong>chữ đậm</strong>') && str_contains($blocks, '<em>nghiêng</em>'), 'bold and italic');
check(str_contains($blocks, '<!-- wp:list -->') && str_contains($blocks, '<ul class="wp-block-list"><!-- wp:list-item -->'), 'bullet list block');
check(str_contains($blocks, '<!-- wp:list {"ordered":true} -->') && substr_count($blocks, '<li>Bước') === 2, 'ordered list block');
check(str_contains($blocks, '<li>Mục <strong>hai</strong></li>'), 'inline formatting inside list items');
check(!str_contains($blocks, '<script') && str_contains($blocks, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'raw HTML is escaped, not executed');
check(str_contains($blocks, '<a href="https://thuexemaynguyentu.com/">Website</a>'), 'https links are kept');
check(!str_contains($blocks, 'javascript:alert(1)">') && !str_contains($blocks, 'href="javascript'), 'javascript: links are not linked');
check(str_contains($blocks, 'snake_case_name'), 'underscores inside words are not italicised');
check(!str_contains($blocks, '---'), 'horizontal rules are dropped');
check(str_contains($blocks, '<p>Trích dẫn</p>'), 'blockquote marker removed');
$quote = Markdown::to_blocks('Giá "đặc biệt" & <b>đậm</b>');
check(str_contains($quote, '&quot;đặc biệt&quot; &amp; &lt;b&gt;đậm&lt;/b&gt;'), 'quotes, ampersands and tags are escaped');
check(Markdown::to_blocks('') === '', 'empty input gives empty output');
check(count(Markdown::parse(str_repeat("a\n", 200000))) === 1, 'input is truncated to MAX_CHARS safely');

finish('Markdown');
