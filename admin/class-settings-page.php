<?php
/**
 * QuickCall Connect admin settings page (Settings API).
 *
 * This class renders the plugin settings screen and registers the option
 * via the WordPress Settings API.
 *
 * @package QuickCallConnect
 */

namespace QuickCallConnect;

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page class.
 */
class Settings_Page {

	const SLUG = 'quickcall-connect';

	/** Hook everything. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** Add a page under Settings. */
	public static function add_menu() {
		add_options_page(
			__( 'QuickCall Connect', 'quickcall-connect' ),
			__( 'QuickCall Connect', 'quickcall-connect' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/** Register the setting with a strict sanitize callback. */
	public static function register() {
		register_setting(
			'quickcall_connect_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'QuickCallConnect\Settings', 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Enqueue admin CSS only on this plugin's settings screen.
	 *
	 * @param string $hook Admin page hook suffix.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style(
			'quickcall-connect-admin',
			plugin_dir_url( dirname( __DIR__ ) . '/quickcall-connect.php' ) . 'assets/css/admin.css',
			array(),
			QUICKCALL_CONNECT_VERSION
		);
	}

	/** Render the settings page. */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'quickcall-connect' ) );
		}

		$hide_hint = __( 'Leave a field empty to keep that icon hidden on your website.', 'quickcall-connect' );
		$s         = Settings::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'QuickCall Connect', 'quickcall-connect' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'quickcall_connect_group' ); ?>

				<h2 class="qc-section-title"><?php esc_html_e( 'General', 'quickcall-connect' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable widget', 'quickcall-connect' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?> />
								<?php esc_html_e( 'Show the contact launcher on your website', 'quickcall-connect' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="qc-placement"><?php esc_html_e( 'Placement', 'quickcall-connect' ); ?></label></th>
						<td>
							<select id="qc-placement" name="<?php echo esc_attr( Settings::OPTION ); ?>[placement]">
								<?php
								$placements = array(
									'right'  => __( 'Floating right', 'quickcall-connect' ),
									'left'   => __( 'Floating left', 'quickcall-connect' ),
									'bottom' => __( 'Bottom dock', 'quickcall-connect' ),
								);
								foreach ( Registry::placements() as $p ) :
									?>
									<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $s['placement'], $p ); ?>><?php echo esc_html( $placements[ $p ] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<h2 class="qc-section-title"><?php esc_html_e( 'Primary Contact', 'quickcall-connect' ); ?></h2>
				<p class="description"><?php echo esc_html( $hide_hint ); ?></p>
				<table class="form-table" role="presentation">
					<?php self::channel_rows( $s, 'primary' ); ?>
				</table>

				<h2 class="qc-section-title"><?php esc_html_e( 'Social & Messaging', 'quickcall-connect' ); ?></h2>
				<p class="description"><?php echo esc_html( $hide_hint ); ?></p>
				<table class="form-table" role="presentation">
					<?php self::channel_rows( $s, 'optional' ); ?>
				</table>

				<h2 class="qc-section-title"><?php esc_html_e( 'Custom Link / Chatbot', 'quickcall-connect' ); ?></h2>
				<p class="description"><?php echo esc_html( $hide_hint ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="qc-custom-label"><?php esc_html_e( 'Label', 'quickcall-connect' ); ?></label></th>
						<td><input type="text" class="regular-text" id="qc-custom-label" name="<?php echo esc_attr( Settings::OPTION ); ?>[custom][label]" value="<?php echo esc_attr( $s['custom']['label'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Chat with us, Book a table', 'quickcall-connect' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="qc-custom-url"><?php esc_html_e( 'URL', 'quickcall-connect' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="qc-custom-url" name="<?php echo esc_attr( Settings::OPTION ); ?>[custom][url]" value="<?php echo esc_attr( $s['custom']['url'] ); ?>" placeholder="https://" />
							<p class="description"><?php esc_html_e( 'Any http(s) link: chatbot, booking page, contact form…', 'quickcall-connect' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Icon', 'quickcall-connect' ); ?></th>
						<td>
							<?php foreach ( Registry::custom_icons() as $icon_key => $icon_label ) : ?>
								<label class="qc-icon-choice">
									<input type="radio" name="<?php echo esc_attr( Settings::OPTION ); ?>[custom][icon]" value="<?php echo esc_attr( $icon_key ); ?>" <?php checked( $s['custom']['icon'], $icon_key ); ?> />
									<?php echo Registry::icon( $icon_key ); // phpcs:ignore WordPress.Security.EscapeOutput -- static trusted markup built in Registry. ?>
									<?php echo esc_html( $icon_label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>

				<h2 class="qc-section-title"><?php esc_html_e( 'Appearance', 'quickcall-connect' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$app = $s['appearance'];
					self::color_row( 'bg', __( 'Launcher background', 'quickcall-connect' ), $app['bg'] );
					self::color_row( 'fg', __( 'Launcher icon / text', 'quickcall-connect' ), $app['fg'] );
					self::color_row( 'accent', __( 'Accent (focus outline, optional)', 'quickcall-connect' ), $app['accent'] );
					?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Size', 'quickcall-connect' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( Settings::OPTION ); ?>[appearance][size]">
								<?php
								$preset = array(
									'sm' => __( 'Small', 'quickcall-connect' ),
									'md' => __( 'Medium', 'quickcall-connect' ),
									'lg' => __( 'Large', 'quickcall-connect' ),
								);
								foreach ( $preset as $k => $v ) :
									?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $app['size'], $k ); ?>><?php echo esc_html( $v ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Gap between buttons', 'quickcall-connect' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( Settings::OPTION ); ?>[appearance][gap]">
								<?php
								$gaps = array(
									'sm' => __( 'Small', 'quickcall-connect' ),
									'md' => __( 'Medium', 'quickcall-connect' ),
									'lg' => __( 'Large', 'quickcall-connect' ),
								);
								foreach ( $gaps as $k => $v ) :
									?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $app['gap'], $k ); ?>><?php echo esc_html( $v ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Corner radius', 'quickcall-connect' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( Settings::OPTION ); ?>[appearance][radius]">
								<?php
								$radii = array(
									'sm'   => __( 'Rounded', 'quickcall-connect' ),
									'md'   => __( 'More rounded', 'quickcall-connect' ),
									'pill' => __( 'Pill', 'quickcall-connect' ),
								);
								foreach ( $radii as $k => $v ) :
									?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $app['radius'], $k ); ?>><?php echo esc_html( $v ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="qc-ox"><?php esc_html_e( 'Horizontal offset', 'quickcall-connect' ); ?></label></th>
						<td><input type="number" id="qc-ox" min="0" max="150" name="<?php echo esc_attr( Settings::OPTION ); ?>[appearance][offset_x]" value="<?php echo esc_attr( (string) $app['offset_x'] ); ?>" class="small-text" /> px</td>
					</tr>
					<tr>
						<th scope="row"><label for="qc-oy"><?php esc_html_e( 'Bottom offset', 'quickcall-connect' ); ?></label></th>
						<td><input type="number" id="qc-oy" min="0" max="150" name="<?php echo esc_attr( Settings::OPTION ); ?>[appearance][offset_y]" value="<?php echo esc_attr( (string) $app['offset_y'] ); ?>" class="small-text" /> px</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Labels / tooltips', 'quickcall-connect' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[appearance][show_labels]" value="1" <?php checked( ! empty( $app['show_labels'] ) ); ?> />
								<?php esc_html_e( 'Show text labels next to icons', 'quickcall-connect' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Animation', 'quickcall-connect' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( Settings::OPTION ); ?>[appearance][animation]">
								<option value="none" <?php selected( $app['animation'], 'none' ); ?>><?php esc_html_e( 'None', 'quickcall-connect' ); ?></option>
								<option value="subtle" <?php selected( $app['animation'], 'subtle' ); ?>><?php esc_html_e( 'Subtle', 'quickcall-connect' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'External links', 'quickcall-connect' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[appearance][new_tab]" value="1" <?php checked( ! empty( $app['new_tab'] ) ); ?> />
								<?php esc_html_e( 'Open external links in a new tab', 'quickcall-connect' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Output admin rows for a channel group.
	 *
	 * @param array  $settings Merged settings.
	 * @param string $group    'primary' or 'optional'.
	 */
	private static function channel_rows( $settings, $group ) {
		foreach ( Registry::channels() as $key => $meta ) {
			if ( $meta['group'] !== $group ) {
				continue;
			}
			$value = $settings['channels'][ $key ]['value'];
			$label = $settings['channels'][ $key ]['label'];
			$id    = 'qc-' . esc_attr( $key );
			?>
			<tr>
				<th scope="row">
					<label for="<?php echo esc_attr( $id ); ?>">
						<span class="qc-channel-icon"><?php echo Registry::icon( $meta['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- static trusted markup built in Registry. ?></span>
						<?php echo esc_html( $meta['label'] ); ?>
					</label>
				</th>
				<td>
					<input type="text" class="regular-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( Settings::OPTION ); ?>[channels][<?php echo esc_attr( $key ); ?>][value]" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $meta['hint'] ); ?>" />
					<label class="qc-label-override">
						<span class="screen-reader-text">
							<?php
							/* translators: %s: channel name. */
							printf( esc_html__( 'Custom label for %s', 'quickcall-connect' ), esc_html( $meta['label'] ) );
							?>
						</span>
						<input type="text" class="medium-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[channels][<?php echo esc_attr( $key ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'Custom label (optional)', 'quickcall-connect' ); ?>" />
					</label>
				</td>
			</tr>
			<?php
		}
	}

	/**
	 * Output a color row.
	 *
	 * @param string $key   Appearance key.
	 * @param string $title Row title.
	 * @param string $value Current value.
	 */
	private static function color_row( $key, $title, $value ) {
		?>
		<tr>
			<th scope="row"><label for="qc-color-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $title ); ?></label></th>
			<td><input type="color" id="qc-color-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( Settings::OPTION ); ?>[appearance][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( '' !== $value ? $value : '#1a9d61' ); ?>" /></td>
		</tr>
		<?php
	}
}
