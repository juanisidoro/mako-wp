<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Entity_Extractor {

	private const MAX_LENGTH = 100;

	/**
	 * Extract the primary entity name for a post.
	 */
	public function extract( WP_Post $post ): string {
		// 1. Try SEO title (Yoast / Rank Math) via filter.
		$seo_title = apply_filters( 'mako_entity_seo_title', '', $post );
		if ( '' !== $seo_title ) {
			return $this->truncate( $seo_title );
		}

		// 2. Post title (primary source for WordPress).
		$title = $post->post_title;
		if ( '' !== trim( $title ) ) {
			return $this->truncate( $this->clean_title( $title ) );
		}

		// 3. Fallback.
		return 'Unknown';
	}

	/**
	 * Clean title by removing site name suffixes.
	 */
	private function clean_title( string $title ): string {
		// Remove patterns like "Title | Site Name", "Title - Company", "Title :: Store".
		$cleaned = preg_replace( '/\s*[|\-\x{2013}\x{2014}:]{1,2}\s*[^|\-\x{2013}\x{2014}:]+$/u', '', $title );
		return trim( $cleaned ?: $title );
	}

	/**
	 * Truncate to max length with ellipsis.
	 */
	private function truncate( string $text ): string {
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );

		if ( mb_strlen( $text ) <= self::MAX_LENGTH ) {
			return $text;
		}

		return mb_substr( $text, 0, self::MAX_LENGTH - 3 ) . '...';
	}
}
