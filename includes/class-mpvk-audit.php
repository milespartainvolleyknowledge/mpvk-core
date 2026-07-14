<?php
defined( 'ABSPATH' ) || exit;

/** Security audit log — append-only. Logins, failures, permission denials, sensitive writes. */
class MPVK_Audit {

	public static function init(): void {
		add_action( 'wp_login', function ( $login, $user ) {
			self::log( 'login_success', array( 'user_id' => $user->ID ) );
		}, 10, 2 );
		add_action( 'wp_login_failed', function ( $login ) {
			self::log( 'login_failed', array( 'meta' => array( 'login' => (string) $login ) ) );
		} );
		add_action( 'wp_logout', function ( $user_id ) {
			self::log( 'logout', array( 'user_id' => (int) $user_id ) );
		} );
	}

	public static function log( string $action, array $data = array() ): void {
		global $wpdb;
		$user_id = (int) ( $data['user_id'] ?? get_current_user_id() );
		$wpdb->insert( MPVK_Schema::table( 'audit_log' ), array(
			'org_id'      => (int) ( $data['org_id'] ?? ( $user_id ? MPVK_Tenancy::org_id_of( $user_id ) : 0 ) ),
			'user_id'     => $user_id,
			'action'      => substr( $action, 0, 60 ),
			'object_type' => isset( $data['object_type'] ) ? substr( (string) $data['object_type'], 0, 40 ) : null,
			'object_id'   => isset( $data['object_id'] ) ? (int) $data['object_id'] : null,
			'ip'          => self::ip(),
			'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : null,
			'meta'        => isset( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : null,
			'created_at'  => current_time( 'mysql', true ),
		) );
	}

	public static function ip(): string {
		// WP Engine terminates at a proxy; REMOTE_ADDR is rewritten to client IP by the platform.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? substr( (string) $_SERVER['REMOTE_ADDR'], 0, 64 ) : '';
	}
}
