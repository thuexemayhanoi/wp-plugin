<?php
/** Official HTTPS provider adapter.
 *
 * @package MultiAISEOContentFactory
 */

namespace MASF;

defined( 'ABSPATH' ) || exit;

/** API_Error implementation for the factory workflow. */
class API_Error extends \RuntimeException {
	/**
	 * Http.
	 *
	 * @var mixed
	 */
	public $http;
	/**
	 * Retry after.
	 *
	 * @var mixed
	 */
	public $retry_after;
	/**
	 * Transient.
	 *
	 * @var mixed
	 */
	public $transient;
	/** Indicates billing, bad credentials, or non-retryable API configuration. */
	public $requires_action;
	/**
	 * Initialize instance.
	 *
	 * @param string $message Message.
	 * @param int    $http Http.
	 * @param int    $retry_after Retry after.
	 * @param bool   $transient Transient.
	 */
	public function __construct( $message, $http = 0, $retry_after = 0, $transient = false, $requires_action = false ) {
		parent::__construct( $message );
		$this->http        = $http;
		$this->retry_after = $retry_after;
		$this->transient   = $transient;
		$this->requires_action = $requires_action;
	}
}
