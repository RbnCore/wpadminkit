<?php
/**
 * Central personal-data filter. Every log row passes through here BEFORE it reaches a driver; no channel can skip it.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	public const REDACTED = '[redacted]';

	private const MAX_DEPTH = 4;
	private const MAX_ITEMS = 50;

	/** Key names that hold secrets (substring match). */
	private const SECRET_KEY = '/(pass|pwd|token|secret|key|auth|nonce|cookie|session|salt|credential|signature|bearer|cvv)/i';

	/** Key names that identify a person or a connection (exact match, plus e-mail / address fragments). */
	private const IDENTITY_KEY = '/^(user|users|uid|user_?id|user_?login|user_?name|username|login|display_?name|author|author_?id|ip|ip_?addr(ess)?|remote_?addr|client_?ip|forwarded_?for|phone|tel|first_?name|last_?name)$|e-?mail|ip_?addr|remote_?addr/i';

	private const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';
	private const IPV4  = '/(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])/';
	private const IPV6  = '/(?<![\w:])(?:(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}|(?:[0-9a-f]{1,4}:){1,7}:(?:[0-9a-f]{1,4})?|:(?::[0-9a-f]{1,4}){1,7})(?![\w:])/i';
	private const URL   = '#https?://[^\s<>"\']+#i';

	/** Cleans a free text: e-mail, IP, URL query strings / credentials and the signed-in user's login. */
	public static function text( string $text ): string {
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
		$text = (string) preg_replace( self::IPV6, '[ip]', $text );
		$text = (string) preg_replace( self::IPV4, '[ip]', $text );
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		if ( $user instanceof \WP_User && $user->exists() && strlen( $user->user_login ) >= 3 ) {
			$text = str_ireplace( $user->user_login, '[user]', $text );
		}
		return $text;
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
			if ( 1 === preg_match( self::SECRET_KEY, $name ) || 1 === preg_match( self::IDENTITY_KEY, $name ) ) {
				$out[ $key ] = self::REDACTED;
			} elseif ( is_array( $value ) ) {
				$out[ $key ] = $depth + 1 >= self::MAX_DEPTH ? '[array]' : self::context( $value, $depth + 1 );
			} elseif ( is_string( $value ) ) {
				$out[ $key ] = self::text( $value );
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
