<?php
/**
 * QuickCall Connect frontend: widget rendering and conditional assets.
 *
 * @package QuickCallConnect
 */

namespace QuickCallConnect;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend class: renders the widget and loads public assets on demand.
 */
class Frontend {

	/** Hook everything. */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
	}

	/**
	 * Register (not enqueue) public assets. Enqueue only when the widget will render.
	 */
	public static function register_assets() {
		$base = self::base_url();
		wp_register_style( 'quickcall-connect', $base . 'assets/css/quickcall-connect.css', array(), QUICKCALL_CONNECT_VERSION );
		wp_register_script( 'quickcall-connect', $base . 'assets/js/quickcall-connect.js', array(), QUICKCALL_CONNECT_VERSION, true );
	}

	/**
	 * Render the widget in the footer, if enabled and there is something to show.
	 */
	public static function render() {
		$settings = Settings::get();
		$channels = Settings::active_channels( $settings );

		if ( empty( $settings['enabled'] ) || empty( $channels ) ) {
			return; // Plugin disabled or no valid channel: no markup, no assets.
		}

		wp_enqueue_style( 'quickcall-connect' );
		wp_enqueue_script( 'quickcall-connect' );

		$app       = $settings['appearance'];
		$placement = in_array( $settings['placement'], Registry::placements(), true ) ? $settings['placement'] : 'right';
		$labels    = ! empty( $app['show_labels'] );
		$new_tab   = ! empty( $app['new_tab'] );
		$style     = Settings::css_vars( $settings );
		$animation = ( 'none' === $app['animation'] ) ? ' qc-anim-none' : '';
		$dock      = ( 'bottom' === $placement ) ? ' qc-dock' : '';
		?>
		<div class="qc-root<?php echo esc_attr( $dock . $animation ); ?> qc-<?php echo esc_attr( $placement ); ?>" style="<?php echo esc_attr( $style ); ?>" data-qc-placement="<?php echo esc_attr( $placement ); ?>">
			<?php if ( 'bottom' === $placement ) : ?>
				<nav class="qc-dock-nav" aria-label="<?php esc_attr_e( 'Quick contact', 'quickcall-connect' ); ?>">
					<ul class="qc-actions">
						<?php foreach ( $channels as $channel ) : ?>
							<li>
								<?php self::render_link( $channel, $labels, $new_tab, false ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php else : ?>
				<div class="qc-float">
					<ul class="qc-actions" id="qc-actions" role="group" aria-label="<?php esc_attr_e( 'Quick contact options', 'quickcall-connect' ); ?>">
						<?php foreach ( $channels as $channel ) : ?>
							<li>
								<?php self::render_link( $channel, $labels, $new_tab, true ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<button type="button" class="qc-toggle" aria-expanded="false" aria-controls="qc-actions" aria-label="<?php esc_attr_e( 'Open quick contact options', 'quickcall-connect' ); ?>" data-label-open="<?php esc_attr_e( 'Open quick contact options', 'quickcall-connect' ); ?>" data-label-close="<?php esc_attr_e( 'Close quick contact options', 'quickcall-connect' ); ?>">
						<?php echo Registry::icon( 'contact' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static trusted markup built in Registry. ?>
					</button>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a single channel link. Progressive enhancement: links are visible
	 * without JavaScript; the script collapses them for floating launchers.
	 *
	 * @param array $channel Active channel.
	 * @param bool  $labels  Show text labels.
	 * @param bool  $new_tab Open external links in a new tab.
	 * @param bool  $tooltip Add a title tooltip (floating mode).
	 */
	private static function render_link( $channel, $labels, $new_tab, $tooltip ) {
		$href = $channel['href'];
		if ( '' === $href ) {
			return;
		}
		$label = $channel['label'];
		?>
		<a class="qc-link" href="<?php echo esc_url( $href ); ?>"
			<?php if ( $channel['external'] && $new_tab ) : ?>
				target="_blank" rel="noopener noreferrer"
			<?php endif; ?>
			aria-label="<?php echo esc_attr( $label ); ?>"
			<?php if ( $tooltip ) : ?>
				title="<?php echo esc_attr( $label ); ?>"
			<?php endif; ?>
			>
			<?php echo Registry::icon( $channel['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- static trusted markup built in Registry. ?>
			<?php if ( $labels ) : ?>
				<span class="qc-label"><?php echo esc_html( $label ); ?></span>
			<?php endif; ?>
		</a>
		<?php
	}

	/** Plugin base URL (no hard-coded plugin paths). */
	private static function base_url() {
		return plugin_dir_url( dirname( __DIR__, 1 ) . '/quickcall-connect.php' );
	}
}
