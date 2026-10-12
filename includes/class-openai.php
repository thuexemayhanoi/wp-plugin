<?php
/** Official HTTPS provider adapter.
 *
 * @package MultiAISEOContentFactory
 */

namespace MASF;

defined( 'ABSPATH' ) || exit;

/** OpenAI implementation for the factory workflow. */
final class OpenAI extends Adapter {
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
			'https://api.openai.com/v1/responses',
			array(
				'model'             => $model,
				'instructions'      => $system,
				'input'             => $prompt,
				'max_output_tokens' => $tokens,
				'store'             => false,
				'text'              => array( 'format' => array( 'type' => 'json_object' ) ),
			),
			array( 'Authorization' => 'Bearer ' . $this->key )
		);
		$text = '';
		foreach ( $j['output'] ?? array() as $item ) {
			foreach ( $item['content'] ?? array() as $part ) {
				if ( 'output_text' === ( $part['type'] ?? '' ) ) {
					$text .= $part['text']; }
			}
		}
		return array(
			'text'           => $text,
			'input'          => (int) ( $j['usage']['input_tokens'] ?? 0 ),
			'output'         => (int) ( $j['usage']['output_tokens'] ?? 0 ),
			'incomplete'     => 'completed' !== ( $j['status'] ?? '' ),
			// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Intentional empty-value fallback; plugin requires PHP 8.1+.
			'request_id'     => $j['_request_id'] ?: ( $j['id'] ?? '' ),
			'rate_remaining' => $j['_rate_remaining'],
		);
	}
	/**
	 * Models.
	 *
	 * @return mixed Operation result.
	 */
	public function models() {
		$j = $this->request( 'https://api.openai.com/v1/models', null, array( 'Authorization' => 'Bearer ' . $this->key ) );
		// Catalog access does not prove Responses compatibility; connection test does.
		return array_values(
			array_map(
				function ( $m ) {
					return $m['id'];
				},
				$j['data'] ?? array()
			)
		);
	}
}
