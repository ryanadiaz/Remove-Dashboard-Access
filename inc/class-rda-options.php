<?php
/**
 * RDA_Options class file
 *
 * @since 1.2.0
 *
 * @package Remove_Dashboard_Access\Core
 */

/**
 * Core RDA class to handle settings management.
 *
 * @since 1.0.0
 */
class RDA_Options {

	/**
	 * Static instance to make removing actions and filters modular.
	 *
	 * @since 1.1
	 * @static
	 */
	public static $instance;

	/**
	 * Representation of RDA's settings for the current site.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	public $settings = array();

	/**
	 * Sets up RDA_Options.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->setup();
	}

	/**
	 * Sets up various actions, filters, and other items for options management.
	 *
	 * @since 1.1
	 */
	public function setup() {
		load_plugin_textdomain( 'remove-dashboard-access', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

		$this->maybe_map_old_settings();

		$this->settings = array(
			'access_switch'  => get_option( 'rda_access_switch', 'manage_options' ),
			'access_cap'     => get_option( 'rda_access_cap',     'manage_options' ),
			'enable_profile' => get_option( 'rda_enable_profile', 1 ),
			'redirect_url'   => get_option( 'rda_redirect_url', home_url() ),
			'login_message'  => get_option( 'rda_login_message', __( 'This site is in maintenance mode.', 'remove-dashboard-access' ) ),
		);

		// Settings.
		add_action( 'admin_menu',                                array( $this, 'options_page' ) );
		add_action( 'admin_init',                                array( $this, 'settings'         ) );
		add_action( 'admin_head-settings_page_dashboard-access', array( $this, 'access_switch_js' ) );
		add_action( 'wp_ajax_cap_lockout_check',                 array( $this, 'cap_lockout_check' ) );

		// Settings link in plugins list.
		add_filter( 'plugin_action_links', array( $this, 'settings_link' ), 10, 2 );

		// Login message.
		add_filter( 'login_message', array( $this, 'output_login_message' ) );
	}

	/**
	 * (maybe) Maps old settings (1.0-) to the new ones (1.1+).
	 *
	 * @since 1.1
	 */
	public function maybe_map_old_settings() {
		// If the settings aren't there, bail.
		if ( false == $old_settings = get_option( 'rda-settings' ) ) {
			return;
		}

		$new_settings = array();

		if ( ! empty( $old_settings ) && is_array( $old_settings ) ) {
			// Access Switch.
			$new_settings['rda_access_switch'] = empty( $old_settings['access_switch'] ) ? 'manage_options' : $old_settings['access_switch'];

			// Access Cap.
			$new_settings['rda_access_cap'] = ( 'capability' == $new_settings['access_switch'] ) ? 'manage_options' : $new_settings['rda_access_switch'];

			// Redirect URL.
			$new_settings['rda_redirect_url'] = empty( $old_settings['redirect_url'] ) ? home_url() : $old_settings['redirect_url'];

			// Enable Profile.
			$new_settings['rda_enable_profile'] = empty( $old_settings['enable_profile'] ) ? true : $old_settings['enable_profile'];

			// Login Message.
			$new_settings['rda_login_message'] = '';
		}

		foreach ( $new_settings as $key => $value ) {
			update_option( $key, $value );
		}

		delete_option( 'rda-settings' );
	}

	/**
	 * Specifies logic to run during plugin activation.
	 *
	 * Sets default options on activation.
	 *
	 * @since 1.0.0
	 */
	public function activate() {
		$settings = array(
			'rda_access_switch'  => 'manage_options',
			'rda_access_cap'     => 'manage_options',
			'rda_redirect_url'   => home_url(),
			'rda_enable_profile' => 1,
			'rda_login_message'  => ''
		);

		foreach ( $settings as $key => $value ) {
			update_option( $key, $value );
		}
	}

	/**
	 * Registers the Dashboard Access submenu page.
	 *
	 * @since 1.1.1
	 */
	public function options_page() {
		add_options_page(
			__( 'Dashboard Access Settings', 'remove-dashboard-access' ),
			__( 'Dashboard Access', 'remove-dashboard-access' ),
			'manage_options',
			'dashboard-access',
			array( $this, 'options_page_cb' )
		);
	}

	/**
	 * Renders the Dashboard Access submenu page.
	 *
	 * @since 1.1.1
	 */
	public function options_page_cb() {
		?>
		<div class="wrap">
			<h1><?php _e( 'Dashboard Access Settings', 'remove-dashboard-access' ); ?></h1>
			<form action="options.php" method="POST" id="rda-options-form">
				<?php
				settings_fields( 'dashboard-access' );
				do_settings_sections( 'dashboard-access' );
				submit_button();
				?>
			</form>
		</div><!-- .wrap -->
		<?php
	}

	/**
	 * Registers settings and settings sections.
	 *
	 * @since 1.0.0
	 */
	public function settings() {
		// Dashboard Access Controls section.
		add_settings_section( 'rda_options', '', array( $this, 'settings_section' ), 'dashboard-access' );

		// Settings.
		$sets = array(
			'rda_access_switch'  => array(
				'label'    => __( 'User Access:', 'remove-dashboard-access' ),
				'callback' => 'access_switch_cb',
			),
			'rda_access_cap'     => array(
				'label'    => '',
				'callback' => 'access_cap_dropdown',
			),
			'rda_redirect_url'   => array(
				'label'    => __( 'Redirect URL:', 'remove-dashboard-access' ),
				'callback' => 'url_redirect_cb',
			),
			'rda_enable_profile' => array(
				'label'    => __( 'User Profile Access:', 'remove-dashboard-access' ),
				'callback' => 'profile_enable_cb',
			),
			'rda_login_message'  => array(
				'label'    => __( 'Login Message', 'remove-dashboard-access' ),
				'callback' => 'login_message_cb',
			),
		);

		foreach ( $sets as $id => $settings ) {
			add_settings_field( $id, $settings['label'], array( $this, $settings['callback'] ), 'dashboard-access', 'rda_options' );

			// Pretty lame that we need separate sanitize callbacks for everything.
			$sanitize_callback = str_replace( 'rda', 'sanitize', $id );
			register_setting( 'dashboard-access', $id, array( $this, $sanitize_callback ) );
		};

		// Debug info "setting".
		if ( ! empty( $_GET['rda_debug'] ) ) {
			add_settings_field( 'rda_debug_mode', __( 'Debug Info', 'remove-dashboard-access' ), array( $this, '_debug_mode' ), 'dashboard-access', 'rda_options' );
		}

	}

	/**
	 * Renders the contents of the 'Dashboard Access Settings' section.
	 *
	 * @since 1.1
	 */
	public function settings_section() {
		_e( 'Dashboard access can be restricted to users of certain roles only or users with a specific capability.', 'remove-dashboard-access' );
	}

	/**
	 * Renders the 'Advanced' section of the 'User Access' settings UI.
	 *
	 * @since 1.1
	 */
	public function access_cap_dropdown() {
		$switch = $this->settings['access_switch'];
		?>
		<style type="text/css">
			.lockout-message {
				margin: 0.5em 0;
				padding: 10px;
				display: block;
			}
			.lockout-message.notice {
				margin: 0.5em 0 0;
			}
			#rda-no-submit-message {
				margin-left: 10px;
			}
		</style>
		<p><label>
				<input name="rda_access_switch" type="radio" value="capability" class="tag" <?php checked( 'capability', esc_attr( $switch ) ); ?> />
				<?php _e( '<strong>Advanced</strong> (limit by capability):', 'remove-dashboard-access' ); ?>
			</label><?php $this->_output_caps_dropdown(); ?></p>
		<p class="description">
			<?php printf( __( 'You can find out more about specific %s in the Codex.', 'remove-dashboard-access' ),
				sprintf( '<a href="%1$s" target="_new">%2$s</a>',
					esc_url( 'http://codex.wordpress.org/Roles_and_Capabilities' ),
					esc_html( __( 'Roles &amp; Capabilities', 'remove-dashboard-access' ) )
				)
			); ?>
		</p>
		<?php
	}

	/**
	 * Enqueues and localizes the JavaScript used by the access switcher.
	 *
	 * @since 1.0.0
	 */
	public function access_switch_js() {
		wp_enqueue_script( 'rda-settings', plugin_dir_url( __FILE__ ) . 'js/settings.js', array( 'wp-a11y' ), '1.0' );

		wp_localize_script( 'rda-settings', 'rda_vars', array(
			'ajaxurl'    => admin_url( 'admin-ajax.php' ),
			'no_submit'  => __( 'Please choose a compatible User Access setting to proceed with saving changes.', 'remove-dashboard-access' ),
			'yes_submit' => __( 'You may now proceed with saving changes.', 'remove-dashboard-access' ),
		) );
	}

	/**
	 * Ajax handler for checking whether the current user has the chosen capability.
	 *
	 * Helps prevent admins from locking themselves out by setting a cap they don't have.
	 *
	 * @since 1.2.0
	 */
	public function cap_lockout_check() {
		check_ajax_referer( 'rda-lockout-nonce', 'nonce' );

		$capbility    = isset( $_REQUEST['cap'] ) ? sanitize_key( $_REQUEST['cap'] ) : '';
		$switch_value = isset( $_REQUEST['switch'] ) ? sanitize_key( $_REQUEST['switch'] ) : '';

		if ( empty( $capbility ) ) {
			wp_send_json_error( new \WP_Error( 'missing_cap', 'A capability must be sent with the request.', $_REQUEST ) );
		}

		if ( empty( $switch_value ) ) {
			wp_send_json_error( new \WP_Error( 'missing_switch', 'A capability switch value must be sent with the request.', $_REQUEST ) );
		}

		if ( current_user_can( $capbility ) ) {
			wp_send_json_success();
		} else {
			$message = $this->get_warning_message( $switch_value );

			wp_send_json_error( array(
				'message' => sprintf( $message, '<code>' . $capbility . '</code>' )
			) );
		}

		wp_die( 1 );
	}

	/**
	 * Retrieves the warning message for the given capability and role alias.
	 *
	 * @since 1.2.0
	 *
	 * @param string $capability_switch The capability switch setting.
	 * @return string Warning message that takes the switch value into account to provide context.
	 */
	public function get_warning_message( $capability_switch ) {

		$defaults = self::get_default_caps();

		switch( $capability_switch ) {

			case $defaults['admin']:
				/* translators: %s is the formatted capability slug */
				$message = __( '<strong>Warning:</strong> Your account doesn&#8217;t have the Admin capability, %s, which could lock you out of the dashboard.', 'remove-dashboard-access' );
				break;

			case $defaults['editor']:
				/* translators: %s is the formatted capability slug */
				$message = __( '<strong>Warning:</strong> Your account doesn&#8217;t have the Editor or Admin capability, %s, which could lock you out of the dashboard.', 'remove-dashboard-access' );
				break;

			case $defaults['author']:
				/* translators: %s is the formatted capability slug */
				$message = __( '<strong>Warning:</strong> Your account doesn&#8217;t have the Author, Editor, or Admin capability, %s, which could lock you out of the dashboard.', 'remove-dashboard-access' );
				break;

			default:
			case 'capability':
				/* translators: %s is the formatted capability slug */
				$message = __( '<strong>Warning:</strong> Your account doesn&#8217;t have the %s capability, which could lock you out of the dashboard.', 'remove-dashboard-access' );
				break;
		}

		return $message;
	}

	/**
	 * Renders the bulk of the 'User Access' control setting UI.
	 *
	 * Displays the radio button switch for choosing which capability users need
	 * to access the Dashboard. Mimics 'Page on front' UI in options-reading.php
	 * for a more integrated feel.
	 *
	 * @since 1.0.0
	 */
	public function access_switch_cb() {
		echo '<a name="dashboard-access"></a>';

		$switch   = $this->settings['access_switch'];
		$defaults = self::get_default_caps();
		?>
		<p><label>
				<input name="rda_access_switch" type="radio" value="<?php echo esc_attr( $defaults['admin'] ); ?>" class="tag" <?php checked( $defaults['admin'], esc_attr( $switch ) ); ?> />
				<?php _e( 'Administrators only', 'remove-dashboard-access' ); ?>
			</label></p>
		<p><label>
				<input name="rda_access_switch" type="radio" value="<?php echo esc_attr( $defaults['editor'] ); ?>" class="tag" <?php checked( $defaults['editor'], esc_attr( $switch ) ); ?> />
				<?php _e( 'Editors and Administrators', 'remove-dashboard-access' ); ?>
			</label></p>
		<p><label>
				<input name="rda_access_switch" type="radio" value="<?php echo esc_attr( $defaults['author'] ); ?>" class="tag" <?php checked( $defaults['author'], esc_attr( $switch ) ); ?> />
				<?php _e( 'Authors, Editors, and Administrators', 'remove-dashboard-access' ); ?>
			</label></p>
		<?php wp_nonce_field( 'rda-lockout-nonce', 'rda-lockout-nonce' ); ?>
		<input type="hidden" id="selected-capability" name="selected-capability" value="<?php echo esc_attr( $this->settings['access_cap'] ); ?>" />
		<span class="lockout-message notice notice-error screen-reader-text" id="lockout-message"></span>
		<?php
	}

	/**
	 * Retrieves the default capabilities for the role-based settings.
	 *
	 * @since 1.2.0
	 * @static
	 *
	 * @return array Pairs of role-based setting abbreviations and their default capabilities.
	 */
	public static function get_default_caps() {

		$defaults = array(
			'admin'  => 'manage_options',
			'editor' => 'edit_others_posts',
			'author' => 'publish_posts'
		);

		/**
		 * Filter the capability defaults for admins, editors, and authors.
		 *
		 * @since 1.1
		 *
		 * @param array $capabilities {
		 *     Default capabilities for various roles.
		 *
		 *     @type string $admin  Capability to use for administrators only. Default 'manage_options'.
		 *     @type string $editor Capability to use for admins + editors. Default 'edit_others_posts'.
		 *     @type string $author Capability to use for admins + editors + authors. Default 'publish_posts'.
		 * }
		 */
		$caps = apply_filters( 'rda_default_caps_for_role', $defaults );

		return array_merge( $caps, $defaults );
	}

	/**
	 * Renders the actual drop-down element used by the access_cap_dropdown() method.
	 *
	 * @since 1.0.0
	 */
	private function _output_caps_dropdown() {
		/** @global WP_Roles $wp_roles */
		global $wp_roles;

		$capabilities = array();

		if ( ! isset( $wp_roles ) ) {
			if ( function_exists( 'wp_roles' ) ) {
				$wp_roles = wp_roles();
			} else {
				$wp_roles = new WP_Roles();
			}
		}

		foreach ( $wp_roles->role_objects as $key => $role ) {
			if ( is_array( $role->capabilities ) ) {
				foreach ( $role->capabilities as $cap => $grant )
					$capabilities[$cap] = $cap;
			}
		}

		// Gather legacy user levels.
		$levels = array(
			'level_0','level_1', 'level_2', 'level_3',
			'level_4', 'level_5', 'level_6', 'level_7',
			'level_8', 'level_9', 'level_10',
		);

		// Remove levels from caps array (Thank you Justin Tadlock).
		$capabilities = array_diff( $capabilities, $levels );

		// Remove # capabilities (maybe from some plugin, perhaps?).
		for ( $i = 0; $i < 12; $i++ ) {
			unset( $capabilities[$i] );
		}

		// Alphabetize for nicer display.
		ksort( $capabilities );

		if ( ! empty( $capabilities ) ) {
			// Start <select> element.
			print( '<select name="rda_access_cap">' );

			// Default first option.
			printf( '<option selected="selected" value="manage_options">%s</option>', __( '--- Select a Capability ---', 'removed_dashboard_access' ) );

			// Build capabilities dropdown.
			foreach ( $capabilities as $capability => $value ) {
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $this->settings['access_cap'], $value ), esc_html( $capability ) );
			}
			print( '</select>' );
		}
	}

	/**
	 * Renders the 'User Profile Access' setting UI.
	 *
	 * @since 1.0.0
	 */
	public function profile_enable_cb() {
		printf( '<input name="rda_enable_profile" type="checkbox" value="1" class="code" %1$s/>%2$s',
			checked( esc_attr( $this->settings['enable_profile'] ), true, false ),
			/* Translators: The leading space is intentional to space the text away from the checkbox */
			__( ' Allow all users to edit their profiles in the dashboard.', 'remove-dashboard-access' )
		);
	}

	/**
	 * Renders the 'Redirect URL' setting UI.
	 *
	 * @since 1.0.0
	 */
	public function url_redirect_cb() {
		?>
		<p><label>
				<?php _e( 'Redirect disallowed users to:', 'remove-dashboard-access' ); ?>
				<input name="rda_redirect_url" class="regular-text" type="text" value="<?php echo esc_attr( $this->settings['redirect_url'] ); ?>" placeholder="<?php printf( esc_attr__( 'Default: %s', 'remove-dashboard-access' ), home_url() ); ?>" />
			</label></p>
		<?php
	}

	/**
	 * Renders the 'Login Screen Message' setting UI.
	 *
	 * @since 1.1
	 */
	public function login_message_cb() {
		?>
		<p><input name="rda_login_message" class="regular-text" type="text" value="<?php echo esc_attr( $this->settings['login_message'] ); ?>" /></p>
		<p class="description"><?php esc_html_e( 'Message to display on the login screen. Disabled if left blank (default).', 'remove-dashboard-access' ); ?></p>
		<?php
	}

	/**
	 * Renders the actual login screen message on the login screen.
	 *
	 * @since 1.1
	 */
	public function output_login_message( $message ) {
		if ( ! empty( $this->settings['login_message'] ) ) {
			$message .= '<p class="message">' . esc_html( $this->settings['login_message'] ) . '</p>';
		}
		return $message;
	}

	/**
	 * Sanitizes the value of the access switch setting on save.
	 *
	 * @since 1.1
	 *
	 * @param string $option Access switch capability.
	 * @return string Sanitized capability.
	 */
	public function sanitize_access_switch( $option ) {
		return $option;
	}

	/**
	 * Sanitizes the value of the access capability setting on save.
	 *
	 * @since 1.1
	 *
	 * @param string $option Access capability.
	 * @return string Sanitized capability. If the option is empty, default to the value of
	 *                'rda_access_switch'.
	 */
	public function sanitize_access_cap( $option ) {
		return empty( $option ) ? get_option( 'rda_access_switch' ) : $option;
	}

	/**
	 * Sanitizes the value of the redirect URL setting on save.
	 *
	 * @since 1.1
	 *
	 * @param string $option Redirect URL.
	 * @return string If empty, defaults to home_url(). Otherwise sanitized URL.
	 */
	public function sanitize_redirect_url( $option ) {
		return empty( $option ) ? home_url() : esc_url_raw( $option );
	}

	/**
	 * Sanitizes the value of the enable profile setting on save.
	 *
	 * @since 1.1
	 *
	 * @param bool $option Whether to enable all users to edit their profiles.
	 * @return bool Whether all users will be able to edit their profiles.
	 */
	public function sanitize_enable_profile( $option ) {
		return (bool) empty( $option ) ? false : true;
	}

	/**
	 * Sanitizes the value of the login screen message setting on save.
	 *
	 * @since 1.1
	 *
	 * @param string $option Login message.
	 * @return string Sanitized login message.
	 */
	public function sanitize_login_message( $option ) {
		return sanitize_text_field( $option );
	}

	/**
	 * Determines the capability required for access to the admin.
	 *
	 * @since 1.0.0
	 *
	 * @return string The value of `$this->settings['access_cap']` if set, otherwise, 'manage_options'.
	 */
	public function capability() {
		/**
		 * Filters the access capability.
		 *
		 * @since 1.1
		 *
		 * @param string $capability Capability needed to access the Dashboard.
		 */
		return apply_filters( 'rda_access_capability', $this->settings['access_cap'] );
	}

	/**
	 * Renders the 'Settings' link in the plugin list table row.
	 *
	 * @since 1.0.0
	 *
	 * @param array $links Row links array to filter.
	 * @return array $links Filtered links array.
	 */
	public function settings_link( $links, $file ) {
		// WordPress.org slug.
		if ( 'remove-dashboard-access-for-non-admins/remove-dashboard-access.php' == $file
		     // GitHub slug
		     || 'remove-dashboard-access/remove-dashboard-access.php' == $file
		) {
			array_unshift( $links, sprintf( '<a href="%1$s">%2$s</a>',
				admin_url( 'options-general.php?page=dashboard-access' ),
				esc_html__( 'Settings', 'remove-dashboard-access' )
			) );
		}
		return $links;
	}

	/**
	 * Outputs debugging information if triggered.
	 *
	 * When rda_debug=1 is passed via the query string on the settings page, a table with
	 * all the raw option values is displayed for debugging purposes.
	 *
	 * @since 1.1
	 */
	public function _debug_mode() {
		?>
		<style type="text/css">
			table.rda_debug {
				width: 400px;
				border: 1px solid #222;
			}
			.rda_debug th {
				text-align: center;
			}
			.rda_debug th,
			.rda_debug td {
				width: 50%;
				padding: 15px 10px;
				border: 1px solid #222;
			}
		</style>
		<table class="rda_debug">
			<tbody>
			<tr>
				<th><?php _e( 'Setting', 'remove-dashboard-access' ); ?></th>
				<th><?php _e( 'Value', 'remove-dashboard-access' ); ?></th>
			</tr>
			<?php foreach ( $this->settings as $key => $value ) :
				$value = empty( $value ) ? __( 'empty', 'remove-dashboard-access' ) : $value;
				?>
				<tr>
					<td><?php echo esc_html( $key ); ?></td>
					<td><?php echo esc_html( $value ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

}
