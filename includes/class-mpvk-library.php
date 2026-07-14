<?php
defined( 'ABSPATH' ) || exit;

/**
 * Exercise Library — Phase 1 of the workout builder (see workout-builder-plan.md).
 * Org-scoped, coach-managed. The program AI (Phase 3) may only prescribe from here,
 * so this is the grounding layer for everything prompt-driven.
 */
class MPVK_Library {

	const NS = 'mpvk/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	private static function can_manage(): bool {
		return current_user_can( 'mpvk_assign_workouts' ) || current_user_can( 'manage_options' );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/library', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_items' ),
				'permission_callback' => fn() => self::can_manage(),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_item' ),
				'permission_callback' => fn() => self::can_manage(),
			),
		) );
		register_rest_route( self::NS, '/library/(?P<id>\d+)', array(
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'update_item' ),
				'permission_callback' => fn() => self::can_manage(),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_item' ),
				'permission_callback' => fn() => self::can_manage(),
			),
		) );
		register_rest_route( self::NS, '/library/seed-starter', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'seed_starter' ),
			'permission_callback' => fn() => self::can_manage(),
		) );
	}

	private static function org(): int|WP_Error {
		$org = MPVK_Tenancy::org_id_of( get_current_user_id() );
		if ( ! $org ) {
			return new WP_Error( 'mpvk_no_org', 'No organization for this account.', array( 'status' => 403 ) );
		}
		return $org;
	}

	/** Fetch a row and verify it belongs to the actor's org (object-level tenancy). */
	private static function own_row( int $id ): stdClass|WP_Error {
		$org = self::org();
		if ( is_wp_error( $org ) ) {
			return $org;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . MPVK_Schema::table( 'exercise_library' ) . ' WHERE id = %d', $id
		) );
		if ( ! $row ) {
			return new WP_Error( 'mpvk_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		if ( (int) $row->org_id !== $org ) {
			MPVK_Audit::log( 'permission_denied', array( 'meta' => array( 'why' => 'library_scope' ) ) );
			return new WP_Error( 'mpvk_forbidden', 'You cannot access that.', array( 'status' => 403 ) );
		}
		return $row;
	}

	private static function shape( object $r ): array {
		return array(
			'id'        => (int) $r->id,
			'name'      => $r->name,
			'category'  => $r->category,
			'equipment' => $r->equipment,
			'level'     => $r->level,
			'cues'      => $r->cues,
			'video_url' => $r->video_url,
			'tags'      => $r->tags,
		);
	}

	public static function list_items( WP_REST_Request $req ): array|WP_Error {
		$org = self::org();
		if ( is_wp_error( $org ) ) {
			return $org;
		}
		global $wpdb;
		$q     = sanitize_text_field( (string) $req->get_param( 'q' ) );
		$cat   = sanitize_text_field( (string) $req->get_param( 'category' ) );
		$where = 'org_id = %d';
		$args  = array( $org );
		if ( '' !== $q ) {
			$where .= ' AND (name LIKE %s OR tags LIKE %s OR cues LIKE %s)';
			$like   = '%' . $wpdb->esc_like( $q ) . '%';
			array_push( $args, $like, $like, $like );
		}
		if ( '' !== $cat ) {
			$where .= ' AND category = %s';
			$args[] = $cat;
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . MPVK_Schema::table( 'exercise_library' ) . " WHERE $where ORDER BY category, name LIMIT 500",
			$args
		) );
		return array( 'exercises' => array_map( array( __CLASS__, 'shape' ), $rows ) );
	}

	/** @return array<string,string> sanitized field map from the request */
	private static function fields( WP_REST_Request $req ): array {
		$out = array();
		foreach ( array( 'name', 'category', 'equipment', 'level', 'tags' ) as $f ) {
			$v = $req->get_param( $f );
			if ( null !== $v ) {
				$out[ $f ] = sanitize_text_field( (string) $v );
			}
		}
		$cues = $req->get_param( 'cues' );
		if ( null !== $cues ) {
			$out['cues'] = sanitize_textarea_field( (string) $cues );
		}
		$url = $req->get_param( 'video_url' );
		if ( null !== $url ) {
			$url              = esc_url_raw( (string) $url );
			$out['video_url'] = $url ?: '';
		}
		return $out;
	}

	public static function create_item( WP_REST_Request $req ): array|WP_Error {
		$org = self::org();
		if ( is_wp_error( $org ) ) {
			return $org;
		}
		$f = self::fields( $req );
		if ( empty( $f['name'] ) ) {
			return new WP_Error( 'mpvk_bad_request', 'Name required.', array( 'status' => 400 ) );
		}
		global $wpdb;
		$wpdb->insert( MPVK_Schema::table( 'exercise_library' ), array_merge( $f, array(
			'org_id'     => $org,
			'created_by' => get_current_user_id(),
			'created_at' => current_time( 'mysql', true ),
		) ) );
		$id = (int) $wpdb->insert_id;
		MPVK_Corpus::log( 'library_exercise_added', array(
			'org_id' => $org, 'actor_user_id' => get_current_user_id(),
			'object_type' => 'exercise', 'object_id' => $id, 'payload' => $f,
		) );
		return array( 'id' => $id );
	}

	public static function update_item( WP_REST_Request $req ): array|WP_Error {
		$row = self::own_row( (int) $req['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$f = self::fields( $req );
		if ( isset( $f['name'] ) && '' === $f['name'] ) {
			return new WP_Error( 'mpvk_bad_request', 'Name cannot be empty.', array( 'status' => 400 ) );
		}
		if ( ! $f ) {
			return new WP_Error( 'mpvk_bad_request', 'Nothing to update.', array( 'status' => 400 ) );
		}
		global $wpdb;
		$wpdb->update( MPVK_Schema::table( 'exercise_library' ), $f, array( 'id' => (int) $row->id ) );
		return array( 'ok' => true );
	}

	public static function delete_item( WP_REST_Request $req ): array|WP_Error {
		$row = self::own_row( (int) $req['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		global $wpdb;
		$wpdb->delete( MPVK_Schema::table( 'exercise_library' ), array( 'id' => (int) $row->id ) );
		MPVK_Corpus::log( 'library_exercise_removed', array(
			'org_id' => (int) $row->org_id, 'actor_user_id' => get_current_user_id(),
			'object_type' => 'exercise', 'object_id' => (int) $row->id, 'payload' => array( 'name' => $row->name ),
		) );
		return array( 'ok' => true );
	}

	/** One-click curated volleyball starter set. Idempotent by (org, name). */
	public static function seed_starter(): array|WP_Error {
		$org = self::org();
		if ( is_wp_error( $org ) ) {
			return $org;
		}
		global $wpdb;
		$table = MPVK_Schema::table( 'exercise_library' );
		$now   = current_time( 'mysql', true );
		$uid   = get_current_user_id();
		$added = 0;
		foreach ( self::starter_set() as $ex ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM $table WHERE org_id = %d AND name = %s", $org, $ex[0]
			) );
			if ( $exists ) {
				continue;
			}
			$wpdb->insert( $table, array(
				'org_id'     => $org,
				'name'       => $ex[0],
				'category'   => $ex[1],
				'equipment'  => $ex[2],
				'level'      => $ex[3],
				'cues'       => $ex[4],
				'created_by' => $uid,
				'created_at' => $now,
			) );
			$added++;
		}
		MPVK_Corpus::log( 'library_starter_seeded', array(
			'org_id' => $org, 'actor_user_id' => $uid, 'payload' => array( 'added' => $added ),
		) );
		return array( 'added' => $added, 'total' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE org_id = %d", $org ) ) );
	}

	/**
	 * Curated volleyball starter library: [name, category, equipment, level, cues].
	 * category ∈ strength | plyo | skill | mobility | conditioning | prehab.
	 * A starting point for Miles to prune/extend — NOT a prescription.
	 */
	private static function starter_set(): array {
		return array(
			// strength — lower
			array( 'Trap Bar Deadlift', 'strength', 'trap bar', 'intermediate', 'Push the floor away; brace before each pull; hips and shoulders rise together.' ),
			array( 'Back Squat', 'strength', 'barbell, rack', 'intermediate', 'Big air into the belt line; knees track over toes; drive up out of the hole.' ),
			array( 'Goblet Squat', 'strength', 'dumbbell', 'beginner', 'Elbows inside knees at the bottom; chest tall; heels heavy.' ),
			array( 'Rear-Foot-Elevated Split Squat', 'strength', 'dumbbells, bench', 'intermediate', 'Front shin vertical; drop the back knee straight down; drive through the front heel.' ),
			array( 'Romanian Deadlift', 'strength', 'barbell or dumbbells', 'intermediate', 'Soft knees; push hips back until hamstrings load; flat back the whole way.' ),
			array( 'Single-Leg RDL', 'strength', 'dumbbell', 'intermediate', 'Hips square to the floor; reach long through the heel; own the wobble.' ),
			array( 'Walking Lunge', 'strength', 'bodyweight or dumbbells', 'beginner', 'Tall torso; knee kisses the floor; push the ground behind you.' ),
			array( 'Hip Thrust', 'strength', 'barbell, bench', 'beginner', 'Chin tucked; ribs down; squeeze to a full lockout without arching the low back.' ),
			array( 'Calf Raise (straight knee)', 'strength', 'step, dumbbell', 'beginner', 'Full stretch at the bottom, pause at the top; slow lowering builds the tendon.' ),
			array( 'Step-Up', 'strength', 'box, dumbbells', 'beginner', 'Whole foot on the box; no push off the bottom leg; control down.' ),
			// strength — upper
			array( 'Push-Up', 'strength', 'bodyweight', 'beginner', 'Rigid plank; elbows ~45°; push the floor away at the top.' ),
			array( 'Dumbbell Bench Press', 'strength', 'dumbbells, bench', 'beginner', 'Shoulder blades tucked; forearms vertical; press to a soft lockout.' ),
			array( 'Overhead Press', 'strength', 'dumbbells or barbell', 'intermediate', 'Ribs down; squeeze glutes; finish with biceps by the ears.' ),
			array( 'One-Arm Dumbbell Row', 'strength', 'dumbbell, bench', 'beginner', 'Pull the elbow to the hip; no torso rotation; full stretch at the bottom.' ),
			array( 'Chin-Up / Assisted Chin-Up', 'strength', 'bar, band optional', 'intermediate', 'Start from a dead hang; lead with the chest; chin over without kipping.' ),
			array( 'Inverted Row', 'strength', 'bar or rings', 'beginner', 'Body like a plank; pull chest to the bar; pause one beat at the top.' ),
			// plyo / jump
			array( 'Approach Jump', 'plyo', 'court or open floor', 'intermediate', 'Penultimate step long and low; arms rip through; full intent every rep.' ),
			array( 'Box Jump', 'plyo', 'plyo box', 'beginner', 'Jump tall, land soft and quiet; step down, never jump down.' ),
			array( 'Depth Drop to Stick', 'plyo', 'low box', 'intermediate', 'Step off, land in your jump stance, freeze 2s — silence is the score.' ),
			array( 'Depth Jump', 'plyo', 'plyo box', 'advanced', 'Minimal ground time; think "hot floor"; quality over quantity — stop when height drops.' ),
			array( 'Broad Jump', 'plyo', 'open floor', 'beginner', 'Big arm swing; land in a quarter squat; stick before stepping out.' ),
			array( 'Lateral Bound to Stick', 'plyo', 'open floor', 'intermediate', 'Push sideways off the whole foot; land on one leg and own it for 2s.' ),
			array( 'Pogo Hops', 'plyo', 'bodyweight', 'beginner', 'Stiff ankles, tall posture; bounce off the floor like a ball — ankles do the work.' ),
			array( 'Single-Leg Line Hops', 'plyo', 'a line on the floor', 'beginner', 'Quick, small, quiet; keep the hips level; both directions.' ),
			array( 'Med Ball Slam', 'plyo', 'medicine ball', 'beginner', 'Whole body into the throw; hips snap; catch and go.' ),
			array( 'Med Ball Rotational Throw', 'plyo', 'medicine ball, wall', 'intermediate', 'Load the back hip, throw the hip through — the arm is along for the ride (arm-swing power).' ),
			// skill-adjacent
			array( 'Blocking Footwork Ladder', 'skill', 'net or wall', 'beginner', 'Swing-block or shuffle pattern; hands stay high; eyes track the "setter."' ),
			array( 'Wall Serve Toss Consistency', 'skill', 'ball, wall', 'beginner', 'Same toss, same contact point, 20 in a row before adding power.' ),
			array( 'Setting Tempo Against Wall', 'skill', 'ball, wall', 'beginner', 'Hands shaped early; contact above the forehead; rhythm over force.' ),
			array( 'Approach Footwork (no ball)', 'skill', 'court', 'beginner', 'Left-right-LEFT (righties); accelerate through the last two steps; arms back on the penultimate.' ),
			// mobility
			array( '90/90 Hip Switch', 'mobility', 'floor', 'beginner', 'Both hips move together; tall spine; breathe into the stretch, no forcing.' ),
			array( 'Ankle Dorsiflexion Rock', 'mobility', 'wall or band', 'beginner', 'Knee over the pinky toe; heel glued down; 10 slow rocks per side.' ),
			array( 'Thoracic Opener on Bench', 'mobility', 'bench', 'beginner', 'Elbows on the bench, hips back; let the chest fall through; exhale to deepen.' ),
			array( 'Couch Stretch', 'mobility', 'wall or couch', 'beginner', 'Squeeze the glute of the back leg; ribs down; 60–90s per side.' ),
			array( 'Hamstring Flossing', 'mobility', 'floor', 'beginner', 'Kick to the edge, not through it; point and flex the toes each rep.' ),
			// prehab
			array( 'Copenhagen Plank', 'prehab', 'bench', 'intermediate', 'Body in one line; squeeze the top leg into the bench; shake is normal, pain is not.' ),
			array( 'Nordic Curl (eccentric)', 'prehab', 'partner or anchor', 'advanced', 'Fight the fall as long as possible; hips stay extended; catch with hands.' ),
			array( 'Tibialis Raise', 'prehab', 'wall', 'beginner', 'Heels forward of hips; pull toes up fast, lower slow; jumper\'s shin insurance.' ),
			array( 'Single-Leg Calf Raise (bent knee)', 'prehab', 'step', 'beginner', 'Knee stays bent ~20° the whole set; targets the soleus — landing armor.' ),
			array( 'Banded Shoulder External Rotation', 'prehab', 'band', 'beginner', 'Elbow pinned to the ribs; rotate out slow, return slower; no shrugging.' ),
			array( 'Y-T-W Raises', 'prehab', 'incline bench, light DBs', 'beginner', 'Thumbs up; lead with the shoulder blades; light weight, crisp positions.' ),
			array( 'Side Plank with Hip Dip', 'prehab', 'floor', 'beginner', 'Stack shoulders over elbow; dip and drive the hip tall; no sag.' ),
			// conditioning
			array( 'Bike Sprints', 'conditioning', 'bike', 'beginner', 'All-out seconds, full recovery — train the engine without pounding the legs.' ),
			array( 'Court Suicides (controlled)', 'conditioning', 'court', 'intermediate', 'Touch every line; decelerate under control — the stop is the training.' ),
			array( 'Jump Rope Intervals', 'conditioning', 'rope', 'beginner', 'Quiet feet, tall posture; 30s on / 30s off; ankles springy.' ),
		);
	}
}
