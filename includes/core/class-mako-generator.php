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
	 * Known AI bot user-agent strings.
	 */
	private const AI_BOTS = array(
		'GPTBot',
		'ClaudeBot',
		'PerplexityBot',
		'Google-Extended',
		'Bytespider',
		'CCBot',
		'ChatGPT-User',
		'anthropic-ai',
		'Applebot-Extended',
		'cohere-ai',
	);

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

		// Step 11: Extract media metadata (cover + counts).
		$media = $this->extract_media( $post, $html, $type );

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
			'media'     => $media,
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

		// If there's substantial content, use it directly with a title heading.
		$text_length = mb_strlen( preg_replace( '/\s+/', '', $markdown ) );
		if ( $text_length >= 50 ) {
			return '# ' . $entity . "\n\n" . $markdown;
		}

		// Very thin content: use section templates as scaffolding.
		$sections = self::SECTION_MAP[ $type ] ?? null;
		if ( ! $sections ) {
			return '# ' . $entity . "\n\n" . $markdown;
		}

		$sections = apply_filters( 'mako_section_template', $sections, $type );

		$body = '# ' . $entity . "\n";

		if ( '' !== trim( $markdown ) ) {
			$body .= "\n" . $markdown . "\n";
		}

		foreach ( $sections as $section_title ) {
			$body .= "\n## " . $section_title . "\n\n";
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
	 * Derive a summary from post excerpt, SEO meta, or content.
	 */
	private function derive_summary( WP_Post $post, string $markdown ): string {
		$max = 160;

		// 1. WooCommerce products: structured summary from product data.
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product_summary = $this->derive_product_summary( $post );
			if ( '' !== $product_summary ) {
				return $this->truncate_summary( $product_summary, $max );
			}
		}

		// 2. Post excerpt.
		if ( get_option( 'mako_use_excerpt', true ) && '' !== trim( $post->post_excerpt ) ) {
			return $this->truncate_summary( wp_strip_all_tags( $post->post_excerpt ), $max );
		}

		// 3. SEO meta description (Yoast, RankMath).
		$seo_desc = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
		if ( empty( $seo_desc ) ) {
			$seo_desc = get_post_meta( $post->ID, 'rank_math_description', true );
		}
		if ( ! empty( $seo_desc ) ) {
			return $this->truncate_summary( $seo_desc, $max );
		}

		// 4. First substantial text paragraph from markdown.
		$paragraphs = preg_split( '/\n{2,}/', trim( $markdown ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $paragraphs as $para ) {
			$trimmed = trim( $para );
			// Skip headings, images, links-only, prices, and very short lines.
			if ( preg_match( '/^#{1,6}\s/', $trimmed ) ) {
				continue;
			}
			if ( preg_match( '/^!\[/', $trimmed ) ) {
				continue;
			}
			$clean = preg_replace( '/!\[[^\]]*\]\([^)]*\)/', '', $para );
			$clean = preg_replace( '/\[[^\]]*\]\([^)]*\)/', '', $clean );
			$clean = preg_replace( '/[*_\[\]()>`#~=]/', '', $clean );
			$clean = preg_replace( '/\s+/', ' ', trim( $clean ) );

			// Skip prices and short product names.
			if ( preg_match( '/^\d+[,.]?\d*\s*€/', $clean ) ) {
				continue;
			}
			if ( mb_strlen( $clean ) >= 30 ) {
				return $this->truncate_summary( $clean, $max );
			}
		}

		// 5. Fallback: empty.
		return '';
	}

	/**
	 * Build a structured summary for WooCommerce products.
	 *
	 * Goal: answer "If a user asks an AI about this product, what should it respond?"
	 * Format: "{Name}. {Short description or key attribute}. €{Price}. {Stock status}."
	 */
	private function derive_product_summary( WP_Post $post ): string {
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return '';
		}

		$parts = array();

		// Product name.
		$parts[] = $product->get_name();

		// Short description (one sentence max).
		$short = wp_strip_all_tags( $product->get_short_description() );
		$short = html_entity_decode( $short, ENT_QUOTES, 'UTF-8' );
		$short = preg_replace( '/\s+/', ' ', trim( $short ) );
		if ( '' !== $short ) {
			// Take first sentence only.
			$dot = mb_strpos( $short, '.' );
			if ( false !== $dot && $dot < 120 ) {
				$short = mb_substr( $short, 0, $dot + 1 );
			} elseif ( mb_strlen( $short ) > 80 ) {
				$short = mb_substr( $short, 0, 77 ) . '...';
			}
			$parts[] = $short;
		}

		// Price.
		$price = $product->get_price();
		if ( '' !== $price ) {
			$currency = get_woocommerce_currency_symbol();
			if ( $product->is_on_sale() && $product->get_regular_price() ) {
				$parts[] = $currency . $product->get_sale_price() . ' (antes ' . $currency . $product->get_regular_price() . ')';
			} else {
				$parts[] = $currency . $price;
			}
		}

		// Stock status.
		if ( ! $product->is_in_stock() ) {
			$parts[] = 'Agotado';
		}

		return implode( '. ', $parts );
	}

	private function truncate_summary( string $text, int $max ): string {
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );
		if ( mb_strlen( $text ) > $max ) {
			return mb_substr( $text, 0, $max - 3 ) . '...';
		}
		return $text;
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
	 * Extract media metadata: cover image + content counts.
	 *
	 * @return array{cover?: array{url: string, alt: string}, images: int, video: int, audio: int, interactive: int}
	 */
	private function extract_media( WP_Post $post, string $html, string $type ): array {
		$media = array();

		// Extract cover image based on content type.
		$cover = $this->extract_cover( $post, $type );
		if ( $cover ) {
			$media['cover'] = $cover;
		}

		// Count media elements from HTML.
		if ( '' !== trim( $html ) ) {
			$media['images']      = $this->count_html_elements( $html, '<img' );
			$media['video']       = $this->count_html_elements( $html, '<video' )
									+ $this->count_html_elements( $html, '<iframe' );
			$media['audio']       = $this->count_html_elements( $html, '<audio' );
			$media['interactive'] = $this->count_html_elements( $html, '<canvas' )
									+ $this->count_html_elements( $html, '<form' );
		}

		// Remove zero counts.
		foreach ( array( 'images', 'video', 'audio', 'interactive' ) as $key ) {
			if ( isset( $media[ $key ] ) && 0 === $media[ $key ] ) {
				unset( $media[ $key ] );
			}
		}

		return $media;
	}

	/**
	 * Extract the cover image for a post based on its content type.
	 *
	 * @return array{url: string, alt: string}|null
	 */
	private function extract_cover( WP_Post $post, string $type ): ?array {
		$image_id = null;

		// WooCommerce product: use product featured image.
		if ( 'product' === $type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				$image_id = $product->get_image_id();
			}
		}

		// Fallback to WordPress featured image (post thumbnail).
		if ( ! $image_id ) {
			$image_id = get_post_thumbnail_id( $post->ID );
		}

		// For listings (taxonomy archives), try category thumbnail.
		if ( ! $image_id && 'listing' === $type ) {
			$terms = get_the_terms( $post->ID, 'product_cat' );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$term_id  = $terms[0]->term_id;
				$image_id = get_term_meta( $term_id, 'thumbnail_id', true );
			}
		}

		if ( ! $image_id ) {
			return null;
		}

		$url = wp_get_attachment_url( (int) $image_id );
		if ( ! $url ) {
			return null;
		}

		$alt = get_post_meta( (int) $image_id, '_wp_attachment_image_alt', true );
		if ( empty( $alt ) ) {
			$alt = $post->post_title;
		}

		return array(
			'url' => $url,
			'alt' => $alt,
		);
	}

	/**
	 * Count occurrences of an HTML tag in content.
	 */
	private function count_html_elements( string $html, string $tag ): int {
		return substr_count( strtolower( $html ), strtolower( $tag ) );
	}

	/**
	 * Check if the current request is from a known AI bot.
	 */
	public static function is_ai_bot(): bool {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		if ( '' === $user_agent ) {
			return false;
		}

		foreach ( self::AI_BOTS as $bot ) {
			if ( str_contains( $user_agent, $bot ) ) {
				return true;
			}
		}

		return false;
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
