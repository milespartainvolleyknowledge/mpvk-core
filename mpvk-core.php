<?php
/**
 * Plugin Name: MPVK Core
 * Description: MPVK platform core — tiers & tenancy (Admin → Org → Client), training calendar, in-portal messaging, corpus capture. MVP skeleton.
 * Version: 0.7.0
 * Author: Miles Partain VolleyKnowledge
 * Requires at least: 6.4
 * Requires PHP: 8.1
 */

defined( 'ABSPATH' ) || exit;

define( 'MPVK_VERSION', '0.7.0' );
define( 'MPVK_SCHEMA_VERSION', 7 );
define( 'MPVK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MPVK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-schema.php';
require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-roles.php';
require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-tenancy.php';
require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-corpus.php';
require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-audit.php';
require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-security.php';
require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-rest.php';
require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-portal.php';
require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-seed.php';
require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-admin.php';
require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-pwa.php';
require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-totp.php';
require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-2fa.php';
require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-push.php';
require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-webauthn.php';
require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-ai.php';
require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-library.php';
require_once MPVK_PLUGIN_DIR . 'includes/class-mpvk-programs.php';

register_activation_hook( __FILE__, function () {
	MPVK_Schema::install();
	MPVK_Roles::install();
	MPVK_Push::ensure_vapid();
	MPVK_Portal::add_rewrites();
	flush_rewrite_rules();
	update_option( 'mpvk_version', MPVK_VERSION );
} );

register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
	// NOTE: roles/tables intentionally NOT removed on deactivation — corpus data is sacred.
} );

add_action( 'plugins_loaded', function () {
	MPVK_Schema::maybe_upgrade();
	MPVK_Push::ensure_vapid(); // idempotent; guarantees the push key exists even after an update (activation hook doesn't re-run on update)
	MPVK_Corpus::init();
	MPVK_Audit::init();
	MPVK_Security::init();
	MPVK_Portal::init();
	MPVK_PWA::init();
	MPVK_2FA::init();
	MPVK_WebAuthn::init();
	MPVK_AI::init();
	MPVK_Library::init();
	MPVK_Programs::init();
	if ( is_admin() ) {
		MPVK_Admin::init();
	}
} );

add_action( 'rest_api_init', array( 'MPVK_REST', 'register_routes' ) );

/**
 * Self-update from the public GitHub repo. When a new GitHub Release is published,
 * the site shows a normal plugin update (one click) — no zip juggling.
 * Public repo → no token needed on the site side.
 */
add_action( 'init', function () {
	if ( ! is_admin() && ! ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	$loader = MPVK_PLUGIN_DIR . 'lib/puc/plugin-update-checker.php';
	if ( ! file_exists( $loader ) ) {
		return;
	}
	require_once $loader;
	if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		return;
	}
	$uc = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/milespartainvolleyknowledge/mpvk-core/',
		__FILE__,
		'mpvk-core'
	);
	// Update off version TAGS (e.g. v0.2.2). Deploys from the sandbox push tags via git;
	// the site reads the latest tag from the public repo and offers a normal plugin update.
	// (GitHub Releases API isn't reachable from the build sandbox, so we use tags, not releases.)
} );
