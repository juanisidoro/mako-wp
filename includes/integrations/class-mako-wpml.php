<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPML and Polylang integration for per-language MAKO generation.
 */
class Mako_WPML {

	/**
	 * Check if WPML is active.
	 */
	public static function is_wpml_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'icl_object_id' );
	}

	/**
	 * Check if Polylang is active.
	 */
	public static function is_polylang_active(): bool {
		return function_exists( 'pll_get_post_language' );
	}

	/**
	 * Check if any multilingual plugin is active.
	 */
	public static function is_active(): bool {
		return self::is_wpml_active() || self::is_polylang_active();
	}

	/**
	 * Register multilingual hooks.
	 */
	public function register(): void {
		if ( ! self::is_active() ) {
			return;
		}

		// Provide per-post language detection.
		add_filter( 'mako_post_language', array( $this, 'detect_language' ), 10, 2 );

		// Regenerate translations when original changes.
		add_action( 'mako_generated', array( $this, 'sync_translations' ), 10, 3 );
	}

	/**
	 * Detect language for a specific post.
	 */
	public function detect_language( string $lang, WP_Post $post ): string {
		if ( '' !== $lang ) {
			return $lang;
		}

		// WPML.
		if ( self::is_wpml_active() ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post->ID );
			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
				return $details['language_code'];
			}
		}

		// Polylang.
		if ( self::is_polylang_active() ) {
			$pll_lang = pll_get_post_language( $post->ID, 'slug' );
			if ( $pll_lang ) {
				return $pll_lang;
			}
		}

		return '';
	}

	/**
	 * When MAKO is generated for a post, check if translations also need regeneration.
	 */
	public function sync_translations( int $post_id, string $content, array $headers ): void {
		$translations = $this->get_translations( $post_id );

		foreach ( $translations as $translation_id ) {
			if ( $translation_id === $post_id ) {
				continue;
			}

			$storage = new Mako_Storage();
			if ( $storage->needs_regeneration( $translation_id ) ) {
				// Schedule async regeneration for translations.
				if ( ! wp_next_scheduled( 'mako_regenerate_post', array( $translation_id ) ) ) {
					wp_schedule_single_event( time() + 10, 'mako_regenerate_post', array( $translation_id ) );
				}
			}
		}
	}

	/**
	 * Get all translation IDs for a post.
	 */
	private function get_translations( int $post_id ): array {
		$ids = array();

		// WPML.
		if ( self::is_wpml_active() ) {
			$trid = apply_filters( 'wpml_element_trid', null, $post_id );
			if ( $trid ) {
				$translations = apply_filters( 'wpml_get_element_translations', array(), $trid );
				if ( is_array( $translations ) ) {
					foreach ( $translations as $t ) {
						if ( isset( $t->element_id ) ) {
							$ids[] = (int) $t->element_id;
						}
					}
				}
			}
		}

		// Polylang.
		if ( self::is_polylang_active() && function_exists( 'pll_get_post_translations' ) ) {
			$translations = pll_get_post_translations( $post_id );
			if ( is_array( $translations ) ) {
				$ids = array_merge( $ids, array_map( 'intval', array_values( $translations ) ) );
			}
		}

		return array_unique( $ids );
	}
}
