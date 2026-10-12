<?php
/** Bounded CSV/XLSX reader. No macros, formulas, extraction or external XML entities. *
 *
 * @package MultiAISEOContentFactory
 */

namespace MASF;

defined( 'ABSPATH' ) || exit;

/** Importer implementation for the factory workflow. */
final class Importer {
	const MAX_ROWS = 10000;
	/**
	 * Upload.
	 *
	 * @param array $file File.
	 *
	 * @return mixed Operation result.
	 * @throws \InvalidArgumentException If the operation cannot complete.
	 */
	public static function upload( $file ) {
		if ( ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ?? '' ) ) {
			throw new \InvalidArgumentException( 'Upload thất bại; chọn lại file.' ); }
		return self::file( $file['tmp_name'], strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) );
	}
	/**
	 * File.
	 *
	 * @param string $path Path.
	 * @param string $extension Extension.
	 *
	 * @return mixed Operation result.
	 * @throws \InvalidArgumentException If the operation cannot complete.
	 */
	public static function file( $path, $extension ) {
		if ( ! is_file( $path ) || filesize( $path ) > 5 * MB_IN_BYTES ) {
			throw new \InvalidArgumentException( 'File tối đa 5 MB.' ); }
		if ( 'csv' === $extension ) {
			return self::csv( $path ); }
		if ( 'xlsx' === $extension ) {
			return self::xlsx( $path ); }
		throw new \InvalidArgumentException( 'Chỉ hỗ trợ CSV UTF-8 và Excel .xlsx; lưu lại .xls thành .xlsx.' );
	}
	/**
	 * Xml.
	 *
	 * @param string $text Text.
	 *
	 * @return mixed Operation result.
	 * @throws \InvalidArgumentException If the operation cannot complete.
	 */
	private static function xml( $text ) {
		if ( false !== stripos( $text, '<!DOCTYPE' ) || false !== stripos( $text, '<!ENTITY' ) ) {
			throw new \InvalidArgumentException( 'XML có khai báo entity không được phép.' ); }
		$before = libxml_use_internal_errors( true );
		$x      = simplexml_load_string( $text, 'SimpleXMLElement', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $before );
		if ( false === $x ) {
			throw new \InvalidArgumentException( 'Excel XML không hợp lệ.' ); }
		return $x;
	}
	/**
	 * Xlsx.
	 *
	 * @param string $path Path.
	 *
	 * @return mixed Operation result.
	 * @throws \RuntimeException If the operation cannot complete.
	 * @throws \InvalidArgumentException If the operation cannot complete.
	 */
	public static function xlsx( $path ) {
		if ( ! class_exists( '\ZipArchive' ) || ! function_exists( 'simplexml_load_string' ) ) {
			throw new \RuntimeException( 'Bật PHP Zip và SimpleXML trên hosting, hoặc dùng CSV UTF-8.' ); }
		$z = new \ZipArchive();
		if ( true !== $z->open( $path ) ) {
			throw new \InvalidArgumentException( 'Không mở được Excel .xlsx.' ); }
		try {
			$total = 0;
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive/DOM property names cannot be renamed.
			if ( $z->numFiles > 2000 ) {
				throw new \InvalidArgumentException( 'Excel có quá nhiều thành phần.' ); }
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive/DOM property names cannot be renamed.
			for ( $i = 0; $i < $z->numFiles; ++$i ) {
				$s      = $z->statIndex( $i );
				$total += $s['size'];
				if ( $total > 20 * MB_IN_BYTES || $s['size'] > 8 * MB_IN_BYTES || false !== stripos( $s['name'], 'vbaProject' ) || false !== stripos( $s['name'], 'externalLinks' ) ) {
					throw new \InvalidArgumentException( 'Excel quá lớn hoặc chứa macro/liên kết ngoài.' ); }
			}
			// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Intentional empty-value fallback; plugin requires PHP 8.1+.
			$workbook = self::xml( $z->getFromName( 'xl/workbook.xml' ) ?: '' );
			$sheets   = $workbook->xpath( '//*[local-name()="sheets"]/*[local-name()="sheet"]' );
			$sheet    = $sheets[0] ?? null;
			if ( null === $sheet ) {
				throw new \InvalidArgumentException( 'Excel không có worksheet.' ); }
			$rid = (string) $sheet->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )->id;
			// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Intentional empty-value fallback; plugin requires PHP 8.1+.
			$rels   = self::xml( $z->getFromName( 'xl/_rels/workbook.xml.rels' ) ?: '' );
			$target = '';
			foreach ( $rels->xpath( '//*[local-name()="Relationship"]' ) as $rel ) {
				if ( (string) $rel['Id'] === $rid && 'External' !== (string) $rel['TargetMode'] ) {
					$target = (string) $rel['Target']; }
			}
			$target = '/' === substr( $target, 0, 1 ) ? ltrim( $target, '/' ) : 'xl/' . $target;
			if ( ! preg_match( '#^xl/worksheets/[a-zA-Z0-9_-]+\.xml$#D', $target ) ) {
				throw new \InvalidArgumentException( 'Đường dẫn worksheet không hợp lệ.' ); }
			$strings = array();
			$raw     = $z->getFromName( 'xl/sharedStrings.xml' );
			if ( $raw ) {
				foreach ( self::xml( $raw )->xpath( '//*[local-name()="si"]' ) as $si ) {
					$parts     = $si->xpath( './/*[local-name()="t"]' );
					$strings[] = implode( '', array_map( 'strval', $parts ) ); }
			}
			$rows = array();
			// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Intentional empty-value fallback; plugin requires PHP 8.1+.
			foreach ( self::xml( $z->getFromName( $target ) ?: '' )->xpath( '//*[local-name()="sheetData"]/*[local-name()="row"]' ) as $row ) {
				$cells = array();
				foreach ( $row->xpath( './*[local-name()="c"]' ) as $cell ) {
					if ( $cell->xpath( './*[local-name()="f"]' ) ) {
						throw new \InvalidArgumentException( 'Excel chứa công thức; dùng Paste Values rồi tải lại.' ); }
					preg_match( '/^([A-Z]+)[0-9]+$/D', (string) $cell['r'], $m );
					$index = 0;
					foreach ( str_split( $m[1] ?? 'A' ) as $ch ) {
						$index = $index * 26 + ord( $ch ) - 64; }
					if ( $index > 30 ) {
						continue; }
					$values = $cell->xpath( './*[local-name()="v"]' );
					$value  = (string) ( $values[0] ?? '' );
					if ( 's' === (string) $cell['t'] ) {
						$value = $strings[ (int) $value ] ?? ''; }
					if ( 'inlineStr' === (string) $cell['t'] ) {
						$value = implode( '', array_map( 'strval', $cell->xpath( './/*[local-name()="is"]//*[local-name()="t"]' ) ) ); }
					$cells[ $index - 1 ] = $value;
				}
				if ( $cells ) {
					$max    = max( array_keys( $cells ) );
					$rows[] = array_replace( array_fill( 0, $max + 1, '' ), $cells ); }
				if ( count( $rows ) > self::MAX_ROWS + 1 ) {
					throw new \InvalidArgumentException( 'Mỗi file tối đa 10.000 dòng.' ); }
			}
			return self::rows( $rows );
		} finally {
			$z->close(); }
	}
	/**
	 * Csv.
	 *
	 * @param string $path Path.
	 *
	 * @return mixed Operation result.
	 * @throws \InvalidArgumentException If the operation cannot complete.
	 */
	public static function csv( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a size-validated local uploaded file, not a remote URL.
		$raw = file_get_contents( $path );
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			throw new \InvalidArgumentException( 'CSV cần mã hóa UTF-8.' ); }
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Intentional empty-value fallback; plugin requires PHP 8.1+.
		$first = strtok( $raw, "\r\n" ) ?: '';
		$sep   = ',';
		$count = 0;
		foreach ( array( ',', ';', "\t" ) as $candidate ) {
			$n = count( str_getcsv( $first, $candidate, '"', '' ) );
			if ( $n > $count ) {
				$sep   = $candidate;
				$count = $n; }
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV parser uses a php://temp memory stream.
		$f = fopen( 'php://temp', 'r+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing only bounded php://temp or php://output streams.
		fwrite( $f, $raw );
		rewind( $f );
		$rows = array();
		try {
			// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Conventional bounded CSV stream-reading loop.
			while ( false !== ( $r = fgetcsv( $f, 0, $sep, '"', '' ) ) ) {
				$rows[] = $r;
				if ( count( $rows ) > self::MAX_ROWS + 1 ) {
					throw new \InvalidArgumentException( 'Mỗi file tối đa 10.000 dòng.' ); }
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing temporary/output streams, not arbitrary files.
			fclose( $f ); }
		return self::rows( $rows );
	}
	/**
	 * Rows.
	 *
	 * @param array $rows Rows.
	 *
	 * @return mixed Operation result.
	 */
	public static function rows( $rows ) {
		$aliases = array(
			'title'              => 'title',
			'working_title'      => 'title',
			'tieu_de'            => 'title',
			'primary_keyword'    => 'primary_keyword',
			'keyword'            => 'primary_keyword',
			'tu_khoa'            => 'primary_keyword',
			'secondary_keywords' => 'secondary_keywords',
			'content_brief'      => 'brief',
			'brief'              => 'brief',
			'language'           => 'language',
			'ngon_ngu'           => 'language',
			'category'           => 'category',
			'category_id'        => 'category',
			'danh_muc'           => 'category',
			'intent'             => 'intent',
			'search_intent'      => 'intent',
			'sources'            => 'sources',
			'internal_links'     => 'internal_links',
		);
		$headers = array();
		foreach ( $rows[0] ?? array() as $i => $value ) {
			$key = str_replace( array( ' ', '-' ), '_', strtolower( remove_accents( trim( $value ?? '' ) ) ) );
			if ( isset( $aliases[ $key ] ) ) {
				$headers[ $i ] = $aliases[ $key ]; }
		}
		if ( ! in_array( 'title', $headers, true ) ) {
			$headers = array(
				0 => 'title',
				1 => 'primary_keyword',
			); } else {
			array_shift( $rows ); }
			$out = array();
			foreach ( $rows as $r ) {
				$d = array();
				foreach ( $headers as $i => $key ) {
					$d[ $key ] = mb_substr( sanitize_textarea_field( (string) ( $r[ $i ] ?? '' ) ), 0, 'brief' === $key || 'sources' === $key ? 12000 : 1000 ); }
				if ( ! empty( trim( $d['title'] ?? '' ) ) ) {
					$out[] = $d; }
			}
			return $out;
	}
	/**
	 * Add.
	 *
	 * @param int   $campaign_id Campaign id.
	 * @param array $rows Rows.
	 *
	 * @return mixed Operation result.
	 * @throws \InvalidArgumentException If the operation cannot complete.
	 */
	public static function add( $campaign_id, $rows ) {
		global $wpdb;
		$c = Store::campaign( $campaign_id );
		if ( ! $c ) {
			throw new \InvalidArgumentException( 'Không tìm thấy chiến dịch.' ); }
		$cfg     = $c['config'];
		$added   = 0;
		$skipped = array();
		foreach ( $rows as $i => $row ) {
			$title = mb_substr( sanitize_text_field( $row['title'] ?? '' ), 0, 500 );
			$dup   = mb_strlen( $title ) < 5 ? 'tiêu đề quá ngắn' : Store::duplicate( $title );
			if ( $dup ) {
				$skipped[] = 'Dòng ' . ( $i + 1 ) . ': ' . ( $dup ?? 'tiêu đề quá ngắn' );
				unset( $dup );
				continue; }
			$cat = $cfg['category'];
			if ( ! empty( $row['category'] ) ) {
				$term = is_numeric( $row['category'] ) ? get_term( absint( $row['category'] ), 'category' ) : get_term_by( 'name', $row['category'], 'category' );
				if ( ! $term || is_wp_error( $term ) ) {
					$skipped[] = 'Dòng ' . ( $i + 1 ) . ': danh mục không tồn tại';
					continue; }
				$cat = $term->term_id;
			}
			$payload = array(
				'secondary_keywords' => sanitize_textarea_field( $row['secondary_keywords'] ?? '' ),
				'brief'              => sanitize_textarea_field( $row['brief'] ?? $cfg['brief'] ),
				'sources'            => sanitize_textarea_field( $row['sources'] ?? $cfg['sources'] ),
				'language'           => preg_match( '/^[a-z]{2}(?:[-_][A-Za-z]{2})?$/D', $row['language'] ?? '' ) ? $row['language'] : $cfg['language'],
				'category'           => $cat,
				'intent'             => sanitize_text_field( $row['intent'] ?? $cfg['intent'] ),
				'internal_links'     => ! empty( $row['internal_links'] ) ? array_filter( array_map( 'absint', preg_split( '/[,;\s]+/', $row['internal_links'] ) ) ) : $cfg['internal_links'],
				'min_words'          => $cfg['min_words'],
				'max_words'          => $cfg['max_words'],
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Factory checkpoints require direct custom-table operations.
			$ok = $wpdb->insert(
				Store::table( 'articles' ),
				array(
					'uuid'            => wp_generate_uuid4(),
					'campaign_id'     => $campaign_id,
					'title'           => $title,
					'title_hash'      => hash( 'sha256', Store::norm( $title ) ),
					'primary_keyword' => mb_substr( sanitize_text_field( $row['primary_keyword'] ?? '' ), 0, 250 ),
					'payload'         => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
					'status'          => 'PLANNED',
					'phase'           => 'write',
					'created_at'      => Store::now(),
					'updated_at'      => Store::now(),
					'next_run'        => Store::now(),
				)
			);
			if ( $ok ) {
				++$added;
			} else {
				$skipped[] = 'Dòng ' . ( $i + 1 ) . ': trùng khóa hoặc lỗi lưu'; }
		}
		Store::log( 'Nhập ' . $added . ' bài; bỏ qua ' . count( $skipped ) . ' dòng.', 'info', $campaign_id );
		return array(
			'added'   => $added,
			'skipped' => $skipped,
		);
	}
}
