<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_RankMath {

	/**
	 * Check if Rank Math is active.
	 */
	public static function is_active(): bool {
		return class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' );
	}

	/**
	 * Register Rank Math integration hooks.
	 */
	public function register(): void {
		if ( ! self::is_active() ) {
			return;
		}

		// Provide SEO title for entity extraction.
		add_filter( 'mako_entity_seo_title', array( $this, 'get_seo_title' ), 10, 2 );

		// Enrich frontmatter with Rank Math data.
		add_filter( 'mako_frontmatter', array( $this, 'enrich_frontmatter' ), 10, 2 );
	}

	/**
	 * Get Rank Math SEO title for entity extraction.
	 */
	public function get_seo_title( string $title, WP_Post $post ): string {
		if ( '' !== $title ) {
			return $title;
		}

		$seo_title = get_post_meta( $post->ID, 'rank_math_title', true );
		if ( $seo_title ) {
			// Strip Rank Math variables like %sitename%.
			$cleaned = preg_replace( '/%[a-z_]+%/i', '', $seo_title );
			$cleaned = preg_replace( '/\s*[|\-\x{2013}\x{2014}]\s*$/u', '', trim( $cleaned ) );
			if ( '' !== $cleaned ) {
				return $cleaned;
			}
		}

		return '';
	}

	/**
	 * Enrich frontmatter with Rank Math metadata.
	 */
	public function enrich_frontmatter( array $fm, WP_Post $post ): array {
		// Summary from meta description.
		if ( empty( $fm['summary'] ) ) {
			$desc = get_post_meta( $post->ID, 'rank_math_description', true );
			if ( $desc ) {
				$desc = preg_replace( '/%[a-z_]+%/i', '', $desc );
				$desc = preg_replace( '/\s+/', ' ', trim( $desc ) );
				if ( mb_strlen( $desc ) > 160 ) {
					$desc = mb_substr( $desc, 0, 157 ) . '...';
				}
				if ( '' !== $desc ) {
					$fm['summary'] = $desc;
				}
			}
		}

		// Canonical URL from Rank Math.
		$canonical = get_post_meta( $post->ID, 'rank_math_canonical_url', true );
		if ( $canonical && empty( $fm['canonical'] ) ) {
			$fm['canonical'] = $canonical;
		}

		// Focus keyword as additional tag.
		$keywords = get_post_meta( $post->ID, 'rank_math_focus_keyword', true );
		if ( $keywords ) {
			$tags     = $fm['tags'] ?? array();
			$kw_array = explode( ',', $keywords );
			foreach ( $kw_array as $kw ) {
				$kw = trim( strtolower( $kw ) );
				if ( '' !== $kw ) {
					$tags[] = $kw;
				}
			}
			$fm['tags'] = array_unique( array_slice( $tags, 0, 10 ) );
		}

		return $fm;
	}
}
