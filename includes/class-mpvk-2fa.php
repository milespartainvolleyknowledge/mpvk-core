<?php
defined( 'ABSPATH' ) || exit;

/**
 * Two-factor auth (TOTP) for admins. Enroll on your WP profile; once enabled, login
 * requires an authenticator code (or a one-time recovery code). MVP scope = admin tier;
 * coach-tier enrollment (portal-based) is a fast-follow.
 *
 * SAFETY: enforcement is per-user "enforced once enrolled" — un-enrolled admins are NUDGED,
 * not hard-blocked, so a config/bug can never lock the owner out. Flip MPVK_2FA_MANDATORY
 * to true (define in wp-config) only after you've enrolled + tested your own device.
 */
class MPVK_2FA {

	const META_SECRET   = 'mpvk_2fa_secret';
	const META_ENABLED  = 'mpvk_2fa_enabled';
	const META_RECOVERY = 'mpvk_2fa_recovery';

	public static function init(): void {
		// Enforcement at login (after core validates the password at priority 20).
		add_filter( 'authenticate', array( __CLASS__, 'check_2fa' ), 25, 3 );
		add_action( 'login_form', array( __CLASS__, 'login_field' ) );

		// Enrollment UI on the user's own profile (admins).
		add_action( 'show_user_profile', array( __CLASS__, 'profile_section' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );

		// Nudge un-enrolled admins.
		add_action( 'admin_notices', array( __CLASS__, 'nag' ) );
	}

	public static function is_enabled( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, self::META_ENABLED, true )
			&& '' !== (string) get_user_meta( $user_id, self::META_SECRET, true );
	}

	public static function requires_2fa( WP_User $user ): bool {
		// Admin tier must; extendable to org later.
		return user_can( $user, 'manage_options' );
	}

	// ---------- login enforcement ----------

	public static function login_field(): void {
		echo '<p><label for="mpvk_2fa_code">Authenticator code <span style="font-weight:400;color:#666">(if 2FA is on)</span><br>'
			. '<input type="text" inputmode="numeric" autocomplete="one-time-code" name="mpvk_2fa_code" id="mpvk_2fa_code" class="input" value="" size="20" /></label></p>';
	}

	public static function check_2fa( $user, $username, $password ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return $user; // password already failed — nothing to do
		}
		if ( ! self::is_enabled( $user->ID ) ) {
			return $user; // 2FA not set up for this user
		}
		// Only enforce when a password was actually submitted (avoid interfering with cookie/app-password auth).
		if ( empty( $password ) ) {
			return $user;
		}
		$code = isset( $_POST['mpvk_2fa_code'] ) ? sanitize_text_field( wp_unslash( $_POST['mpvk_2fa_code'] ) ) : '';
		if ( '' === $code ) {
			return new WP_Error( 'mpvk_2fa_required', __( 'Enter your authenticator code to finish signing in.' ) );
		}
		$secret = (string) get_user_meta( $user->ID, self::META_SECRET, true );
		if ( MPVK_TOTP::verify( $secret, $code ) ) {
			return $user;
		}
		if ( self::consume_recovery( $user->ID, $code ) ) {
			MPVK_Audit::log( 'twofa_recovery_used', array( 'user_id' => $user->ID ) );
			return $user;
		}
		MPVK_Audit::log( 'twofa_failed', array( 'user_id' => $user->ID ) );
		return new WP_Error( 'mpvk_2fa_bad', __( 'That authenticator code was not valid.' ) );
	}

	// ---------- recovery codes ----------

	public static function generate_recovery( int $user_id ): array {
		$codes  = array();
		$hashed = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$c        = strtoupper( wp_generate_password( 10, false ) );
			$c        = substr( $c, 0, 5 ) . '-' . substr( $c, 5, 5 );
			$codes[]  = $c;
			$hashed[] = wp_hash_password( $c );
		}
		update_user_meta( $user_id, self::META_RECOVERY, $hashed );
		return $codes; // plaintext shown once
	}

	public static function consume_recovery( int $user_id, string $code ): bool {
		$code   = strtoupper( trim( $code ) );
		$hashed = (array) get_user_meta( $user_id, self::META_RECOVERY, true );
		require_once ABSPATH . 'wp-includes/class-phpass.php';
		foreach ( $hashed as $i => $h ) {
			if ( wp_check_password( $code, $h ) ) {
				unset( $hashed[ $i ] );
				update_user_meta( $user_id, self::META_RECOVERY, array_values( $hashed ) );
				return true;
			}
		}
		return false;
	}

	// ---------- enrollment (profile page) ----------

	public static function profile_section( WP_User $user ): void {
		if ( ! current_user_can( 'manage_options' ) || $user->ID !== get_current_user_id() ) {
			return;
		}
		$enabled = self::is_enabled( $user->ID );
		$secret  = (string) get_user_meta( $user->ID, self::META_SECRET, true );
		if ( ! $secret ) {
			$secret = MPVK_TOTP::new_secret();
			update_user_meta( $user->ID, self::META_SECRET, $secret ); // pending until confirmed
		}
		$uri = MPVK_TOTP::provisioning_uri( $secret, $user->user_email, get_bloginfo( 'name' ) );
		wp_nonce_field( 'mpvk_2fa', 'mpvk_2fa_nonce' );
		echo '<h2>MPVK Two-Factor Authentication</h2><table class="form-table"><tr><th>Status</th><td>';
		if ( $enabled ) {
			echo '<strong style="color:#2C5F63">On.</strong> Login requires your authenticator code.'
				. '<p><label><input type="checkbox" name="mpvk_2fa_disable" value="1"> Turn off 2FA</label> '
				. '— confirm with a current code (or a recovery code): '
				. '<input type="text" name="mpvk_2fa_disable_code" inputmode="numeric" autocomplete="one-time-code" size="12"></p>';
		} else {
			echo '<strong style="color:#a00">Off.</strong> Protect your admin account:';
			echo '<ol style="max-width:640px">';
			echo '<li>In your authenticator app (Google Authenticator, 1Password, Authy…), add an account and enter this key manually:<br><code style="font-size:16px;letter-spacing:2px">' . esc_html( trim( chunk_split( $secret, 4, ' ' ) ) ) . '</code></li>';
			echo '<li>Account name: <code>' . esc_html( get_bloginfo( 'name' ) . ':' . $user->user_email ) . '</code> · type TOTP · 6 digits · 30s.</li>';
			echo '<li>Enter the current 6-digit code to confirm: <input type="text" name="mpvk_2fa_confirm" inputmode="numeric" autocomplete="one-time-code" size="10"> then Save.</li>';
			echo '</ol>';
			echo '<p style="font-size:12px;color:#666">Advanced: otpauth URI — <code style="word-break:break-all">' . esc_html( $uri ) . '</code></p>';
		}
		echo '</td></tr></table>';
	}

	public static function save_profile( int $user_id ): void {
		if ( $user_id !== get_current_user_id() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_POST['mpvk_2fa_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mpvk_2fa_nonce'] ) ), 'mpvk_2fa' ) ) {
			return;
		}
		if ( ! empty( $_POST['mpvk_2fa_disable'] ) ) {
			// Disabling requires proving control of the second factor (a current TOTP code
			// or a recovery code) — otherwise a hijacked logged-in session could silently
			// strip 2FA. You can always log in first with a recovery code if the phone is lost.
			$dcode  = isset( $_POST['mpvk_2fa_disable_code'] ) ? sanitize_text_field( wp_unslash( $_POST['mpvk_2fa_disable_code'] ) ) : '';
			$secret = (string) get_user_meta( $user_id, self::META_SECRET, true );
			$ok     = '' !== $dcode && ( MPVK_TOTP::verify( $secret, $dcode ) || self::consume_recovery( $user_id, $dcode ) );
			if ( ! $ok ) {
				set_transient( 'mpvk_2fa_err_' . $user_id, 'To turn off 2FA, enter a current authenticator code (or a recovery code) next to the checkbox. 2FA is still on.', 30 );
				MPVK_Audit::log( 'twofa_disable_denied', array( 'user_id' => $user_id ) );
				return;
			}
			update_user_meta( $user_id, self::META_ENABLED, 0 );
			delete_user_meta( $user_id, self::META_SECRET );
			delete_user_meta( $user_id, self::META_RECOVERY );
			MPVK_Audit::log( 'twofa_disabled', array( 'user_id' => $user_id ) );
			return;
		}
		$confirm = isset( $_POST['mpvk_2fa_confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['mpvk_2fa_confirm'] ) ) : '';
		if ( ! self::is_enabled( $user_id ) && '' !== $confirm ) {
			$secret = (string) get_user_meta( $user_id, self::META_SECRET, true );
			if ( $secret && MPVK_TOTP::verify( $secret, $confirm ) ) {
				update_user_meta( $user_id, self::META_ENABLED, 1 );
				$codes = self::generate_recovery( $user_id );
				set_transient( 'mpvk_2fa_codes_' . $user_id, $codes, 5 * MINUTE_IN_SECONDS );
				MPVK_Audit::log( 'twofa_enabled', array( 'user_id' => $user_id ) );
			} else {
				set_transient( 'mpvk_2fa_err_' . $user_id, 'The code did not match — 2FA not enabled. Try again.', 30 );
			}
		}
	}

	public static function nag(): void {
		$u = get_current_user_id();
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$codes = get_transient( 'mpvk_2fa_codes_' . $u );
		if ( $codes ) {
			delete_transient( 'mpvk_2fa_codes_' . $u );
			echo '<div class="notice notice-success"><p><strong>2FA is on.</strong> Save these one-time recovery codes somewhere safe (shown once):</p><p style="font-family:monospace;font-size:14px">' . esc_html( implode( '   ', (array) $codes ) ) . '</p></div>';
		}
		$err = get_transient( 'mpvk_2fa_err_' . $u );
		if ( $err ) {
			delete_transient( 'mpvk_2fa_err_' . $u );
			echo '<div class="notice notice-error"><p>' . esc_html( $err ) . '</p></div>';
		}
		if ( ! self::is_enabled( $u ) ) {
			$url = admin_url( 'profile.php#mpvk-2fa' );
			echo '<div class="notice notice-warning"><p><strong>Protect your MPVK admin account:</strong> two-factor authentication is off. <a href="' . esc_url( $url ) . '">Turn it on in your profile →</a></p></div>';
		}
	}
}
