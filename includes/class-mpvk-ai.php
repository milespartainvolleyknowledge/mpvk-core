<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI draft replies — the first slice of the "AI brain" (draft-only, never auto-sends).
 *
 * When a CLIENT messages their coach, this generates a suggested reply in Miles's voice
 * from the thread history + the client's recent training, and shows it to the coach above
 * the composer: use it (edited or not) or dismiss it. Every outcome is logged to the
 * corpus as a training signal — especially the diff between draft and what actually got sent.
 *
 * DORMANT unless BOTH are set on the MPVK admin page: the Anthropic API key + the enable
 * toggle. No key ever ships in code. Generation runs async (WP-Cron) so sending is never
 * slowed down; failures degrade silently (coach just sees no suggestion).
 */
class MPVK_AI {

	const DEFAULT_MODEL = 'claude-sonnet-4-5';

	public static function init(): void {
		add_action( 'mpvk_message_sent', array( __CLASS__, 'maybe_queue' ), 10, 2 );
		add_action( 'mpvk_ai_generate', array( __CLASS__, 'generate' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function enabled(): bool {
		return (bool) get_option( 'mpvk_ai_enabled', false ) && '' !== (string) get_option( 'mpvk_ai_key', '' );
	}

	public static function model(): string {
		return (string) get_option( 'mpvk_ai_model', self::DEFAULT_MODEL ) ?: self::DEFAULT_MODEL;
	}

	/**
	 * Reusable Anthropic completion. Returns the model's text or a WP_Error.
	 * Shared by message drafts and the program builder/analyzer.
	 */
	public static function complete( string $system, string $user, int $max_tokens = 1500 ): string|WP_Error {
		if ( ! self::enabled() ) {
			return new WP_Error( 'mpvk_ai_off', 'AI is not enabled. Add your Anthropic API key on the MPVK admin page.', array( 'status' => 400 ) );
		}
		$resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 60,
			'headers' => array(
				'content-type'      => 'application/json',
				'x-api-key'         => (string) get_option( 'mpvk_ai_key', '' ),
				'anthropic-version' => '2023-06-01',
			),
			'body'    => wp_json_encode( array(
				'model'      => self::model(),
				'max_tokens' => $max_tokens,
				'system'     => $system,
				'messages'   => array( array( 'role' => 'user', 'content' => $user ) ),
			) ),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			$err = isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'HTTP ' . $code );
			return new WP_Error( 'mpvk_ai_http', $err, array( 'status' => 502 ) );
		}
		$text = '';
		foreach ( (array) ( $body['content'] ?? array() ) as $b ) {
			if ( 'text' === ( $b['type'] ?? '' ) ) {
				$text .= $b['text'];
			}
		}
		return trim( $text );
	}

	/** Extract the first JSON object/array from a model reply (handles ```json fences). */
	public static function extract_json( string $text ): mixed {
		$text = preg_replace( '/^```(?:json)?|```$/m', '', $text );
		$start = strcspn( $text, '{[' );
		if ( $start >= strlen( $text ) ) {
			return null;
		}
		$sub = substr( $text, $start );
		$dec = json_decode( $sub, true );
		if ( null !== $dec ) {
			return $dec;
		}
		// trim trailing prose after the JSON by matching balanced braces
		$open = $sub[0]; $close = '{' === $open ? '}' : ']';
		$depth = 0; $end = 0; $instr = false; $esc = false;
		for ( $i = 0; $i < strlen( $sub ); $i++ ) {
			$c = $sub[ $i ];
			if ( $instr ) {
				if ( $esc ) { $esc = false; }
				elseif ( '\\' === $c ) { $esc = true; }
				elseif ( '"' === $c ) { $instr = false; }
				continue;
			}
			if ( '"' === $c ) { $instr = true; }
			elseif ( $c === $open ) { $depth++; }
			elseif ( $c === $close ) { $depth--; if ( 0 === $depth ) { $end = $i + 1; break; } }
		}
		return $end ? json_decode( substr( $sub, 0, $end ), true ) : null;
	}

	/** Queue a draft when a client messages their coach (fires from send_message). */
	public static function maybe_queue( int $msg_id, array $ctx ): void {
		if ( ! self::enabled() ) {
			return;
		}
		// Only draft replies TO the coach (inbound from a client).
		if ( 'client' !== MPVK_Roles::tier_of( (int) $ctx['sender'] ) ) {
			return;
		}
		// Cost/DoS guard: at most 10 generations per client per hour. A chatty athlete
		// still gets a fresh draft on their latest message most of the time; a scripted
		// flood can't run up the API bill or pin workers. (Review finding v0.4-1.)
		$rk = 'mpvk_ai_rl_' . (int) $ctx['sender'];
		$n  = (int) get_transient( $rk );
		if ( $n >= 10 ) {
			return;
		}
		set_transient( $rk, $n + 1, HOUR_IN_SECONDS );
		global $wpdb;
		$table = MPVK_Schema::table( 'ai_drafts' );
		// Supersede older unresolved drafts for this thread — only the latest message needs one.
		$wpdb->query( $wpdb->prepare(
			"UPDATE $table SET status = 'superseded', resolved_at = %s WHERE thread_key = %s AND status IN ('pending','ready')",
			current_time( 'mysql', true ), $ctx['thread_key']
		) );
		$wpdb->insert( $table, array(
			'org_id'         => (int) $ctx['org_id'],
			'thread_key'     => $ctx['thread_key'],
			'message_id'     => $msg_id,
			'client_user_id' => (int) $ctx['sender'],
			'status'         => 'pending',
			'model'          => self::model(),
			'created_at'     => current_time( 'mysql', true ),
		) );
		$draft_id = (int) $wpdb->insert_id;
		// Async only — never generate on the sender's own request (a 45s outbound call
		// would pin a PHP worker per message; review finding v0.4-1). WP-Cron spawns on
		// the next request, and the portal polls every 4s, so pickup is near-immediate.
		wp_schedule_single_event( time(), 'mpvk_ai_generate', array( $draft_id ) );
	}

	/** Build the context block: recent thread + the client's recent training. */
	private static function context_for( object $draft ): string {
		global $wpdb;
		$msgs = array_reverse( $wpdb->get_results( $wpdb->prepare(
			'SELECT sender_user_id, body, attachment_type, created_at FROM ' . MPVK_Schema::table( 'messages' ) .
			' WHERE thread_key = %s AND id <= %d ORDER BY id DESC LIMIT 25',
			$draft->thread_key, (int) $draft->message_id
		) ) );
		$client      = get_user_by( 'id', (int) $draft->client_user_id );
		$client_name = $client ? $client->display_name : 'the athlete';
		$lines       = array();
		foreach ( $msgs as $m ) {
			$who    = ( (int) $m->sender_user_id === (int) $draft->client_user_id ) ? $client_name : 'Coach';
			$body   = '' !== $m->body ? $m->body : ( $m->attachment_type ? '[sent a ' . $m->attachment_type . ']' : '' );
			$lines[] = $who . ': ' . $body;
		}
		$workouts = $wpdb->get_results( $wpdb->prepare(
			'SELECT workout_date, title, status FROM ' . MPVK_Schema::table( 'workouts' ) .
			' WHERE client_user_id = %d AND workout_date BETWEEN %s AND %s ORDER BY workout_date',
			(int) $draft->client_user_id,
			gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS )
		) );
		$wlines = array_map(
			fn( $w ) => $w->workout_date . ' — ' . $w->title . ' (' . $w->status . ')',
			$workouts
		);
		return "ATHLETE: $client_name\n\nRECENT CONVERSATION (oldest first):\n" . implode( "\n", $lines )
			. "\n\nTRAINING CALENDAR (±7 days):\n" . ( $wlines ? implode( "\n", $wlines ) : '(nothing scheduled)' );
	}

	/** Cron handler: call the Anthropic API and store the draft. */
	public static function generate( int $draft_id ): void {
		if ( ! self::enabled() ) {
			return;
		}
		global $wpdb;
		$table = MPVK_Schema::table( 'ai_drafts' );
		$draft = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $draft_id ) );
		if ( ! $draft || 'pending' !== $draft->status ) {
			return;
		}
		// Claim it (prevents cron + shutdown double-run).
		$claimed = $wpdb->query( $wpdb->prepare(
			"UPDATE $table SET status = 'generating' WHERE id = %d AND status = 'pending'", $draft_id
		) );
		if ( ! $claimed ) {
			return;
		}

		$system = 'You draft replies for Miles Partain, a professional volleyball player and coach, answering his coaching clients. '
			. 'Write ONLY the reply text — no preamble, no signature, no quotation marks. Match a texting register: '
			. 'warm, direct, encouraging, concise (usually 1-4 sentences), specific to what the athlete said and their training. '
			. 'Never invent workout details that are not in the context. If the athlete mentions pain, injury, or anything medical, '
			. 'the draft should acknowledge it and say Miles will look at it personally — never give medical advice.';

		$resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 45,
			'headers' => array(
				'content-type'      => 'application/json',
				'x-api-key'         => (string) get_option( 'mpvk_ai_key', '' ),
				'anthropic-version' => '2023-06-01',
			),
			'body'    => wp_json_encode( array(
				'model'      => $draft->model ?: self::model(),
				'max_tokens' => 500,
				'system'     => $system,
				'messages'   => array(
					array(
						'role'    => 'user',
						'content' => self::context_for( $draft ) . "\n\nDraft Miles's reply to the last message from the athlete.",
					),
				),
			) ),
		) );

		$now = current_time( 'mysql', true );
		if ( is_wp_error( $resp ) ) {
			$wpdb->update( $table, array( 'status' => 'failed', 'error' => $resp->get_error_message(), 'resolved_at' => $now ), array( 'id' => $draft_id ) );
			return;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		$text = '';
		foreach ( (array) ( $body['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= $block['text'];
			}
		}
		$text = trim( $text );
		if ( $code < 200 || $code >= 300 || '' === $text ) {
			$err = is_array( $body ) && isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'HTTP ' . $code );
			$wpdb->update( $table, array( 'status' => 'failed', 'error' => mb_substr( $err, 0, 500 ), 'resolved_at' => $now ), array( 'id' => $draft_id ) );
			return;
		}
		$wpdb->update( $table, array( 'status' => 'ready', 'draft_body' => $text ), array( 'id' => $draft_id ) );
		MPVK_Corpus::log( 'ai_draft_generated', array(
			'org_id' => (int) $draft->org_id, 'subject_user_id' => (int) $draft->client_user_id,
			'object_type' => 'ai_draft', 'object_id' => $draft_id,
			'payload' => array( 'model' => $draft->model, 'draft' => $text, 'message_id' => (int) $draft->message_id ),
		) );
	}

	// ---------------- REST (coach-facing) ----------------
	public static function register_routes(): void {
		register_rest_route( 'mpvk/v1', '/ai/draft', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_draft' ),
			'permission_callback' => fn() => current_user_can( 'mpvk_message_clients' ) || current_user_can( 'manage_options' ),
		) );
		register_rest_route( 'mpvk/v1', '/ai/draft/(?P<id>\d+)/dismiss', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'dismiss' ),
			'permission_callback' => fn() => current_user_can( 'mpvk_message_clients' ) || current_user_can( 'manage_options' ),
		) );
	}

	/** Latest ready draft for this thread — only if it answers the thread's newest message. */
	public static function get_draft( WP_REST_Request $req ): array|WP_Error {
		$actor  = get_current_user_id();
		$client = (int) $req->get_param( 'client_id' );
		if ( ! $client || ! MPVK_Tenancy::can_access_client( $actor, $client ) ) {
			return new WP_Error( 'mpvk_forbidden', 'You cannot access that.', array( 'status' => 403 ) );
		}
		$org = MPVK_Tenancy::org_id_of( $client );
		$key = MPVK_Tenancy::thread_key( $org, $client );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, message_id, draft_body, status FROM ' . MPVK_Schema::table( 'ai_drafts' ) .
			" WHERE thread_key = %s AND status = 'ready' ORDER BY id DESC LIMIT 1", $key
		) );
		if ( ! $row ) {
			return array( 'draft' => null );
		}
		// Stale? Someone (client or coach) has messaged since the draft's trigger message.
		$latest = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT MAX(id) FROM ' . MPVK_Schema::table( 'messages' ) . ' WHERE thread_key = %s', $key
		) );
		if ( $latest > (int) $row->message_id ) {
			return array( 'draft' => null );
		}
		return array( 'draft' => array( 'id' => (int) $row->id, 'body' => $row->draft_body ) );
	}

	public static function dismiss( WP_REST_Request $req ): array|WP_Error {
		global $wpdb;
		$table = MPVK_Schema::table( 'ai_drafts' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $req['id'] ) );
		if ( ! $row ) {
			return new WP_Error( 'mpvk_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		if ( ! MPVK_Tenancy::can_access_client( get_current_user_id(), (int) $row->client_user_id ) ) {
			return new WP_Error( 'mpvk_forbidden', 'You cannot access that.', array( 'status' => 403 ) );
		}
		$wpdb->update( $table, array( 'status' => 'dismissed', 'resolved_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $row->id ) );
		MPVK_Corpus::log( 'ai_draft_dismissed', array(
			'org_id' => (int) $row->org_id, 'actor_user_id' => get_current_user_id(), 'subject_user_id' => (int) $row->client_user_id,
			'object_type' => 'ai_draft', 'object_id' => (int) $row->id,
			'payload' => array( 'draft' => $row->draft_body ),
		) );
		return array( 'ok' => true );
	}

	/** Called from send_message when the coach sends with a draft_id — the training signal. */
	public static function mark_used( int $draft_id, string $final_body ): void {
		global $wpdb;
		$table = MPVK_Schema::table( 'ai_drafts' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $draft_id ) );
		if ( ! $row || ! MPVK_Tenancy::can_access_client( get_current_user_id(), (int) $row->client_user_id ) ) {
			return;
		}
		$wpdb->update( $table, array( 'status' => 'used', 'resolved_at' => current_time( 'mysql', true ) ), array( 'id' => $draft_id ) );
		MPVK_Corpus::log( 'ai_draft_used', array(
			'org_id' => (int) $row->org_id, 'actor_user_id' => get_current_user_id(), 'subject_user_id' => (int) $row->client_user_id,
			'object_type' => 'ai_draft', 'object_id' => $draft_id,
			'payload' => array(
				'draft'  => $row->draft_body,
				'final'  => $final_body,
				'edited' => trim( (string) $row->draft_body ) !== trim( $final_body ),
			),
		) );
	}
}
