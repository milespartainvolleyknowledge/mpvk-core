<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin dashboard (wp-admin, admin-only): platform status at a glance + dev tooling.
 * Lets Miles seed/reset demo data and watch the corpus fill without CLI access.
 */
class MPVK_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_mpvk_seed', array( __CLASS__, 'handle_seed' ) );
		add_action( 'admin_post_mpvk_export_corpus', array( __CLASS__, 'handle_export' ) );
	}

	public static function menu(): void {
		add_menu_page(
			'MPVK Platform',
			'MPVK Platform',
			'manage_options',
			'mpvk',
			array( __CLASS__, 'render' ),
			'dashicons-groups',
			3
		);
	}

	private static function count( string $table, string $where = '' ): int {
		global $wpdb;
		$t = MPVK_Schema::table( $table );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" . ( $where ? " WHERE $where" : '' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$stats = array(
			'Organizations'   => self::count( 'orgs' ),
			'Coaches'         => count( get_users( array( 'role' => 'mpvk_org_coach', 'fields' => 'ID' ) ) ),
			'Clients'         => count( get_users( array( 'role' => 'mpvk_client', 'fields' => 'ID' ) ) ),
			'Workouts'        => self::count( 'workouts' ),
			'Exercise logs'   => self::count( 'exercise_logs' ),
			'Messages'        => self::count( 'messages' ),
			'Corpus events'   => self::count( 'corpus_events' ),
			'Audit entries'   => self::count( 'audit_log' ),
		);
		$corpus_breakdown = $wpdb->get_results(
			'SELECT event_type, COUNT(*) n FROM ' . MPVK_Schema::table( 'corpus_events' ) . ' GROUP BY event_type ORDER BY n DESC',
			ARRAY_A
		);
		$portal = esc_url( home_url( '/portal' ) );
		?>
		<div class="wrap">
			<h1>MPVK Platform <span style="font-size:13px;color:#666;">v<?php echo esc_html( MPVK_VERSION ); ?> · schema v<?php echo (int) get_option( 'mpvk_schema_version' ); ?></span></h1>

			<?php if ( isset( $_GET['seeded'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success is-dismissible"><p>Demo data seeded. Test accounts below.</p></div>
			<?php endif; ?>

			<h2>Status</h2>
			<table class="widefat striped" style="max-width:520px">
				<tbody>
				<?php foreach ( $stats as $label => $n ) : ?>
					<tr><td><strong><?php echo esc_html( $label ); ?></strong></td><td><?php echo (int) $n; ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:1em">
				Portal (mobile-first): <a href="<?php echo $portal; ?>" target="_blank"><?php echo $portal; ?></a>
			</p>

			<h2 style="margin-top:2em">Corpus capture</h2>
			<?php if ( $corpus_breakdown ) : ?>
				<table class="widefat striped" style="max-width:520px"><tbody>
				<?php foreach ( $corpus_breakdown as $r ) : ?>
					<tr><td><code><?php echo esc_html( $r['event_type'] ); ?></code></td><td><?php echo (int) $r['n']; ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mpvk_export_corpus' ), 'mpvk_export' ) ); ?>">Download corpus (JSON)</a>
				</p>
			<?php else : ?>
				<p>No corpus events yet — they start recording as soon as workouts, logs, and messages happen.</p>
			<?php endif; ?>

			<h2 style="margin-top:2em">Demo / test data <span style="font-size:12px;color:#a00">(for staging)</span></h2>
			<?php if ( 'production' === ( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production' ) ) : ?>
				<div class="notice notice-warning inline"><p><strong>This looks like a production environment.</strong> Seeding creates demo accounts here. It's meant for staging — delete the demo accounts before real launch if you run it.</p></div>
			<?php endif; ?>
			<p>Creates 1 test org, a demo coach, and 4 placeholder clients (ava, ben, cody, dana) with a sample workout. Each account gets a strong random password shown once, right below, after you seed. Safe to re-run — it won't duplicate.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mpvk_seed' ); ?>
				<input type="hidden" name="action" value="mpvk_seed">
				<button class="button button-primary">Seed / refresh demo data</button>
			</form>

			<?php
			$creds = MPVK_Seed::last_credentials();
			if ( $creds ) :
				?>
				<h3 style="margin-top:1.5em">Test logins <span style="font-size:12px;color:#666">(shown once — copy them now)</span></h3>
				<table class="widefat striped" style="max-width:640px"><thead><tr><th>Role</th><th>Username</th><th>Password</th></tr></thead><tbody>
				<?php foreach ( $creds as $login => $pass ) : ?>
					<tr>
						<td><?php echo esc_html( 'coach_demo' === $login ? 'Coach (org)' : 'Client' ); ?></td>
						<td><code><?php echo esc_html( $login ); ?></code></td>
						<td><code><?php echo esc_html( $pass ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
				<p class="description">Open the portal link above in a private/incognito window to log in without logging out of admin. These passwords disappear from this page in an hour — you can always reset any of them from Users.</p>
			<?php elseif ( get_user_by( 'login', 'coach_demo' ) ) : ?>
				<p class="description" style="margin-top:1em">Demo accounts already exist (<code>coach_demo</code>, <code>client_ava…dana</code>). Passwords were shown once at creation — reset any of them from <a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">Users</a> if needed.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_seed(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'mpvk_seed' ) ) {
			wp_die( 'Not allowed.' );
		}
		MPVK_Seed::demo();
		wp_safe_redirect( admin_url( 'admin.php?page=mpvk&seeded=1' ) );
		exit;
	}

	public static function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'mpvk_export' ) ) {
			wp_die( 'Not allowed.' );
		}
		$data = MPVK_Corpus::export();
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mpvk-corpus-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}
}
