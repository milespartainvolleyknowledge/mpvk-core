<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API — namespace mpvk/v1. Cookie auth + X-WP-Nonce (portal) only.
 * Every route has an explicit permission_callback; every handler re-checks tenancy
 * against the actual record (permission callback + object-level check = no IDOR).
 */
class MPVK_REST {

	const NS = 'mpvk/v1';

	public static function register_routes(): void {
		register_rest_route( self::NS, '/me', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'me' ),
			'permission_callback' => fn() => is_user_logged_in(),
		) );

		register_rest_route( self::NS, '/org/clients', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'org_clients' ),
			'permission_callback' => fn() => current_user_can( 'mpvk_view_client_data' ) || current_user_can( 'manage_options' ),
		) );

		register_rest_route( self::NS, '/calendar', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'calendar' ),
			'permission_callback' => fn() => is_user_logged_in(),
			'args'                => array(
				'client_id' => array( 'type' => 'integer', 'required' => false ),
				'start'     => array( 'type' => 'string', 'required' => true ),
				'end'       => array( 'type' => 'string', 'required' => true ),
			),
		) );

		register_rest_route( self::NS, '/workouts', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'create_workout' ),
			'permission_callback' => fn() => current_user_can( 'mpvk_assign_workouts' ) || current_user_can( 'manage_options' ),
		) );

		register_rest_route( self::NS, '/workouts/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_workout' ),
				'permission_callback' => fn() => is_user_logged_in(),
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'patch_workout' ),
				'permission_callback' => fn() => is_user_logged_in(),
			),
		) );

		register_rest_route( self::NS, '/exercises/(?P<id>\d+)/logs', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'log_set' ),
			'permission_callback' => fn() => current_user_can( 'mpvk_log_own_training' ) || current_user_can( 'manage_options' ),
		) );

		// Simple check-off of a whole exercise (the MVP workout UI).
		register_rest_route( self::NS, '/exercises/(?P<id>\d+)/complete', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'toggle_exercise' ),
			'permission_callback' => fn() => current_user_can( 'mpvk_log_own_training' ) || current_user_can( 'manage_options' ),
		) );

		register_rest_route( self::NS, '/messages', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_messages' ),
				'permission_callback' => fn() => is_user_logged_in(),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'send_message' ),
				'permission_callback' => fn() => current_user_can( 'mpvk_message_clients' ) || current_user_can( 'mpvk_message_coach' ) || current_user_can( 'manage_options' ),
			),
		) );

		// Lightweight poll: new messages + reaction state since a cursor id (near-real-time).
		register_rest_route( self::NS, '/messages/poll', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'poll_messages' ),
			'permission_callback' => fn() => is_user_logged_in(),
		) );

		// Upload an image/video for a message (validated + tenancy-scoped).
		register_rest_route( self::NS, '/messages/attachment', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'upload_attachment' ),
			'permission_callback' => fn() => current_user_can( 'mpvk_message_clients' ) || current_user_can( 'mpvk_message_coach' ) || current_user_can( 'manage_options' ),
		) );

		// Toggle an emoji reaction on a message.
		register_rest_route( self::NS, '/messages/(?P<id>\d+)/react', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'toggle_reaction' ),
			'permission_callback' => fn() => is_user_logged_in(),
		) );

		// Authenticated, tenancy-checked attachment streaming (attachments are NOT public URLs).
		register_rest_route( self::NS, '/attachment/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'serve_attachment' ),
			'permission_callback' => fn() => is_user_logged_in(),
		) );

		// Register a web-push subscription for the current user.
		register_rest_route( self::NS, '/push/subscribe', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'push_subscribe' ),
			'permission_callback' => fn() => is_user_logged_in(),
		) );

		// "I'm typing" heartbeat for the thread (picked up by the other side's poll).
		register_rest_route( self::NS, '/messages/typing', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'set_typing' ),
			'permission_callback' => fn() => current_user_can( 'mpvk_message_clients' ) || current_user_can( 'mpvk_message_coach' ) || current_user_can( 'manage_options' ),
		) );
	}

	/** Typing heartbeat: transient per thread+user, short TTL — vanishes when they stop. */
	public static function set_typing( WP_REST_Request $req ): array|WP_Error {
		$t = self::thread_for( $req );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		[ $client, $org, $key ] = $t;
		set_transient( 'mpvk_typing_' . md5( $key ) . '_' . get_current_user_id(), time(), 8 );
		return array( 'ok' => true );
	}

	/** Is anyone OTHER than the current user typing in this thread right now? */
	private static function peer_typing( string $key, int $client ): bool {
		$me    = get_current_user_id();
		$other = ( $me === $client ) ? MPVK_Tenancy::coach_of_client( $client ) : $client;
		if ( ! $other ) {
			return false;
		}
		return false !== get_transient( 'mpvk_typing_' . md5( $key ) . '_' . (int) $other );
	}

	public static function push_subscribe( WP_REST_Request $req ): array|WP_Error {
		$sub      = $req->get_param( 'subscription' );
		$endpoint = is_array( $sub ) ? (string) ( $sub['endpoint'] ?? '' ) : '';
		$p256dh   = is_array( $sub ) ? (string) ( $sub['keys']['p256dh'] ?? '' ) : '';
		$auth     = is_array( $sub ) ? (string) ( $sub['keys']['auth'] ?? '' ) : '';
		// SSRF guard: only accept HTTPS endpoints on a known push-service host, so the row
		// can never later drive a server-side request at an internal address.
		if ( ! $endpoint || ! $p256dh || ! $auth || ! MPVK_Push::is_allowed_endpoint( $endpoint ) ) {
			return new WP_Error( 'mpvk_bad_request', 'Invalid subscription.', array( 'status' => 400 ) );
		}
		global $wpdb;
		$uid   = get_current_user_id();
		$table = MPVK_Schema::table( 'push_subscriptions' );
		$hash  = hash( 'sha256', $endpoint );
		$now   = current_time( 'mysql', true );

		// Never re-attribute an existing subscription across users (anti-takeover): a caller
		// who submits someone else's endpoint must not be able to hijack that device's row.
		$owner = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, user_id FROM $table WHERE endpoint_hash = %s", $hash
		) );
		if ( $owner ) {
			if ( (int) $owner->user_id !== $uid ) {
				return new WP_Error( 'mpvk_conflict', 'This device is registered to another account.', array( 'status' => 409 ) );
			}
			// Same user re-subscribing on this device: refresh its keys only.
			$wpdb->update(
				$table,
				array( 'p256dh' => sanitize_text_field( $p256dh ), 'auth' => sanitize_text_field( $auth ) ),
				array( 'id' => (int) $owner->id )
			);
			return array( 'ok' => true );
		}

		$wpdb->insert( $table, array(
			'user_id'       => $uid,
			'org_id'        => MPVK_Tenancy::org_id_of( $uid ),
			'endpoint'      => $endpoint,
			'p256dh'        => sanitize_text_field( $p256dh ),
			'auth'          => sanitize_text_field( $auth ),
			'endpoint_hash' => $hash,
			'created_at'    => $now,
		) );
		return array( 'ok' => true );
	}

	// ---------- helpers ----------

	private static function deny( string $why = 'forbidden' ): WP_Error {
		MPVK_Audit::log( 'permission_denied', array( 'meta' => array( 'why' => $why ) ) );
		return new WP_Error( 'mpvk_forbidden', 'You cannot access that.', array( 'status' => 403 ) );
	}

	/** Resolve which client the request is about, enforcing tenancy. */
	private static function resolve_client( WP_REST_Request $req ): int|WP_Error {
		$actor  = get_current_user_id();
		$tier   = MPVK_Roles::tier_of( $actor );
		$client = (int) $req->get_param( 'client_id' );
		if ( 'client' === $tier ) {
			$client = $actor; // clients may only ever be themselves
		}
		if ( ! $client ) {
			return new WP_Error( 'mpvk_bad_request', 'client_id required.', array( 'status' => 400 ) );
		}
		if ( ! MPVK_Tenancy::can_access_client( $actor, $client ) ) {
			return self::deny( 'client_scope' );
		}
		return $client;
	}

	private static function workout_row( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . MPVK_Schema::table( 'workouts' ) . ' WHERE id = %d', $id
		) );
	}

	private static function date_ok( string $d ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );
	}

	// ---------- endpoints ----------

	public static function me(): array {
		$uid  = get_current_user_id();
		$user = wp_get_current_user();
		$org  = MPVK_Tenancy::org_id_of( $uid );
		global $wpdb;
		$unread = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . MPVK_Schema::table( 'messages' ) . ' WHERE recipient_user_id = %d AND read_at IS NULL', $uid
		) );
		return array(
			'id'      => $uid,
			'name'    => $user->display_name,
			'tier'    => MPVK_Roles::tier_of( $uid ),
			'org_id'  => $org,
			'unread'  => $unread,
		);
	}

	public static function org_clients(): array|WP_Error {
		$actor = get_current_user_id();
		$org   = MPVK_Tenancy::org_id_of( $actor );
		if ( ! $org && ! current_user_can( 'manage_options' ) ) {
			return self::deny( 'no_org' );
		}
		// Admin without org param: show all orgs' clients is out of MVP scope; admins use an org they own.
		$clients = MPVK_Tenancy::org_clients( $org );
		return array_map( fn( $c ) => array(
			'id'    => (int) $c->ID,
			'name'  => $c->display_name,
			'email' => $c->user_email,
		), $clients );
	}

	public static function calendar( WP_REST_Request $req ): array|WP_Error {
		$client = self::resolve_client( $req );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$start = (string) $req->get_param( 'start' );
		$end   = (string) $req->get_param( 'end' );
		if ( ! self::date_ok( $start ) || ! self::date_ok( $end ) ) {
			return new WP_Error( 'mpvk_bad_request', 'start/end must be YYYY-MM-DD.', array( 'status' => 400 ) );
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, workout_date, title, status FROM ' . MPVK_Schema::table( 'workouts' ) .
			' WHERE client_user_id = %d AND workout_date BETWEEN %s AND %s ORDER BY workout_date, sort',
			$client, $start, $end
		), ARRAY_A );
		return array( 'client_id' => $client, 'workouts' => $rows );
	}

	public static function get_workout( WP_REST_Request $req ): array|WP_Error {
		$row = self::workout_row( (int) $req['id'] );
		if ( ! $row ) {
			return new WP_Error( 'mpvk_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		if ( ! MPVK_Tenancy::can_access_client( get_current_user_id(), (int) $row->client_user_id ) ) {
			return self::deny( 'workout_scope' );
		}
		global $wpdb;
		$exercises = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . MPVK_Schema::table( 'workout_exercises' ) . ' WHERE workout_id = %d ORDER BY position', $row->id
		), ARRAY_A );
		$logs = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . MPVK_Schema::table( 'exercise_logs' ) . ' WHERE workout_id = %d ORDER BY workout_exercise_id, set_number', $row->id
		), ARRAY_A );
		$by_ex = array();
		foreach ( $logs as $l ) {
			$by_ex[ (int) $l['workout_exercise_id'] ][] = $l;
		}
		foreach ( $exercises as &$ex ) {
			$ex['logs'] = $by_ex[ (int) $ex['id'] ] ?? array();
		}
		return array( 'workout' => (array) $row, 'exercises' => $exercises );
	}

	public static function create_workout( WP_REST_Request $req ): array|WP_Error {
		$client = self::resolve_client( $req );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$date  = (string) $req->get_param( 'date' );
		$title = sanitize_text_field( (string) $req->get_param( 'title' ) );
		if ( ! self::date_ok( $date ) || '' === $title ) {
			return new WP_Error( 'mpvk_bad_request', 'date (YYYY-MM-DD) and title required.', array( 'status' => 400 ) );
		}
		$actor = get_current_user_id();
		$org   = MPVK_Tenancy::org_id_of( $client );
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( MPVK_Schema::table( 'workouts' ), array(
			'org_id'         => $org,
			'client_user_id' => $client,
			'workout_date'   => $date,
			'title'          => $title,
			'notes'          => sanitize_textarea_field( (string) $req->get_param( 'notes' ) ),
			'status'         => 'planned',
			'created_by'     => $actor,
			'created_at'     => $now,
			'updated_at'     => $now,
		) );
		$workout_id = (int) $wpdb->insert_id;

		$exercises = $req->get_param( 'exercises' );
		if ( is_array( $exercises ) ) {
			$pos = 0;
			foreach ( $exercises as $ex ) {
				if ( ! is_array( $ex ) || empty( $ex['name'] ) ) {
					continue;
				}
				$wpdb->insert( MPVK_Schema::table( 'workout_exercises' ), array(
					'workout_id'      => $workout_id,
					'org_id'          => $org,
					'exercise_name'   => sanitize_text_field( (string) $ex['name'] ),
					'position'        => $pos++,
					'prescribed_sets' => sanitize_text_field( (string) ( $ex['sets'] ?? '' ) ),
					'prescribed_reps' => sanitize_text_field( (string) ( $ex['reps'] ?? '' ) ),
					'prescribed_load' => sanitize_text_field( (string) ( $ex['load'] ?? '' ) ),
					'prescribed_tempo'=> sanitize_text_field( (string) ( $ex['tempo'] ?? '' ) ),
					'prescribed_rest' => sanitize_text_field( (string) ( $ex['rest'] ?? '' ) ),
					'cues'            => sanitize_textarea_field( (string) ( $ex['cues'] ?? '' ) ),
				) );
			}
		}

		MPVK_Corpus::log( 'workout_created', array(
			'org_id' => $org, 'actor_user_id' => $actor, 'subject_user_id' => $client,
			'object_type' => 'workout', 'object_id' => $workout_id,
			'payload' => array( 'date' => $date, 'title' => $title, 'exercises' => $exercises ),
		) );
		return array( 'id' => $workout_id );
	}

	public static function patch_workout( WP_REST_Request $req ): array|WP_Error {
		$row = self::workout_row( (int) $req['id'] );
		if ( ! $row ) {
			return new WP_Error( 'mpvk_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		$actor = get_current_user_id();
		if ( ! MPVK_Tenancy::can_access_client( $actor, (int) $row->client_user_id ) ) {
			return self::deny( 'workout_scope' );
		}
		$tier    = MPVK_Roles::tier_of( $actor );
		$updates = array();
		$status  = $req->get_param( 'status' );

		if ( null !== $status ) {
			$allowed = ( 'client' === $tier )
				? array( 'completed', 'missed', 'planned' )               // client: own status only; missed does NOT roll forward
				: array( 'completed', 'missed', 'planned', 'partial' );
			if ( ! in_array( $status, $allowed, true ) ) {
				return new WP_Error( 'mpvk_bad_request', 'Bad status.', array( 'status' => 400 ) );
			}
			$updates['status'] = $status;
		}
		if ( 'client' !== $tier ) { // coach/admin field edits
			foreach ( array( 'title' => 'sanitize_text_field', 'notes' => 'sanitize_textarea_field' ) as $f => $fn ) {
				$v = $req->get_param( $f );
				if ( null !== $v ) {
					$updates[ $f ] = $fn( (string) $v );
				}
			}
			$date = $req->get_param( 'date' );
			if ( null !== $date ) {
				if ( ! self::date_ok( (string) $date ) ) {
					return new WP_Error( 'mpvk_bad_request', 'Bad date.', array( 'status' => 400 ) );
				}
				$updates['workout_date'] = $date;
			}
		}
		if ( ! $updates ) {
			return new WP_Error( 'mpvk_bad_request', 'Nothing to update.', array( 'status' => 400 ) );
		}
		$updates['updated_at'] = current_time( 'mysql', true );
		global $wpdb;
		$wpdb->update( MPVK_Schema::table( 'workouts' ), $updates, array( 'id' => (int) $row->id ) );

		MPVK_Corpus::log( 'workout_updated', array(
			'org_id' => (int) $row->org_id, 'actor_user_id' => $actor, 'subject_user_id' => (int) $row->client_user_id,
			'object_type' => 'workout', 'object_id' => (int) $row->id,
			'payload' => array( 'changes' => $updates, 'actor_tier' => $tier ),
		) );
		return array( 'ok' => true, 'id' => (int) $row->id );
	}

	public static function log_set( WP_REST_Request $req ): array|WP_Error {
		global $wpdb;
		$ex = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . MPVK_Schema::table( 'workout_exercises' ) . ' WHERE id = %d', (int) $req['id']
		) );
		if ( ! $ex ) {
			return new WP_Error( 'mpvk_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		$workout = self::workout_row( (int) $ex->workout_id );
		if ( ! $workout ) {
			return new WP_Error( 'mpvk_not_found', 'Parent workout not found.', array( 'status' => 404 ) );
		}
		$actor = get_current_user_id();
		// Only the owning client (or admin) logs actuals.
		if ( (int) $workout->client_user_id !== $actor && ! current_user_can( 'manage_options' ) ) {
			return self::deny( 'log_scope' );
		}
		$rpe = $req->get_param( 'rpe' );
		$row = array(
			'workout_exercise_id' => (int) $ex->id,
			'workout_id'          => (int) $workout->id,
			'org_id'              => (int) $workout->org_id,
			'client_user_id'      => (int) $workout->client_user_id,
			'set_number'          => max( 1, (int) $req->get_param( 'set_number' ) ),
			'actual_reps'         => sanitize_text_field( (string) $req->get_param( 'reps' ) ),
			'actual_load'         => sanitize_text_field( (string) $req->get_param( 'load' ) ),
			'rpe'                 => ( null === $rpe || '' === $rpe ) ? null : max( 0, min( 10, (float) $rpe ) ),
			'comment'             => sanitize_textarea_field( (string) $req->get_param( 'comment' ) ),
			'logged_at'           => current_time( 'mysql', true ),
		);
		$wpdb->insert( MPVK_Schema::table( 'exercise_logs' ), $row );
		$log_id = (int) $wpdb->insert_id;

		MPVK_Corpus::log( 'exercise_logged', array(
			'org_id' => (int) $workout->org_id, 'actor_user_id' => $actor, 'subject_user_id' => (int) $workout->client_user_id,
			'object_type' => 'exercise_log', 'object_id' => $log_id,
			'payload' => array( 'exercise' => $ex->exercise_name, 'set' => $row['set_number'], 'reps' => $row['actual_reps'], 'load' => $row['actual_load'], 'rpe' => $row['rpe'], 'comment' => $row['comment'] ),
		) );
		return array( 'id' => $log_id );
	}

	/** Hydrate a set of message rows with reactions + reply-to snippets. */
	private static function hydrate_messages( array $msgs, int $org, string $key ): array {
		global $wpdb;
		if ( ! $msgs ) {
			return array();
		}
		$ids     = array_map( fn( $m ) => (int) $m['id'], $msgs );
		$in      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rx_rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT message_id, user_id, emoji FROM ' . MPVK_Schema::table( 'message_reactions' ) . " WHERE message_id IN ($in)",
			$ids
		), ARRAY_A );
		$rx = array();
		foreach ( $rx_rows as $r ) {
			$mid = (int) $r['message_id'];
			$rx[ $mid ][ $r['emoji'] ]['count'] = ( $rx[ $mid ][ $r['emoji'] ]['count'] ?? 0 ) + 1;
			if ( (int) $r['user_id'] === get_current_user_id() ) {
				$rx[ $mid ][ $r['emoji'] ]['mine'] = true;
			}
		}
		// reply-to snippets (short preview of the quoted message)
		$reply_ids = array_values( array_filter( array_map( fn( $m ) => (int) $m['reply_to_id'], $msgs ) ) );
		$replies   = array();
		if ( $reply_ids ) {
			$rin  = implode( ',', array_fill( 0, count( $reply_ids ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT id, sender_user_id, body, attachment_type FROM ' . MPVK_Schema::table( 'messages' ) . " WHERE id IN ($rin)",
				$reply_ids
			), ARRAY_A );
			foreach ( $rows as $r ) {
				$replies[ (int) $r['id'] ] = array(
					'id'     => (int) $r['id'],
					'sender' => (int) $r['sender_user_id'],
					'body'   => $r['body'] !== '' ? mb_substr( $r['body'], 0, 120 ) : ( $r['attachment_type'] ? '[' . $r['attachment_type'] . ']' : '' ),
				);
			}
		}
		foreach ( $msgs as &$m ) {
			$mid              = (int) $m['id'];
			$m['reactions']   = array_map(
				fn( $emoji, $d ) => array( 'emoji' => $emoji, 'count' => $d['count'], 'mine' => ! empty( $d['mine'] ) ),
				array_keys( $rx[ $mid ] ?? array() ),
				array_values( $rx[ $mid ] ?? array() )
			);
			$m['reply_to']    = $m['reply_to_id'] ? ( $replies[ (int) $m['reply_to_id'] ] ?? null ) : null;
			$m['id']          = $mid;
		}
		return $msgs;
	}

	private static function thread_for( WP_REST_Request $req ): array|WP_Error {
		$client = self::resolve_client( $req );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$org = MPVK_Tenancy::org_id_of( $client );
		return array( $client, $org, MPVK_Tenancy::thread_key( $org, $client ) );
	}

	private static function mark_read( string $key ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . MPVK_Schema::table( 'messages' ) . ' SET read_at = %s WHERE thread_key = %s AND recipient_user_id = %d AND read_at IS NULL',
			current_time( 'mysql', true ), $key, get_current_user_id()
		) );
	}

	private static function msg_columns(): string {
		return 'id, sender_user_id, recipient_user_id, body, reply_to_id, attachment_url, attachment_type, created_at, read_at';
	}

	public static function get_messages( WP_REST_Request $req ): array|WP_Error {
		$t = self::thread_for( $req );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		[ $client, $org, $key ] = $t;
		global $wpdb;
		$msgs = $wpdb->get_results( $wpdb->prepare(
			'SELECT ' . self::msg_columns() . ' FROM ' . MPVK_Schema::table( 'messages' ) .
			' WHERE org_id = %d AND thread_key = %s ORDER BY id DESC LIMIT 200', $org, $key
		), ARRAY_A );
		self::mark_read( $key );
		$msgs = array_reverse( $msgs );
		$last = $msgs ? (int) end( $msgs )['id'] : 0;
		return array( 'thread' => $key, 'client_id' => $client, 'messages' => self::hydrate_messages( $msgs, $org, $key ), 'last_id' => $last );
	}

	/** Poll: messages after ?since=ID (near-real-time), plus fresh reaction/read state for a recent window. */
	public static function poll_messages( WP_REST_Request $req ): array|WP_Error {
		$t = self::thread_for( $req );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		[ $client, $org, $key ] = $t;
		$since = max( 0, (int) $req->get_param( 'since' ) );
		global $wpdb;
		$new = $wpdb->get_results( $wpdb->prepare(
			'SELECT ' . self::msg_columns() . ' FROM ' . MPVK_Schema::table( 'messages' ) .
			' WHERE org_id = %d AND thread_key = %s AND id > %d ORDER BY id ASC LIMIT 200', $org, $key, $since
		), ARRAY_A );
		self::mark_read( $key );
		// Reaction/read refresh for the most recent 60 messages so existing bubbles update live.
		// (Reactions on messages older than this refresh only on a full thread reload — acceptable at MVP.)
		$recent = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, reply_to_id, read_at FROM ' . MPVK_Schema::table( 'messages' ) .
			' WHERE org_id = %d AND thread_key = %s ORDER BY id DESC LIMIT 60', $org, $key
		), ARRAY_A );
		$recent = self::hydrate_messages( array_reverse( $recent ), $org, $key );
		$state  = array();
		foreach ( $recent as $r ) {
			$state[] = array( 'id' => (int) $r['id'], 'reactions' => $r['reactions'], 'read' => ! empty( $r['read_at'] ) );
		}
		$last = $new ? (int) end( $new )['id'] : $since;
		return array(
			'messages' => self::hydrate_messages( $new, $org, $key ),
			'state'    => $state,
			'last_id'  => $last,
			'typing'   => self::peer_typing( $key, $client ),
		);
	}

	public static function send_message( WP_REST_Request $req ): array|WP_Error {
		$t = self::thread_for( $req );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		[ $client, $org, $key ] = $t;
		$body        = sanitize_textarea_field( trim( (string) $req->get_param( 'body' ) ) );
		$attach_id   = (int) $req->get_param( 'attachment_id' );
		$reply_to_id = (int) $req->get_param( 'reply_to_id' );

		if ( strlen( $body ) > 20000 ) {
			return new WP_Error( 'mpvk_bad_request', 'Message too long (max 20k chars).', array( 'status' => 400 ) );
		}
		if ( '' === $body && ! $attach_id ) {
			return new WP_Error( 'mpvk_bad_request', 'Message needs text or an attachment.', array( 'status' => 400 ) );
		}

		global $wpdb;
		$actor     = get_current_user_id();
		$recipient = ( $actor === $client ) ? MPVK_Tenancy::coach_of_client( $client ) : $client;
		if ( ! $recipient ) {
			return new WP_Error( 'mpvk_bad_request', 'No recipient for this thread.', array( 'status' => 400 ) );
		}

		// Validate reply target belongs to THIS thread (no cross-thread quoting).
		if ( $reply_to_id ) {
			$ok = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM ' . MPVK_Schema::table( 'messages' ) . ' WHERE id = %d AND thread_key = %s AND org_id = %d',
				$reply_to_id, $key, $org
			) );
			if ( ! $ok ) {
				return new WP_Error( 'mpvk_bad_request', 'Reply target not in this thread.', array( 'status' => 400 ) );
			}
		}

		// Validate attachment: must be an attachment this user uploaded for this thread.
		$attach_url = null; $attach_type = null;
		if ( $attach_id ) {
			$att = get_post( $attach_id );
			if ( ! $att || 'attachment' !== $att->post_type
				|| (int) get_post_meta( $attach_id, '_mpvk_thread_owner', true ) !== $actor
				|| get_post_meta( $attach_id, '_mpvk_thread_key', true ) !== $key ) {
				return new WP_Error( 'mpvk_bad_request', 'Invalid attachment.', array( 'status' => 400 ) );
			}
			// Store the AUTH route, not the public /uploads URL — attachments stay tenancy-gated.
			$attach_url  = rest_url( self::NS . '/attachment/' . $attach_id );
			$mime        = get_post_mime_type( $attach_id );
			$attach_type = ( strpos( (string) $mime, 'video/' ) === 0 ) ? 'video' : 'image';
		}

		$wpdb->insert( MPVK_Schema::table( 'messages' ), array(
			'org_id'            => $org,
			'thread_key'        => $key,
			'sender_user_id'    => $actor,
			'recipient_user_id' => $recipient,
			'body'              => $body,
			'reply_to_id'       => $reply_to_id ?: null,
			'attachment_id'     => $attach_id ?: null,
			'attachment_url'    => $attach_url,
			'attachment_type'   => $attach_type,
			'created_at'        => current_time( 'mysql', true ),
		) );
		$msg_id = (int) $wpdb->insert_id;

		// Sending ends "typing" immediately (don't leave the other side watching dots).
		delete_transient( 'mpvk_typing_' . md5( $key ) . '_' . $actor );

		MPVK_Corpus::log( 'message_sent', array(
			'org_id' => $org, 'actor_user_id' => $actor, 'subject_user_id' => $client,
			'object_type' => 'message', 'object_id' => $msg_id,
			'payload' => array( 'body' => $body, 'recipient' => $recipient, 'reply_to' => $reply_to_id ?: null, 'attachment' => $attach_type ),
		) );

		// Push-notify the recipient (no-op unless push is enabled + they've subscribed).
		$sender_name = wp_get_current_user()->display_name;
		MPVK_Push::send_to_user( $recipient, array(
			'title' => $sender_name ?: 'New message',
			'body'  => $body !== '' ? mb_substr( $body, 0, 140 ) : ( $attach_type ? "Sent a $attach_type" : 'New message' ),
			'url'   => home_url( '/portal' ),
			'tag'   => 'mpvk-msg-' . $org,
		) );

		return array( 'id' => $msg_id );
	}

	public static function upload_attachment( WP_REST_Request $req ): array|WP_Error {
		$t = self::thread_for( $req );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		[ $client, $org, $key ] = $t;

		$files = $req->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'mpvk_bad_request', 'No file.', array( 'status' => 400 ) );
		}
		$file = $files['file'];

		// Whitelist: images + common video types only.
		$allowed = array(
			'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'heic' => 'image/heic',
			'mp4|m4v'  => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
		);
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed );
		if ( empty( $check['type'] ) ) {
			return new WP_Error( 'mpvk_bad_request', 'Only images and videos are allowed.', array( 'status' => 400 ) );
		}
		$is_video = strpos( $check['type'], 'video/' ) === 0;
		$max      = $is_video ? 100 * MB_IN_BYTES : 15 * MB_IN_BYTES;
		if ( (int) $file['size'] > $max ) {
			return new WP_Error( 'mpvk_bad_request', 'File too large (max ' . ( $is_video ? '100MB video' : '15MB image' ) . ').', array( 'status' => 413 ) );
		}

		// Light per-user rate limit (anti-DoS / orphan flood): 40 uploads / hour.
		$rk    = 'mpvk_upl_' . get_current_user_id();
		$count = (int) get_transient( $rk );
		if ( $count >= 40 ) {
			return new WP_Error( 'mpvk_rate', 'Too many uploads, slow down.', array( 'status' => 429 ) );
		}
		set_transient( $rk, $count + 1, HOUR_IN_SECONDS );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Unguessable filename (defense-in-depth; the canonical URL is the auth route below).
		$ext   = pathinfo( $file['name'], PATHINFO_EXTENSION );
		$rand  = wp_generate_password( 20, false );
		$mimes_cb = function () use ( $allowed ) { return $allowed; };
		add_filter( 'upload_mimes', $mimes_cb );
		$attach_id = media_handle_sideload( array(
			'name'     => 'mpvk-' . $rand . ( $ext ? '.' . strtolower( $ext ) : '' ),
			'tmp_name' => $file['tmp_name'],
		), 0 );
		remove_filter( 'upload_mimes', $mimes_cb );

		if ( is_wp_error( $attach_id ) ) {
			return new WP_Error( 'mpvk_upload_failed', $attach_id->get_error_message(), array( 'status' => 500 ) );
		}
		// Bind this attachment to the uploader + thread so send_message / serve can validate it.
		update_post_meta( $attach_id, '_mpvk_thread_owner', get_current_user_id() );
		update_post_meta( $attach_id, '_mpvk_thread_key', $key );

		return array(
			'attachment_id' => (int) $attach_id,
			'type'          => $is_video ? 'video' : 'image',
		);
	}

	/**
	 * Stream a message attachment, re-checking tenancy on the attachment's own thread.
	 * Attachments are never exposed as public /uploads URLs; this is the only serving path.
	 */
	public static function serve_attachment( WP_REST_Request $req ) {
		$id  = (int) $req['id'];
		$key = (string) get_post_meta( $id, '_mpvk_thread_key', true );
		if ( ! $key || 'attachment' !== get_post_type( $id ) ) {
			return new WP_Error( 'mpvk_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		$client = (int) substr( strrchr( $key, ':' ), 1 );
		if ( ! MPVK_Tenancy::can_access_client( get_current_user_id(), $client ) ) {
			return self::deny( 'attachment_scope' );
		}
		$path = get_attached_file( $id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'mpvk_not_found', 'File missing.', array( 'status' => 404 ) );
		}
		$mime = get_post_mime_type( $id ) ?: 'application/octet-stream';
		$size = filesize( $path );

		// Minimal single-range support so video seeking/playback works on iOS Safari.
		$start = 0; $end = $size - 1; $status = 200;
		if ( isset( $_SERVER['HTTP_RANGE'] ) && preg_match( '/bytes=(\d+)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $m ) ) {
			$start  = (int) $m[1];
			$end    = ( '' !== $m[2] ) ? min( (int) $m[2], $size - 1 ) : $size - 1;
			$status = 206;
		}
		$length = $end - $start + 1;

		while ( ob_get_level() ) { ob_end_clean(); }
		status_header( $status );
		header( 'Content-Type: ' . $mime );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . $length );
		header( 'Content-Disposition: inline' );
		header( 'Cache-Control: private, max-age=3600' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( 206 === $status ) {
			header( "Content-Range: bytes $start-$end/$size" );
		}
		$fp = fopen( $path, 'rb' );
		if ( $fp ) {
			fseek( $fp, $start );
			$remaining = $length;
			while ( $remaining > 0 && ! feof( $fp ) ) {
				$chunk = fread( $fp, (int) min( 8192, $remaining ) );
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput -- raw binary stream
				$remaining -= strlen( $chunk );
				flush();
			}
			fclose( $fp );
		}
		exit;
	}

	public static function toggle_reaction( WP_REST_Request $req ): array|WP_Error {
		global $wpdb;
		$mid   = (int) $req['id'];
		$msg   = $wpdb->get_row( $wpdb->prepare(
			'SELECT org_id, thread_key FROM ' . MPVK_Schema::table( 'messages' ) . ' WHERE id = %d', $mid
		) );
		if ( ! $msg ) {
			return new WP_Error( 'mpvk_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		// The thread key is org:client — verify the actor can access that client.
		$client = (int) substr( strrchr( $msg->thread_key, ':' ), 1 );
		if ( ! MPVK_Tenancy::can_access_client( get_current_user_id(), $client ) ) {
			return self::deny( 'reaction_scope' );
		}
		$emoji = (string) $req->get_param( 'emoji' );
		$allowed_emoji = array( '👍', '❤️', '🔥', '💪', '😂', '👀', '✅' );
		if ( ! in_array( $emoji, $allowed_emoji, true ) ) {
			return new WP_Error( 'mpvk_bad_request', 'Unsupported reaction.', array( 'status' => 400 ) );
		}
		$table = MPVK_Schema::table( 'message_reactions' );
		$uid   = get_current_user_id();
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table WHERE message_id = %d AND user_id = %d AND emoji = %s", $mid, $uid, $emoji
		) );
		if ( $existing ) {
			$wpdb->delete( $table, array( 'id' => $existing ) );
			$on = false;
		} else {
			$wpdb->insert( $table, array(
				'message_id' => $mid, 'org_id' => (int) $msg->org_id, 'user_id' => $uid,
				'emoji' => $emoji, 'created_at' => current_time( 'mysql', true ),
			) );
			$on = true;
		}
		return array( 'ok' => true, 'on' => $on, 'emoji' => $emoji, 'message_id' => $mid );
	}

	public static function toggle_exercise( WP_REST_Request $req ): array|WP_Error {
		global $wpdb;
		$ex = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . MPVK_Schema::table( 'workout_exercises' ) . ' WHERE id = %d', (int) $req['id']
		) );
		if ( ! $ex ) {
			return new WP_Error( 'mpvk_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		$workout = self::workout_row( (int) $ex->workout_id );
		if ( ! $workout ) {
			return new WP_Error( 'mpvk_not_found', 'Parent workout not found.', array( 'status' => 404 ) );
		}
		$actor = get_current_user_id();
		if ( (int) $workout->client_user_id !== $actor && ! current_user_can( 'manage_options' ) ) {
			return self::deny( 'exercise_scope' );
		}
		$now  = current_time( 'mysql', true );
		$done = null === $ex->completed_at; // toggling: currently incomplete → complete
		$wpdb->update(
			MPVK_Schema::table( 'workout_exercises' ),
			$done ? array( 'completed_at' => $now, 'completed_by' => $actor ) : array( 'completed_at' => null, 'completed_by' => null ),
			array( 'id' => (int) $ex->id )
		);

		// Auto-roll the workout status: all done → completed; some done → partial; none → planned.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MPVK_Schema::table( 'workout_exercises' ) . ' WHERE workout_id = %d', $workout->id ) );
		$dcnt  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MPVK_Schema::table( 'workout_exercises' ) . ' WHERE workout_id = %d AND completed_at IS NOT NULL', $workout->id ) );
		$status = 0 === $dcnt ? 'planned' : ( $dcnt >= $total ? 'completed' : 'partial' );
		$wpdb->update( MPVK_Schema::table( 'workouts' ), array( 'status' => $status, 'updated_at' => $now ), array( 'id' => (int) $workout->id ) );

		MPVK_Corpus::log( 'exercise_checked', array(
			'org_id' => (int) $workout->org_id, 'actor_user_id' => $actor, 'subject_user_id' => (int) $workout->client_user_id,
			'object_type' => 'workout_exercise', 'object_id' => (int) $ex->id,
			'payload' => array( 'exercise' => $ex->exercise_name, 'done' => $done, 'workout_status' => $status, 'progress' => "$dcnt/$total" ),
		) );
		return array( 'ok' => true, 'done' => $done, 'workout_status' => $status, 'progress' => array( 'done' => $dcnt, 'total' => $total ) );
	}
}
