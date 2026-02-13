<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Yoast {

	/**
	 * Check if Yoast SEO is active.
	 */
	public static function is_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Meta' );
	}

	/**
	 * Register Yoast integration hooks.
	 */
	public function register(): void {
		if ( ! self::is_active() ) {
			return;
		}

		// Provide SEO title for entity extraction.
		add_filter( 'mako_entity_seo_title', array( $this, 'get_seo_title' ), 10, 2 );

		// Enrich frontmatter with Yoast data.
		add_filter( 'mako_frontmatter', array( $this, 'enrich_frontmatter' ), 10, 2 );
	}

	/**
	 * Get Yoast SEO title for entity extraction.
	 */
	public function get_seo_title( string $title, WP_Post $post ): string {
		if ( '' !== $title ) {
			return $title; // Already set by another integration.
		}

		$seo_title = get_post_meta( $post->ID, '_yoast_wpseo_title', true );
		if ( $seo_title ) {
			// Strip Yoast variables like %%sitename%%.
			$cleaned = preg_replace( '/%%[a-z_]+%%/i', '', $seo_title );
			$cleaned = preg_replace( '/\s*[|\-\x{2013}\x{2014}]\s*$/u', '', trim( $cleaned ) );
			if ( '' !== $cleaned ) {
				return $cleaned;
			}
		}

		return '';
	}

	/**
	 * Enrich frontmatter with Yoast SEO metadata.
	 */
	public function enrich_frontmatter( array $fm, WP_Post $post ): array {
		// Summary from meta description.
		if ( empty( $fm['summary'] ) ) {
			$desc = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
			if ( $desc ) {
				$desc = preg_replace( '/%%[a-z_]+%%/i', '', $desc );
				$desc = preg_replace( '/\s+/', ' ', trim( $desc ) );
				if ( mb_strlen( $desc ) > 160 ) {
					$desc = mb_substr( $desc, 0, 157 ) . '...';
				}
				if ( '' !== $desc ) {
					$fm['summary'] = $desc;
				}
			}
		}

		// Canonical URL from Yoast.
		$canonical = get_post_meta( $post->ID, '_yoast_wpseo_canonical', true );
		if ( $canonical && empty( $fm['canonical'] ) ) {
			$fm['canonical'] = $canonical;
		}

		// Focus keyphrase as additional tag.
		$keyphrase = get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true );
		if ( $keyphrase ) {
			$tags   = $fm['tags'] ?? array();
			$tags[] = strtolower( $keyphrase );
			$fm['tags'] = array_unique( array_slice( $tags, 0, 10 ) );
		}

		return $fm;
	}
}
