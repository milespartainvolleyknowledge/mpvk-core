<?php
defined( 'ABSPATH' ) || exit;

/**
 * RFC 6238 TOTP (+ RFC 4648 base32). Dependency-free; validated against the
 * RFC 6238 Appendix B test vectors in tests/twofa-tests.php.
 */
class MPVK_TOTP {

	const PERIOD = 30;
	const DIGITS = 6;

	public static function base32_encode( string $bin ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$out = '';
		$bits = '';
		foreach ( str_split( $bin ) as $c ) {
			$bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
		}
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= $alphabet[ bindec( str_pad( $chunk, 5, '0', STR_PAD_RIGHT ) ) ];
		}
		return $out;
	}

	public static function base32_decode( string $b32 ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$b32 = strtoupper( preg_replace( '/[^A-Z2-7]/', '', $b32 ) );
		$bits = '';
		foreach ( str_split( $b32 ) as $c ) {
			$bits .= str_pad( decbin( strpos( $alphabet, $c ) ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) {
				$out .= chr( bindec( $byte ) );
			}
		}
		return $out;
	}

	public static function new_secret( int $bytes = 20 ): string {
		return self::base32_encode( random_bytes( $bytes ) );
	}

	/** Compute the TOTP for a base32 secret at a given unix time. */
	public static function code_at( string $secret_b32, int $time, int $period = self::PERIOD, int $digits = self::DIGITS ): string {
		$key     = self::base32_decode( $secret_b32 );
		$counter = (int) floor( $time / $period );
		$binctr  = pack( 'N*', 0 ) . pack( 'N*', $counter ); // 8-byte big-endian counter
		$hash    = hash_hmac( 'sha1', $binctr, $key, true );
		$offset  = ord( $hash[ strlen( $hash ) - 1 ] ) & 0x0F;
		$part    = ( ( ord( $hash[ $offset ] ) & 0x7F ) << 24 )
			| ( ( ord( $hash[ $offset + 1 ] ) & 0xFF ) << 16 )
			| ( ( ord( $hash[ $offset + 2 ] ) & 0xFF ) << 8 )
			| ( ord( $hash[ $offset + 3 ] ) & 0xFF );
		return str_pad( (string) ( $part % ( 10 ** $digits ) ), $digits, '0', STR_PAD_LEFT );
	}

	/** Verify a submitted code within +/- 1 period (clock skew tolerance). */
	public static function verify( string $secret_b32, string $code, ?int $now = null ): bool {
		$code = preg_replace( '/\D/', '', $code );
		if ( strlen( $code ) !== self::DIGITS ) {
			return false;
		}
		$now = $now ?? time();
		foreach ( array( -1, 0, 1 ) as $w ) {
			if ( hash_equals( self::code_at( $secret_b32, $now + $w * self::PERIOD ), $code ) ) {
				return true;
			}
		}
		return false;
	}

	public static function provisioning_uri( string $secret_b32, string $label, string $issuer ): string {
		return 'otpauth://totp/' . rawurlencode( $issuer . ':' . $label )
			. '?secret=' . $secret_b32
			. '&issuer=' . rawurlencode( $issuer )
			. '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
	}
}
