<?php
/** Lightweight Markdown support for articles generated in simple mode. */
namespace MASF;
defined( 'ABSPATH' ) || exit;

final class Markdown {
	/** Render a conservative Markdown subset; never execute embedded HTML. */
	public static function render( $source ) {
		$source = str_replace( array( "\r\n", "\r" ), "\n", (string) $source );
		$source = mb_substr( $source, 0, 750000 );
		$blocks = array(); $para = array(); $items = array(); $code = array(); $fenced = false;
		$flush_para = function () use ( &$para, &$blocks ) {
			if ( $para ) { $blocks[] = '<p>' . self::inline( implode( ' ', $para ) ) . '</p>'; $para = array(); }
		};
		$flush_list = function () use ( &$items, &$blocks ) {
			if ( $items ) { $blocks[] = '<ul><li>' . implode( '</li><li>', $items ) . '</li></ul>'; $items = array(); }
		};
		foreach ( explode( "\n", $source ) as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^```/', $line ) ) {
				$flush_para(); $flush_list();
				if ( $fenced ) { $blocks[] = '<pre><code>' . esc_html( implode( "\n", $code ) ) . '</code></pre>'; $code = array(); }
				$fenced = ! $fenced; continue;
			}
			if ( $fenced ) { $code[] = $line; continue; }
			if ( '' === $line ) { $flush_para(); $flush_list(); continue; }
			if ( preg_match( '/^(#{2,6})\s+(.+)$/u', $line, $m ) ) {
				$flush_para(); $flush_list(); $level = strlen( $m[1] );
				$blocks[] = '<h' . $level . '>' . self::inline( $m[2] ) . '</h' . $level . '>'; continue;
			}
			if ( preg_match( '/^[-*+]\s+(.+)$/u', $line, $m ) ) {
				$flush_para(); $items[] = self::inline( $m[1] ); continue;
			}
			if ( preg_match( '/^\d+[.)]\s+(.+)$/u', $line, $m ) ) {
				$flush_para(); $items[] = self::inline( $m[1] ); continue;
			}
			$flush_list(); $para[] = $line;
		}
		$flush_para(); $flush_list();
		if ( $fenced && $code ) { $blocks[] = '<pre><code>' . esc_html( implode( "\n", $code ) ) . '</code></pre>'; }
		return wp_kses( implode( "\n", $blocks ), QA::allowed_html() );
	}
	private static function inline( $text ) {
		$s = esc_html( $text );
		$s = preg_replace_callback( '/\[([^\]\n]{1,200})\]\((https?:\/\/[^\s)]+)\)/u', function ( $m ) {
			$url = esc_url( html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' ), array( 'https', 'http' ) );
			return $url ? '<a href="' . esc_attr( $url ) . '" rel="noopener noreferrer">' . $m[1] . '</a>' : $m[1];
		}, $s );
		$s = preg_replace( '/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s );
		$s = preg_replace( '/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '<em>$1</em>', $s );
		$s = preg_replace( '/`([^`\n]+)`/u', '<code>$1</code>', $s );
		return $s;
	}
	/** Show the original Markdown where the editor can find it. */
	public static function metabox() {
		add_meta_box( 'masf-markdown', '📝 Markdown gốc của bài AI', array( __CLASS__, 'show' ), 'post', 'normal', 'default' );
	}
	public static function show( $post ) {
		$markdown = get_post_meta( $post->ID, '_masf_markdown', true );
		if ( ! $markdown ) { echo '<p>Bài này chưa có Markdown do AI Content Factory tạo.</p>'; return; }
		echo '<p>Đây là bản Markdown nguồn. Nội dung hiển thị cho khách đã chuyển sang HTML an toàn phía trên. Có thể sao chép Markdown để sửa thủ công.</p>';
		echo '<textarea readonly rows="18" style="width:100%;font-family:monospace" aria-label="Markdown gốc">' . esc_textarea( $markdown ) . '</textarea>';
	}
}
