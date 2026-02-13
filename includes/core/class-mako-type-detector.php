<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Type_Detector {

	/**
	 * Detect MAKO content type from a WordPress post.
	 *
	 * @return string One of: product, article, docs, landing, listing, profile, event, recipe, faq, custom
	 */
	public function detect( WP_Post $post, string $markdown = '' ): string {
		// Let developers override.
		$type = apply_filters( 'mako_content_type', null, $post, $markdown );
		if ( null !== $type ) {
			return $type;
		}

		// Direct mapping for known post types.
		$type = $this->detect_from_post_type( $post );

		// Heuristic refinement for pages.
		if ( 'landing' === $type && '' !== $markdown ) {
			$type = $this->refine_page_type( $post, $markdown );
		}

		return $type;
	}

	/**
	 * Map WordPress post type to MAKO content type.
	 */
	private function detect_from_post_type( WP_Post $post ): string {
		return match ( $post->post_type ) {
			'post'                => 'article',
			'page'                => 'landing',
			'product'             => 'product',
			'tribe_events',
			'event',
			'tribe_event'         => 'event',
			'recipe'              => 'recipe',
			'faq'                 => 'faq',
			'docs', 'document',
			'documentation',
			'knowledgebase',
			'kb', 'doc'           => 'docs',
			default               => 'custom',
		};
	}

	/**
	 * Refine page type using content heuristics.
	 */
	private function refine_page_type( WP_Post $post, string $markdown ): string {
		$slug    = $post->post_name;
		$title   = strtolower( $post->post_title );
		$content = strtolower( $markdown );

		// Docs detection: slug or title hints.
		$docs_slugs = array( 'docs', 'documentation', 'api', 'reference', 'guide', 'manual', 'handbook' );
		foreach ( $docs_slugs as $ds ) {
			if ( str_contains( $slug, $ds ) || str_contains( $title, $ds ) ) {
				return 'docs';
			}
		}

		// Docs detection: 3+ code blocks.
		if ( substr_count( $markdown, '```' ) >= 6 ) { // 6 backtick markers = 3+ code blocks.
			return 'docs';
		}

		// FAQ detection: many question marks.
		if ( substr_count( $content, '?' ) >= 5 ) {
			$faq_slugs = array( 'faq', 'frequently-asked', 'preguntas' );
			foreach ( $faq_slugs as $fs ) {
				if ( str_contains( $slug, $fs ) || str_contains( $title, $fs ) ) {
					return 'faq';
				}
			}
		}

		// Profile: about page.
		$profile_slugs = array( 'about', 'about-us', 'about-me', 'team', 'author', 'sobre-nosotros' );
		foreach ( $profile_slugs as $ps ) {
			if ( $slug === $ps ) {
				return 'profile';
			}
		}

		// Listing: has many links or list items.
		$list_count = substr_count( $markdown, "\n- " ) + substr_count( $markdown, "\n1. " );
		if ( $list_count >= 10 ) {
			$listing_slugs = array( 'directory', 'listing', 'catalog', 'index', 'resources' );
			foreach ( $listing_slugs as $ls ) {
				if ( str_contains( $slug, $ls ) ) {
					return 'listing';
				}
			}
		}

		return 'landing';
	}

	/**
	 * Get all valid MAKO content types.
	 */
	public static function valid_types(): array {
		return array(
			'product', 'article', 'docs', 'landing', 'listing',
			'profile', 'event', 'recipe', 'faq', 'custom',
		);
	}
}
