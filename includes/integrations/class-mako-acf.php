<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_ACF {

	/**
	 * Check if ACF is active.
	 */
	public static function is_active(): bool {
		return class_exists( 'ACF' ) || function_exists( 'get_fields' );
	}

	/**
	 * Register ACF integration hooks.
	 */
	public function register(): void {
		if ( ! self::is_active() ) {
			return;
		}

		// Enrich body with ACF fields.
		add_filter( 'mako_body', array( $this, 'enrich_body' ), 15, 2 );
	}

	/**
	 * Append relevant ACF fields to the MAKO body.
	 */
	public function enrich_body( string $body, WP_Post $post ): string {
		if ( ! function_exists( 'get_fields' ) ) {
			return $body;
		}

		$fields = get_fields( $post->ID );
		if ( ! $fields || ! is_array( $fields ) ) {
			return $body;
		}

		$extra = '';

		foreach ( $fields as $name => $value ) {
			// Skip private/internal fields.
			if ( str_starts_with( $name, '_' ) ) {
				continue;
			}

			// Skip non-displayable types.
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			// Convert field name to readable label.
			$label = ucfirst( str_replace( array( '_', '-' ), ' ', $name ) );

			// Skip if content already contains this data.
			if ( str_contains( $body, $value ) ) {
				continue;
			}

			$extra .= "- {$label}: {$value}\n";
		}

		if ( '' !== $extra ) {
			$body .= "\n\n## Additional Information\n" . $extra;
		}

		return $body;
	}
}
