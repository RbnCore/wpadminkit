<?php
/**
 * AdminKit Helpers: the one notification centre of every RBN Kit plugin (bell + badge + dialog).
 *
 * A kit plugin never prints an admin notice box. It hooks `adminkit_notices` (fires once per admin screen, after the screen is
 * known) and calls `Notices::add()`; the bell in the page head (`Header::render`) and, for warnings and errors, in the admin
 * bar shows the badge and opens one modal. This class prints no `admin_notices` output.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Helpers;

use AdminKit\Assets;

defined( 'ABSPATH' ) || exit;

final class Notices {

	/** Levels, most urgent first. */
	private const LEVELS = array( 'error', 'warning', 'info' );

	/** Dialog id (one per page). */
	public const DIALOG = 'rbn-bell-dlg';

	/** @var array<string, array<string, mixed>> Registered notices by id. */
	private static array $items = array();

	private static bool $bell_shown = false;

	/** Hooks the centre; called once from `Plugin::init()`. */
	public static function hooks(): void {
		add_action( 'current_screen', array( self::class, 'collect' ), 20 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
		add_action( 'admin_bar_menu', array( self::class, 'bar' ), 90 );
		add_action( 'admin_footer', array( self::class, 'dialog' ), 5 );
	}

	/**
	 * Registers one notice for this request. The same `$id` twice keeps the last one.
	 *
	 * @param string               $id    Stable id (`plugin-topic`).
	 * @param string               $level error|warning|info.
	 * @param string               $title Short headline (plain text).
	 * @param array<string, mixed> $args  body:string (plain text), items:string[] (plain text; `<code>`/`<strong>` allowed),
	 *                                    links:array<int,array{label:string,url:string,primary?:bool}>, plugin:string (label of the sender).
	 */
	public static function add( string $id, string $level, string $title, array $args = array() ): void {
		$id = sanitize_key( $id );
		if ( '' === $id || '' === $title ) {
			return;
		}
		$links = array();
		foreach ( (array) ( $args['links'] ?? array() ) as $link ) {
			if ( ! empty( $link['label'] ) && ! empty( $link['url'] ) ) {
				$links[] = array(
					'label'   => (string) $link['label'],
					'url'     => (string) $link['url'],
					'primary' => ! empty( $link['primary'] ),
				);
			}
		}
		self::$items[ $id ] = array(
			'id'     => $id,
			'level'  => in_array( $level, self::LEVELS, true ) ? $level : 'info',
			'title'  => $title,
			'body'   => (string) ( $args['body'] ?? '' ),
			'items'  => array_values( array_map( 'strval', (array) ( $args['items'] ?? array() ) ) ),
			'links'  => $links,
			'plugin' => (string) ( $args['plugin'] ?? '' ),
		);
	}

	/** Asks every kit plugin for its notices, once per request. */
	public static function collect(): void {
		static $done = false;
		if ( $done || ! is_admin() ) {
			return;
		}
		$done = true;
		/**
		 * Kit plugins register their admin notices here with `Notices::add()`.
		 */
		do_action( 'adminkit_notices' );
	}

	/** @return array<string, array<string, mixed>> */
	public static function all(): array {
		return self::$items;
	}

	public static function count(): int {
		return count( self::$items );
	}

	/** Warnings and errors (what the admin bar bell counts). */
	private static function urgent(): int {
		$n = 0;
		foreach ( self::$items as $item ) {
			if ( 'info' !== $item['level'] ) {
				++$n;
			}
		}
		return $n;
	}

	/** Bell and dialog assets on every admin screen (small, tokens only). */
	public static function enqueue(): void {
		wp_enqueue_style( Assets::UI );
		wp_enqueue_script( Assets::UI );
		Assets::load( 'bell' );
	}

	/** Label of the bell button for screen readers. */
	private static function label( int $count ): string {
		return 0 === $count
			? __( 'Notifications, none', 'adminkit' )
			/* translators: %d: number of notifications. */
			: sprintf( _n( 'Notifications, %d item', 'Notifications, %d items', $count, 'adminkit' ), $count );
	}

	/** The bell button of the page head (called by `Header::render`). */
	public static function bell(): string {
		self::$bell_shown = true;
		$count            = self::count();
		return '<button type="button" class="rbn-btn rbn-bell" aria-haspopup="dialog" aria-controls="' . self::DIALOG . '" data-rbn-bell="1" data-rbn-count="' . $count . '">'
			. Icons::icon( 'bell' ) . '<span class="rbn-bell__t">' . esc_html( self::label( $count ) ) . '</span></button>';
	}

	/**
	 * Admin bar bell for warnings and errors. CSS hides it on kit screens (they carry the bell in the head).
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public static function bar( $bar ): void {
		$urgent = self::urgent();
		if ( ! is_admin() || $urgent < 1 ) {
			return;
		}
		self::$bell_shown = true;
		$bar->add_node(
			array(
				'id'    => 'rbn-notices',
				'title' => Icons::icon( 'bell' ) . '<span class="rbn-bell__n" aria-hidden="true">' . $urgent . '</span><span class="screen-reader-text">' . esc_html( self::label( self::count() ) ) . '</span>',
				'href'  => '#' . self::DIALOG,
				'meta'  => array( 'class' => 'rbn-bell-bar' ),
			)
		);
	}

	/** The modal (native `dialog`): printed once when a bell is on the page. */
	public static function dialog(): void {
		if ( ! self::$bell_shown ) {
			return;
		}
		$tones   = array( 'error' => 'err', 'warning' => 'warn', 'info' => '' );
		$icons   = array( 'error' => 'x', 'warning' => 'alert', 'info' => 'info' );
		$allowed = array( 'code' => array(), 'strong' => array() );
		$out     = '<dialog class="rbn-dlg rbn-bell-dlg" id="' . self::DIALOG . '"' . Icons::tone_attr() . ' aria-labelledby="' . self::DIALOG . '-h"><div class="rbn-dlg__h"><h2 id="' . self::DIALOG . '-h">'
			. Icons::icon( 'bell' ) . '<span>' . esc_html__( 'Notifications', 'adminkit' ) . '</span></h2>'
			. '<button type="button" class="rbn-btn rbn-btn--icon" data-rbn-bell-close aria-label="' . esc_attr__( 'Close', 'adminkit' ) . '">' . Icons::icon( 'x' ) . '</button></div><div class="rbn-dlg__b">';
		if ( ! self::$items ) {
			$out .= '<p>' . esc_html__( 'No notifications.', 'adminkit' ) . '</p>';
		}
		foreach ( self::sorted() as $item ) {
			$tone = $tones[ $item['level'] ];
			$out .= '<div class="rbn-note' . ( '' !== $tone ? ' rbn-note--' . $tone : '' ) . '" role="note" data-rbn-notice="' . esc_attr( $item['id'] ) . '">' . Icons::icon( $icons[ $item['level'] ] ) . '<div class="rbn-note__t">'
				. '<p><strong>' . esc_html( $item['title'] ) . '</strong>' . ( '' !== $item['plugin'] ? ' <span class="rbn-help">' . esc_html( $item['plugin'] ) . '</span>' : '' ) . '</p>';
			if ( '' !== $item['body'] ) {
				$out .= '<p>' . esc_html( $item['body'] ) . '</p>';
			}
			if ( $item['items'] ) {
				$out .= '<ul>';
				foreach ( $item['items'] as $line ) {
					$out .= '<li>' . wp_kses( $line, $allowed ) . '</li>';
				}
				$out .= '</ul>';
			}
			if ( $item['links'] ) {
				$out .= '<div class="rbn-note__act">';
				foreach ( $item['links'] as $link ) {
					$out .= '<a' . ( $link['primary'] ? ' class="rbn-btn rbn-btn--pri"' : '' ) . ' href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a> ';
				}
				$out .= '</div>';
			}
			$out .= '</div></div>';
		}
		$out .= '</div><div class="rbn-dlg__f"><button type="button" class="rbn-btn" data-rbn-bell-close>' . esc_html__( 'Close', 'adminkit' ) . '</button></div></dialog>';
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part is escaped above.
	}

	/** @return array<int, array<string, mixed>> Notices, errors first. */
	private static function sorted(): array {
		$rank = array_flip( self::LEVELS );
		$list = array_values( self::$items );
		usort( $list, static fn( array $a, array $b ): int => $rank[ $a['level'] ] <=> $rank[ $b['level'] ] );
		return $list;
	}
}
