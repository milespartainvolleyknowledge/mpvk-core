<?php
defined( 'ABSPATH' ) || exit;

/**
 * Seeding: 1 test org + 4 placeholder clients (MVP spec — hand-created, no checkout).
 * Callable from a CLI harness or (admin-only) via do_action('mpvk_seed_demo').
 */
class MPVK_Seed {

	const CRED_TRANSIENT = 'mpvk_demo_creds';

	/** Freshly generated demo credentials (shown once, right after seeding). */
	public static function last_credentials(): array {
		$c = get_transient( self::CRED_TRANSIENT );
		return is_array( $c ) ? $c : array();
	}

	public static function demo( int $coach_user_id = 0 ): array {
		global $wpdb;
		$creds = array(); // login => password, for newly created accounts only

		// Coach user (org tier). Strong random password, surfaced once via transient —
		// no fixed/known credentials ever exist, so seeding is safe on any environment.
		if ( ! $coach_user_id ) {
			$coach_user_id = username_exists( 'coach_demo' );
			if ( ! $coach_user_id ) {
				$pass          = wp_generate_password( 16, false );
				$coach_user_id = wp_insert_user( array(
					'user_login'   => 'coach_demo',
					'user_pass'    => $pass,
					'user_email'   => 'coach_demo@example.test',
					'display_name' => 'Demo Coach',
					'role'         => 'mpvk_org_coach',
				) );
				if ( ! is_wp_error( $coach_user_id ) ) {
					$creds['coach_demo'] = $pass;
				}
			}
		}
		if ( is_wp_error( $coach_user_id ) ) {
			return array( 'error' => $coach_user_id->get_error_message() );
		}

		// Org
		$orgs_table = MPVK_Schema::table( 'orgs' );
		$org_id     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $orgs_table WHERE slug = %s", 'mpvk-demo' ) );
		if ( ! $org_id ) {
			$wpdb->insert( $orgs_table, array(
				'name'          => 'MPVK Demo Org',
				'slug'          => 'mpvk-demo',
				'owner_user_id' => (int) $coach_user_id,
				'status'        => 'active',
				'created_at'    => current_time( 'mysql', true ),
			) );
			$org_id = (int) $wpdb->insert_id;
		}
		MPVK_Tenancy::set_org( (int) $coach_user_id, $org_id );

		// 4 placeholder clients
		$client_ids = array();
		foreach ( array( 'ava', 'ben', 'cody', 'dana' ) as $i => $name ) {
			$login = 'client_' . $name;
			$uid   = username_exists( $login );
			if ( ! $uid ) {
				$pass = wp_generate_password( 16, false );
				$uid  = wp_insert_user( array(
					'user_login'   => $login,
					'user_pass'    => $pass,
					'user_email'   => $name . '@example.test',
					'display_name' => ucfirst( $name ) . ' Placeholder',
					'role'         => 'mpvk_client',
				) );
				if ( ! is_wp_error( $uid ) ) {
					$creds[ $login ] = $pass;
				}
			}
			if ( is_wp_error( $uid ) ) {
				continue;
			}
			MPVK_Tenancy::set_org( (int) $uid, $org_id );
			$client_ids[] = (int) $uid;

			MPVK_Corpus::log( 'client_provisioned', array(
				'org_id' => $org_id, 'subject_user_id' => (int) $uid,
				'object_type' => 'user', 'object_id' => (int) $uid,
				'payload' => array( 'via' => 'seed' ),
			) );
		}

		// A demo workout for the first client (today) — only if not already seeded (idempotent).
		$demo_title = 'Lower Body + Approach Mechanics';
		$already = $client_ids ? (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . MPVK_Schema::table( 'workouts' ) . ' WHERE client_user_id = %d AND workout_date = %s AND title = %s',
			$client_ids[0], gmdate( 'Y-m-d' ), $demo_title
		) ) : 1;
		if ( $client_ids && ! $already ) {
			$req = new WP_REST_Request( 'POST', '/mpvk/v1/workouts' );
			$req->set_param( 'client_id', $client_ids[0] );
			$req->set_param( 'date', gmdate( 'Y-m-d' ) );
			$req->set_param( 'title', $demo_title );
			$req->set_param( 'notes', 'Focus: hip hinge quality before load. Film your last approach set.' );
			$req->set_param( 'exercises', array(
				array( 'name' => 'Trap Bar Deadlift', 'sets' => '4', 'reps' => '5', 'load' => 'RPE 7', 'tempo' => '21X1', 'rest' => '2-3 min', 'cues' => 'Push the floor away; brace before each pull.' ),
				array( 'name' => 'Approach Jumps', 'sets' => '5', 'reps' => '3', 'load' => 'BW', 'rest' => '90s', 'cues' => 'Penultimate step long and low.' ),
				array( 'name' => 'Copenhagen Plank', 'sets' => '3', 'reps' => '20s/side', 'rest' => '60s' ),
			) );
			$old = wp_get_current_user();
			wp_set_current_user( (int) $coach_user_id );
			MPVK_REST::create_workout( $req );
			wp_set_current_user( $old ? $old->ID : 0 );
		}

		// Surface any freshly generated passwords once (1h), so the admin page can show them.
		if ( $creds ) {
			set_transient( self::CRED_TRANSIENT, $creds, HOUR_IN_SECONDS );
		}

		return array( 'org_id' => $org_id, 'coach_id' => (int) $coach_user_id, 'client_ids' => $client_ids, 'new_credentials' => $creds );
	}
}

add_action( 'mpvk_seed_demo', function () {
	if ( current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI ) || php_sapi_name() === 'cli' ) {
		MPVK_Seed::demo();
	}
} );
