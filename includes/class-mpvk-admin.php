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
		add_action( 'admin_post_mpvk_push_toggle', array( __CLASS__, 'handle_push_toggle' ) );
		add_action( 'admin_post_mpvk_push_test', array( __CLASS__, 'handle_push_test' ) );
		add_action( 'admin_post_mpvk_passkey_toggle', array( __CLASS__, 'handle_passkey_toggle' ) );
		add_action( 'admin_post_mpvk_ai_save', array( __CLASS__, 'handle_ai_save' ) );
		add_action( 'admin_post_mpvk_ai_test', array( __CLASS__, 'handle_ai_test' ) );
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

			<h2 style="margin-top:2em">Push notifications <span style="font-size:12px;color:#666">(experimental)</span></h2>
			<?php
			$push_on  = (bool) get_option( 'mpvk_push_enabled', false );
			global $wpdb;
			$sub_count = self::count( 'push_subscriptions' );
			$mine      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MPVK_Schema::table( 'push_subscriptions' ) . ' WHERE user_id = %d', get_current_user_id() ) );
			?>
			<p>Status: <strong style="color:<?php echo $push_on ? '#2C5F63' : '#a00'; ?>"><?php echo $push_on ? 'ON' : 'OFF'; ?></strong>
			 · <?php echo (int) $sub_count; ?> device(s) subscribed (<?php echo (int) $mine; ?> yours).</p>
			<p class="description" style="max-width:640px">Web-push encryption is validated (round-trips + VAPID JWT verifies), but real delivery should be confirmed on a device first. Steps: (1) open the portal on your phone and tap the 🔕 bell to allow notifications, (2) come back and click "Send test to me," (3) if it arrives, turn push ON so new messages notify.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'mpvk_push' ); ?>
				<input type="hidden" name="action" value="mpvk_push_test">
				<button class="button"<?php echo $mine ? '' : ' disabled title="Subscribe on your phone first"'; ?>>Send test to me</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:.5rem">
				<?php wp_nonce_field( 'mpvk_push' ); ?>
				<input type="hidden" name="action" value="mpvk_push_toggle">
				<button class="button <?php echo $push_on ? '' : 'button-primary'; ?>"><?php echo $push_on ? 'Turn push OFF' : 'Turn push ON'; ?></button>
			</form>
			<?php if ( isset( $_GET['pushtest'] ) ) : // phpcs:ignore ?>
				<span style="margin-left:.6rem"><?php echo 'sent' === $_GET['pushtest'] ? '✅ test sent — check your phone' : '⚠️ send failed (see below)'; // phpcs:ignore ?></span>
			<?php endif; ?>

			<h2 style="margin-top:2em">Passkeys — Face ID / fingerprint login</h2>
			<?php
			$pk_on    = MPVK_WebAuthn::enabled();
			$pk_count = self::count( 'passkeys' );
			?>
			<p>Status: <strong style="color:<?php echo $pk_on ? '#2C5F63' : '#a00'; ?>"><?php echo $pk_on ? 'ON' : 'OFF'; ?></strong>
			 · <?php echo (int) $pk_count; ?> passkey(s) registered.</p>
			<p class="description" style="max-width:640px">When ON: a "Sign in with Face ID / passkey" button appears on the login page, and the portal offers everyone a one-tap "Set up Face ID login" card. Passwords keep working exactly as before — passkeys are purely additive, so nobody can get locked out. Passkey-verified devices stay logged in 30 days (vs 3 for password logins on client accounts).</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'mpvk_passkey' ); ?>
				<input type="hidden" name="action" value="mpvk_passkey_toggle">
				<button class="button <?php echo $pk_on ? '' : 'button-primary'; ?>"><?php echo $pk_on ? 'Turn passkeys OFF' : 'Turn passkeys ON'; ?></button>
			</form>

			<h2 style="margin-top:2em">AI draft replies <span style="font-size:12px;color:#666">(draft-only — never auto-sends)</span></h2>
			<?php
			$ai_key   = (string) get_option( 'mpvk_ai_key', '' );
			$ai_on    = (bool) get_option( 'mpvk_ai_enabled', false );
			$ai_model = (string) get_option( 'mpvk_ai_model', MPVK_AI::DEFAULT_MODEL );
			$drafts   = self::count( 'ai_drafts' );
			$used     = self::count( 'ai_drafts', "status = 'used'" );
			?>
			<p>Status: <strong style="color:<?php echo MPVK_AI::enabled() ? '#2C5F63' : '#a00'; ?>"><?php echo MPVK_AI::enabled() ? 'ON' : 'OFF'; ?></strong>
			 · <?php echo (int) $drafts; ?> draft(s) generated, <?php echo (int) $used; ?> used.</p>
			<p class="description" style="max-width:640px">When a client messages you, Claude drafts a suggested reply in your voice from the conversation + their training calendar. It appears above your composer in Messages: tap <em>Use</em> (then edit freely) or <em>Dismiss</em>. Your edits are captured as training signal. Needs your Anthropic API key from <a href="https://console.anthropic.com/settings/keys" target="_blank">console.anthropic.com</a>.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:520px">
				<?php wp_nonce_field( 'mpvk_ai' ); ?>
				<input type="hidden" name="action" value="mpvk_ai_save">
				<p>
					<label>Anthropic API key<br>
					<input type="password" name="mpvk_ai_key" style="width:100%" placeholder="<?php echo $ai_key ? esc_attr( 'saved — ends in ' . substr( $ai_key, -4 ) . ' (paste to replace)' ) : 'sk-ant-…'; ?>" autocomplete="off"></label>
				</p>
				<p>
					<label>Model<br>
					<input type="text" name="mpvk_ai_model" style="width:100%" value="<?php echo esc_attr( $ai_model ); ?>"></label>
				</p>
				<p>
					<label><input type="checkbox" name="mpvk_ai_enabled" value="1" <?php checked( $ai_on ); ?>> Enable AI draft replies</label>
				</p>
				<button class="button button-primary">Save AI settings</button>
			</form>
			<?php if ( $ai_key ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:.5rem">
				<?php wp_nonce_field( 'mpvk_ai' ); ?>
				<input type="hidden" name="action" value="mpvk_ai_test">
				<button class="button">Test API key</button>
				<?php if ( isset( $_GET['aitest'] ) ) : // phpcs:ignore ?>
					<span style="margin-left:.6rem"><?php echo 'ok' === $_GET['aitest'] ? '✅ key works — model responded' : '⚠️ ' . esc_html( (string) get_transient( 'mpvk_ai_test_err' ) ); // phpcs:ignore ?></span>
				<?php endif; ?>
			</form>
			<?php endif; ?>

			<h2 style="margin-top:2em">Demo / test data <span style="font-size:12px;color:#a00">(for staging)</span></h2>
			<?php if ( 'production' === ( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production' ) ) : ?>
				<div class="notice notice-warning inline"><p><strong>This looks like a production environment.</strong> Seeding creates demo accounts here. It's meant for staging — delete the demo accounts before real launch if you run it.</p></div>
			<?php endif; ?>
			<p>Creates 1 test org, a demo coach, and 4 placeholder clients (ava, ben, cody, dana) with a sample workout. New accounts get a strong random password shown once, right below. Safe to re-run any time — it won't duplicate, and <strong>re-seeding never changes the password of an account that already exists</strong> (so a login you're using stays valid; reset from Users if you forget one).</p>
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

	public static function handle_push_toggle(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'mpvk_push' ) ) {
			wp_die( 'Not allowed.' );
		}
		update_option( 'mpvk_push_enabled', ! get_option( 'mpvk_push_enabled', false ) );
		wp_safe_redirect( admin_url( 'admin.php?page=mpvk' ) );
		exit;
	}

	public static function handle_push_test(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'mpvk_push' ) ) {
			wp_die( 'Not allowed.' );
		}
		// Force-send to self regardless of the global enable flag (this is the validation path).
		global $wpdb;
		$subs = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . MPVK_Schema::table( 'push_subscriptions' ) . ' WHERE user_id = %d', get_current_user_id()
		) );
		$ok = false;
		foreach ( $subs as $s ) {
			$ok = MPVK_Push::send_one( $s, array( 'title' => 'VolleyKnowledge', 'body' => 'Test notification — it works! 🎉', 'url' => home_url( '/portal' ), 'tag' => 'mpvk-test' ) ) || $ok;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mpvk&pushtest=' . ( $ok ? 'sent' : 'fail' ) ) );
		exit;
	}

	public static function handle_passkey_toggle(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'mpvk_passkey' ) ) {
			wp_die( 'Not allowed.' );
		}
		update_option( 'mpvk_passkeys_enabled', ! get_option( 'mpvk_passkeys_enabled', false ) );
		wp_safe_redirect( admin_url( 'admin.php?page=mpvk' ) );
		exit;
	}

	public static function handle_ai_save(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'mpvk_ai' ) ) {
			wp_die( 'Not allowed.' );
		}
		$key = isset( $_POST['mpvk_ai_key'] ) ? trim( (string) wp_unslash( $_POST['mpvk_ai_key'] ) ) : ''; // phpcs:ignore
		if ( '' !== $key ) { // empty = keep existing
			update_option( 'mpvk_ai_key', $key, false ); // autoload off — secret stays out of the alloptions blob
		}
		$model = isset( $_POST['mpvk_ai_model'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['mpvk_ai_model'] ) ) : ''; // phpcs:ignore
		update_option( 'mpvk_ai_model', $model ?: MPVK_AI::DEFAULT_MODEL );
		update_option( 'mpvk_ai_enabled', ! empty( $_POST['mpvk_ai_enabled'] ) ); // phpcs:ignore
		wp_safe_redirect( admin_url( 'admin.php?page=mpvk' ) );
		exit;
	}

	public static function handle_ai_test(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'mpvk_ai' ) ) {
			wp_die( 'Not allowed.' );
		}
		$resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 20,
			'headers' => array(
				'content-type'      => 'application/json',
				'x-api-key'         => (string) get_option( 'mpvk_ai_key', '' ),
				'anthropic-version' => '2023-06-01',
			),
			'body'    => wp_json_encode( array(
				'model'      => MPVK_AI::model(),
				'max_tokens' => 20,
				'messages'   => array( array( 'role' => 'user', 'content' => 'Reply with the single word: ok' ) ),
			) ),
		) );
		if ( is_wp_error( $resp ) ) {
			set_transient( 'mpvk_ai_test_err', $resp->get_error_message(), 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=mpvk&aitest=fail' ) );
			exit;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		if ( $code >= 200 && $code < 300 ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mpvk&aitest=ok' ) );
			exit;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		$err  = isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'HTTP ' . $code );
		set_transient( 'mpvk_ai_test_err', mb_substr( (string) $err, 0, 200 ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=mpvk&aitest=fail' ) );
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
