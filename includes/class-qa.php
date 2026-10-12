<?php
/** Deterministic technical/SEO checks; factual checks are bounded, never a truth guarantee. *
 *
 * @package MultiAISEOContentFactory
 */

namespace MASF;

defined( 'ABSPATH' ) || exit;

/** QA implementation for the factory workflow. */
final class QA {
	/**
	 * Allowed html.
	 *
	 * @return mixed Operation result.
	 */
	public static function allowed_html() {
		return array_fill_keys( array( 'p', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'strong', 'em', 'blockquote', 'br', 'hr', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'pre', 'code', 'figure', 'figcaption' ), array() ) + array(
			'a' => array(
				'href'  => true,
				'title' => true,
				'rel'   => true,
			),
		);
	}
	/**
	 * Words.
	 *
	 * @param string $html Html.
	 * @param string $language Language.
	 *
	 * @return mixed Operation result.
	 */
	public static function words( $html, $language = 'vi' ) {
		$text = html_entity_decode( wp_strip_all_tags( str_replace( '><', '> <', $html ) ), ENT_QUOTES, 'UTF-8' );
		if ( preg_match( '/^(zh|ja|ko)/', $language ) ) {
			preg_match_all( '/[\p{Han}\p{Hiragana}\p{Katakana}]|[\p{L}\p{N}]+/u', $text, $parts );
			return count( $parts[0] );
		}
		preg_match_all( '/[\p{L}\p{N}]+(?:[’\x27-][\p{L}\p{N}]+)*/u', $text, $parts );
		return count( $parts[0] );
	}
	/**
	 * Check.
	 *
	 * @param array $a A.
	 * @param mixed $campaign Campaign.
	 * @param bool  $network Network.
	 *
	 * @return mixed Operation result.
	 */
	public static function check( $a, $campaign, $network = true ) {
		global $wpdb;
		$d        = $a['content'];
		$p        = $a['payload'];
		$cfg      = $campaign['config'];
		$prefs    = Settings::global();
		$errors   = array();
		$warnings = array();
		$score    = 100;
		$groups   = array(
			'technical' => array(),
			'seo'       => array(),
			'content'   => array(),
		);
		$fail     = function ( $message, $group, $points = 10 ) use ( &$errors, &$score, &$groups ) {
			$errors[]           = $message;
			$groups[ $group ][] = $message;
			$score             -= $points;
		};
		$warn     = function ( $message, $group, $points = 2 ) use ( &$warnings, &$score, &$groups ) {
			$warnings[]         = $message;
			$groups[ $group ][] = $message;
			$score             -= $points;
		};
		$html     = (string) ( $d['html'] ?? '' );
		$title    = (string) ( $d['title'] ?? '' );
		if ( mb_strlen( $title ) < 5 || ! $html ) {
			$fail( 'Thiếu tiêu đề hoặc nội dung.', 'technical', 50 ); }
		if ( preg_match( '/<(script|iframe|style|form|input|object|embed|svg|html|head|body|header|footer|h1)\b|\bon[a-z]+\s*=|javascript\s*:|<\?|<!--\s*wp:|\[\/?[a-zA-Z_][a-zA-Z0-9_-]*(?:\s[^\]]*)?\]/i', $html ) ) {
			$fail( 'HTML chứa thành phần nguy hiểm/trùng theme/shortcode động; H1 do theme sinh từ post_title.', 'technical', 40 ); }
		if ( ! mb_check_encoding( $html . $title, 'UTF-8' ) || preg_match( '/\x{FFFD}|Ã[\x{0080}-\x{00FF}]|â€|áº|á»/u', $html . $title ) ) {
			$fail( 'Encoding có dấu hiệu lỗi UTF-8.', 'technical', 30 ); }
		$before = libxml_use_internal_errors( true );
		$dom    = new \DOMDocument( '1.0', 'UTF-8' );
		$dom->loadHTML( '<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NONET );
		$xml_errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $before );
		foreach ( $xml_errors as $e ) {
			if ( $e->level >= LIBXML_ERR_ERROR && ! in_array( $e->code, array( 801 ), true ) ) {
				$fail( 'HTML không cân bằng/hợp lệ; cần sửa cấu trúc.', 'technical', 10 );
				break; }
		}
		if ( $dom->getElementsByTagName( 'h2' )->length < 2 ) {
			$fail( 'Cần ít nhất hai H2 có nội dung hữu ích.', 'seo' ); }
		$xpath = new \DOMXPath( $dom );
		$last  = 1;
		foreach ( $xpath->query( '//h2|//h3|//h4' ) as $heading ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive/DOM property names cannot be renamed.
			$level = (int) substr( $heading->nodeName, 1 );
			if ( $level > $last + 1 ) {
				$warn( 'Có heading nhảy cấp; nên sửa thứ tự H2/H3.', 'seo' );
				break;
			} $last = $level; }
		$slug = $d['slug'] ?? '';
		if ( ! $slug || strlen( $slug ) > 200 || sanitize_title( $slug ) !== $slug ) {
			$fail( 'Slug trống hoặc không hợp lệ.', 'technical' ); }
		$dup = Store::duplicate( $title, $a['id'] );
		if ( $dup ) {
			$fail( 'Tiêu đề trùng ' . $dup . '.', 'seo', 30 ); }
		if ( $slug ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache.
			$other = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name=%s AND post_type='post' AND ID<>%d AND post_status<>'trash' LIMIT 1", $slug, $a['post_id'] ) );
			if ( $other ) {
				$fail( 'Slug đã được bài #' . $other . ' sử dụng.', 'seo', 20 ); }
		}
		$cat = get_term( $p['category'], 'category' );
		if ( ! $cat || is_wp_error( $cat ) ) {
			$fail( 'Danh mục không tồn tại.', 'technical', 20 ); }
		$wc = self::words( $html, $p['language'] );
		if ( $wc < $p['min_words'] || $wc > $p['max_words'] ) {
			$fail( 'Độ dài ' . $wc . ' ngoài khoảng ' . $p['min_words'] . '–' . $p['max_words'] . '.', 'content', 15 ); }
		$meta = $d['meta_title'] ?? '';
		$desc = $d['meta_description'] ?? '';
		if ( ! $meta || mb_strlen( $desc ) < 50 ) {
			$fail( 'Thiếu SEO title/meta description hữu ích.', 'seo', 15 ); }
		if ( mb_strlen( $meta ) < $prefs['title_min'] || mb_strlen( $meta ) > $prefs['title_max'] ) {
			$warn( 'SEO title ngoài khoảng khuyến nghị; đây là cảnh báo.', 'seo', 1 ); }
		if ( mb_strlen( $desc ) < $prefs['meta_min'] || mb_strlen( $desc ) > $prefs['meta_max'] ) {
			$warn( 'Meta description ngoài khoảng khuyến nghị.', 'seo', 1 ); }
		$text    = Store::norm( $html );
		$keyword = Store::norm( $a['primary_keyword'] );
		if ( $keyword && false === mb_strpos( Store::norm( $title . ' ' . $meta . ' ' . $html ), $keyword ) ) {
			$warn( 'Chưa có primary keyword nguyên cụm; xem xét biến thể tự nhiên.', 'seo', 5 ); }
		if ( $keyword && mb_substr_count( $text, $keyword ) > max( 12, $wc * 0.025 ) ) {
			$fail( 'Primary keyword lặp quá nhiều; nguy cơ nhồi từ khóa.', 'seo', 15 ); }
		if ( $keyword ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Schema/table names are trusted constants from Store::table(); all dynamic values are bound or handled by wpdb.
			$conflict = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Store::table( 'articles' ) . ' WHERE primary_keyword=%s AND id<>%d AND campaign_id=%d LIMIT 1', $a['primary_keyword'], $a['id'], $a['campaign_id'] ) );
			if ( $conflict ) {
				$warn( 'Cùng primary keyword với Matrix #' . $conflict . '; kiểm tra search intent/cannibalization.', 'seo', 3 ); }
		}
		$seen = array();
		foreach ( $dom->getElementsByTagName( 'p' ) as $paragraph ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive/DOM property names cannot be renamed.
			$para = Store::norm( $paragraph->textContent );
			if ( mb_strlen( $para ) < 80 ) {
				continue; }
			$hash = hash( 'sha256', $para );
			if ( isset( $seen[ $hash ] ) ) {
				$fail( 'Có đoạn văn lặp nguyên văn.', 'content', 15 );
				break;
			} $seen[ $hash ] = true;
		}
		if ( preg_match( '/\b(lorem ipsum|as an ai language model|insert .* here|TODO|TBD)\b|\[(?:điền|insert|placeholder)/iu', $html ) ) {
			$fail( 'Có placeholder/nội dung sáo rỗng.', 'content', 20 ); }
		$brand = Settings::brand();
		$facts = $brand;
		unset( $facts['forbidden'] );
		$verified  = Store::norm(
			implode(
				"\n",
				array_map(
					function ( $v ) {
						return is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
					},
					$facts
				)
			) . "\n" . ( $p['sources'] ?? '' )
		);
		$is_rental = preg_match( '/thuê.{0,15}xe|motorbike.{0,15}rental|scooter.{0,15}rental/iu', $a['title'] . ' ' . $a['primary_keyword'] );
		if ( $is_rental && empty( $brand['name'] ) ) {
			$fail( 'Thiếu Site / Brand Profile cho nội dung thuê xe; bổ sung dữ liệu trước khi viết.', 'content', 40 ); }
		// Numeric prices, deposits and phone numbers must appear in supplied factual data.
		preg_match_all( '/(?:\d[\d., ]{1,18}\s*(?:₫|đ\b|VND\b|USD\b|triệu\b|million\b)|\+?\d[\d .-]{7,16}\d)/iu', wp_strip_all_tags( $html ), $matches );
		foreach ( array_unique( $matches[0] ) as $claim ) {
			$compact  = preg_replace( '/[\s.,-]/u', '', mb_strtolower( $claim ) );
			$evidence = preg_replace( '/[\s.,-]/u', '', $verified );
			if ( $compact && false === mb_strpos( $evidence, $compact ) ) {
				$fail( 'Có số tiền/số điện thoại chưa đối chiếu được với dữ liệu: ' . $claim, 'content', 30 ); }
		}
		if ( preg_match( '/(?:không cần (?:cọc|đặt cọc)|no deposit|deposit.free)/iu', $html ) && ! preg_match( '/(?:không cần (?:cọc|đặt cọc)|no deposit|deposit.free)/iu', $verified ) ) {
			$fail( 'Tuyên bố không cần đặt cọc chưa được xác nhận.', 'content', 30 ); }
		foreach ( $d['claims'] ?? array() as $claim ) {
			if ( ! is_array( $claim ) || empty( $claim['evidence'] ) || false === mb_strpos( $verified, Store::norm( $claim['evidence'] ) ) ) {
				$fail( 'AI khai báo claim không có bằng chứng trong Brand Profile/nguồn nhập.', 'content', 15 );
				break; }
		}
		if ( ! empty( $d['missing_data'] ) ) {
			$fail( 'AI báo thiếu dữ liệu: ' . implode( '; ', array_map( 'sanitize_text_field', (array) $d['missing_data'] ) ), 'content', 20 ); }
		$internal    = array();
		$external    = array();
		$source_urls = array();
		preg_match_all( '#https?://[^\s<>"\x27]+#u', $p['sources'] ?? '', $source_matches );
		$source_urls = $source_matches[0];
		$home_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( $dom->getElementsByTagName( 'a' ) as $link ) {
			$href = $link->getAttribute( 'href' );
			if ( '#' === substr( $href, 0, 1 ) ) {
				continue; }
			if ( '/' === substr( $href, 0, 1 ) && '//' !== substr( $href, 0, 2 ) ) {
				$href = home_url( $href ); }
			if ( ! in_array( wp_parse_url( $href, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
				$fail( 'Link có giao thức không phù hợp.', 'technical' );
				continue; }
			if ( wp_parse_url( $href, PHP_URL_HOST ) === $home_host ) {
				$id = url_to_postid( $href );
				if ( $id && 'publish' === get_post_status( $id ) ) {
					$internal[] = $id; } elseif ( untrailingslashit( $href ) !== untrailingslashit( home_url() ) ) {
					$fail( 'Internal link không trỏ đến bài/trang đã publish: ' . $href, 'technical' ); }
			} else {
				if ( ! in_array( $href, $source_urls, true ) ) {
					$fail( 'External link không thuộc nguồn đã nhập: ' . $href, 'technical' );
					continue; }
				$external[ $href ] = true;
			}
		}
		if ( $p['internal_links'] && ! array_intersect( $p['internal_links'], $internal ) ) {
			$fail( 'Chưa liên kết đến internal link target đã chỉ định.', 'seo' ); }
		if ( ! $internal ) {
			$warn( 'Chưa có internal link đến bài/trang đã tồn tại.', 'seo', 3 ); }
		if ( count( $external ) > 5 ) {
			$fail( 'Quá 5 external sources trong một job.', 'technical' ); }
		if ( $network ) {
			foreach ( array_slice( array_keys( $external ), 0, 5 ) as $url ) {
				// WordPress safe HTTP rejects loopback/private networks and unsafe redirects.
				$r    = wp_safe_remote_head(
					$url,
					array(
						'timeout'     => 3,
						'redirection' => 2,
						'sslverify'   => true,
					)
				);
				$code = is_wp_error( $r ) ? 0 : wp_remote_retrieve_response_code( $r );
				if ( 404 === $code || 410 === $code ) {
					$fail( 'Nguồn bên ngoài trả HTTP ' . $code . '.', 'technical' ); } elseif ( $code < 200 || $code >= 400 ) {
					$warn( 'Chưa xác minh truy cập nguồn (HEAD timeout/bị chặn).', 'content', 3 ); }
			}
		}
		$review = $d['_review'] ?? array();
		if ( $cfg['require_review'] && empty( $cfg['reviewer'] ) ) {
			$fail( 'Chiến dịch yêu cầu AI review nhưng chưa chọn reviewer.', 'content', 40 ); }
		if ( $review ) {
			if ( empty( $review['pass'] ) || (int) ( $review['score'] ?? 0 ) < $cfg['qa_threshold'] ) {
				$fail( 'AI reviewer yêu cầu sửa: ' . implode( '; ', array_map( 'sanitize_text_field', (array) ( $review['issues'] ?? array() ) ) ), 'content', 25 ); }
			$score = min( $score, max( 0, min( 100, (int) ( $review['score'] ?? 0 ) ) ) );
		}
		$score = max( 0, min( 100, $score ) );
		if ( $score < $cfg['qa_threshold'] ) {
			$errors[] = 'Điểm QA ' . $score . ' dưới ngưỡng ' . $cfg['qa_threshold'] . '.'; }
		return array(
			'score'          => $score,
			'pass'           => ! $errors,
			'errors'         => array_values( array_unique( $errors ) ),
			'warnings'       => array_values( array_unique( $warnings ) ),
			'groups'         => $groups,
			'word_count'     => $wc,
			'internal_links' => array_values( array_unique( $internal ) ),
			'checked_at'     => Store::now(),
		);
	}
}
