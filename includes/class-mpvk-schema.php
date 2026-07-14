<?php
defined( 'ABSPATH' ) || exit;

/**
 * Schema: built for the full vision from day one (change-cost guide: schema = expensive).
 * Every tenant-scoped table carries org_id. Corpus + audit are append-only by convention:
 * no UPDATE/DELETE paths exist in plugin code for those tables.
 */
class MPVK_Schema {

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'mpvk_' . $name;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$t = fn( $n ) => self::table( $n );

		$sql = array();

		// ---- Tenancy ----
		$sql[] = "CREATE TABLE {$t('orgs')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			slug VARCHAR(190) NOT NULL,
			owner_user_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			settings LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY owner_user_id (owner_user_id)
		) $charset;";

		// ---- Exercise library (deferred module; schema now) ----
		$sql[] = "CREATE TABLE {$t('exercise_library')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			org_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			cues TEXT NULL,
			video_url VARCHAR(500) NULL,
			tags VARCHAR(500) NULL,
			created_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY org_id (org_id),
			KEY org_name (org_id, name)
		) $charset;";

		// ---- Templates (master programs) ----
		$sql[] = "CREATE TABLE {$t('templates')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			org_id BIGINT UNSIGNED NOT NULL,
			title VARCHAR(190) NOT NULL,
			description TEXT NULL,
			length_days INT UNSIGNED NOT NULL DEFAULT 7,
			created_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY org_id (org_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$t('template_days')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			template_id BIGINT UNSIGNED NOT NULL,
			org_id BIGINT UNSIGNED NOT NULL,
			day_index INT UNSIGNED NOT NULL,
			title VARCHAR(190) NULL,
			notes TEXT NULL,
			PRIMARY KEY  (id),
			KEY template_id (template_id),
			KEY org_id (org_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$t('template_day_exercises')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			template_day_id BIGINT UNSIGNED NOT NULL,
			org_id BIGINT UNSIGNED NOT NULL,
			library_id BIGINT UNSIGNED NULL,
			exercise_name VARCHAR(190) NOT NULL,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			prescribed_sets VARCHAR(40) NULL,
			prescribed_reps VARCHAR(40) NULL,
			prescribed_load VARCHAR(60) NULL,
			prescribed_tempo VARCHAR(40) NULL,
			prescribed_rest VARCHAR(40) NULL,
			cues TEXT NULL,
			PRIMARY KEY  (id),
			KEY template_day_id (template_day_id),
			KEY org_id (org_id)
		) $charset;";

		// subscribe = linked to master (edits propagate future-days-only); copy = independent instance
		$sql[] = "CREATE TABLE {$t('template_subscriptions')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			org_id BIGINT UNSIGNED NOT NULL,
			client_user_id BIGINT UNSIGNED NOT NULL,
			template_id BIGINT UNSIGNED NOT NULL,
			start_date DATE NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY org_client (org_id, client_user_id),
			KEY template_id (template_id)
		) $charset;";

		// ---- Calendar / workouts ----
		$sql[] = "CREATE TABLE {$t('workouts')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			org_id BIGINT UNSIGNED NOT NULL,
			client_user_id BIGINT UNSIGNED NOT NULL,
			workout_date DATE NOT NULL,
			title VARCHAR(190) NOT NULL,
			notes TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'planned',
			template_id BIGINT UNSIGNED NULL,
			subscription_id BIGINT UNSIGNED NULL,
			sort INT UNSIGNED NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY org_client_date (org_id, client_user_id, workout_date),
			KEY subscription_id (subscription_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$t('workout_exercises')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			workout_id BIGINT UNSIGNED NOT NULL,
			org_id BIGINT UNSIGNED NOT NULL,
			library_id BIGINT UNSIGNED NULL,
			exercise_name VARCHAR(190) NOT NULL,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			prescribed_sets VARCHAR(40) NULL,
			prescribed_reps VARCHAR(40) NULL,
			prescribed_load VARCHAR(60) NULL,
			prescribed_tempo VARCHAR(40) NULL,
			prescribed_rest VARCHAR(40) NULL,
			cues TEXT NULL,
			completed_at DATETIME NULL,
			completed_by BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY workout_id (workout_id),
			KEY org_id (org_id)
		) $charset;";

		// logged actuals: one row per set — RETAINED for the future (Brent) richer-tracking
		// mode; the MVP UI uses the simple completed_at check-off above, not this table.
		$sql[] = "CREATE TABLE {$t('exercise_logs')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			workout_exercise_id BIGINT UNSIGNED NOT NULL,
			workout_id BIGINT UNSIGNED NOT NULL,
			org_id BIGINT UNSIGNED NOT NULL,
			client_user_id BIGINT UNSIGNED NOT NULL,
			set_number INT UNSIGNED NOT NULL DEFAULT 1,
			actual_reps VARCHAR(40) NULL,
			actual_load VARCHAR(60) NULL,
			rpe DECIMAL(3,1) NULL,
			comment TEXT NULL,
			logged_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY workout_exercise_id (workout_exercise_id),
			KEY org_client (org_id, client_user_id),
			KEY workout_id (workout_id)
		) $charset;";

		// ---- Messaging ----
		// v2 adds reply_to_id + attachment fields (iMessage-style). body may be empty when
		// a message is a pure attachment. attachment_type: 'image' | 'video'.
		$sql[] = "CREATE TABLE {$t('messages')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			org_id BIGINT UNSIGNED NOT NULL,
			thread_key VARCHAR(80) NOT NULL,
			sender_user_id BIGINT UNSIGNED NOT NULL,
			recipient_user_id BIGINT UNSIGNED NOT NULL,
			body LONGTEXT NOT NULL,
			reply_to_id BIGINT UNSIGNED NULL,
			attachment_id BIGINT UNSIGNED NULL,
			attachment_url VARCHAR(500) NULL,
			attachment_type VARCHAR(20) NULL,
			read_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY thread (org_id, thread_key, id),
			KEY recipient_unread (recipient_user_id, read_at)
		) $charset;";

		// Emoji reactions on messages (one row per user+message+emoji).
		$sql[] = "CREATE TABLE {$t('message_reactions')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			message_id BIGINT UNSIGNED NOT NULL,
			org_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			emoji VARCHAR(16) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY one_per (message_id, user_id, emoji),
			KEY message_id (message_id)
		) $charset;";

		// ---- Corpus (append-only; THE irreplaceable table) ----
		$sql[] = "CREATE TABLE {$t('corpus_events')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			org_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			event_type VARCHAR(60) NOT NULL,
			actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			subject_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			object_type VARCHAR(40) NULL,
			object_id BIGINT UNSIGNED NULL,
			payload LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY org_type_time (org_id, event_type, created_at),
			KEY subject (subject_user_id, created_at)
		) $charset;";

		// ---- Web push subscriptions ----
		$sql[] = "CREATE TABLE {$t('push_subscriptions')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			org_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			endpoint VARCHAR(500) NOT NULL,
			p256dh VARCHAR(200) NOT NULL,
			auth VARCHAR(100) NOT NULL,
			endpoint_hash CHAR(64) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY endpoint_hash (endpoint_hash),
			KEY user_id (user_id)
		) $charset;";

		// ---- Audit log (security events; append-only) ----
		$sql[] = "CREATE TABLE {$t('audit_log')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			org_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(60) NOT NULL,
			object_type VARCHAR(40) NULL,
			object_id BIGINT UNSIGNED NULL,
			ip VARCHAR(64) NULL,
			user_agent VARCHAR(255) NULL,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_time (user_id, created_at),
			KEY action_time (action, created_at)
		) $charset;";

		foreach ( $sql as $q ) {
			dbDelta( $q );
		}
		update_option( 'mpvk_schema_version', MPVK_SCHEMA_VERSION );
	}

	public static function maybe_upgrade(): void {
		if ( (int) get_option( 'mpvk_schema_version', 0 ) < MPVK_SCHEMA_VERSION ) {
			self::install();
		}
	}
}
