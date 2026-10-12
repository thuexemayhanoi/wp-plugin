<?php
/** Official HTTPS provider adapter.
 *
 * @package MultiAISEOContentFactory
 */

namespace MASF;

defined( 'ABSPATH' ) || exit;

/** Claude implementation for the factory workflow. */
final class Claude extends Adapter {
	/**
	 * Generate.
	 *
	 * @param string $model Model.
	 * @param string $system System.
	 * @param string $prompt Prompt.
	 * @param int    $tokens Tokens.
	 *
	 * @return mixed Operation result.
	 */
	public function generate( $model, $system, $prompt, $tokens ) {
		$j    = $this->request(
			'https://api.anthropic.com/v1/messages',
			array(
				'model'      => $model,
				'max_tokens' => $tokens,
				'system'     => $system,
				'messages'   => array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
			),
			array(
				'x-api-key'         => $this->key,
				'anthropic-version' => '2023-06-01',
			)
		);
		$text = '';
		foreach ( $j['content'] ?? array() as $part ) {
			if ( 'text' === ( $part['type'] ?? '' ) ) {
				$text .= $part['text']; }
		}
		return array(
			'text'           => $text,
			'input'          => (int) ( $j['usage']['input_tokens'] ?? 0 ) + (int) ( $j['usage']['cache_creation_input_tokens'] ?? 0 ) + (int) ( $j['usage']['cache_read_input_tokens'] ?? 0 ),
			'output'         => (int) ( $j['usage']['output_tokens'] ?? 0 ),
			'incomplete'     => ! in_array( $j['stop_reason'] ?? '', array( 'end_turn', 'stop_sequence' ), true ),
			'request_id'     => $j['_request_id'],
			'rate_remaining' => $j['_rate_remaining'],
		);
	}
	/**
	 * Models.
	 *
	 * @return mixed Operation result.
	 */
	public function models() {
		$out   = array();
		$after = '';
		for ( $i = 0; $i < 5; ++$i ) {
			$j = $this->request(
				'https://api.anthropic.com/v1/models?limit=100' . ( $after ? '&after_id=' . rawurlencode( $after ) : '' ),
				null,
				array(
					'x-api-key'         => $this->key,
					'anthropic-version' => '2023-06-01',
				)
			);
			foreach ( $j['data'] ?? array() as $m ) {
				$out[] = $m['id']; }
			if ( empty( $j['has_more'] ) ) {
				break; }
			$after = $j['last_id'] ?? '';
			if ( ! $after ) {
				break; }
		}
		return $out;
	}
}
