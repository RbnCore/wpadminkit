<?php
/**
 * Central host / URL validator: ONE place that decides what a host is, whether it may be contacted and whether a
 * redirect may go there.
 *
 * Every RBN plugin uses this class instead of writing its own `parse_url` + host comparison. The rules are:
 *
 * - A host is ASCII, lower-case and without a trailing dot; anything else is normalized away before comparing.
 * - `same_site_pair()` treats `www.example.com` and `example.com` as the same site (one site, two spellings).
 * - `is_public()` refuses addresses that only exist inside the machine or the network (SSRF): loopback, private
 *   ranges, link-local, `localhost`, `.test`, `.local`, `.internal` and single-label names.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core\Security;

defined( 'ABSPATH' ) || exit;

final class Host {

	/** Local / reserved suffixes that must never be contacted from the outside world. */
	private const LOCAL_SUFFIXES = array(
		'localhost',
		'localdomain',
		'test',
		'local',
		'internal',
		'home',
		'lan',
		'intranet',
		'invalid',
		'example',
	);

	/** Characters that turn a URL into a header-splitting or parser-confusing string. */
	private const FORBIDDEN_URL = '/[\x00-\x20\x7f]/';

	/**
	 * The host of a URL, normalized. Empty string when the URL has no usable host.
	 *
	 * `https://ornek.com@evil.com/x` gives `evil.com`: user information can never pretend to be a host.
	 *
	 * @param string $url Full URL or a bare authority.
	 */
	public static function host( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		// A URL never carries raw control characters or whitespace; such a value is not parsed at all (header
		// splitting / parser confusion).
		if ( 1 === preg_match( self::FORBIDDEN_URL, str_replace( ' ', '%20', $url ) ) ) {
			return '';
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) ? self::normalize( (string) $host ) : '';
	}

	/**
	 * Normalizes a host: trimmed, lower-case, IPv6 brackets removed, trailing dot removed.
	 *
	 * A value that still carries a scheme, credentials, a port or a path is parsed first, so a half-URL cannot pass
	 * as a host.
	 *
	 * @param string $host Host or authority.
	 */
	public static function normalize( string $host ): string {
		$host = trim( $host );
		if ( '' === $host ) {
			return '';
		}
		if ( 1 === preg_match( self::FORBIDDEN_URL, $host ) ) {
			return '';
		}
		if ( false !== strpos( $host, '/' ) || false !== strpos( $host, '@' ) || false !== strpos( $host, ':' ) && false === strpos( $host, '::' ) ) {
			$parsed = wp_parse_url( 'http://' . ltrim( $host, '/' ), PHP_URL_HOST );
			$host   = is_string( $parsed ) ? (string) $parsed : '';
		}
		if ( '' === $host ) {
			return '';
		}
		if ( '[' === $host[0] && str_ends_with( $host, ']' ) ) {
			$host = substr( $host, 1, -1 );
		}
		$host = strtolower( $host );
		return rtrim( $host, '.' );
	}

	/**
	 * May this host be contacted from the outside world?
	 *
	 * False for loopback, private and link-local addresses, `localhost`, single-label names and the reserved local
	 * suffixes (`.test`, `.local`, `.internal`, …): contacting those is an SSRF, not a download.
	 *
	 * @param string $host Host name or IP address.
	 */
	public static function is_public( string $host ): bool {
		$host = self::normalize( $host );
		if ( '' === $host ) {
			return false;
		}
		if ( self::is_ip( $host ) ) {
			return self::is_public_ip( $host );
		}
		// A host name is ASCII only: a non-ASCII (IDN homograph) spelling is never trusted as an address.
		if ( 1 === preg_match( '/[^\x21-\x7e]/', $host ) ) {
			return false;
		}
		if ( false === strpos( $host, '.' ) ) {
			return false; // Single label: only resolves inside a private network.
		}
		foreach ( self::LOCAL_SUFFIXES as $suffix ) {
			if ( $host === $suffix || str_ends_with( $host, '.' . $suffix ) ) {
				return false;
			}
		}
		// A trailing numeric label ("example.123") is treated as an IPv4 shorthand by some resolvers.
		return 1 !== preg_match( '/\.\d+$/', $host );
	}

	/** Is this string a valid IPv4 / IPv6 address? */
	public static function is_ip( string $candidate, int $flags = 0 ): bool {
		$candidate = trim( $candidate );
		return '' !== $candidate && false !== filter_var( $candidate, FILTER_VALIDATE_IP, $flags );
	}

	/** Is this string a valid IPv6 address? */
	public static function is_ipv6( string $candidate ): bool {
		return false !== strpos( $candidate, ':' ) && self::is_ip( $candidate, FILTER_FLAG_IPV6 );
	}

	/** Is this a public (routable) IP address? */
	public static function is_public_ip( string $address ): bool {
		$address = trim( $address );
		if ( ! self::is_ip( $address ) ) {
			return false;
		}
		if ( self::is_ipv6( $address ) ) {
			$normalized = strtolower( $address );
			// Loopback, unspecified, link-local (fe80::/10) and unique-local (fc00::/7).
			foreach ( array( '::1', '::', '0:0:0:0:0:0:0:1', '0:0:0:0:0:0:0:0' ) as $local ) {
				if ( $normalized === $local ) {
					return false;
				}
			}
			$head = substr( $normalized, 0, 2 );
			if ( in_array( $head, array( 'fc', 'fd' ), true ) || 1 === preg_match( '/^fe[89ab]/', $normalized ) ) {
				return false;
			}
			return false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_RES_RANGE );
		}
		return false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Do these two hosts mean the same site? `www.` is one spelling of the same site.
	 *
	 * Both values are normalized first, so `EXAMPLE.com.` and `example.com` match.
	 */
	public static function same_site_pair( string $a, string $b ): bool {
		$a = self::normalize( $a );
		$b = self::normalize( $b );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( $a === $b ) {
			return true;
		}
		return self::without_www( $a ) === self::without_www( $b );
	}

	/**
	 * Is this URL allowed, and does it point at one of the given hosts?
	 *
	 * @param string             $url     URL to check.
	 * @param array<int,string>  $hosts   Allowed hosts (a `www.` spelling matches its naked pair).
	 * @param array<int,string>  $schemes Allowed schemes.
	 */
	public static function allowed( string $url, array $hosts, array $schemes = array( 'https' ) ): bool {
		$url = trim( $url );
		if ( '' === $url || 1 === preg_match( self::FORBIDDEN_URL, str_replace( ' ', '%20', $url ) ) ) {
			return false;
		}
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! is_string( $scheme ) || ! in_array( strtolower( $scheme ), array_map( 'strtolower', $schemes ), true ) ) {
			return false;
		}
		$host = self::host( $url );
		if ( '' === $host ) {
			return false;
		}
		foreach ( $hosts as $allowed ) {
			if ( self::same_site_pair( $host, (string) $allowed ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A redirect target: the URL itself when it stays on this site and uses http/https, otherwise the fallback.
	 *
	 * @param string $url      Requested redirect target.
	 * @param string $fallback Used when the target is not on this site.
	 */
	public static function safe_redirect_target( string $url, string $fallback = '' ): string {
		$url    = trim( $url );
		$scheme = is_string( $url ) ? wp_parse_url( $url, PHP_URL_SCHEME ) : null;
		if ( '' === $url || ( null !== $scheme && ! in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ) ) {
			return $fallback;
		}
		$home = self::normalize( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$host = self::host( $url );
		// A scheme-relative address (`//evil.com/x`) has a host; a path-only address has none and stays on this site.
		if ( '' === $host ) {
			return '' !== $home ? ( $url ) : $fallback;
		}
		return self::same_site_pair( $host, $home ) ? $url : $fallback;
	}

	/** Drops one leading `www.` label. */
	private static function without_www( string $host ): string {
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
