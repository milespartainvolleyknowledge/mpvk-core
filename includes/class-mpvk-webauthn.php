<?php
defined( 'ABSPATH' ) || exit;

/**
 * Passkeys (WebAuthn): Face ID / Touch ID / Android fingerprint login.
 *
 * FLAG-GATED: everything here is inert unless option `mpvk_passkeys_enabled` is on
 * (MPVK admin page toggle). Built dependency-free: minimal CBOR decoding + openssl
 * signature verification, per the W3C WebAuthn Level 2 verification procedure.
 *
 * Supported algorithms: ES256 (-7, all Apple/Android platform authenticators)
 * and RS256 (-257, some Windows Hello devices).
 *
 * SAFETY: passkeys are ADDITIVE — password login keeps working for everyone.
 * A verification bug can therefore never lock anyone out.
 */
class MPVK_WebAuthn {

	const NS = 'mpvk/v1';

	public static function enabled(): bool {
		return (bool) get_option( 'mpvk_passkeys_enabled', false );
	}

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_assets' ) );
		add_action( 'login_form', array( __CLASS__, 'login_button' ) );
	}

	// ---------------- base64url ----------------
	public static function b64u_encode( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}
	public static function b64u_decode( string $s ): string {
		$d = base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
		return false === $d ? '' : $d;
	}

	// ---------------- minimal CBOR (definite-length; all WebAuthn payloads are) ----------------
	const CBOR_MAX_DEPTH = 16; // authData/COSE structures are ≤4 deep; bomb protection

	/** @return array{0:mixed,1:int} [value, bytes consumed] */
	public static function cbor_decode( string $bin, int $off = 0, int $depth = 0 ): array {
		if ( $depth > self::CBOR_MAX_DEPTH ) {
			throw new Exception( 'cbor: nesting too deep' );
		}
		if ( $off >= strlen( $bin ) ) {
			throw new Exception( 'cbor: truncated' );
		}
		$ib    = ord( $bin[ $off ] );
		$major = $ib >> 5;
		$info  = $ib & 0x1f;
		$p     = $off + 1;

		// argument (length / value) — validate the buffer actually holds the bytes
		$need = array( 24 => 1, 25 => 2, 26 => 4, 27 => 8 );
		if ( $info < 24 ) {
			$arg = $info;
		} elseif ( isset( $need[ $info ] ) ) {
			$raw = substr( $bin, $p, $need[ $info ] );
			if ( strlen( $raw ) !== $need[ $info ] ) {
				throw new Exception( 'cbor: truncated argument' );
			}
			$arg = 1 === $need[ $info ] ? ord( $raw ) : unpack( array( 2 => 'n', 4 => 'N', 8 => 'J' )[ $need[ $info ] ], $raw )[1];
			$p  += $need[ $info ];
		} else {
			throw new Exception( 'cbor: indefinite length unsupported' );
		}

		switch ( $major ) {
			case 0: // unsigned int
				return array( $arg, $p - $off );
			case 1: // negative int
				return array( -1 - $arg, $p - $off );
			case 2: // byte string
			case 3: // text string
				if ( $arg > strlen( $bin ) - $p ) {
					throw new Exception( 'cbor: string exceeds buffer' );
				}
				$v = substr( $bin, $p, $arg );
				return array( $v, $p - $off + $arg );
			case 4: // array
				if ( $arg > strlen( $bin ) - $p ) { // each element needs ≥1 byte
					throw new Exception( 'cbor: array exceeds buffer' );
				}
				$out = array();
				for ( $i = 0; $i < $arg; $i++ ) {
					[ $v, $n ] = self::cbor_decode( $bin, $p, $depth + 1 );
					$out[]     = $v;
					$p        += $n;
				}
				return array( $out, $p - $off );
			case 5: // map
				if ( $arg > ( strlen( $bin ) - $p ) / 2 ) { // each pair needs ≥2 bytes
					throw new Exception( 'cbor: map exceeds buffer' );
				}
				$out = array();
				for ( $i = 0; $i < $arg; $i++ ) {
					[ $k, $n ] = self::cbor_decode( $bin, $p, $depth + 1 );
					$p        += $n;
					[ $v, $n ] = self::cbor_decode( $bin, $p, $depth + 1 );
					$p        += $n;
					$out[ is_int( $k ) || is_string( $k ) ? $k : (string) $k ] = $v;
				}
				return array( $out, $p - $off );
			case 6: // tag — skip, return tagged value
				[ $v, $n ] = self::cbor_decode( $bin, $p, $depth + 1 );
				return array( $v, $p - $off + $n );
			default: // 7: simple/float
				if ( 20 === $info ) { return array( false, $p - $off ); }
				if ( 21 === $info ) { return array( true, $p - $off ); }
				if ( 22 === $info ) { return array( null, $p - $off ); }
				throw new Exception( 'cbor: unsupported simple/float' );
		}
	}

	// ---------------- COSE key → PEM ----------------
	/** @return array{pem:string, alg:int}|null */
	public static function cose_to_pem( array $cose ): ?array {
		$kty = $cose[1] ?? null;
		$alg = (int) ( $cose[3] ?? 0 );
		if ( 2 === $kty && -7 === $alg ) { // EC2 / ES256 / P-256
			$crv = $cose[-1] ?? null;
			$x   = $cose[-2] ?? '';
			$y   = $cose[-3] ?? '';
			if ( 1 !== $crv || 32 !== strlen( $x ) || 32 !== strlen( $y ) ) {
				return null;
			}
			$point = "\x04" . $x . $y;
			$der   = hex2bin( MPVK_Push::P256_SPKI_PREFIX ) . $point;
			$pem   = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
			return array( 'pem' => $pem, 'alg' => -7 );
		}
		if ( 3 === $kty && -257 === $alg ) { // RSA / RS256
			$n = $cose[-1] ?? '';
			$e = $cose[-2] ?? '';
			if ( '' === $n || '' === $e ) {
				return null;
			}
			$rsa = self::der_seq( self::der_int( $n ) . self::der_int( $e ) );
			$spki = self::der_seq(
				self::der_seq( hex2bin( '06092a864886f70d010101' ) . hex2bin( '0500' ) ) // rsaEncryption OID + NULL
				. self::der_bits( $rsa )
			);
			$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
			return array( 'pem' => $pem, 'alg' => -257 );
		}
		return null;
	}
	private static function der_len( int $len ): string {
		if ( $len < 0x80 ) { return chr( $len ); }
		$b = ltrim( pack( 'N', $len ), "\x00" );
		return chr( 0x80 | strlen( $b ) ) . $b;
	}
	private static function der_int( string $bin ): string {
		if ( '' !== $bin && ( ord( $bin[0] ) & 0x80 ) ) { $bin = "\x00" . $bin; }
		return "\x02" . self::der_len( strlen( $bin ) ) . $bin;
	}
	private static function der_seq( string $body ): string {
		return "\x30" . self::der_len( strlen( $body ) ) . $body;
	}
	private static function der_bits( string $body ): string {
		return "\x03" . self::der_len( strlen( $body ) + 1 ) . "\x00" . $body;
	}

	// ---------------- relying party identity ----------------
	public static function rp_id(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}
	public static function origin(): string {
		$p = wp_parse_url( home_url() );
		return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
	}

	// ---------------- routes ----------------
	public static function register_routes(): void {
		register_rest_route( self::NS, '/passkey/register/options', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'register_options' ),
			'permission_callback' => fn() => self::enabled() && is_user_logged_in(),
		) );
		register_rest_route( self::NS, '/passkey/register', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'register_verify' ),
			'permission_callback' => fn() => self::enabled() && is_user_logged_in(),
		) );
		// Login routes are PUBLIC by nature (user isn't logged in yet) — rate-limited below.
		register_rest_route( self::NS, '/passkey/login/options', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'login_options' ),
			'permission_callback' => fn() => self::enabled(),
		) );
		register_rest_route( self::NS, '/passkey/login', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'login_verify' ),
			'permission_callback' => fn() => self::enabled(),
		) );
		// List/remove own passkeys (portal settings).
		register_rest_route( self::NS, '/passkey/mine', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'mine' ),
			'permission_callback' => fn() => self::enabled() && is_user_logged_in(),
		) );
		register_rest_route( self::NS, '/passkey/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'remove' ),
			'permission_callback' => fn() => self::enabled() && is_user_logged_in(),
		) );
	}

	private static function rate_limited( string $bucket, int $max, int $window ): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'noip';
		$key = 'mpvk_rl_' . $bucket . '_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return true;
		}
		set_transient( $key, $n + 1, $window );
		return false;
	}

	// ---- registration (logged-in user adds a passkey) ----
	public static function register_options(): array {
		$user      = wp_get_current_user();
		$challenge = random_bytes( 32 );
		set_transient( 'mpvk_wan_reg_' . $user->ID, self::b64u_encode( $challenge ), 5 * MINUTE_IN_SECONDS );

		global $wpdb;
		$exclude = $wpdb->get_col( $wpdb->prepare(
			'SELECT cred_id FROM ' . MPVK_Schema::table( 'passkeys' ) . ' WHERE user_id = %d', $user->ID
		) );

		return array(
			'publicKey' => array(
				'challenge'              => self::b64u_encode( $challenge ),
				'rp'                     => array( 'id' => self::rp_id(), 'name' => get_bloginfo( 'name' ) ),
				'user'                   => array(
					'id'          => self::b64u_encode( 'uid:' . $user->ID ),
					'name'        => $user->user_login,
					'displayName' => $user->display_name,
				),
				'pubKeyCredParams'       => array(
					array( 'type' => 'public-key', 'alg' => -7 ),
					array( 'type' => 'public-key', 'alg' => -257 ),
				),
				'timeout'                => 60000,
				'attestation'            => 'none',
				'authenticatorSelection' => array(
					'authenticatorAttachment' => 'platform',
					'residentKey'             => 'required',
					'requireResidentKey'      => true,
					'userVerification'        => 'required',
				),
				'excludeCredentials'     => array_map(
					fn( $id ) => array( 'type' => 'public-key', 'id' => $id ),
					$exclude
				),
			),
		);
	}

	public static function register_verify( WP_REST_Request $req ): array|WP_Error {
		if ( self::rate_limited( 'wan_reg', 15, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'mpvk_rate', 'Too many attempts — wait a few minutes.', array( 'status' => 429 ) );
		}
		$uid  = get_current_user_id();
		$cred = $req->get_param( 'credential' );
		if ( ! is_array( $cred ) ) {
			return new WP_Error( 'mpvk_bad_request', 'Missing credential.', array( 'status' => 400 ) );
		}
		$client_json = self::b64u_decode( (string) ( $cred['response']['clientDataJSON'] ?? '' ) );
		$att_obj     = self::b64u_decode( (string) ( $cred['response']['attestationObject'] ?? '' ) );
		$raw_id      = (string) ( $cred['rawId'] ?? '' );
		if ( '' === $client_json || '' === $att_obj || '' === $raw_id ) {
			return new WP_Error( 'mpvk_bad_request', 'Malformed credential.', array( 'status' => 400 ) );
		}

		// 1. clientData: type/challenge/origin
		$cd       = json_decode( $client_json, true );
		$expected = get_transient( 'mpvk_wan_reg_' . $uid );
		delete_transient( 'mpvk_wan_reg_' . $uid ); // single-use
		if ( ! is_array( $cd ) || 'webauthn.create' !== ( $cd['type'] ?? '' ) ) {
			return new WP_Error( 'mpvk_webauthn', 'Bad clientData type.', array( 'status' => 400 ) );
		}
		if ( ! $expected || ! hash_equals( $expected, (string) ( $cd['challenge'] ?? '' ) ) ) {
			return new WP_Error( 'mpvk_webauthn', 'Challenge mismatch (try again).', array( 'status' => 400 ) );
		}
		if ( ( $cd['origin'] ?? '' ) !== self::origin() ) {
			return new WP_Error( 'mpvk_webauthn', 'Origin mismatch.', array( 'status' => 400 ) );
		}

		// 2. attestationObject → authData
		try {
			[ $att ] = self::cbor_decode( $att_obj );
		} catch ( Exception $e ) {
			return new WP_Error( 'mpvk_webauthn', 'Bad attestation encoding.', array( 'status' => 400 ) );
		}
		$auth_data = (string) ( $att['authData'] ?? '' );
		if ( strlen( $auth_data ) < 55 ) {
			return new WP_Error( 'mpvk_webauthn', 'authData too short.', array( 'status' => 400 ) );
		}
		if ( ! hash_equals( hash( 'sha256', self::rp_id(), true ), substr( $auth_data, 0, 32 ) ) ) {
			return new WP_Error( 'mpvk_webauthn', 'rpId hash mismatch.', array( 'status' => 400 ) );
		}
		$flags = ord( $auth_data[32] );
		if ( ! ( $flags & 0x01 ) || ! ( $flags & 0x04 ) ) { // UP + UV (Face ID / fingerprint verified)
			return new WP_Error( 'mpvk_webauthn', 'User verification required.', array( 'status' => 400 ) );
		}
		if ( ! ( $flags & 0x40 ) ) { // AT: attested credential data present
			return new WP_Error( 'mpvk_webauthn', 'No credential data.', array( 'status' => 400 ) );
		}

		// 3. attested credential data: aaguid(16) credIdLen(2) credId cosePubkey
		$counter    = unpack( 'N', substr( $auth_data, 33, 4 ) )[1];
		$cred_len   = unpack( 'n', substr( $auth_data, 53, 2 ) )[1];
		$cred_id    = substr( $auth_data, 55, $cred_len );
		if ( strlen( $cred_id ) !== $cred_len || $cred_len < 8 ) {
			return new WP_Error( 'mpvk_webauthn', 'Bad credential id.', array( 'status' => 400 ) );
		}
		if ( ! hash_equals( self::b64u_encode( $cred_id ), $raw_id ) ) {
			return new WP_Error( 'mpvk_webauthn', 'Credential id mismatch.', array( 'status' => 400 ) );
		}
		try {
			[ $cose ] = self::cbor_decode( $auth_data, 55 + $cred_len );
		} catch ( Exception $e ) {
			return new WP_Error( 'mpvk_webauthn', 'Bad public key encoding.', array( 'status' => 400 ) );
		}
		$key = is_array( $cose ) ? self::cose_to_pem( $cose ) : null;
		if ( ! $key ) {
			return new WP_Error( 'mpvk_webauthn', 'Unsupported key type (need ES256 or RS256).', array( 'status' => 400 ) );
		}

		// 4. store (refuse if this credential already belongs to another user)
		global $wpdb;
		$table = MPVK_Schema::table( 'passkeys' );
		$hash  = hash( 'sha256', $cred_id );
		$owner = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id FROM $table WHERE cred_id_hash = %s", $hash ) );
		if ( $owner && (int) $owner->user_id !== $uid ) {
			return new WP_Error( 'mpvk_conflict', 'This passkey is registered to another account.', array( 'status' => 409 ) );
		}
		$label = sanitize_text_field( (string) $req->get_param( 'label' ) );
		if ( $owner ) {
			$wpdb->update( $table, array(
				'public_key' => $key['pem'], 'alg' => $key['alg'], 'counter' => $counter,
				'label'      => $label ?: null,
			), array( 'id' => (int) $owner->id ) );
		} else {
			$wpdb->insert( $table, array(
				'user_id'      => $uid,
				'cred_id'      => self::b64u_encode( $cred_id ),
				'cred_id_hash' => $hash,
				'public_key'   => $key['pem'],
				'alg'          => $key['alg'],
				'counter'      => $counter,
				'label'        => $label ?: null,
				'created_at'   => current_time( 'mysql', true ),
			) );
		}
		MPVK_Audit::log( 'passkey_registered', array( 'meta' => array( 'label' => $label ) ) );
		return array( 'ok' => true );
	}

	// ---- login (public; discoverable credentials so no username needed) ----
	public static function login_options(): array|WP_Error {
		if ( self::rate_limited( 'wan_opt', 30, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'mpvk_rate', 'Too many attempts — wait a few minutes.', array( 'status' => 429 ) );
		}
		$challenge = random_bytes( 32 );
		$rid       = self::b64u_encode( random_bytes( 16 ) );
		set_transient( 'mpvk_wan_login_' . $rid, self::b64u_encode( $challenge ), 5 * MINUTE_IN_SECONDS );
		return array(
			'request_id' => $rid,
			'publicKey'  => array(
				'challenge'        => self::b64u_encode( $challenge ),
				'rpId'             => self::rp_id(),
				'timeout'          => 60000,
				'userVerification' => 'required',
				'allowCredentials' => array(), // discoverable: the phone shows its saved passkeys
			),
		);
	}

	public static function login_verify( WP_REST_Request $req ): array|WP_Error {
		if ( self::rate_limited( 'wan_login', 10, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'mpvk_rate', 'Too many attempts — wait a few minutes.', array( 'status' => 429 ) );
		}
		$rid  = (string) $req->get_param( 'request_id' );
		$cred = $req->get_param( 'credential' );
		if ( '' === $rid || ! is_array( $cred ) ) {
			return new WP_Error( 'mpvk_bad_request', 'Malformed request.', array( 'status' => 400 ) );
		}
		$expected = get_transient( 'mpvk_wan_login_' . $rid );
		delete_transient( 'mpvk_wan_login_' . $rid ); // single-use
		if ( ! $expected ) {
			return new WP_Error( 'mpvk_webauthn', 'Login attempt expired — try again.', array( 'status' => 400 ) );
		}

		$client_json = self::b64u_decode( (string) ( $cred['response']['clientDataJSON'] ?? '' ) );
		$auth_data   = self::b64u_decode( (string) ( $cred['response']['authenticatorData'] ?? '' ) );
		$sig         = self::b64u_decode( (string) ( $cred['response']['signature'] ?? '' ) );
		$raw_id      = (string) ( $cred['rawId'] ?? '' );
		$user_handle = self::b64u_decode( (string) ( $cred['response']['userHandle'] ?? '' ) );
		if ( '' === $client_json || strlen( $auth_data ) < 37 || '' === $sig || '' === $raw_id ) {
			return new WP_Error( 'mpvk_bad_request', 'Malformed credential.', array( 'status' => 400 ) );
		}

		$cd = json_decode( $client_json, true );
		if ( ! is_array( $cd ) || 'webauthn.get' !== ( $cd['type'] ?? '' ) ) {
			return new WP_Error( 'mpvk_webauthn', 'Bad clientData type.', array( 'status' => 400 ) );
		}
		if ( ! hash_equals( $expected, (string) ( $cd['challenge'] ?? '' ) ) ) {
			return new WP_Error( 'mpvk_webauthn', 'Challenge mismatch.', array( 'status' => 400 ) );
		}
		if ( ( $cd['origin'] ?? '' ) !== self::origin() ) {
			return new WP_Error( 'mpvk_webauthn', 'Origin mismatch.', array( 'status' => 400 ) );
		}
		if ( ! hash_equals( hash( 'sha256', self::rp_id(), true ), substr( $auth_data, 0, 32 ) ) ) {
			return new WP_Error( 'mpvk_webauthn', 'rpId mismatch.', array( 'status' => 400 ) );
		}
		$flags = ord( $auth_data[32] );
		if ( ! ( $flags & 0x01 ) || ! ( $flags & 0x04 ) ) {
			return new WP_Error( 'mpvk_webauthn', 'User verification required.', array( 'status' => 400 ) );
		}

		// look up credential
		global $wpdb;
		$table = MPVK_Schema::table( 'passkeys' );
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $table WHERE cred_id_hash = %s", hash( 'sha256', self::b64u_decode( $raw_id ) )
		) );
		if ( ! $row ) {
			MPVK_Audit::log( 'passkey_login_failed', array( 'meta' => array( 'why' => 'unknown_credential' ) ) );
			return new WP_Error( 'mpvk_webauthn', 'Passkey not recognized — log in with your password once, then re-add it.', array( 'status' => 403 ) );
		}
		// userHandle (when present) must match the stored owner
		if ( '' !== $user_handle && 'uid:' . $row->user_id !== $user_handle ) {
			MPVK_Audit::log( 'passkey_login_failed', array( 'meta' => array( 'why' => 'user_handle_mismatch' ) ) );
			return new WP_Error( 'mpvk_webauthn', 'Credential/account mismatch.', array( 'status' => 403 ) );
		}

		// signature over authData || SHA256(clientDataJSON)
		$signed = $auth_data . hash( 'sha256', $client_json, true );
		$pub    = openssl_pkey_get_public( $row->public_key );
		if ( ! $pub || 1 !== openssl_verify( $signed, $sig, $pub, OPENSSL_ALGO_SHA256 ) ) {
			MPVK_Audit::log( 'passkey_login_failed', array( 'user_id' => (int) $row->user_id, 'meta' => array( 'why' => 'bad_signature' ) ) );
			return new WP_Error( 'mpvk_webauthn', 'Signature verification failed.', array( 'status' => 403 ) );
		}

		// counter (clone detection): reject if it went backwards; some platforms always send 0
		$counter = unpack( 'N', substr( $auth_data, 33, 4 ) )[1];
		if ( $counter > 0 && (int) $row->counter > 0 && $counter <= (int) $row->counter ) {
			MPVK_Audit::log( 'passkey_login_failed', array( 'user_id' => (int) $row->user_id, 'meta' => array( 'why' => 'counter_regression' ) ) );
			return new WP_Error( 'mpvk_webauthn', 'Credential replay detected.', array( 'status' => 403 ) );
		}
		$wpdb->update( $table, array( 'counter' => $counter, 'last_used_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $row->id ) );

		$user = get_user_by( 'id', (int) $row->user_id );
		if ( ! $user ) {
			return new WP_Error( 'mpvk_webauthn', 'Account no longer exists.', array( 'status' => 403 ) );
		}

		// Passkey-verified device → clients get the long session horizon back
		// (the 3-day cap was a password-era mitigation).
		self::$passkey_login = true;
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
		self::$passkey_login = false;

		MPVK_Audit::log( 'passkey_login', array( 'user_id' => $user->ID ) );
		// NOTE: deliberately does NOT clear the password-lockout counter — passkey and
		// password brute-force protections stay independent (review finding v0.4-3).
		return array( 'ok' => true, 'redirect' => home_url( '/portal' ) );
	}

	public static bool $passkey_login = false;

	public static function mine(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, label, created_at, last_used_at FROM ' . MPVK_Schema::table( 'passkeys' ) . ' WHERE user_id = %d ORDER BY id',
			get_current_user_id()
		), ARRAY_A );
		return array( 'passkeys' => array_map( fn( $r ) => array(
			'id'        => (int) $r['id'],
			'label'     => $r['label'] ?: 'Passkey',
			'created'   => $r['created_at'],
			'last_used' => $r['last_used_at'],
		), $rows ) );
	}

	public static function remove( WP_REST_Request $req ): array|WP_Error {
		global $wpdb;
		$table = MPVK_Schema::table( 'passkeys' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id FROM $table WHERE id = %d", (int) $req['id'] ) );
		if ( ! $row || (int) $row->user_id !== get_current_user_id() ) {
			return new WP_Error( 'mpvk_forbidden', 'Not your passkey.', array( 'status' => 403 ) );
		}
		$wpdb->delete( $table, array( 'id' => (int) $row->id ) );
		MPVK_Audit::log( 'passkey_removed', array() );
		return array( 'ok' => true );
	}

	// ---- login screen button ----
	public static function login_assets(): void {
		if ( ! self::enabled() ) {
			return;
		}
		wp_enqueue_script( 'mpvk-passkey', MPVK_PLUGIN_URL . 'assets/passkey-login.js', array(), MPVK_VERSION, true );
		wp_localize_script( 'mpvk-passkey', 'MPVK_PK', array(
			'rest' => esc_url_raw( rest_url( self::NS ) ),
		) );
	}

	public static function login_button(): void {
		if ( ! self::enabled() ) {
			return;
		}
		echo '<div id="mpvk-passkey-slot" style="margin:0 0 16px"></div>';
	}
}
