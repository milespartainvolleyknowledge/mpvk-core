<?php
defined( 'ABSPATH' ) || exit;

/**
 * Roles & capabilities — least privilege.
 * Admin (Miles) = WP administrator + all mpvk_* caps.
 * mpvk_org_coach: manages own org's clients/training/messages. NO wp-admin content caps.
 * mpvk_client: sees + logs own training, messages own coach. Nothing else.
 */
class MPVK_Roles {

	const ORG_CAPS = array(
		'read'                 => true,
		'mpvk_manage_org'      => true,
		'mpvk_manage_clients'  => true,
		'mpvk_assign_workouts' => true,
		'mpvk_view_client_data'=> true,
		'mpvk_message_clients' => true,
	);

	const CLIENT_CAPS = array(
		'read'                    => true,
		'mpvk_view_own_training'  => true,
		'mpvk_log_own_training'   => true,
		'mpvk_message_coach'      => true,
	);

	public static function install(): void {
		remove_role( 'mpvk_org_coach' );
		remove_role( 'mpvk_client' );
		add_role( 'mpvk_org_coach', 'MPVK Org Coach', self::ORG_CAPS );
		add_role( 'mpvk_client', 'MPVK Client', self::CLIENT_CAPS );

		// Grant every mpvk cap to administrators.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_merge( array_keys( self::ORG_CAPS ), array_keys( self::CLIENT_CAPS ) ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	public static function tier_of( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return 'none';
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return 'admin';
		}
		if ( in_array( 'mpvk_org_coach', (array) $user->roles, true ) ) {
			return 'org';
		}
		if ( in_array( 'mpvk_client', (array) $user->roles, true ) ) {
			return 'client';
		}
		return 'none';
	}
}
