<?php
defined( 'ABSPATH' ) || exit;

/**
 * Onboarding + history import (v0.8.0). Admin-only tooling to:
 *   1. Ensure a real org owned by the coach/admin, and provision real client accounts into it.
 *   2. Import external coaching history (Notion pages, meeting notes, transcripts) into the
 *      append-only corpus, tagged to the right athlete — the AI's memory of each relationship.
 *
 * Reusable for production later. Idempotent: re-running provisioning won't duplicate accounts,
 * and each imported document carries a stable key so re-imports are skipped.
 */
class MPVK_Onboard {

	const NS = 'mpvk/v1';
	const IMPORTED_KEYS_OPTION = 'mpvk_imported_doc_keys';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$admin = fn() => current_user_can( 'manage_options' );
		register_rest_route( self::NS, '/admin/onboard/clients', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'provision' ), 'permission_callback' => $admin,
		) );
		register_rest_route( self::NS, '/admin/onboard/import', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'import' ), 'permission_callback' => $admin,
		) );
		register_rest_route( self::NS, '/admin/onboard/status', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'status' ), 'permission_callback' => $admin,
		) );
	}

	/** Ensure the caller owns a real org (and is org-attached), creating one if needed. */
	public static function ensure_org( int $user_id ): int {
		$org = MPVK_Tenancy::org_id_of( $user_id );
		global $wpdb;
		$table = MPVK_Schema::table( 'orgs' );
		if ( $org && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE id = %d", $org ) ) ) {
			return $org;
		}
		$user = get_user_by( 'id', $user_id );
		$name = $user ? $user->display_name : 'MPVK';
		$slug = sanitize_title( 'mpvk-' . ( $user ? $user->user_login : $user_id ) );
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE slug = %s", $slug ) );
		if ( $existing ) {
			$org = $existing;
		} else {
			$wpdb->insert( $table, array(
				'name' => $name . ' — Coaching', 'slug' => $slug, 'owner_user_id' => $user_id,
				'status' => 'active', 'created_at' => current_time( 'mysql', true ),
			) );
			$org = (int) $wpdb->insert_id;
		}
		MPVK_Tenancy::set_org( $user_id, $org ); // the coach/admin is attached to their own org
		return $org;
	}

	/** Create (or reuse) client accounts under the caller's org. Idempotent. */
	public static function provision( WP_REST_Request $req ): array|WP_Error {
		$actor = get_current_user_id();
		$org   = self::ensure_org( $actor );
		$in    = $req->get_param( 'clients' );
		if ( ! is_array( $in ) || ! $in ) {
			return new WP_Error( 'mpvk_bad_request', 'Provide a clients array.', array( 'status' => 400 ) );
		}
		$out = array();
		foreach ( $in as $c ) {
			$name  = sanitize_text_field( (string) ( $c['name'] ?? '' ) );
			$email = sanitize_email( (string) ( $c['email'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			// Derive a stable login: email localpart, else a slug of the name.
			$base  = $email ? preg_replace( '/[^a-z0-9_.]/', '', strtolower( strstr( $email, '@', true ) ) ) : sanitize_title( $name );
			$base  = $base ?: ( 'client' . wp_generate_password( 5, false ) );
			if ( ! $email ) {
				$email = $base . '@mpvk.staging'; // placeholder — no mail is ever sent from here
			}
			$existing = get_user_by( 'email', $email );
			if ( ! $existing && username_exists( $base ) ) {
				$existing = get_user_by( 'login', $base );
			}
			if ( $existing ) {
				$uid = (int) $existing->ID;
				if ( ! in_array( 'mpvk_client', (array) $existing->roles, true ) ) {
					$existing->add_role( 'mpvk_client' );
				}
				$created = false;
			} else {
				$login = username_exists( $base ) ? $base . wp_generate_password( 3, false ) : $base;
				$uid   = wp_insert_user( array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 20 ),
					'user_email'   => $email,
					'display_name' => $name,
					'role'         => 'mpvk_client',
				) );
				if ( is_wp_error( $uid ) ) {
					$out[] = array( 'name' => $name, 'error' => $uid->get_error_message() );
					continue;
				}
				$created = true;
			}
			MPVK_Tenancy::set_org( (int) $uid, $org );
			if ( $created ) {
				MPVK_Corpus::log( 'client_provisioned', array(
					'org_id' => $org, 'actor_user_id' => $actor, 'subject_user_id' => (int) $uid,
					'object_type' => 'user', 'object_id' => (int) $uid, 'payload' => array( 'via' => 'onboard', 'name' => $name ),
				) );
			}
			$out[] = array( 'name' => $name, 'id' => (int) $uid, 'email' => $email, 'created' => $created );
		}
		return array( 'org_id' => $org, 'clients' => $out );
	}

	/**
	 * Import external history documents into the corpus for one client.
	 * Each doc: { key, title, text, date, source }. `key` (e.g. a Notion page id) makes
	 * re-imports idempotent. Long text is chunked so single corpus rows stay reasonable.
	 */
	public static function import( WP_REST_Request $req ): array|WP_Error {
		$actor  = get_current_user_id();
		$client = (int) $req->get_param( 'client_id' );
		if ( ! $client || ! MPVK_Tenancy::can_access_client( $actor, $client ) ) {
			return new WP_Error( 'mpvk_forbidden', 'Cannot import to that client.', array( 'status' => 403 ) );
		}
		$docs = $req->get_param( 'documents' );
		if ( ! is_array( $docs ) ) {
			return new WP_Error( 'mpvk_bad_request', 'documents array required.', array( 'status' => 400 ) );
		}
		$source = sanitize_text_field( (string) $req->get_param( 'source' ) ) ?: 'notion';
		$org    = MPVK_Tenancy::org_id_of( $client );
		$seen   = get_option( self::IMPORTED_KEYS_OPTION, array() );
		$seen   = is_array( $seen ) ? $seen : array();
		$imported = 0; $skipped = 0; $chunks = 0;
		foreach ( $docs as $d ) {
			$text = trim( (string) ( $d['text'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$title = sanitize_text_field( (string) ( $d['title'] ?? 'Untitled' ) );
			$date  = sanitize_text_field( (string) ( $d['date'] ?? '' ) );
			$key   = sanitize_text_field( (string) ( $d['key'] ?? md5( $source . $title . mb_substr( $text, 0, 200 ) ) ) );
			$dedupe = $client . ':' . $key;
			if ( in_array( $dedupe, $seen, true ) ) {
				$skipped++;
				continue;
			}
			// Chunk very long documents (~8k chars) so corpus rows + later embeddings stay sane.
			$parts = self::chunk( $text, 8000 );
			foreach ( $parts as $i => $part ) {
				MPVK_Corpus::log( 'history_imported', array(
					'org_id' => $org, 'actor_user_id' => $actor, 'subject_user_id' => $client,
					'object_type' => 'note', 'object_id' => 0,
					'payload' => array(
						'source' => $source, 'title' => $title, 'doc_key' => $key,
						'part'   => $i + 1, 'parts' => count( $parts ),
						'date'   => $date, 'text' => $part,
					),
				) );
				$chunks++;
			}
			$seen[] = $dedupe;
			$imported++;
		}
		update_option( self::IMPORTED_KEYS_OPTION, array_slice( $seen, -5000 ), false );
		MPVK_Corpus::log( 'history_import_batch', array(
			'org_id' => $org, 'actor_user_id' => $actor, 'subject_user_id' => $client,
			'object_type' => 'import', 'payload' => array( 'source' => $source, 'imported' => $imported, 'skipped' => $skipped, 'chunks' => $chunks ),
		) );
		return array( 'ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'chunks' => $chunks );
	}

	private static function chunk( string $text, int $size ): array {
		if ( mb_strlen( $text ) <= $size ) {
			return array( $text );
		}
		$out = array();
		$paras = preg_split( '/\n\s*\n/', $text );
		$buf = '';
		foreach ( $paras as $p ) {
			if ( mb_strlen( $buf ) + mb_strlen( $p ) + 2 > $size && '' !== $buf ) {
				$out[] = $buf; $buf = '';
			}
			// a single para longer than size → hard-split it
			if ( mb_strlen( $p ) > $size ) {
				foreach ( str_split( $p, $size ) as $piece ) {
					if ( '' !== $buf ) { $out[] = $buf; $buf = ''; }
					$out[] = $piece;
				}
				continue;
			}
			$buf = '' === $buf ? $p : $buf . "\n\n" . $p;
		}
		if ( '' !== $buf ) {
			$out[] = $buf;
		}
		return $out;
	}

	/** Verification snapshot for the coach/admin. */
	public static function status(): array {
		$actor = get_current_user_id();
		$org   = MPVK_Tenancy::org_id_of( $actor );
		global $wpdb;
		$clients = MPVK_Tenancy::org_clients( $org );
		$rows    = array();
		foreach ( $clients as $c ) {
			$docs = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(DISTINCT ' . "JSON_UNQUOTE(JSON_EXTRACT(payload,'$.doc_key'))" . ') FROM ' . MPVK_Schema::table( 'corpus_events' ) .
				" WHERE subject_user_id = %d AND event_type = 'history_imported'", (int) $c->ID
			) );
			$rows[] = array( 'id' => (int) $c->ID, 'name' => $c->display_name, 'imported_docs' => $docs );
		}
		return array( 'org_id' => $org, 'clients' => $rows );
	}
}
