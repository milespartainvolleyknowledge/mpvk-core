<?php
defined( 'ABSPATH' ) || exit;

/**
 * Web Push (VAPID / RFC 8292 + aes128gcm / RFC 8291 + RFC 8188), dependency-free (openssl).
 *
 * STATUS: infrastructure + sender are complete and the encryption round-trips in tests, but
 * end-to-end delivery to a real push service can only be confirmed on a real device. Sending
 * is therefore OFF by default (option `mpvk_push_enabled`) — enable it after the "Send test
 * notification" button on the MPVK admin page delivers to your phone.
 */
class MPVK_Push {

	// SPKI DER prefix for an uncompressed P-256 public key point.
	const P256_SPKI_PREFIX = "3059301306072a8648ce3d020106082a8648ce3d030107034200";

	public static function enabled(): bool {
		return (bool) get_option( 'mpvk_push_enabled', false ) && '' !== (string) get_option( 'mpvk_vapid_private', '' );
	}

	/**
	 * SSRF guard: a push endpoint must be HTTPS on a known push-service host.
	 * IP-literal hosts are rejected outright (real services always use hostnames),
	 * which blocks the cloud-metadata range (169.254.169.254) and other internal IPs.
	 * Allowing only these four provider domains means send_one() can never be pointed
	 * at an internal address the WordPress host can reach.
	 */
	public static function is_allowed_endpoint( string $url ): bool {
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) || ( $p['scheme'] ?? '' ) !== 'https' ) {
			return false;
		}
		$host = strtolower( $p['host'] );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false; // no raw IPs — push services publish hostnames
		}
		$allow = array(
			'fcm.googleapis.com',            // Chrome / Android (FCM)
			'push.services.mozilla.com',     // Firefox (autopush)
			'notify.windows.com',            // Edge / Windows (WNS)
			'push.apple.com',                // Safari / iOS (Apple)
		);
		foreach ( $allow as $suffix ) {
			if ( $host === $suffix || substr( $host, - ( strlen( $suffix ) + 1 ) ) === '.' . $suffix ) {
				return true;
			}
		}
		return false;
	}

	// ---------- base64url ----------
	public static function b64u_encode( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}
	public static function b64u_decode( string $s ): string {
		return base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) );
	}

	// ---------- VAPID keypair ----------
	public static function ensure_vapid(): void {
		if ( get_option( 'mpvk_vapid_private' ) && get_option( 'mpvk_vapid_public' ) ) {
			return;
		}
		$res = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
		if ( ! $res ) {
			return;
		}
		openssl_pkey_export( $res, $pem );
		$details = openssl_pkey_get_details( $res );
		$point   = "\x04" . str_pad( $details['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $details['ec']['y'], 32, "\0", STR_PAD_LEFT );
		update_option( 'mpvk_vapid_private', $pem, false );
		update_option( 'mpvk_vapid_public', self::b64u_encode( $point ), false );
	}

	public static function vapid_public(): string {
		return (string) get_option( 'mpvk_vapid_public', '' );
	}

	/** Build an openssl public-key resource from a raw uncompressed P-256 point. */
	private static function pubkey_from_point( string $point ): mixed {
		$der = hex2bin( self::P256_SPKI_PREFIX ) . $point;
		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		return openssl_pkey_get_public( $pem );
	}

	// ---------- RFC 8291 payload encryption ----------
	/**
	 * @return array{salt:string, as_public:string, body:string} raw bytes
	 */
	public static function encrypt( string $plaintext, string $ua_public_raw, string $auth_secret, ?string $as_pem = null, ?string $salt = null ): array {
		$salt = $salt ?? random_bytes( 16 );
		// Application-server ephemeral keypair (or provided, for tests).
		if ( $as_pem ) {
			$as_priv = openssl_pkey_get_private( $as_pem );
		} else {
			$as_priv = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
		}
		$as_det    = openssl_pkey_get_details( $as_priv );
		$as_public = "\x04" . str_pad( $as_det['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $as_det['ec']['y'], 32, "\0", STR_PAD_LEFT );

		$ua_pub  = self::pubkey_from_point( $ua_public_raw );
		$ecdh    = openssl_pkey_derive( $ua_pub, $as_priv, 32 );

		// IKM = HKDF(auth_secret; ecdh; "WebPush: info\0"||ua||as; 32)
		$key_info = "WebPush: info\x00" . $ua_public_raw . $as_public;
		$ikm      = hash_hkdf( 'sha256', $ecdh, 32, $key_info, $auth_secret );

		$cek   = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt );
		$nonce = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt );

		$padded = $plaintext . "\x02"; // single record, last-record delimiter
		$tag    = '';
		$cipher = openssl_encrypt( $padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );

		$rs     = 4096;
		$header = $salt . pack( 'N', $rs ) . chr( strlen( $as_public ) ) . $as_public;
		return array( 'salt' => $salt, 'as_public' => $as_public, 'body' => $header . $cipher . $tag );
	}

	/** Decrypt (test-only) — proves the encrypt path round-trips. */
	public static function decrypt( string $body, string $ua_private_pem, string $auth_secret ): string|false {
		$salt   = substr( $body, 0, 16 );
		$idlen  = ord( $body[20] );
		$as_public = substr( $body, 21, $idlen );
		$cipher = substr( $body, 21 + $idlen );

		$ua_priv = openssl_pkey_get_private( $ua_private_pem );
		$ua_det  = openssl_pkey_get_details( $ua_priv );
		$ua_public = "\x04" . str_pad( $ua_det['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $ua_det['ec']['y'], 32, "\0", STR_PAD_LEFT );

		$as_pub = self::pubkey_from_point( $as_public );
		$ecdh   = openssl_pkey_derive( $as_pub, $ua_priv, 32 );
		$key_info = "WebPush: info\x00" . $ua_public . $as_public;
		$ikm    = hash_hkdf( 'sha256', $ecdh, 32, $key_info, $auth_secret );
		$cek    = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt );
		$nonce  = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt );

		$ct  = substr( $cipher, 0, -16 );
		$tag = substr( $cipher, -16 );
		$pt  = openssl_decrypt( $ct, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );
		if ( false === $pt ) {
			return false;
		}
		return rtrim( $pt, "\x02\x00" ); // strip padding delimiter
	}

	// ---------- VAPID JWT (ES256) ----------
	public static function vapid_auth_header( string $endpoint ): string {
		$parts  = wp_parse_url( $endpoint );
		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$header = self::b64u_encode( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
		$claims = self::b64u_encode( wp_json_encode( array(
			'aud' => $origin,
			'exp' => time() + 12 * HOUR_IN_SECONDS,
			'sub' => 'mailto:' . get_option( 'admin_email' ),
		) ) );
		$signing = $header . '.' . $claims;
		$priv    = openssl_pkey_get_private( (string) get_option( 'mpvk_vapid_private' ) );
		openssl_sign( $signing, $der, $priv, OPENSSL_ALGO_SHA256 );
		$sig = self::der_to_raw_sig( $der );
		$jwt = $signing . '.' . self::b64u_encode( $sig );
		return 'vapid t=' . $jwt . ', k=' . self::vapid_public();
	}

	/** ECDSA DER signature → raw 64-byte R||S. */
	private static function der_to_raw_sig( string $der ): string {
		$off = 3; // 0x30 len 0x02
		$rlen = ord( $der[ $off ] ); $off++;
		$r = substr( $der, $off, $rlen ); $off += $rlen;
		$off++; // 0x02
		$slen = ord( $der[ $off ] ); $off++;
		$s = substr( $der, $off, $slen );
		$r = ltrim( $r, "\x00" ); $s = ltrim( $s, "\x00" );
		return str_pad( $r, 32, "\0", STR_PAD_LEFT ) . str_pad( $s, 32, "\0", STR_PAD_LEFT );
	}

	// ---------- send ----------
	public static function send_to_user( int $user_id, array $payload ): int {
		if ( ! self::enabled() ) {
			return 0;
		}
		global $wpdb;
		$subs = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . MPVK_Schema::table( 'push_subscriptions' ) . ' WHERE user_id = %d', $user_id
		) );
		$sent = 0;
		foreach ( $subs as $s ) {
			if ( self::send_one( $s, $payload ) ) {
				$sent++;
			}
		}
		return $sent;
	}

	public static function send_one( object $sub, array $payload ): bool {
		// SSRF defense-in-depth: never POST to anything but a known push host.
		// (Endpoints are also validated at subscribe time; this re-checks in case a
		// row predates the allowlist or the stored value was tampered with.)
		if ( ! self::is_allowed_endpoint( (string) $sub->endpoint ) ) {
			return false;
		}
		$json = wp_json_encode( $payload );
		$enc  = self::encrypt( $json, self::b64u_decode( $sub->p256dh ), self::b64u_decode( $sub->auth ) );
		$resp = wp_remote_post( $sub->endpoint, array(
			'headers' => array(
				'Authorization'    => self::vapid_auth_header( $sub->endpoint ),
				'Content-Type'     => 'application/octet-stream',
				'Content-Encoding' => 'aes128gcm',
				'TTL'              => '2419200',
				'Urgency'          => 'high',
			),
			'body'               => $enc['body'],
			'timeout'            => 10,
			'reject_unsafe_urls' => true,
		) );
		if ( is_wp_error( $resp ) ) {
			return false;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		if ( 404 === $code || 410 === $code ) {
			// Subscription gone — prune it.
			global $wpdb;
			$wpdb->delete( MPVK_Schema::table( 'push_subscriptions' ), array( 'id' => $sub->id ) );
		}
		return $code >= 200 && $code < 300;
	}
}
