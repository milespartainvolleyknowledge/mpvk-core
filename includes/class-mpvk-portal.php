<?php
defined( 'ABSPATH' ) || exit;

/**
 * Front-end portal at /portal — standalone, theme-independent, mobile-first.
 * Also brands the WP login screen (single branded login for all tiers) and routes
 * org/client users to the portal instead of wp-admin.
 */
class MPVK_Portal {

	public static function add_rewrites(): void {
		add_rewrite_rule( '^portal/?$', 'index.php?mpvk_portal=1', 'top' );
	}

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrites' ) );

		add_filter( 'query_vars', function ( $vars ) {
			$vars[] = 'mpvk_portal';
			return $vars;
		} );

		add_action( 'template_redirect', function () {
			if ( '1' !== get_query_var( 'mpvk_portal' ) ) {
				return;
			}
			if ( ! is_user_logged_in() ) {
				wp_safe_redirect( wp_login_url( home_url( '/portal' ) ) );
				exit;
			}
			$tier = MPVK_Roles::tier_of( get_current_user_id() );
			if ( 'none' === $tier ) {
				wp_die( 'Your account has no MPVK access. Contact your coach.' );
			}
			self::render();
			exit;
		} );

		// After login, send org/client tiers to the portal (admins keep wp-admin).
		add_filter( 'login_redirect', function ( $redirect, $requested, $user ) {
			if ( $user instanceof WP_User && in_array( MPVK_Roles::tier_of( $user->ID ), array( 'org', 'client' ), true ) ) {
				return home_url( '/portal' );
			}
			return $redirect;
		}, 10, 3 );

		// Keep org/client out of wp-admin; hide the admin bar for them.
		add_action( 'admin_init', function () {
			if ( wp_doing_ajax() ) {
				return;
			}
			$tier = MPVK_Roles::tier_of( get_current_user_id() );
			if ( in_array( $tier, array( 'org', 'client' ), true ) ) {
				wp_safe_redirect( home_url( '/portal' ) );
				exit;
			}
		} );
		add_filter( 'show_admin_bar', function ( $show ) {
			$tier = MPVK_Roles::tier_of( get_current_user_id() );
			return in_array( $tier, array( 'org', 'client' ), true ) ? false : $show;
		} );

		// Branded login (parchment + ink + logo).
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_css' ) );
		add_filter( 'login_headerurl', fn() => home_url( '/' ) );
		add_filter( 'login_headertext', fn() => get_bloginfo( 'name' ) );
	}

	public static function login_css(): void {
		$logo = '';
		$logo_id = (int) get_option( 'site_logo' );
		if ( $logo_id ) {
			$src = wp_get_attachment_image_url( $logo_id, 'medium' );
			if ( $src ) {
				$logo = "#login h1 a{background-image:url('" . esc_url( $src ) . "')!important;background-size:contain;width:110px;height:110px;}";
			}
		}
		echo '<style>
		body.login{background:#F0E7D3;}
		body.login #login form{background:#FFFCF3;border:1px solid #6E4E30;border-radius:12px;}
		body.login .button-primary{background:#B9922F!important;border-color:#B9922F!important;color:#1F2E40!important;border-radius:999px;}
		body.login #backtoblog a, body.login #nav a{color:#2C5F63;}
		' . $logo . '</style>';
	}

	private static function render(): void {
		$css   = file_get_contents( MPVK_PLUGIN_DIR . 'assets/portal.css' );
		$js    = file_get_contents( MPVK_PLUGIN_DIR . 'assets/portal.js' );
		$nonce = wp_create_nonce( 'wp_rest' );
		$root  = esc_url_raw( rest_url( 'mpvk/v1' ) );
		$logout = esc_url( wp_logout_url( home_url( '/' ) ) );
		header( 'Content-Type: text/html; charset=utf-8' );
		// phpcs:disable WordPress.Security.EscapeOutput -- assets are plugin-shipped files
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
			. '<title>' . esc_html( get_bloginfo( 'name' ) ) . ' — Portal</title>'
			. MPVK_PWA::head_tags()
			. '<style>' . $css . '</style></head><body>'
			. '<div id="mpvk-app" data-rest="' . esc_attr( $root ) . '" data-nonce="' . esc_attr( $nonce ) . '" data-logout="' . esc_attr( $logout ) . '" data-vapid="' . esc_attr( get_option( 'mpvk_vapid_public', '' ) ) . '" data-passkeys="' . ( MPVK_WebAuthn::enabled() ? '1' : '' ) . '"></div>'
			. '<script>' . $js . '</script>'
			. '<script>' . MPVK_PWA::boot_script() . '</script></body></html>';
		// phpcs:enable
	}
}
