<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Generator {

	/**
	 * Guard flag to prevent recursive self-fetch within the same process.
	 */
	private static bool $is_generating = false;

	private Mako_Content_Converter $converter;
	private Mako_Type_Detector $type_detector;
	private Mako_Entity_Extractor $entity_extractor;
	private Mako_Link_Extractor $link_extractor;
	private Mako_Action_Extractor $action_extractor;
	private Mako_Frontmatter $frontmatter;
	private Mako_Token_Counter $token_counter;
	private Mako_Validator $validator;

	/**
	 * Section templates per content type.
	 */
	private const SECTION_MAP = array(
		'product' => array( 'Key Facts', 'Context', 'Reviews Summary' ),
		'article' => array( 'Summary', 'Key Points', 'Context' ),
		'docs'    => array( 'Overview', 'Usage', 'Parameters/API', 'See Also' ),
		'landing' => array( 'What It Does', 'Key Features', 'Pricing', 'Alternatives' ),
		'listing' => array( 'Items', 'Filters Available' ),
		'profile' => array( 'About', 'Key Information', 'Notable Work' ),
		'event'   => array( 'Details', 'Description', 'Registration' ),
		'recipe'  => array( 'Ingredients', 'Steps', 'Notes' ),
	);

	public function __construct() {
		$this->converter        = new Mako_Content_Converter();
		$this->type_detector    = new Mako_Type_Detector();
		$this->entity_extractor = new Mako_Entity_Extractor();
		$this->link_extractor   = new Mako_Link_Extractor();
		$this->action_extractor = new Mako_Action_Extractor();
		$this->frontmatter      = new Mako_Frontmatter();
		$this->token_counter    = new Mako_Token_Counter();
		$this->validator        = new Mako_Validator();
	}

	/**
	 * Generate MAKO content for a WordPress post.
	 *
	 * @return array{content: string, headers: array, frontmatter: array, tokens: int, html_tokens: int, savings: float}|null
	 */
	public function generate( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}

		$site_url = home_url();

		// Step 1: Get rendered HTML content.
		$html = $this->get_rendered_content( $post );

		// Step 2: Count HTML tokens.
		$html_tokens = '' !== trim( $html ) ? $this->token_counter->count_html( $html ) : 0;

		// Step 3-4: Convert HTML to clean Markdown.
		$markdown = '' !== trim( $html ) ? $this->converter->convert( $html, $site_url ) : '';

		// For WooCommerce products with empty content, generate from metadata.
		if ( '' === trim( $markdown ) && 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$markdown = $post->post_title;
		} elseif ( '' === trim( $markdown ) ) {
			return null;
		}

		// Step 5: Detect content type.
		$type = $this->type_detector->detect( $post, $markdown );

		// Step 6: Extract entity name.
		$entity = $this->entity_extractor->extract( $post );

		// Step 7: Extract semantic links.
		$links = $this->link_extractor->extract( $html, $site_url );

		// Step 8: Extract actions/CTAs.
		$actions = $this->action_extractor->extract( $html );

		// Step 9: Detect language.
		$language = $this->detect_language( $post );

		// Step 10: Build MAKO body.
		$body = $this->build_body( $entity, $markdown, $type );

		// Count MAKO tokens.
		$mako_tokens = $this->token_counter->count( $body );

		// Enforce max tokens.
		$max_tokens = (int) get_option( 'mako_max_tokens', 1000 );
		if ( $mako_tokens > $max_tokens ) {
			$body        = $this->truncate_body( $body, $max_tokens );
			$mako_tokens = $this->token_counter->count( $body );
		}

		// Build summary.
		$summary = $this->derive_summary( $post, $markdown );

		// Build tags.
		$tags = $this->get_tags( $post );

		// Determine freshness.
		$freshness = $this->detect_freshness( $post );

		// Updated date.
		$updated = gmdate( 'Y-m-d', strtotime( $post->post_modified_gmt ) );

		// Canonical URL.
		$canonical = get_permalink( $post_id );

		// Build frontmatter data.
		$fm_data = array(
			'mako'      => MAKO_SPEC_VERSION,
			'type'      => $type,
			'entity'    => $entity,
			'updated'   => $updated,
			'tokens'    => $mako_tokens,
			'language'  => $language,
			'summary'   => $summary,
			'freshness' => $freshness,
			'canonical' => $canonical,
			'tags'      => $tags,
			'actions'   => $actions,
			'links'     => $links,
		);

		$fm_data = apply_filters( 'mako_frontmatter', $fm_data, $post );

		// Validate.
		$validation = $this->validator->validate( $fm_data, $body );
		if ( ! $validation['valid'] ) {
			// Log errors but still generate (best-effort).
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'MAKO validation errors for post ' . $post_id . ': ' . implode( ', ', $validation['errors'] ) );
			}
		}

		// Assemble full MAKO file.
		$frontmatter_str = $this->frontmatter->build( $fm_data );
		$body            = apply_filters( 'mako_body', $body, $post );
		$content         = $frontmatter_str . "\n" . $body;
		$content         = apply_filters( 'mako_content', $content, $post );

		// Build HTTP headers.
		$headers = Mako_Headers::build( $fm_data, $canonical );
		$headers = apply_filters( 'mako_response_headers', $headers, $post );

		// Calculate savings.
		$savings = $this->token_counter->savings_percent( $html_tokens, $mako_tokens );

		return array(
			'content'     => $content,
			'headers'     => $headers,
			'frontmatter' => $fm_data,
			'tokens'      => $mako_tokens,
			'html_tokens' => $html_tokens,
			'savings'     => $savings,
			'type'        => $type,
			'validation'  => $validation,
		);
	}

	/**
	 * Bulk generate MAKO for multiple posts.
	 */
	public function bulk_generate( array $post_ids, ?callable $progress = null ): array {
		$results = array();
		$total   = count( $post_ids );

		foreach ( $post_ids as $i => $post_id ) {
			$results[ $post_id ] = $this->generate( (int) $post_id );

			if ( $progress ) {
				$progress( $post_id, $i + 1, $total );
			}

			do_action( 'mako_bulk_progress', $post_id, $i + 1, $total );
		}

		return $results;
	}

	/**
	 * Get rendered HTML by fetching the public URL (self-fetch).
	 *
	 * This captures the final rendered output including page builders,
	 * shortcodes, theme templates, and any plugin modifications —
	 * exactly what a browser or LLM agent would see.
	 */
	private function get_rendered_content( WP_Post $post ): string {
		if ( self::$is_generating ) {
			return '';
		}

		$url = get_permalink( $post->ID );
		if ( ! $url ) {
			return $this->get_rendered_content_fallback( $post );
		}

		self::$is_generating = true;

		$args = array(
			'timeout'     => (int) apply_filters( 'mako_self_fetch_timeout', 30 ),
			'headers'     => array(
				'Accept' => 'text/html',
			),
			'user-agent'  => 'MAKO-Generator/' . MAKO_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
			'sslverify'   => (bool) apply_filters( 'mako_self_fetch_sslverify', ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ),
			'redirection' => 5,
		);

		$response = wp_remote_get( $url, $args );

		self::$is_generating = false;

		if ( is_wp_error( $response ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'MAKO self-fetch failed for post ' . $post->ID . ': ' . $response->get_error_message() );
			}
			return $this->get_rendered_content_fallback( $post );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'MAKO self-fetch got HTTP ' . $status . ' for post ' . $post->ID );
			}
			return $this->get_rendered_content_fallback( $post );
		}

		$html = wp_remote_retrieve_body( $response );
		if ( '' === trim( $html ) ) {
			return $this->get_rendered_content_fallback( $post );
		}

		return $html;
	}

	/**
	 * Fallback: render content from WordPress internals when self-fetch fails.
	 */
	private function get_rendered_content_fallback( WP_Post $post ): string {
		$content = $post->post_content;
		$content = apply_filters( 'the_content', $content );

		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				$short = $product->get_short_description();
				if ( $short ) {
					$content = '<div class="short-description">' . $short . '</div>' . $content;
				}
			}
		}

		return $content;
	}

	/**
	 * Build the markdown body with section structure.
	 */
	private function build_body( string $entity, string $markdown, string $type ): string {
		// Check if markdown already has sufficient heading structure.
		$heading_count = preg_match_all( '/^#{1,6}\s/m', $markdown );
		if ( $heading_count >= 2 ) {
			// Already structured, use as-is but ensure title heading.
			if ( ! preg_match( '/^#\s/', $markdown ) ) {
				$markdown = '# ' . $entity . "\n\n" . $markdown;
			}
			return $markdown;
		}

		// Build structured body using section templates.
		$sections = self::SECTION_MAP[ $type ] ?? null;
		if ( ! $sections ) {
			// No template (faq, custom): just add title and use markdown as-is.
			return '# ' . $entity . "\n\n" . $markdown;
		}

		$sections = apply_filters( 'mako_section_template', $sections, $type );

		// Split markdown into paragraphs.
		$paragraphs = preg_split( '/\n{2,}/', trim( $markdown ), -1, PREG_SPLIT_NO_EMPTY );
		$paragraphs = array_values( array_filter( $paragraphs, fn( $p ) => '' !== trim( $p ) ) );

		$body = '# ' . $entity . "\n";

		if ( ! empty( $paragraphs ) ) {
			// Distribute paragraphs across sections.
			$per_section = max( 1, (int) ceil( count( $paragraphs ) / count( $sections ) ) );
			$chunks      = array_chunk( $paragraphs, $per_section );

			foreach ( $sections as $i => $section_title ) {
				$body .= "\n## " . $section_title . "\n";
				if ( isset( $chunks[ $i ] ) ) {
					$body .= "\n" . implode( "\n\n", $chunks[ $i ] ) . "\n";
				}
			}
		} else {
			foreach ( $sections as $section_title ) {
				$body .= "\n## " . $section_title . "\n\n";
			}
		}

		return $body;
	}

	/**
	 * Truncate body to fit within max token limit.
	 */
	private function truncate_body( string $body, int $max_tokens ): string {
		$lines  = explode( "\n", $body );
		$result = '';

		foreach ( $lines as $line ) {
			$test = $result . $line . "\n";
			if ( $this->token_counter->count( $test ) > $max_tokens ) {
				break;
			}
			$result = $test;
		}

		return rtrim( $result );
	}

	/**
	 * Derive a summary from post excerpt or content.
	 */
	private function derive_summary( WP_Post $post, string $markdown ): string {
		$max = 160;

		// Prefer excerpt.
		if ( get_option( 'mako_use_excerpt', true ) && '' !== trim( $post->post_excerpt ) ) {
			$summary = wp_strip_all_tags( $post->post_excerpt );
			$summary = preg_replace( '/\s+/', ' ', trim( $summary ) );
			if ( mb_strlen( $summary ) > $max ) {
				$summary = mb_substr( $summary, 0, $max - 3 ) . '...';
			}
			return $summary;
		}

		// Fallback: first paragraph from markdown.
		$paragraphs = preg_split( '/\n{2,}/', trim( $markdown ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $paragraphs as $para ) {
			$clean = preg_replace( '/^#{1,6}\s+/', '', $para ); // Strip heading markers.
			$clean = preg_replace( '/[*_\[\]()>`]/', '', $clean ); // Strip markdown formatting.
			$clean = preg_replace( '/\s+/', ' ', trim( $clean ) );

			if ( mb_strlen( $clean ) >= 20 ) {
				if ( mb_strlen( $clean ) > $max ) {
					return mb_substr( $clean, 0, $max - 3 ) . '...';
				}
				return $clean;
			}
		}

		return '';
	}

	/**
	 * Detect language from WordPress locale.
	 */
	private function detect_language( WP_Post $post ): string {
		// Allow per-post language (WPML/Polylang integration via filter).
		$lang = apply_filters( 'mako_post_language', '', $post );
		if ( '' !== $lang ) {
			return $lang;
		}

		$locale = get_locale();
		// Extract language code: en_US → en.
		$parts = explode( '_', $locale );
		return strtolower( $parts[0] );
	}

	/**
	 * Get tags/categories for the post.
	 */
	private function get_tags( WP_Post $post ): array {
		if ( ! get_option( 'mako_include_tags', true ) ) {
			return array();
		}

		$tags = array();

		// WordPress tags.
		$post_tags = get_the_tags( $post->ID );
		if ( $post_tags && ! is_wp_error( $post_tags ) ) {
			foreach ( $post_tags as $tag ) {
				$tags[] = strtolower( $tag->name );
			}
		}

		// Categories.
		$categories = get_the_category( $post->ID );
		if ( $categories && ! is_wp_error( $categories ) ) {
			foreach ( $categories as $cat ) {
				if ( 'uncategorized' !== $cat->slug ) {
					$tags[] = strtolower( $cat->name );
				}
			}
		}

		// WooCommerce product categories.
		if ( 'product' === $post->post_type ) {
			$product_cats = get_the_terms( $post->ID, 'product_cat' );
			if ( $product_cats && ! is_wp_error( $product_cats ) ) {
				foreach ( $product_cats as $cat ) {
					$tags[] = strtolower( $cat->name );
				}
			}
		}

		return array_unique( array_slice( $tags, 0, 10 ) );
	}

	/**
	 * Detect content freshness based on post type and update frequency.
	 */
	private function detect_freshness( WP_Post $post ): string {
		$default = get_option( 'mako_freshness_default', 'weekly' );

		return match ( $post->post_type ) {
			'product' => 'daily',
			'post'    => $default,
			'page'    => 'monthly',
			default   => $default,
		};
	}
}
