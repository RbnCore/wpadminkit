<?php
/**
 * AdminKit Helpers: the one page head of every RBN Kit plugin screen.
 *
 * Fixed in every plugin: brand name, version chip and status chip. Everything else is an optional part
 * (back button, menu, action buttons, extra chips, page title, note) and prints nothing when not given.
 * A plugin passes data only; it cannot print its own head markup, so all four plugins look the same.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Helpers;

defined( 'ABSPATH' ) || exit;

final class Header {

	/** Chip and note tones the kit styles. */
	private const TONES = array( 'ok', 'warn', 'err' );

	/** Id of the menu list (one menu per page). */
	private const MENU_ID = 'rbn-head-menu';

	/**
	 * Returns the page head markup.
	 *
	 * @param array<string, mixed> $args {
	 *     name:string (required), version:string (shown as "v{version}"), status:array{label:string,tone?:string} (required),
	 *     back?:array{label:string,url:string,icon?:string}, menu?:array{label:string,items:array<int,array{label:string,url:string,icon?:string,current?:bool}>,icon?:string},
	 *     actions?:array<int,array{label:string,url?:string,icon?:string,kind?:string,data?:array<string,string>}>,
	 *     chips?:array<int,array{label:string,tone?:string}>, title?:string, title_hidden?:bool,
	 *     note?:array{text:string,tone?:string}|string, nav_label?:string
	 * }
	 */
	public static function render( array $args ): string {
		$name   = (string) ( $args['name'] ?? '' );
		$title  = (string) ( $args['title'] ?? '' );
		$status = (array) ( $args['status'] ?? array() );

		// The brand row owns the page `h1` only when there is no page title under it.
		$tag   = '' === $title ? 'h1' : 'p';
		$ident = '<' . $tag . ' class="rbn-brand">' . esc_html( $name ) . '</' . $tag . '>'
			. '<span class="rbn-chip">' . esc_html( 'v' . (string) ( $args['version'] ?? '' ) ) . '</span>'
			. self::chip( (string) ( $status['label'] ?? '' ), (string) ( $status['tone'] ?? 'ok' ), true );
		foreach ( (array) ( $args['chips'] ?? array() ) as $chip ) {
			$ident .= self::chip( (string) ( $chip['label'] ?? '' ), (string) ( $chip['tone'] ?? '' ), false );
		}

		$act = self::nav( $args ) . self::actions( (array) ( $args['actions'] ?? array() ) );
		$out = '<div class="rbn-head"><div class="rbn-head__id">' . $ident . '</div>'
			. ( '' !== $act ? '<div class="rbn-head__act">' . $act . '</div>' : '' ) . '</div>';

		if ( '' !== $title && ! empty( $args['title_hidden'] ) ) {
			$out .= '<h1 class="rbn-sr">' . esc_html( $title ) . '</h1>';
		} elseif ( '' !== $title ) {
			$out .= '<div class="rbn-head"><div class="rbn-head__id"><h1>' . esc_html( $title ) . '</h1></div></div>';
		}
		// WordPress `common.js` moves admin notices below this marker: after the whole head, never into the head row.
		return $out . self::note( $args['note'] ?? null ) . '<hr class="wp-header-end rbn-sr">';
	}

	/** One chip; the status chip carries the leading dot. */
	private static function chip( string $label, string $tone, bool $dot ): string {
		if ( '' === $label ) {
			return '';
		}
		$tone = in_array( $tone, self::TONES, true ) ? ' rbn-chip--' . $tone : '';
		return '<span class="rbn-chip' . $tone . '">' . ( $dot ? '<span class="rbn-dot" aria-hidden="true"></span>' : '' ) . esc_html( $label ) . '</span>';
	}

	/** Back button and menu, grouped in one labelled `nav`. */
	private static function nav( array $args ): string {
		$html = '';
		$back = (array) ( $args['back'] ?? array() );
		if ( ! empty( $back['url'] ) && ! empty( $back['label'] ) ) {
			$html .= '<a class="rbn-btn" href="' . esc_url( (string) $back['url'] ) . '">' . Icons::icon( (string) ( $back['icon'] ?? 'chevl' ) ) . esc_html( (string) $back['label'] ) . '</a>';
		}
		$menu  = (array) ( $args['menu'] ?? array() );
		$items = '';
		foreach ( (array) ( $menu['items'] ?? array() ) as $row ) {
			$icon   = ! empty( $row['icon'] ) ? Icons::icon( (string) $row['icon'] ) : '';
			$items .= '<li><a href="' . esc_url( (string) ( $row['url'] ?? '' ) ) . '"' . ( ! empty( $row['current'] ) ? ' aria-current="page"' : '' ) . '>' . $icon . esc_html( (string) ( $row['label'] ?? '' ) ) . '</a></li>';
		}
		if ( '' !== $items && ! empty( $menu['label'] ) ) {
			$html .= '<div class="rbn-menu"><button type="button" class="rbn-btn" aria-expanded="false" aria-controls="' . self::MENU_ID . '" data-rbn-menu>'
				. Icons::icon( (string) ( $menu['icon'] ?? 'grid' ) ) . esc_html( (string) $menu['label'] ) . Icons::icon( 'chevd' ) . '</button>'
				. '<ul class="rbn-menu__list" id="' . self::MENU_ID . '" hidden>' . $items . '</ul></div>';
		}
		if ( '' === $html ) {
			return '';
		}
		$label = (string) ( $args['nav_label'] ?? '' );
		return '<nav class="rbn-head__nav" aria-label="' . esc_attr( '' !== $label ? $label : __( 'Page navigation', 'adminkit' ) ) . '">' . $html . '</nav>';
	}

	/**
	 * Action buttons: a link when `url` is given, otherwise a plain button the plugin script finds through `data`.
	 *
	 * @param array<int, array<string, mixed>> $actions Button definitions.
	 */
	private static function actions( array $actions ): string {
		$html = '';
		foreach ( $actions as $a ) {
			if ( empty( $a['label'] ) ) {
				continue;
			}
			$class = 'rbn-btn' . ( 'pri' === ( $a['kind'] ?? '' ) ? ' rbn-btn--pri' : '' );
			$inner = ( ! empty( $a['icon'] ) ? Icons::icon( (string) $a['icon'] ) : '' ) . esc_html( (string) $a['label'] );
			if ( ! empty( $a['url'] ) ) {
				$html .= '<a class="' . $class . '" href="' . esc_url( (string) $a['url'] ) . '">' . $inner . '</a>';
				continue;
			}
			$data = '';
			foreach ( (array) ( $a['data'] ?? array() ) as $key => $value ) {
				$data .= ' data-' . preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $key ) ) . '="' . esc_attr( (string) $value ) . '"';
			}
			$html .= '<button type="button" class="' . $class . '"' . $data . '>' . $inner . '</button>';
		}
		return $html;
	}

	/**
	 * Short strip under the head.
	 *
	 * @param array{text?:string,tone?:string}|string|null $note Text or text with tone.
	 */
	private static function note( $note ): string {
		$note = is_string( $note ) ? array( 'text' => $note ) : (array) $note;
		$text = (string) ( $note['text'] ?? '' );
		if ( '' === $text ) {
			return '';
		}
		$tone = in_array( $note['tone'] ?? '', self::TONES, true ) ? ' rbn-note--' . $note['tone'] : '';
		return '<div class="rbn-note' . $tone . '" role="status"><div><p>' . esc_html( $text ) . '</p></div></div>';
	}
}
