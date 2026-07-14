<?php
defined( 'ABSPATH' ) || exit;

/**
 * Workout Programs — the full builder (Phases 2–5 of workout-builder-plan.md).
 *
 * Coach side:  build templates (visually or from a PROMPT), subscribe/copy them,
 *              assign to athletes (materializes onto their calendar), and ANALYZE an
 *              athlete via prompt. Every AI output is a coach-approved draft.
 * Athlete side: fixed sets/reps (coach-owned) but they INPUT their weights + RPE,
 *              can OPT OUT of optional exercises, see per-lift WEIGHT HISTORY, and
 *              tap into any exercise for its directions + form video.
 *
 * Exercise expressiveness (all coach-set, per exercise):
 *   entry_type  : sets | circuit | text        (straight sets, a circuit member, or a free text block)
 *   rep_unit    : reps | sec | each_side | each (how reps read: "8 reps", "30 sec", "10 each side")
 *   load_mode   : weight | rpe | percent | bw | none  (athlete enters weight only when load_mode=weight/percent)
 *   is_optional : athlete may opt out
 *   circuit_group : exercises in a day sharing this label render as one circuit
 *   block_text  : for entry_type=text (a note/instruction with no sets/reps)
 */
class MPVK_Programs {

	const NS = 'mpvk/v1';

	const REP_UNITS  = array( 'reps', 'sec', 'each_side', 'each' );
	const LOAD_MODES = array( 'weight', 'rpe', 'percent', 'bw', 'none' );
	const ENTRY_TYPES = array( 'sets', 'circuit', 'text' );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	private static function coach(): bool {
		return current_user_can( 'mpvk_assign_workouts' ) || current_user_can( 'manage_options' );
	}

	public static function register_routes(): void {
		$coach = fn() => self::coach();
		$auth  = fn() => is_user_logged_in();

		// ---- coach: template builder ----
		register_rest_route( self::NS, '/programs', array(
			array( 'methods' => 'GET',  'callback' => array( __CLASS__, 'list_programs' ),  'permission_callback' => $coach ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_program' ), 'permission_callback' => $coach ),
		) );
		register_rest_route( self::NS, '/programs/(?P<id>\d+)', array(
			array( 'methods' => 'GET',    'callback' => array( __CLASS__, 'get_program' ),    'permission_callback' => $coach ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_program' ), 'permission_callback' => $coach ),
		) );
		register_rest_route( self::NS, '/programs/(?P<id>\d+)/exercise', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'save_exercise' ), 'permission_callback' => $coach,
		) );
		register_rest_route( self::NS, '/programs/exercise/(?P<eid>\d+)', array(
			'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_exercise' ), 'permission_callback' => $coach,
		) );
		register_rest_route( self::NS, '/programs/(?P<id>\d+)/assign', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'assign_program' ), 'permission_callback' => $coach,
		) );
		register_rest_route( self::NS, '/programs/(?P<id>\d+)/copy', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'copy_program' ), 'permission_callback' => $coach,
		) );

		// ---- coach: prompt-driven ----
		register_rest_route( self::NS, '/programs/generate', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'generate_program' ), 'permission_callback' => $coach,
		) );
		register_rest_route( self::NS, '/programs/analyze', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'analyze_athlete' ), 'permission_callback' => $coach,
		) );

		// ---- pre-workout readiness check-in ----
		register_rest_route( self::NS, '/workouts/(?P<id>\d+)/checkin', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'save_checkin' ), 'permission_callback' => $auth,
		) );

		// ---- athlete: weights / opt-out / history / detail ----
		register_rest_route( self::NS, '/exercises/(?P<id>\d+)/weight', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'log_weight' ), 'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/exercises/(?P<id>\d+)/skip', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'toggle_skip' ), 'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/exercises/(?P<id>\d+)/history', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'lift_history' ), 'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/exercises/(?P<id>\d+)/detail', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'exercise_detail' ), 'permission_callback' => $auth,
		) );
	}

	/**
	 * Configured readiness-check-in areas for this org (coach-editable option).
	 * Default maps Miles's list: Achilles tendon, patellar (knee) tendon, low back, shoulder.
	 */
	public static function checkin_areas(): array {
		$raw = get_option( 'mpvk_checkin_areas', '' );
		$arr = $raw ? json_decode( $raw, true ) : null;
		if ( is_array( $arr ) && $arr ) {
			$clean = array_map( fn( $a ) => mb_substr( sanitize_text_field( (string) $a ), 0, 40 ), $arr );
			return array_values( array_slice( array_filter( $clean ), 0, 10 ) );
		}
		return array( 'Achilles', 'Knee', 'Lower back', 'Shoulder' );
	}

	/** Athlete records how their joints/tendons feel BEFORE training. One per workout (upsert). */
	public static function save_checkin( WP_REST_Request $req ): array|WP_Error {
		$r = self::athlete_workout( (int) $req['id'] );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		[ $w, $own ] = $r;
		if ( ! $own && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'mpvk_forbidden', 'Only the athlete logs their own check-in.', array( 'status' => 403 ) );
		}
		$in     = $req->get_param( 'scores' );
		$areas  = self::checkin_areas();
		$scores = array();
		if ( is_array( $in ) ) {
			foreach ( $areas as $a ) {
				if ( isset( $in[ $a ] ) && '' !== $in[ $a ] ) {
					$scores[ $a ] = max( 0, min( 10, (int) $in[ $a ] ) );
				}
			}
		}
		if ( ! $scores ) {
			return new WP_Error( 'mpvk_bad_request', 'No scores submitted.', array( 'status' => 400 ) );
		}
		$overall = (int) round( array_sum( $scores ) / count( $scores ) );
		$note    = mb_substr( sanitize_textarea_field( (string) $req->get_param( 'note' ) ), 0, 500 );
		global $wpdb;
		$t   = MPVK_Schema::table( 'workout_checkins' );
		$now = current_time( 'mysql', true );
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE workout_id = %d", (int) $w->id ) );
		$row = array(
			'workout_id' => (int) $w->id, 'org_id' => (int) $w->org_id, 'client_user_id' => (int) $w->client_user_id,
			'scores' => wp_json_encode( $scores ), 'overall' => $overall, 'note' => $note, 'updated_at' => $now,
		);
		if ( $existing ) {
			$wpdb->update( $t, $row, array( 'id' => $existing ) );
		} else {
			$row['created_at'] = $now;
			// Race-safe: if a concurrent request already inserted (UNIQUE workout_id), fall back to update.
			if ( false === $wpdb->insert( $t, $row ) ) {
				unset( $row['created_at'] );
				$wpdb->update( $t, $row, array( 'workout_id' => (int) $w->id ) );
			}
		}
		// Flag concerning areas (≤3/10) for the coach in the corpus.
		$flags = array();
		foreach ( $scores as $a => $v ) {
			if ( $v <= 3 ) {
				$flags[] = $a;
			}
		}
		MPVK_Corpus::log( 'readiness_checkin', array(
			'org_id' => (int) $w->org_id, 'actor_user_id' => get_current_user_id(), 'subject_user_id' => (int) $w->client_user_id,
			'object_type' => 'workout', 'object_id' => (int) $w->id,
			'payload' => array( 'scores' => $scores, 'overall' => $overall, 'flags' => $flags, 'note' => $note ),
		) );
		return array( 'ok' => true, 'overall' => $overall, 'flags' => $flags );
	}

	/** Resolve a workout the actor owns (athlete) or can access (coach). */
	private static function athlete_workout( int $wid ): array|WP_Error {
		global $wpdb;
		$w = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MPVK_Schema::table( 'workouts' ) . ' WHERE id = %d', $wid ) );
		if ( ! $w ) {
			return new WP_Error( 'mpvk_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		$actor = get_current_user_id();
		$own   = (int) $w->client_user_id === $actor;
		if ( ! $own && ! MPVK_Tenancy::can_access_client( $actor, (int) $w->client_user_id ) ) {
			return new WP_Error( 'mpvk_forbidden', 'Not your workout.', array( 'status' => 403 ) );
		}
		return array( $w, $own );
	}

	/** Fetch a workout's check-in (for get_workout hydration + coach view). */
	public static function checkin_for( int $workout_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT scores, overall, note FROM ' . MPVK_Schema::table( 'workout_checkins' ) . ' WHERE workout_id = %d', $workout_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		return array( 'scores' => json_decode( (string) $row['scores'], true ) ?: array(), 'overall' => (int) $row['overall'], 'note' => (string) $row['note'] );
	}

	/** Per-coach throttle on paid AI calls (cost/DoS guard, review v0.6-2). 30/hour. */
	private static function rate_limited( string $bucket ): ?WP_Error {
		$key = 'mpvk_prog_rl_' . $bucket . '_' . get_current_user_id();
		$n   = (int) get_transient( $key );
		if ( $n >= 30 ) {
			return new WP_Error( 'mpvk_rate', 'Too many AI requests this hour — try again shortly.', array( 'status' => 429 ) );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return null;
	}

	private static function org(): int|WP_Error {
		$org = MPVK_Tenancy::org_id_of( get_current_user_id() );
		if ( ! $org && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'mpvk_no_org', 'No organization.', array( 'status' => 403 ) );
		}
		return (int) $org;
	}

	private static function own_program( int $id ): stdClass|WP_Error {
		$org = self::org();
		if ( is_wp_error( $org ) ) {
			return $org;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MPVK_Schema::table( 'templates' ) . ' WHERE id = %d', $id ) );
		if ( ! $row ) {
			return new WP_Error( 'mpvk_not_found', 'Program not found.', array( 'status' => 404 ) );
		}
		if ( (int) $row->org_id !== $org ) {
			return new WP_Error( 'mpvk_forbidden', 'Not your program.', array( 'status' => 403 ) );
		}
		return $row;
	}

	// ================= coach: builder =================

	public static function list_programs(): array|WP_Error {
		$org = self::org();
		if ( is_wp_error( $org ) ) {
			return $org;
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, title, goal, athlete_level, weeks, days_per_week, status, updated_at FROM ' . MPVK_Schema::table( 'templates' ) .
			' WHERE org_id = %d ORDER BY updated_at DESC', $org
		), ARRAY_A );
		return array( 'programs' => $rows );
	}

	public static function create_program( WP_REST_Request $req ): array|WP_Error {
		$org = self::org();
		if ( is_wp_error( $org ) ) {
			return $org;
		}
		$title = sanitize_text_field( (string) $req->get_param( 'title' ) );
		if ( '' === $title ) {
			return new WP_Error( 'mpvk_bad_request', 'Title required.', array( 'status' => 400 ) );
		}
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( MPVK_Schema::table( 'templates' ), array(
			'org_id'        => $org,
			'title'         => $title,
			'description'   => sanitize_textarea_field( (string) $req->get_param( 'description' ) ),
			'goal'          => sanitize_text_field( (string) $req->get_param( 'goal' ) ),
			'athlete_level' => sanitize_text_field( (string) $req->get_param( 'athlete_level' ) ),
			'weeks'         => max( 1, (int) $req->get_param( 'weeks' ) ?: 1 ),
			'days_per_week' => max( 1, (int) $req->get_param( 'days_per_week' ) ?: 3 ),
			'status'        => 'draft',
			'created_by'    => get_current_user_id(),
			'created_at'    => $now,
			'updated_at'    => $now,
		) );
		return array( 'id' => (int) $wpdb->insert_id );
	}

	/** A program = weeks → days → exercises, fully hydrated. */
	public static function get_program( WP_REST_Request $req ): array|WP_Error {
		$p = self::own_program( (int) $req['id'] );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		global $wpdb;
		$days = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . MPVK_Schema::table( 'template_days' ) . ' WHERE template_id = %d ORDER BY week_index, day_index', $p->id
		), ARRAY_A );
		$ex_by_day = array();
		if ( $days ) {
			$ids = implode( ',', array_map( fn( $d ) => (int) $d['id'], $days ) );
			$exs = $wpdb->get_results(
				'SELECT * FROM ' . MPVK_Schema::table( 'template_day_exercises' ) . " WHERE template_day_id IN ($ids) ORDER BY position", ARRAY_A
			);
			foreach ( $exs as $e ) {
				$ex_by_day[ (int) $e['template_day_id'] ][] = $e;
			}
		}
		foreach ( $days as &$d ) {
			$d['exercises'] = $ex_by_day[ (int) $d['id'] ] ?? array();
		}
		return array( 'program' => (array) $p, 'days' => $days );
	}

	public static function delete_program( WP_REST_Request $req ): array|WP_Error {
		$p = self::own_program( (int) $req['id'] );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		global $wpdb;
		$dids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . MPVK_Schema::table( 'template_days' ) . ' WHERE template_id = %d', $p->id ) );
		if ( $dids ) {
			$in = implode( ',', array_map( 'intval', $dids ) );
			$wpdb->query( 'DELETE FROM ' . MPVK_Schema::table( 'template_day_exercises' ) . " WHERE template_day_id IN ($in)" );
		}
		$wpdb->delete( MPVK_Schema::table( 'template_days' ), array( 'template_id' => (int) $p->id ) );
		$wpdb->delete( MPVK_Schema::table( 'templates' ), array( 'id' => (int) $p->id ) );
		return array( 'ok' => true );
	}

	/** Normalize + sanitize one exercise spec (shared by save + AI import). */
	private static function clean_exercise( array $e, int $org ): array {
		$entry = $e['entry_type'] ?? 'sets';
		$entry = in_array( $entry, self::ENTRY_TYPES, true ) ? $entry : 'sets';
		$unit  = $e['rep_unit'] ?? 'reps';
		$unit  = in_array( $unit, self::REP_UNITS, true ) ? $unit : 'reps';
		$load  = $e['load_mode'] ?? 'weight';
		$load  = in_array( $load, self::LOAD_MODES, true ) ? $load : 'weight';
		return array(
			'org_id'           => $org,
			'library_id'       => isset( $e['library_id'] ) ? (int) $e['library_id'] : null,
			'exercise_name'    => sanitize_text_field( (string) ( $e['exercise_name'] ?? $e['name'] ?? ( 'text' === $entry ? 'Note' : '' ) ) ),
			'position'         => (int) ( $e['position'] ?? 0 ),
			'prescribed_sets'  => sanitize_text_field( (string) ( $e['sets'] ?? $e['prescribed_sets'] ?? '' ) ),
			'prescribed_reps'  => sanitize_text_field( (string) ( $e['reps'] ?? $e['prescribed_reps'] ?? '' ) ),
			'prescribed_load'  => sanitize_text_field( (string) ( $e['load'] ?? $e['prescribed_load'] ?? '' ) ),
			'prescribed_tempo' => sanitize_text_field( (string) ( $e['tempo'] ?? $e['prescribed_tempo'] ?? '' ) ),
			'prescribed_rest'  => sanitize_text_field( (string) ( $e['rest'] ?? $e['prescribed_rest'] ?? '' ) ),
			'entry_type'       => $entry,
			'rep_unit'         => $unit,
			'load_mode'        => $load,
			'is_optional'      => ! empty( $e['is_optional'] ) ? 1 : 0,
			'circuit_group'    => isset( $e['circuit_group'] ) && '' !== $e['circuit_group'] ? sanitize_text_field( (string) $e['circuit_group'] ) : null,
			'block_text'       => isset( $e['block_text'] ) ? sanitize_textarea_field( (string) $e['block_text'] ) : null,
			'notes'            => isset( $e['notes'] ) ? sanitize_textarea_field( (string) $e['notes'] ) : null,
			'cues'             => isset( $e['cues'] ) ? sanitize_textarea_field( (string) $e['cues'] ) : null,
		);
	}

	/** Add/replace an exercise on a (week,day). Creates the day if needed. */
	public static function save_exercise( WP_REST_Request $req ): array|WP_Error {
		$p = self::own_program( (int) $req['id'] );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$week = max( 1, (int) $req->get_param( 'week_index' ) ?: 1 );
		$day  = max( 1, (int) $req->get_param( 'day_index' ) ?: 1 );
		global $wpdb;
		$day_id = self::ensure_day( (int) $p->id, (int) $p->org_id, $week, $day, (string) $req->get_param( 'block_label' ), (string) $req->get_param( 'day_title' ) );
		$data   = self::clean_exercise( (array) $req->get_json_params() ?: $req->get_params(), (int) $p->org_id );
		if ( 'text' !== $data['entry_type'] && '' === $data['exercise_name'] ) {
			return new WP_Error( 'mpvk_bad_request', 'Exercise name required.', array( 'status' => 400 ) );
		}
		$data['template_day_id'] = $day_id;
		$eid = (int) $req->get_param( 'exercise_id' );
		if ( $eid ) {
			// Must belong to THIS program (not just this org) — else an edit could relocate/
			// overwrite an exercise from another of the coach's programs. (Review v0.6-1.)
			$own = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT te.id FROM ' . MPVK_Schema::table( 'template_day_exercises' ) . ' te
				 JOIN ' . MPVK_Schema::table( 'template_days' ) . ' td ON td.id = te.template_day_id
				 WHERE te.id = %d AND td.template_id = %d', $eid, (int) $p->id
			) );
			if ( ! $own ) {
				return new WP_Error( 'mpvk_not_found', 'Exercise not found in this program.', array( 'status' => 404 ) );
			}
			$wpdb->update( MPVK_Schema::table( 'template_day_exercises' ), $data, array( 'id' => $eid ) );
		} else {
			$wpdb->insert( MPVK_Schema::table( 'template_day_exercises' ), $data );
			$eid = (int) $wpdb->insert_id;
		}
		$wpdb->update( MPVK_Schema::table( 'templates' ), array( 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $p->id ) );
		return array( 'exercise_id' => $eid, 'day_id' => $day_id );
	}

	private static function ensure_day( int $tpl, int $org, int $week, int $day, string $block = '', string $title = '' ): int {
		global $wpdb;
		$t   = MPVK_Schema::table( 'template_days' );
		$id  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE template_id = %d AND week_index = %d AND day_index = %d", $tpl, $week, $day ) );
		if ( $id ) {
			if ( '' !== $block || '' !== $title ) {
				$wpdb->update( $t, array_filter( array( 'block_label' => $block ?: null, 'title' => $title ?: null ) ), array( 'id' => $id ) );
			}
			return $id;
		}
		$wpdb->insert( $t, array(
			'template_id' => $tpl, 'org_id' => $org, 'week_index' => $week, 'day_index' => $day,
			'block_label' => $block ?: null, 'title' => $title ?: null,
		) );
		return (int) $wpdb->insert_id;
	}

	public static function delete_exercise( WP_REST_Request $req ): array|WP_Error {
		$org = self::org();
		if ( is_wp_error( $org ) ) {
			return $org;
		}
		global $wpdb;
		$t   = MPVK_Schema::table( 'template_day_exercises' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, org_id FROM $t WHERE id = %d", (int) $req['eid'] ) );
		if ( ! $row || (int) $row->org_id !== $org ) {
			return new WP_Error( 'mpvk_forbidden', 'Not yours.', array( 'status' => 403 ) );
		}
		$wpdb->delete( $t, array( 'id' => (int) $row->id ) );
		return array( 'ok' => true );
	}

	// ================= assign / copy (subscribe or customize) =================

	/** Materialize a program onto an athlete's calendar from a start date. */
	public static function assign_program( WP_REST_Request $req ): array|WP_Error {
		$p = self::own_program( (int) $req['id'] );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$client = (int) $req->get_param( 'client_id' );
		$start  = (string) $req->get_param( 'start_date' );
		if ( ! $client || ! MPVK_Tenancy::can_access_client( get_current_user_id(), $client ) ) {
			return new WP_Error( 'mpvk_forbidden', 'Cannot assign to that athlete.', array( 'status' => 403 ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) {
			return new WP_Error( 'mpvk_bad_request', 'start_date must be YYYY-MM-DD.', array( 'status' => 400 ) );
		}
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( MPVK_Schema::table( 'template_subscriptions' ), array(
			'org_id' => (int) $p->org_id, 'client_user_id' => $client, 'template_id' => (int) $p->id,
			'start_date' => $start, 'status' => 'active', 'created_at' => $now,
		) );
		$sub_id = (int) $wpdb->insert_id;

		$days = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . MPVK_Schema::table( 'template_days' ) . ' WHERE template_id = %d ORDER BY week_index, day_index', $p->id
		) );
		$made = 0;
		$base = strtotime( $start . ' 00:00:00 UTC' );
		foreach ( $days as $d ) {
			// day N of week W → offset = (W-1)*7 + (dayIndex-1)  [athletes train on consecutive slotted days]
			$offset = ( (int) $d->week_index - 1 ) * 7 + ( (int) $d->day_index - 1 );
			$date   = gmdate( 'Y-m-d', $base + $offset * DAY_IN_SECONDS );
			$wpdb->insert( MPVK_Schema::table( 'workouts' ), array(
				'org_id' => (int) $p->org_id, 'client_user_id' => $client, 'workout_date' => $date,
				'title' => $d->title ?: ( $p->title . ' — W' . $d->week_index . 'D' . $d->day_index ),
				'notes' => $d->notes, 'status' => 'planned', 'template_id' => (int) $p->id, 'subscription_id' => $sub_id,
				'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
			) );
			$wid = (int) $wpdb->insert_id;
			$exs = $wpdb->get_results( $wpdb->prepare(
				'SELECT * FROM ' . MPVK_Schema::table( 'template_day_exercises' ) . ' WHERE template_day_id = %d ORDER BY position', (int) $d->id
			), ARRAY_A );
			foreach ( $exs as $e ) {
				unset( $e['id'], $e['template_day_id'], $e['progression_rule'] );
				$e['workout_id'] = $wid;
				$wpdb->insert( MPVK_Schema::table( 'workout_exercises' ), $e );
			}
			$made++;
		}
		$wpdb->update( MPVK_Schema::table( 'templates' ), array( 'status' => 'active' ), array( 'id' => (int) $p->id ) );
		MPVK_Corpus::log( 'program_assigned', array(
			'org_id' => (int) $p->org_id, 'actor_user_id' => get_current_user_id(), 'subject_user_id' => $client,
			'object_type' => 'template', 'object_id' => (int) $p->id, 'payload' => array( 'workouts' => $made, 'start' => $start ),
		) );
		return array( 'ok' => true, 'workouts_created' => $made, 'subscription_id' => $sub_id );
	}

	/** Deep-copy a program so it can be customized without touching the master. */
	public static function copy_program( WP_REST_Request $req ): array|WP_Error {
		$p = self::own_program( (int) $req['id'] );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		global $wpdb;
		$now   = current_time( 'mysql', true );
		$title = sanitize_text_field( (string) $req->get_param( 'title' ) ) ?: ( $p->title . ' (copy)' );
		$wpdb->insert( MPVK_Schema::table( 'templates' ), array(
			'org_id' => (int) $p->org_id, 'title' => $title, 'description' => $p->description, 'goal' => $p->goal,
			'athlete_level' => $p->athlete_level, 'weeks' => (int) $p->weeks, 'days_per_week' => (int) $p->days_per_week,
			'status' => 'draft', 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
		) );
		$new_id = (int) $wpdb->insert_id;
		$days   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . MPVK_Schema::table( 'template_days' ) . ' WHERE template_id = %d', $p->id ) );
		foreach ( $days as $d ) {
			$wpdb->insert( MPVK_Schema::table( 'template_days' ), array(
				'template_id' => $new_id, 'org_id' => (int) $p->org_id, 'week_index' => (int) $d->week_index,
				'day_index' => (int) $d->day_index, 'block_label' => $d->block_label, 'title' => $d->title, 'notes' => $d->notes,
			) );
			$new_day = (int) $wpdb->insert_id;
			$exs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . MPVK_Schema::table( 'template_day_exercises' ) . ' WHERE template_day_id = %d', (int) $d->id ), ARRAY_A );
			foreach ( $exs as $e ) {
				unset( $e['id'] );
				$e['template_day_id'] = $new_day;
				$wpdb->insert( MPVK_Schema::table( 'template_day_exercises' ), $e );
			}
		}
		return array( 'id' => $new_id );
	}

	// ================= prompt-driven create + analyze =================

	public static function generate_program( WP_REST_Request $req ): array|WP_Error {
		$org = self::org();
		if ( is_wp_error( $org ) ) {
			return $org;
		}
		$prompt = trim( (string) $req->get_param( 'prompt' ) );
		if ( '' === $prompt ) {
			return new WP_Error( 'mpvk_bad_request', 'Describe the program you want.', array( 'status' => 400 ) );
		}
		if ( $rl = self::rate_limited( 'gen' ) ) {
			return $rl;
		}
		global $wpdb;
		$lib = $wpdb->get_col( $wpdb->prepare(
			'SELECT name FROM ' . MPVK_Schema::table( 'exercise_library' ) . ' WHERE org_id = %d ORDER BY name LIMIT 300', $org
		) );
		$system = 'You are a strength & conditioning program designer for Miles Partain, a pro volleyball player/coach. '
			. "Output ONLY valid JSON, no prose. Shape:\n"
			. '{"title":str,"goal":str,"athlete_level":"beginner|intermediate|advanced","weeks":int,"days_per_week":int,'
			. '"days":[{"week_index":int,"day_index":int,"block_label":str,"title":str,'
			. '"exercises":[{"exercise_name":str,"entry_type":"sets|circuit|text","sets":str,"reps":str,'
			. '"rep_unit":"reps|sec|each_side|each","load_mode":"weight|rpe|percent|bw|none","load":str,'
			. '"rest":str,"tempo":str,"circuit_group":str,"is_optional":bool,"block_text":str,"notes":str}]}]}\n'
			. 'RULES: Prefer exercises from the provided library (match names exactly). Never invent a 1RM — use RPE (load_mode=rpe) '
			. 'unless a % is explicitly wanted (load_mode=percent). Bodyweight → load_mode=bw. A pure instruction with no sets → '
			. 'entry_type=text with block_text. Circuit members share the same circuit_group letter and set entry_type=circuit. '
			. 'Sane volume for the stated level. Anything medical/pain → put it in notes for the coach, never program around it.';
		$user = "LIBRARY (prefer these):\n" . ( $lib ? implode( ', ', $lib ) : '(empty — use standard names)' ) . "\n\nREQUEST:\n" . $prompt;

		$text = MPVK_AI::complete( $system, $user, 4000 );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$data = MPVK_AI::extract_json( $text );
		if ( ! is_array( $data ) || empty( $data['days'] ) ) {
			return new WP_Error( 'mpvk_ai_parse', 'The model did not return a usable program. Try rephrasing.', array( 'status' => 502 ) );
		}
		// Persist as a DRAFT the coach can review/edit before assigning.
		$now = current_time( 'mysql', true );
		$wpdb->insert( MPVK_Schema::table( 'templates' ), array(
			'org_id' => $org, 'title' => sanitize_text_field( (string) ( $data['title'] ?? 'AI Program' ) ),
			'description' => 'Generated from: ' . mb_substr( $prompt, 0, 240 ),
			'goal' => sanitize_text_field( (string) ( $data['goal'] ?? '' ) ),
			'athlete_level' => sanitize_text_field( (string) ( $data['athlete_level'] ?? '' ) ),
			'weeks' => max( 1, (int) ( $data['weeks'] ?? 1 ) ), 'days_per_week' => max( 1, (int) ( $data['days_per_week'] ?? 3 ) ),
			'status' => 'draft', 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
		) );
		$tpl = (int) $wpdb->insert_id;
		foreach ( (array) $data['days'] as $d ) {
			$day_id = self::ensure_day( $tpl, $org, max( 1, (int) ( $d['week_index'] ?? 1 ) ), max( 1, (int) ( $d['day_index'] ?? 1 ) ), (string) ( $d['block_label'] ?? '' ), (string) ( $d['title'] ?? '' ) );
			$pos    = 0;
			foreach ( (array) ( $d['exercises'] ?? array() ) as $e ) {
				$row                    = self::clean_exercise( (array) $e, $org );
				$row['position']        = $pos++;
				$row['template_day_id'] = $day_id;
				$wpdb->insert( MPVK_Schema::table( 'template_day_exercises' ), $row );
			}
		}
		MPVK_Corpus::log( 'program_generated', array(
			'org_id' => $org, 'actor_user_id' => get_current_user_id(),
			'object_type' => 'template', 'object_id' => $tpl, 'payload' => array( 'prompt' => $prompt, 'days' => count( $data['days'] ) ),
		) );
		return array( 'id' => $tpl, 'title' => $data['title'] ?? 'AI Program', 'days' => count( $data['days'] ) );
	}

	public static function analyze_athlete( WP_REST_Request $req ): array|WP_Error {
		$actor  = get_current_user_id();
		$client = (int) $req->get_param( 'client_id' );
		$q      = trim( (string) $req->get_param( 'prompt' ) ) ?: 'How is this athlete trending? Compliance, load progression, RPE, and anything to watch.';
		if ( ! $client || ! MPVK_Tenancy::can_access_client( $actor, $client ) ) {
			return new WP_Error( 'mpvk_forbidden', 'Cannot access that athlete.', array( 'status' => 403 ) );
		}
		if ( $rl = self::rate_limited( 'analyze' ) ) {
			return $rl;
		}
		global $wpdb;
		$since = gmdate( 'Y-m-d', time() - 42 * DAY_IN_SECONDS );
		$workouts = $wpdb->get_results( $wpdb->prepare(
			'SELECT workout_date, title, status FROM ' . MPVK_Schema::table( 'workouts' ) .
			' WHERE client_user_id = %d AND workout_date >= %s ORDER BY workout_date', $client, $since
		) );
		$logs = $wpdb->get_results( $wpdb->prepare(
			'SELECT l.logged_at, l.actual_load, l.rpe, l.set_number, e.exercise_name
			 FROM ' . MPVK_Schema::table( 'exercise_logs' ) . ' l
			 JOIN ' . MPVK_Schema::table( 'workout_exercises' ) . ' e ON e.id = l.workout_exercise_id
			 WHERE l.client_user_id = %d AND l.logged_at >= %s ORDER BY l.logged_at', $client, $since . ' 00:00:00'
		) );
		$done = 0; $miss = 0; $part = 0;
		foreach ( $workouts as $w ) {
			if ( 'completed' === $w->status ) { $done++; } elseif ( 'missed' === $w->status ) { $miss++; } elseif ( 'partial' === $w->status ) { $part++; }
		}
		// Readiness check-ins (joint/tendon health) in the window — surfaces pain trends.
		$checks = $wpdb->get_results( $wpdb->prepare(
			'SELECT wo.workout_date, c.scores, c.overall, c.note FROM ' . MPVK_Schema::table( 'workout_checkins' ) . ' c
			 JOIN ' . MPVK_Schema::table( 'workouts' ) . ' wo ON wo.id = c.workout_id
			 WHERE c.client_user_id = %d AND wo.workout_date >= %s ORDER BY wo.workout_date', $client, $since
		) );
		$check_lines = array();
		foreach ( $checks as $c ) {
			$sc = json_decode( (string) $c->scores, true ) ?: array();
			$parts = array();
			foreach ( $sc as $area => $v ) { $parts[] = "$area $v/10"; }
			$check_lines[] = $c->workout_date . '  overall ' . (int) $c->overall . '/10  (' . implode( ', ', $parts ) . ')' . ( $c->note ? '  note: ' . $c->note : '' );
		}
		$name = get_user_by( 'id', $client )->display_name ?? 'Athlete';
		$ctx  = "ATHLETE: $name\nWindow: last 6 weeks (since $since)\n"
			. "Sessions: " . count( $workouts ) . " scheduled — $done completed, $part partial, $miss missed.\n\n"
			. "SESSION LOG:\n" . ( $workouts ? implode( "\n", array_map( fn( $w ) => "$w->workout_date  $w->title  [$w->status]", $workouts ) ) : '(none)' )
			. "\n\nLOGGED SETS (date · exercise · set · load · RPE):\n"
			. ( $logs ? implode( "\n", array_map( fn( $l ) => substr( $l->logged_at, 0, 10 ) . "  $l->exercise_name  set$l->set_number  " . ( $l->actual_load ?: '—' ) . '  RPE ' . ( $l->rpe ?: '—' ), array_slice( $logs, -120 ) ) ) : '(no weights logged yet)' )
			. "\n\nPRE-WORKOUT READINESS (joint/tendon health, 10=healthy 0=pain):\n"
			. ( $check_lines ? implode( "\n", $check_lines ) : '(no check-ins logged yet)' );

		$system = 'You are an analyst for volleyball S&C coach Miles Partain. Answer the coach\'s question about ONE athlete using ONLY the data given. '
			. 'Be concrete and cite specifics (dates, exercises, numbers). If data is thin, say so. Flag anything concerning (missed streaks, RPE creep at same load, stalled progression). '
			. 'End with 1–2 concrete suggested actions. Plain text, tight — a coach reading on their phone.';
		$text = MPVK_AI::complete( $system, "DATA:\n$ctx\n\nCOACH'S QUESTION: $q", 1200 );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		MPVK_Corpus::log( 'athlete_analyzed', array(
			'org_id' => MPVK_Tenancy::org_id_of( $client ), 'actor_user_id' => $actor, 'subject_user_id' => $client,
			'object_type' => 'analysis', 'payload' => array( 'question' => $q ),
		) );
		return array( 'analysis' => $text, 'stats' => array( 'scheduled' => count( $workouts ), 'completed' => $done, 'partial' => $part, 'missed' => $miss ) );
	}

	// ================= athlete: weights / skip / history / detail =================

	/** Resolve an exercise row + parent workout, enforcing that the actor owns it. */
	private static function athlete_exercise( int $ex_id ): array|WP_Error {
		global $wpdb;
		$ex = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MPVK_Schema::table( 'workout_exercises' ) . ' WHERE id = %d', $ex_id ) );
		if ( ! $ex ) {
			return new WP_Error( 'mpvk_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		$w = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MPVK_Schema::table( 'workouts' ) . ' WHERE id = %d', (int) $ex->workout_id ) );
		if ( ! $w ) {
			return new WP_Error( 'mpvk_not_found', 'Workout gone.', array( 'status' => 404 ) );
		}
		$actor = get_current_user_id();
		$own   = (int) $w->client_user_id === $actor;
		if ( ! $own && ! MPVK_Tenancy::can_access_client( $actor, (int) $w->client_user_id ) ) {
			return new WP_Error( 'mpvk_forbidden', 'Not your workout.', array( 'status' => 403 ) );
		}
		return array( $ex, $w, $own );
	}

	/** Athlete logs the WEIGHT (and optional RPE) they used — sets/reps stay fixed. */
	public static function log_weight( WP_REST_Request $req ): array|WP_Error {
		$r = self::athlete_exercise( (int) $req['id'] );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		[ $ex, $w, $own ] = $r;
		if ( ! $own && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'mpvk_forbidden', 'Only the athlete logs their weights.', array( 'status' => 403 ) );
		}
		$set  = max( 1, (int) $req->get_param( 'set_number' ) );
		$load = sanitize_text_field( (string) $req->get_param( 'load' ) );
		$rpe  = $req->get_param( 'rpe' );
		global $wpdb;
		$t   = MPVK_Schema::table( 'exercise_logs' );
		// upsert by (exercise, set) so re-entering a weight overwrites rather than duplicates
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE workout_exercise_id = %d AND set_number = %d", (int) $ex->id, $set ) );
		$row = array(
			'workout_exercise_id' => (int) $ex->id, 'workout_id' => (int) $w->id, 'org_id' => (int) $w->org_id,
			'client_user_id' => (int) $w->client_user_id, 'set_number' => $set,
			'actual_reps' => sanitize_text_field( (string) ( $req->get_param( 'reps' ) ?? $ex->prescribed_reps ) ),
			'actual_load' => $load, 'rpe' => ( null === $rpe || '' === $rpe ) ? null : max( 0, min( 10, (float) $rpe ) ),
			'comment' => sanitize_text_field( (string) $req->get_param( 'comment' ) ), 'logged_at' => current_time( 'mysql', true ),
		);
		if ( $existing ) {
			$wpdb->update( $t, $row, array( 'id' => $existing ) );
			$log_id = $existing;
		} else {
			$wpdb->insert( $t, $row );
			$log_id = (int) $wpdb->insert_id;
		}
		MPVK_Corpus::log( 'weight_logged', array(
			'org_id' => (int) $w->org_id, 'actor_user_id' => get_current_user_id(), 'subject_user_id' => (int) $w->client_user_id,
			'object_type' => 'exercise_log', 'object_id' => $log_id,
			'payload' => array( 'exercise' => $ex->exercise_name, 'set' => $set, 'load' => $load, 'rpe' => $row['rpe'] ),
		) );
		return array( 'ok' => true, 'set_number' => $set, 'load' => $load, 'rpe' => $row['rpe'] );
	}

	/** Opt out of (or back into) an optional exercise. */
	public static function toggle_skip( WP_REST_Request $req ): array|WP_Error {
		$r = self::athlete_exercise( (int) $req['id'] );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		[ $ex, $w, $own ] = $r;
		if ( ! $own && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'mpvk_forbidden', 'Only the athlete can opt out.', array( 'status' => 403 ) );
		}
		if ( ! (int) $ex->is_optional ) {
			return new WP_Error( 'mpvk_bad_request', 'This exercise is required by your coach.', array( 'status' => 400 ) );
		}
		global $wpdb;
		$skip = null === $ex->skipped_at;
		$wpdb->update( MPVK_Schema::table( 'workout_exercises' ),
			array( 'skipped_at' => $skip ? current_time( 'mysql', true ) : null ),
			array( 'id' => (int) $ex->id )
		);
		return array( 'ok' => true, 'skipped' => $skip );
	}

	/** Weight history for THIS lift for THIS athlete (by exercise name), most recent first. */
	public static function lift_history( WP_REST_Request $req ): array|WP_Error {
		$r = self::athlete_exercise( (int) $req['id'] );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		[ $ex, $w ] = $r;
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT wo.workout_date, l.set_number, l.actual_load, l.actual_reps, l.rpe
			 FROM ' . MPVK_Schema::table( 'exercise_logs' ) . ' l
			 JOIN ' . MPVK_Schema::table( 'workout_exercises' ) . ' e ON e.id = l.workout_exercise_id
			 JOIN ' . MPVK_Schema::table( 'workouts' ) . ' wo ON wo.id = l.workout_id
			 WHERE l.client_user_id = %d AND e.exercise_name = %s AND l.actual_load <> %s
			 ORDER BY wo.workout_date DESC, l.set_number ASC LIMIT 60',
			(int) $w->client_user_id, $ex->exercise_name, ''
		), ARRAY_A );
		$by_date = array();
		foreach ( $rows as $row ) {
			$by_date[ $row['workout_date'] ][] = array( 'set' => (int) $row['set_number'], 'load' => $row['actual_load'], 'reps' => $row['actual_reps'], 'rpe' => $row['rpe'] );
		}
		$history = array();
		foreach ( $by_date as $date => $sets ) {
			$history[] = array( 'date' => $date, 'sets' => $sets );
		}
		return array( 'exercise' => $ex->exercise_name, 'history' => $history );
	}

	/** Directions + form video + coach notes for one exercise (tap-in detail). */
	public static function exercise_detail( WP_REST_Request $req ): array|WP_Error {
		$r = self::athlete_exercise( (int) $req['id'] );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		[ $ex, $w ] = $r;
		$video = ''; $directions = (string) ( $ex->cues ?? '' );
		if ( $ex->library_id ) {
			global $wpdb;
			$lib = $wpdb->get_row( $wpdb->prepare(
				'SELECT video_url, cues FROM ' . MPVK_Schema::table( 'exercise_library' ) . ' WHERE id = %d AND org_id = %d', (int) $ex->library_id, (int) $w->org_id
			) );
			if ( $lib ) {
				$video      = (string) $lib->video_url;
				$directions = $directions ?: (string) $lib->cues;
			}
		}
		$embed = self::embed_url( $video );
		return array(
			'name'       => $ex->exercise_name,
			'directions' => $directions,
			'notes'      => (string) ( $ex->notes ?? '' ),
			'video'      => $embed,
			// raw link only when we can't safely embed — UI shows it as an "open video" anchor
			'video_link' => ( '' === $embed && '' !== trim( $video ) ) ? esc_url_raw( $video ) : '',
			'load_mode'  => $ex->load_mode,
			'rep_unit'   => $ex->rep_unit,
		);
	}

	/**
	 * Turn a YouTube/Vimeo/Loom watch URL into an embeddable one. Anything that isn't a
	 * known video host returns '' — we never iframe an arbitrary coach-supplied URL at an
	 * athlete (phishing/clickjacking guard, review v0.6-3). The raw link is still shown as a
	 * plain "open video" anchor in the UI for non-embeddable hosts.
	 */
	public static function embed_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/shorts/)([\w-]{6,})~i', $url, $m ) ) {
			return 'https://www.youtube.com/embed/' . $m[1];
		}
		if ( preg_match( '~vimeo\.com/(\d+)~i', $url, $m ) ) {
			return 'https://player.vimeo.com/video/' . $m[1];
		}
		if ( preg_match( '~loom\.com/share/([\w]+)~i', $url, $m ) ) {
			return 'https://www.loom.com/embed/' . $m[1];
		}
		return ''; // unknown host — not embeddable
	}
}
