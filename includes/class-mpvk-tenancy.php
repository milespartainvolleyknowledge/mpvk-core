<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tenancy guards. Every data access flows through these checks.
 * org_id lives in usermeta ('mpvk_org_id') for coaches and clients.
 */
class MPVK_Tenancy {

	public static function org_id_of( int $user_id ): int {
		return (int) get_user_meta( $user_id, 'mpvk_org_id', true );
	}

	public static function set_org( int $user_id, int $org_id ): void {
		update_user_meta( $user_id, 'mpvk_org_id', $org_id );
	}

	/** Admin sees everything; coach sees own org; client sees only self. */
	public static function can_access_client( int $actor_id, int $client_user_id ): bool {
		if ( user_can( $actor_id, 'manage_options' ) ) {
			return true;
		}
		if ( $actor_id === $client_user_id && user_can( $actor_id, 'mpvk_view_own_training' ) ) {
			return true;
		}
		if ( user_can( $actor_id, 'mpvk_view_client_data' ) ) {
			$actor_org  = self::org_id_of( $actor_id );
			$client_org = self::org_id_of( $client_user_id );
			return $actor_org > 0 && $actor_org === $client_org
				&& 'client' === MPVK_Roles::tier_of( $client_user_id );
		}
		return false;
	}

	public static function can_access_org( int $actor_id, int $org_id ): bool {
		if ( user_can( $actor_id, 'manage_options' ) ) {
			return true;
		}
		return self::org_id_of( $actor_id ) === $org_id && $org_id > 0;
	}

	/** Coach of a client (first coach of the client's org — MVP: one coach per org). */
	public static function coach_of_client( int $client_user_id ): int {
		$org_id = self::org_id_of( $client_user_id );
		if ( ! $org_id ) {
			return 0;
		}
		global $wpdb;
		$org = $wpdb->get_row( $wpdb->prepare(
			'SELECT owner_user_id FROM ' . MPVK_Schema::table( 'orgs' ) . ' WHERE id = %d', $org_id
		) );
		return $org ? (int) $org->owner_user_id : 0;
	}

	/** Clients of an org. */
	public static function org_clients( int $org_id ): array {
		$q = new WP_User_Query( array(
			'role'       => 'mpvk_client',
			'meta_key'   => 'mpvk_org_id',
			'meta_value' => $org_id,
			'fields'     => array( 'ID', 'display_name', 'user_email' ),
			'number'     => 500,
		) );
		return $q->get_results();
	}

	public static function thread_key( int $org_id, int $client_user_id ): string {
		return $org_id . ':' . $client_user_id;
	}
}
