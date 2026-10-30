<?php
/**
 * Central personal-data filter. Every log row passes through here BEFORE it reaches a driver; no channel can skip it.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core;

use AdminKit\Core\Security\Host;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	public const REDACTED = '[redacted]';

	private const MAX_DEPTH = 4;
	private const MAX_ITEMS = 50;

	private const MAX_JSON = 65536;

	/** Secret key names, matched per SEGMENT (`api_key`, `apiKey`, `X-Auth-Token`), so `monkey` and `keyboard` are not secrets. */
	private const SECRET_SEGMENT = '/^(?:pass|pw|pwd|key|keys|auth|authorization|nonce|cookie|cookies|session|sessions|salt|credential|credentials|signature|bearer|cvv|.*(?:password|passwd|passphrase|token|secret|apikey|sessid))$/';

	/** Key names that identify a person or a connection (exact match, plus e-mail / address fragments). */
	private const IDENTITY_KEY = '/^(user|users|uid|user_?id|user_?login|user_?name|username|login|display_?name|author|author_?id|ip|ip_?addr(ess)?|remote_?addr|client_?ip|forwarded_?for|phone|tel|first_?name|last_?name)$|e-?mail|ip_?addr|remote_?addr/i';

	private const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';
	private const IPV4  = '/(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])/';
	/** Runs of address characters; every run is validated with filter_var(), the regex never decides what an address is. */
	private const IP_RUN = '/(?<![0-9A-Fa-f:.])[0-9A-Fa-f:.]++/';
	/** Well-known secret shapes: GitHub, Stripe, OpenAI-style, AWS, JWT, Slack. */
	private const SECRET_TOKEN = '/(?<![A-Za-z0-9_-])(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|sk_(?:live|test)_[A-Za-z0-9]{16,}|sk-[A-Za-z0-9_-]{20,}|(?:AKIA|ASIA)[0-9A-Z]{16}(?![0-9A-Z])|eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}|xox[baprs]-[A-Za-z0-9-]{10,})/';
	private const URL   = '#https?://[^\s<>"\']+#i';

	/** Cleans a free text: secret prefixes, e-mail, IP, URL query strings / credentials and the signed-in user's login. */
	public static function text( string $text ): string {
		$text = (string) preg_replace( self::SECRET_TOKEN, self::REDACTED, $text );
		$text = (string) preg_replace_callback(
			self::URL,
			static function ( array $m ): string {
				$url = preg_replace( '#^(https?://)[^/@\s]+@#i', '$1', $m[0] );
				$pos = strpos( (string) $url, '?' );
				$url = false === $pos ? (string) $url : substr( (string) $url, 0, $pos ) . '?' . self::REDACTED;
				$pos = strpos( $url, '#' );
				return false === $pos ? $url : substr( $url, 0, $pos );
			},
			$text
		);
		$text = (string) preg_replace( self::EMAIL, '[email]', $text );
		$text = self::ipv6( $text );
		$text = (string) preg_replace( self::IPV4, '[ip]', $text );
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		if ( $user instanceof \WP_User && $user->exists() && strlen( $user->user_login ) >= 3 ) {
			$text = str_ireplace( $user->user_login, '[user]', $text );
		}
		return $text;
	}

	/** Replaces every valid IPv6 address (compressed, full, link-local with zone, IPv4-mapped) with `[ip]`. */
	private static function ipv6( string $text ): string {
		if ( false === strpos( $text, '::' ) && substr_count( $text, ':' ) < 2 ) {
			return $text;
		}
		if ( 1 > (int) preg_match_all( self::IP_RUN, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return $text;
		}
		$out = '';
		$pos = 0;
		foreach ( $m[0] as $hit ) {
			list( $run, $off ) = $hit;
			if ( strlen( $run ) > 64 || substr_count( $run, ':' ) < 2 ) {
				continue;
			}
			$prev  = $off > 0 ? $text[ $off - 1 ] : '';
			$start = 0;
			// "ip:2001:db8::1": a label glued to the address leaves one leading colon; "Foo::bar" (scope operator) is not an address.
			if ( '' !== $prev && 1 === preg_match( '/[A-Za-z0-9_]/', $prev ) ) {
				if ( ':' !== $run[0] || ':' === ( $run[1] ?? '' ) ) {
					continue;
				}
				$start = 1;
			}
			$cand = rtrim( substr( $run, $start ), '.' );
			if ( ! self::is_ipv6( $cand ) ) {
				$cand = substr( $cand, 0, -1 );
				if ( ! self::is_ipv6( $cand ) ) {
					continue;
				}
			}
			$end  = $off + $start + strlen( $cand );
			$zone = array();
			if ( 1 === preg_match( '/\G%[A-Za-z0-9_.\-]+/', $text, $zone, 0, $end ) ) {
				$end += strlen( $zone[0] );
			}
			$out .= substr( $text, $pos, $off + $start - $pos ) . '[ip]';
			$pos  = $end;
		}
		return $out . substr( $text, $pos );
	}

	private static function is_ipv6( string $candidate ): bool {
		return Host::is_ipv6( $candidate );
	}

	/** Splits a key name into lower-case segments (`apiKey`, `API-KEY`, `api_key` -> api, key) and checks them against the secret list. */
	private static function is_secret_key( string $name ): bool {
		$name = (string) preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', '_', $name );
		foreach ( preg_split( '/[^a-z0-9]+/', strtolower( $name ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $segment ) {
			if ( 1 === preg_match( self::SECRET_SEGMENT, $segment ) ) {
				return true;
			}
		}
		return false;
	}

	/** A string that is a JSON array/object is cleaned like a context and written back as JSON; anything else is plain text. */
	private static function string_value( string $value, int $depth ): string {
		$head = ltrim( substr( $value, 0, 16 ) );
		if ( '' !== $head && ( '{' === $head[0] || '[' === $head[0] ) ) {
			if ( strlen( $value ) > self::MAX_JSON ) {
				return '[json too large]'; // Cannot be inspected safely, so it is not stored.
			}
			$data = json_decode( $value, true, 32, JSON_BIGINT_AS_STRING );
			if ( is_array( $data ) ) {
				$json = $depth + 1 >= self::MAX_DEPTH ? false : wp_json_encode( self::context( $data, $depth + 1 ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				return is_string( $json ) ? $json : '[json]';
			}
		}
		return self::text( $value );
	}

	/**
	 * Cleans a context array: secret and identity keys lose their value, strings go through text(), objects shrink
	 * to their class name, depth and size are capped.
	 *
	 * @param array<mixed> $context Raw context.
	 * @return array<mixed>
	 */
	public static function context( array $context, int $depth = 0 ): array {
		$out = array();
		$n   = 0;
		foreach ( $context as $key => $value ) {
			if ( ++$n > self::MAX_ITEMS ) {
				$out['_truncated'] = true;
				break;
			}
			$name = (string) $key;
			if ( self::is_secret_key( $name ) || 1 === preg_match( self::IDENTITY_KEY, $name ) ) {
				$out[ $key ] = self::REDACTED;
			} elseif ( is_array( $value ) ) {
				$out[ $key ] = $depth + 1 >= self::MAX_DEPTH ? '[array]' : self::context( $value, $depth + 1 );
			} elseif ( is_string( $value ) ) {
				$out[ $key ] = self::string_value( $value, $depth );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = $value;
			} elseif ( is_object( $value ) ) {
				$out[ $key ] = '[' . get_class( $value ) . ']';
			} else {
				$out[ $key ] = '[' . gettype( $value ) . ']';
			}
		}
		return $out;
	}
}
