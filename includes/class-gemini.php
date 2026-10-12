<?php
/** Official HTTPS provider adapter.
 *
 * @package MultiAISEOContentFactory
 */

namespace MASF;

defined( 'ABSPATH' ) || exit;

/** Gemini implementation for the factory workflow. */
final class Gemini extends Adapter {
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
		$model = preg_replace( '#^models/#', '', $model );
		$j     = $this->request(
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent',
			array(
				'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
				'contents'          => array(
					array(
						'role'  => 'user',
						'parts' => array( array( 'text' => $prompt ) ),
					),
				),
				'generationConfig'  => array(
					'maxOutputTokens'  => $tokens,
					'responseMimeType' => 'application/json',
				),
			),
			array( 'x-goog-api-key' => $this->key )
		);
		$text  = '';
		foreach ( $j['candidates'][0]['content']['parts'] ?? array() as $part ) {
			if ( empty( $part['thought'] ) ) {
				$text .= $part['text'] ?? ''; }
		}
		return array(
			'text'           => $text,
			'input'          => (int) ( $j['usageMetadata']['promptTokenCount'] ?? 0 ),
			'output'         => (int) ( $j['usageMetadata']['candidatesTokenCount'] ?? 0 ) + (int) ( $j['usageMetadata']['thoughtsTokenCount'] ?? 0 ),
			'incomplete'     => 'STOP' !== ( $j['candidates'][0]['finishReason'] ?? '' ),
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
		$out  = array();
		$page = '';
		for ( $i = 0; $i < 5; ++$i ) {
			$j = $this->request( 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=100' . ( $page ? '&pageToken=' . rawurlencode( $page ) : '' ), null, array( 'x-goog-api-key' => $this->key ) );
			foreach ( $j['models'] ?? array() as $m ) {
				if ( in_array( 'generateContent', $m['supportedGenerationMethods'] ?? array(), true ) ) {
					$out[] = preg_replace( '#^models/#', '', $m['name'] ); }
			}
			$page = $j['nextPageToken'] ?? '';
			if ( ! $page ) {
				break; }
		}
		return $out;
	}
}
