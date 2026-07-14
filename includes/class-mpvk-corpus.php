<?php
defined( 'ABSPATH' ) || exit;

/**
 * Corpus capture — append-only, on from day one. Data never captured is irreversibly lost.
 * Everything meaningful funnels through MPVK_Corpus::record(). Other code fires the
 * 'mpvk_event' action; this class is the single writer.
 */
class MPVK_Corpus {

	public static function init(): void {
		add_action( 'mpvk_event', array( __CLASS__, 'record' ), 10, 1 );
	}

	/**
	 * @param array $e {event_type, org_id, actor_user_id, subject_user_id, object_type, object_id, payload(array)}
	 */
	public static function record( array $e ): int {
		global $wpdb;
		$row = array(
			'org_id'          => (int) ( $e['org_id'] ?? 0 ),
			'event_type'      => substr( (string) ( $e['event_type'] ?? 'unknown' ), 0, 60 ),
			'actor_user_id'   => (int) ( $e['actor_user_id'] ?? get_current_user_id() ),
			'subject_user_id' => (int) ( $e['subject_user_id'] ?? 0 ),
			'object_type'     => isset( $e['object_type'] ) ? substr( (string) $e['object_type'], 0, 40 ) : null,
			'object_id'       => isset( $e['object_id'] ) ? (int) $e['object_id'] : null,
			'payload'         => isset( $e['payload'] ) ? wp_json_encode( $e['payload'] ) : null,
			'created_at'      => current_time( 'mysql', true ),
		);
		$wpdb->insert( MPVK_Schema::table( 'corpus_events' ), $row );
		return (int) $wpdb->insert_id;
	}

	/** Convenience wrapper used across the plugin. */
	public static function log( string $type, array $data = array() ): int {
		$data['event_type'] = $type;
		return self::record( $data );
	}

	/** Export slice (admin-only usage; structured + portable from day one). */
	public static function export( int $org_id = 0, string $since = '', int $limit = 5000 ): array {
		global $wpdb;
		$where  = 'WHERE 1=1';
		$params = array();
		if ( $org_id ) {
			$where   .= ' AND org_id = %d';
			$params[] = $org_id;
		}
		if ( $since ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $since;
		}
		$sql = 'SELECT * FROM ' . MPVK_Schema::table( 'corpus_events' ) . " $where ORDER BY id ASC LIMIT %d";
		$params[] = $limit;
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}
}
