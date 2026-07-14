<?php
defined( 'ABSPATH' ) || exit;

/**
 * Security baseline for MVP. (2FA lands as its own pass after the deep-research report;
 * this file is where it will plug in.)
 * - Login lockout: transient counter per IP+username, 5 fails → 15 min lock.
 * - REST hardening: user enumeration closed for non-privileged users.
 * - Session: clients get shorter "remember me" horizon.
 */
class MPVK_Security {

	const MAX_ATTEMPTS = 5;
	const LOCK_SECONDS = 15 * MINUTE_IN_SECONDS;

	public static function init(): void {
		// Priority 30: runs AFTER core's wp_authenticate_username_password (20), which
		// otherwise discards an early WP_Error and re-checks credentials. We deny here
		// regardless of whether core produced a valid WP_User, so the lock is real.
		add_filter( 'authenticate', array( __CLASS__, 'check_lockout' ), 30, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'record_failure' ) );
		add_action( 'wp_login', array( __CLASS__, 'clear_failures' ), 10, 1 );

		// Close user enumeration via REST for anyone who can't manage users.
		add_filter( 'rest_endpoints', function ( $endpoints ) {
			if ( ! current_user_can( 'list_users' ) ) {
				unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
			}
			return $endpoints;
		} );

		// Close ?author=N archives (classic enumeration vector).
		add_action( 'template_redirect', function () {
			if ( is_author() && ! is_user_logged_in() ) {
				wp_safe_redirect( home_url(), 301 );
				exit;
			}
		} );

		// Genuinely shorten the client auth-cookie horizon (core default is already 2d /
		// 14d with remember-me, so capping at 14d did nothing). Cap clients at 3 days —
		// EXCEPT passkey logins: a Face-ID-verified device earns the long horizon (30d),
		// because the short cap was a password-era mitigation.
		add_filter( 'auth_cookie_expiration', function ( $seconds, $user_id ) {
			if ( 'client' === MPVK_Roles::tier_of( (int) $user_id ) ) {
				if ( class_exists( 'MPVK_WebAuthn' ) && MPVK_WebAuthn::$passkey_login ) {
					return 30 * DAY_IN_SECONDS;
				}
				return min( $seconds, 3 * DAY_IN_SECONDS );
			}
			return $seconds;
		}, 10, 2 );
	}

	private static function lock_key( string $username ): string {
		return 'mpvk_lock_' . md5( MPVK_Audit::ip() . '|' . strtolower( trim( $username ) ) );
	}

	public static function check_lockout( $user, $username ) {
		if ( empty( $username ) ) {
			return $user;
		}
		$data = get_transient( self::lock_key( $username ) );
		if ( is_array( $data ) && $data['count'] >= self::MAX_ATTEMPTS ) {
			MPVK_Audit::log( 'login_locked_out', array( 'meta' => array( 'login' => $username ) ) );
			return new WP_Error(
				'mpvk_locked',
				__( 'Too many failed attempts. Try again in 15 minutes.' )
			);
		}
		return $user;
	}

	public static function record_failure( $username ): void {
		$key  = self::lock_key( (string) $username );
		$data = get_transient( $key );
		$data = is_array( $data ) ? $data : array( 'count' => 0 );
		$data['count']++;
		set_transient( $key, $data, self::LOCK_SECONDS );
	}

	public static function clear_failures( $username ): void {
		delete_transient( self::lock_key( (string) $username ) );
	}
}
