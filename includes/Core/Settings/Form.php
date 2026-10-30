<?php
/**
 * Shared form-field helpers for the settings engine: ONE place that writes the RBN kit inputs.
 *
 * A plugin names its field (`option[a][b]`, dotted or prefixed), hands over state and classes, and gets escaped
 * markup back. Checkboxes can carry the hidden `0` twin; the engine's FORM mode also treats a checkbox that was
 * not posted as off, so a forgotten twin can never save the wrong value.
 *
 * @package AdminKit
 */

declare(strict_types=1);

namespace AdminKit\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class Form {

	/**
	 * The `name` of a field.
	 *
	 * @param string $option Option name (`wpseokit_settings`, `cookiekit_settings`, …).
	 * @param string $path   Dotted field path (`templates.sep`).
	 * @param string $style  bracket (default): `option[a][b]`; dotted: `a.b`; plain: `b`.
	 */
	public static function name( string $option, string $path, string $style = 'bracket' ): string {
		if ( 'dotted' === $style || 'plain' === $style ) {
			return $path;
		}
		$parts = explode( '.', $path );
		return $option . '[' . implode( '][', $parts ) . ']';
	}

	/**
	 * Escaped attribute list. `true` prints a bare attribute, null/false are skipped.
	 *
	 * @param array<string, mixed> $attrs Attributes.
	 */
	public static function attrs( array $attrs ): string {
		$out = '';
		foreach ( $attrs as $key => $value ) {
			$key = (string) $key;
			if ( null === $value || false === $value || '' === $value || 1 !== preg_match( '/^[a-z0-9_:.\-]+$/i', $key ) ) {
				continue;
			}
			if ( true === $value ) {
				$out .= ' ' . $key;
				continue;
			}
			$out .= ' ' . $key . '="' . esc_attr( (string) $value ) . '"';
		}
		return $out;
	}

	/**
	 * `<form>` opening tag for the `options.php` route, with the option group's hidden fields.
	 *
	 * @param string              $group Settings group.
	 * @param array<string,mixed> $o     savebar (bool, default true), novalidate (bool, default true),
	 *                                   form_field + form (hidden carrier), class, id.
	 */
	public static function open( string $group, array $o = array() ): string {
		ob_start();
		settings_fields( $group );
		$hidden = (string) ob_get_clean();
		$html   = '<form method="post" action="options.php"'
			. self::attrs(
				array(
					'class'      => $o['class'] ?? null,
					'id'         => $o['id'] ?? null,
					'data-savebar' => isset( $o['savebar'] ) && false === $o['savebar'] ? null : true,
					'novalidate' => isset( $o['novalidate'] ) && false === $o['novalidate'] ? null : true,
				)
			)
			. '>' . $hidden;
		if ( isset( $o['form_field'], $o['form'] ) ) {
			$html .= self::hidden( (string) $o['form_field'], (string) $o['form'] );
		}
		return $html;
	}

	/** A hidden input. */
	public static function hidden( string $name, string $value ): string {
		return '<input type="hidden"' . self::attrs(
			array(
				'name'  => $name,
				'value' => $value,
			)
		) . '>';
	}

	/**
	 * The hidden `0` twin of a checkbox: a browser posts `0` when the box is unchecked.
	 *
	 * @param string $name  Checkbox name.
	 * @param string $value Twin value.
	 */
	public static function twin( string $name, string $value = '0' ): string {
		return self::hidden( $name, $value );
	}

	/**
	 * An input. Everything the kit needs (mono, counters, hex pickers) is passed as attributes.
	 *
	 * @param array<string,mixed> $o type, id, name, value, class, maxlength, min, max, step, inputmode,
	 *                               placeholder, pattern, autocomplete, required, disabled, readonly, checked,
	 *                               aria_label, describedby, invalid (bool: aria-invalid), attrs.
	 */
	public static function input( array $o ): string {
		$attrs = array(
			'type'             => $o['type'] ?? 'text',
			'class'            => $o['class'] ?? null,
			'id'               => $o['id'] ?? null,
			'name'             => $o['name'] ?? null,
			'value'            => $o['value'] ?? null,
			'placeholder'      => $o['placeholder'] ?? null,
			'maxlength'        => $o['maxlength'] ?? null,
			'min'              => $o['min'] ?? null,
			'max'              => $o['max'] ?? null,
			'step'             => $o['step'] ?? null,
			'inputmode'        => $o['inputmode'] ?? null,
			'pattern'          => $o['pattern'] ?? null,
			'autocomplete'     => $o['autocomplete'] ?? null,
			'required'         => ! empty( $o['required'] ) ? true : null,
			'disabled'         => ! empty( $o['disabled'] ) ? true : null,
			'readonly'         => ! empty( $o['readonly'] ) ? true : null,
			'checked'          => ! empty( $o['checked'] ) ? 'checked' : null,
			'aria-label'       => $o['aria_label'] ?? null,
			'aria-describedby' => $o['describedby'] ?? null,
			'aria-invalid'     => ! empty( $o['invalid'] ) ? 'true' : null,
		);
		return '<input' . self::attrs( array_merge( $attrs, (array) ( $o['attrs'] ?? array() ) ) ) . '>';
	}

	/**
	 * A checkbox with a `role="switch"` label (kit pattern). Optional hidden `0` twin.
	 *
	 * @param array<string,mixed> $o name, value (default '1'), on, title, help, help_id, twin, disabled,
	 *                               label_class (default 'rbn-switch'), box_class (default 'rbn-switch__x'),
	 *                               text_class (default 'rbn-switch__t'), wrap (default true), attrs.
	 */
	public static function switch( array $o ): string {
		$name  = (string) ( $o['name'] ?? '' );
		$attrs = array(
			'type'             => 'checkbox',
			'role'             => $o['role'] ?? 'switch',
			'name'             => $name,
			'value'            => $o['value'] ?? '1',
			'checked'          => ! empty( $o['on'] ) ? 'checked' : null,
			'disabled'         => ! empty( $o['disabled'] ) ? true : null,
			'aria-describedby' => $o['help_id'] ?? null,
			'aria-label'       => $o['aria_label'] ?? null,
		);
		$html  = ! empty( $o['twin'] ) ? self::twin( $name, (string) ( $o['twin_value'] ?? '0' ) ) : '';
		$html .= '<input' . self::attrs( array_merge( $attrs, (array) ( $o['attrs'] ?? array() ) ) ) . '>'
			. '<span class="' . esc_attr( (string) ( $o['box_class'] ?? 'rbn-switch__x' ) ) . '" aria-hidden="true"></span>'
			. '<span class="' . esc_attr( (string) ( $o['text_class'] ?? 'rbn-switch__t' ) ) . '">'
			. '<b>' . esc_html( (string) ( $o['title'] ?? '' ) ) . '</b>';
		if ( '' !== (string) ( $o['help'] ?? '' ) ) {
			$html .= '<span class="rbn-help"' . ( isset( $o['help_id'] ) ? ' id="' . esc_attr( (string) $o['help_id'] ) . '"' : '' ) . '>' . esc_html( (string) $o['help'] ) . '</span>';
		}
		$html .= '</span>';
		if ( isset( $o['wrap'] ) && false === $o['wrap'] ) {
			return $html;
		}
		return '<label class="' . esc_attr( (string) ( $o['label_class'] ?? 'rbn-switch' ) ) . '">' . $html . '</label>';
	}

	/**
	 * A plain checkbox (hidden twin supported).
	 *
	 * @param array<string,mixed> $o name, value, checked, twin, disabled, attrs.
	 */
	public static function checkbox( array $o ): string {
		$name = (string) ( $o['name'] ?? '' );
		$html = ! empty( $o['twin'] ) ? self::twin( $name, (string) ( $o['twin_value'] ?? '0' ) ) : '';
		return $html . self::input(
			array(
				'type'    => 'checkbox',
				'name'    => $name,
				'value'   => $o['value'] ?? '1',
				'id'      => $o['id'] ?? null,
				'class'   => $o['class'] ?? null,
				'checked' => ! empty( $o['checked'] ),
				'disabled' => ! empty( $o['disabled'] ),
				'attrs'   => $o['attrs'] ?? array(),
			)
		);
	}

	/**
	 * A `<textarea>`; the value is escaped with `esc_textarea` so line breaks survive.
	 *
	 * @param array<string,mixed> $o id, name, value, class, rows, maxlength, placeholder, describedby,
	 *                               invalid, attrs.
	 */
	public static function textarea( array $o ): string {
		$attrs = array(
			'class'            => $o['class'] ?? null,
			'id'               => $o['id'] ?? null,
			'name'             => $o['name'] ?? null,
			'rows'             => $o['rows'] ?? null,
			'maxlength'        => $o['maxlength'] ?? null,
			'placeholder'      => $o['placeholder'] ?? null,
			'aria-describedby' => $o['describedby'] ?? null,
			'aria-invalid'     => ! empty( $o['invalid'] ) ? 'true' : null,
		);
		return '<textarea' . self::attrs( array_merge( $attrs, (array) ( $o['attrs'] ?? array() ) ) ) . '>' . esc_textarea( (string) ( $o['value'] ?? '' ) ) . '</textarea>';
	}

	/**
	 * A `<select>`.
	 *
	 * @param array<string,mixed> $o id, name, value, options (value => label), empty (placeholder label),
	 *                               class, describedby, invalid, disabled, attrs.
	 */
	public static function select( array $o ): string {
		$current = (string) ( $o['value'] ?? '' );
		$attrs   = array(
			'class'            => $o['class'] ?? null,
			'id'               => $o['id'] ?? null,
			'name'             => $o['name'] ?? null,
			'aria-describedby' => $o['describedby'] ?? null,
			'aria-invalid'     => ! empty( $o['invalid'] ) ? 'true' : null,
			'disabled'         => ! empty( $o['disabled'] ) ? true : null,
		);
		$html    = '<select' . self::attrs( array_merge( $attrs, (array) ( $o['attrs'] ?? array() ) ) ) . '>';
		if ( isset( $o['empty'] ) ) {
			$html .= '<option value="">' . esc_html( (string) $o['empty'] ) . '</option>';
		}
		foreach ( (array) ( $o['options'] ?? array() ) as $value => $label ) {
			$html .= '<option' . self::attrs(
				array(
					'value'    => (string) $value,
					'selected' => (string) $value === $current ? 'selected' : null,
				)
			) . '>' . esc_html( (string) $label ) . '</option>';
		}
		return $html . '</select>';
	}

	/**
	 * A field error: the message `<p>` the kit screens show under the input.
	 *
	 * @param string $id      Field id (`f-days`); the `<p>` gets `<id>-e`.
	 * @param string $message Message.
	 * @param string $icon    Leading icon markup (already escaped by the icon helper).
	 */
	public static function error( string $id, string $message, string $icon = '' ): string {
		return '<p class="rbn-field__err" id="' . esc_attr( $id ) . '-e">' . $icon . '<span>' . esc_html( $message ) . '</span></p>';
	}

	/**
	 * The whole field wrapper class, with the error state.
	 *
	 * @param string $id    Field id.
	 * @param array<string,string> $errors Field id => message.
	 */
	public static function field_class( string $id, array $errors ): string {
		return isset( $errors[ $id ] ) ? 'rbn-field rbn-field--err' : 'rbn-field';
	}

	/**
	 * `aria-invalid` + `aria-describedby` for a field that has an error.
	 *
	 * @param string               $id     Field id.
	 * @param array<string,string> $errors Field id => message.
	 */
	public static function invalid_attr( string $id, array $errors ): string {
		return isset( $errors[ $id ] ) ? ' aria-invalid="true" aria-describedby="' . esc_attr( $id ) . '-e"' : '';
	}
}
