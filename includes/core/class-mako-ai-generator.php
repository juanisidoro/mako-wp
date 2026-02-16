<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI-enhanced MAKO generation using external LLM providers (BYOK).
 *
 * The plugin provides the prompt + spec. The user provides the API key.
 * The AI adapts the real page content to the MAKO standard.
 */
class Mako_AI_Generator {

	/**
	 * Supported AI providers.
	 */
	private const PROVIDERS = array(
		'openai'    => array(
			'label'    => 'OpenAI',
			'endpoint' => 'https://api.openai.com/v1/chat/completions',
			'models'   => array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4.1-nano' ),
			'default'  => 'gpt-4o-mini',
		),
		'anthropic' => array(
			'label'    => 'Anthropic',
			'endpoint' => 'https://api.anthropic.com/v1/messages',
			'models'   => array( 'claude-haiku-4-5-20251001', 'claude-sonnet-4-5-20250929' ),
			'default'  => 'claude-haiku-4-5-20251001',
		),
	);

	/**
	 * Token limits per content type.
	 */
	private const TOKEN_LIMITS = array(
		'product' => array( 200, 400 ),
		'article' => array( 300, 500 ),
		'landing' => array( 300, 500 ),
		'listing' => array( 200, 400 ),
		'recipe'  => array( 300, 500 ),
		'profile' => array( 150, 300 ),
		'event'   => array( 200, 300 ),
		'docs'    => array( 300, 500 ),
		'faq'     => array( 200, 400 ),
		'custom'  => array( 200, 500 ),
	);

	/**
	 * Generate MAKO content using AI.
	 *
	 * @param int    $post_id The WordPress post ID.
	 * @param string $current_mako The current auto-generated MAKO content (as base).
	 * @return array{content: string, model: string, provider: string, usage: array}|WP_Error
	 */
	public function generate( int $post_id, string $current_mako = '' ) {
		$provider = get_option( 'mako_ai_provider', '' );
		$api_key  = get_option( 'mako_ai_api_key', '' );
		$model    = get_option( 'mako_ai_model', '' );

		if ( '' === $provider || '' === $api_key ) {
			return new WP_Error( 'mako_ai_not_configured', 'AI provider not configured. Add your API key in Settings > MAKO.' );
		}

		if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
			return new WP_Error( 'mako_ai_invalid_provider', 'Unknown AI provider: ' . $provider );
		}

		$config = self::PROVIDERS[ $provider ];
		if ( '' === $model ) {
			$model = $config['default'];
		}

		// Get the post and its content.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'mako_ai_invalid_post', 'Post not found.' );
		}

		// Build the prompt.
		$prompt = $this->build_prompt( $post, $current_mako );

		// Call the AI provider.
		$result = match ( $provider ) {
			'openai'    => $this->call_openai( $api_key, $model, $prompt, $config['endpoint'] ),
			'anthropic' => $this->call_anthropic( $api_key, $model, $prompt, $config['endpoint'] ),
			default     => new WP_Error( 'mako_ai_unsupported', 'Provider not supported.' ),
		};

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'content'  => $result['content'],
			'model'    => $model,
			'provider' => $provider,
			'usage'    => $result['usage'],
		);
	}

	/**
	 * Build the system and user prompts.
	 *
	 * @return array{system: string, user: string}
	 */
	private function build_prompt( WP_Post $post, string $current_mako ): array {
		$type_detector = new Mako_Type_Detector();
		$type          = $type_detector->detect( $post, '' );
		$limits        = self::TOKEN_LIMITS[ $type ] ?? self::TOKEN_LIMITS['custom'];
		$permalink     = get_permalink( $post->ID );

		// Get clean rendered content instead of raw shortcodes/HTML.
		$clean_content = $this->get_clean_content( $post );

		$section_map = array(
			'product' => 'Key Facts (price, availability, brand, SKU), Shipping, Reviews Summary',
			'article' => 'Summary, Key Points, Context',
			'docs'    => 'Overview, Usage, Parameters/API, See Also',
			'landing' => 'What It Does, Key Features, Pricing, Alternatives',
			'listing' => 'Items, Filters Available',
			'profile' => 'About, Key Information, Notable Work',
			'event'   => 'Details, Description, Registration',
			'recipe'  => 'Ingredients, Steps, Notes',
			'faq'     => 'Q&A pairs as ## headings',
			'custom'  => 'Adapt sections to content',
		);
		$sections = $section_map[ $type ] ?? $section_map['custom'];

		$system = <<<SYSTEM
You are a MAKO content specialist. You generate MAKO files following the MAKO v1.0 standard.

A MAKO file is a UTF-8 markdown document with YAML frontmatter that provides an AI-optimized representation of a web page.

## Required frontmatter fields
- mako: "1.0" (MUST be quoted string, not number)
- type: one of product|article|docs|landing|listing|profile|event|recipe|faq|custom
- entity: primary entity name (max 100 chars)
- updated: ISO 8601 datetime
- tokens: approximate token count of the BODY only (not frontmatter). Calculate as: word_count × 1.3
- language: BCP 47 code

## Optional frontmatter fields
- summary: max 160 chars. Answer: "If a user asks an AI about this page, what should it respond?" Must describe the entity itself (what it is, key features, price if applicable). NOT a shipping policy, marketing slogan, or generic text.
- freshness: realtime|daily|weekly|monthly|static
- canonical: URL of the HTML version
- media: cover (url + alt) and counts (images, video, audio, interactive, downloads)
- tags: content categories
- actions: name + description + endpoint + method + params
- links: internal (url + context) and external (url + context)

## Body rules
1. Lead with the most important facts about the entity
2. Use ## headings to structure sections
3. Prefer bullet lists and key-value pairs over prose
4. Omit: navigation, legal boilerplate, UI chrome, marketing fluff, emojis, social media CTAs
5. Preserve ALL factual content: prices, specs, availability, shipping, dimensions, materials, reviews
6. Use the page language consistently (do NOT mix languages)
7. HTML entities must be decoded (use € not &euro;)
8. If a section has NO data from the source, OMIT IT entirely. Do NOT write "no data available" or similar.
9. For products: include ALL available attributes (size, color, material, weight, brand, SKU)

## Links format (spec-compliant)
```yaml
links:
  internal:
    - url: /path
      context: "Short description"
  external:
    - url: https://example.com
      context: "Short description"
```
Note: links only have url + context. No other fields.

## Output rules
1. Output ONLY the MAKO file. No explanations, no code fences, no preamble.
2. Start with --- and end frontmatter with ---
3. Do NOT invent facts not present in the source content.
4. You MUST recalculate the tokens field based on the actual body you generate. Do NOT copy it from the base.
5. The summary answers: "If a user asks an AI about this page, what should it respond?" Example for product: "Camiseta básica manga mini, talla única beige. €10. Agotado." Example for article: "Guide explaining how to set up MAKO content negotiation for WordPress sites."
SYSTEM;

		$user = "Generate a MAKO file for this page.\n\n";
		$user .= "- URL: {$permalink}\n";
		$user .= "- Title: {$post->post_title}\n";
		$user .= "- Type: {$type}\n";
		$user .= "- Language: {$this->get_language($post)}\n";
		$user .= "- Token target: {$limits[0]}-{$limits[1]} (max 1000)\n";
		$user .= "- Recommended sections: {$sections}\n";

		// Add structured product data for WooCommerce products.
		$product_context = $this->get_product_context( $post );
		if ( '' !== $product_context ) {
			$user .= "\n## Product Data (structured)\n\n{$product_context}\n";
		}

		$user .= "\n## Page Content\n\n{$clean_content}\n";

		if ( '' !== $current_mako ) {
			// Strip the tokens line so the AI is forced to recalculate.
			$base_mako = preg_replace( '/^tokens:\s*\d+\s*$/m', 'tokens: [CALCULATE]', $current_mako );
			$user .= "\n## Current MAKO (auto-generated base, improve it)\n\n{$base_mako}\n";
		}

		return array(
			'system' => $system,
			'user'   => $user,
		);
	}

	/**
	 * Extract structured product data from WooCommerce.
	 */
	private function get_product_context( WP_Post $post ): string {
		if ( 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return '';
		}

		$lines = array();

		// Basic info.
		$lines[] = '- Name: ' . $product->get_name();
		$lines[] = '- Price: €' . $product->get_price();

		if ( $product->get_regular_price() !== $product->get_sale_price() && $product->get_sale_price() ) {
			$lines[] = '- Regular price: €' . $product->get_regular_price();
			$lines[] = '- Sale price: €' . $product->get_sale_price();
		}

		$lines[] = '- In stock: ' . ( $product->is_in_stock() ? 'Yes' : 'No (Agotado)' );
		$lines[] = '- Stock status: ' . $product->get_stock_status();

		if ( $product->get_sku() ) {
			$lines[] = '- SKU: ' . $product->get_sku();
		}

		// Short description.
		$short_desc = $product->get_short_description();
		if ( $short_desc ) {
			$short_desc = wp_strip_all_tags( $short_desc );
			$short_desc = html_entity_decode( $short_desc, ENT_QUOTES, 'UTF-8' );
			$lines[] = '- Short description: ' . trim( $short_desc );
		}

		// Categories.
		$cats = wp_get_post_terms( $post->ID, 'product_cat', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) {
			$lines[] = '- Categories: ' . implode( ', ', $cats );
		}

		// Tags.
		$tags = wp_get_post_terms( $post->ID, 'product_tag', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
			$lines[] = '- Tags: ' . implode( ', ', $tags );
		}

		// Attributes.
		$attributes = $product->get_attributes();
		foreach ( $attributes as $attr ) {
			$name = wc_attribute_label( $attr->get_name() );
			if ( $attr->is_taxonomy() ) {
				$values = wc_get_product_terms( $post->ID, $attr->get_name(), array( 'fields' => 'names' ) );
				$value  = implode( ', ', $values );
			} else {
				$value = implode( ', ', $attr->get_options() );
			}
			if ( $value ) {
				$lines[] = "- {$name}: {$value}";
			}
		}

		// Weight and dimensions.
		if ( $product->get_weight() ) {
			$lines[] = '- Weight: ' . $product->get_weight() . ' ' . get_option( 'woocommerce_weight_unit', 'kg' );
		}

		$dims = $product->get_dimensions( false );
		if ( $dims && array_filter( $dims ) ) {
			$unit = get_option( 'woocommerce_dimension_unit', 'cm' );
			$parts = array();
			if ( $dims['length'] ) $parts[] = $dims['length'];
			if ( $dims['width'] )  $parts[] = $dims['width'];
			if ( $dims['height'] ) $parts[] = $dims['height'];
			if ( $parts ) {
				$lines[] = '- Dimensions: ' . implode( ' × ', $parts ) . ' ' . $unit;
			}
		}

		// Reviews.
		$review_count = $product->get_review_count();
		if ( $review_count > 0 ) {
			$lines[] = '- Average rating: ' . $product->get_average_rating() . '/5';
			$lines[] = '- Reviews: ' . $review_count;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Get clean rendered content for the post (strips shortcodes/builder HTML).
	 */
	private function get_clean_content( WP_Post $post ): string {
		// Try to use the rendered content (processes shortcodes, blocks, etc).
		$content = apply_filters( 'the_content', $post->post_content );

		// Strip HTML tags but keep structure with newlines.
		$content = preg_replace( '/<br\s*\/?>/i', "\n", $content );
		$content = preg_replace( '/<\/(?:p|div|li|h[1-6]|tr)>/i', "\n", $content );
		$content = wp_strip_all_tags( $content );

		// Clean up whitespace.
		$content = preg_replace( '/\n{3,}/', "\n\n", $content );
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );

		// Truncate to ~8000 chars to stay within API limits.
		if ( strlen( $content ) > 8000 ) {
			$content = substr( $content, 0, 8000 ) . "\n\n[Content truncated]";
		}

		return trim( $content );
	}

	/**
	 * Call OpenAI API.
	 *
	 * @return array{content: string, usage: array}|WP_Error
	 */
	private function call_openai( string $api_key, string $model, array $prompt, string $endpoint ): array|WP_Error {
		$response = wp_remote_post( $endpoint, array(
			'timeout' => 60,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => wp_json_encode( array(
				'model'       => $model,
				'messages'    => array(
					array( 'role' => 'system', 'content' => $prompt['system'] ),
					array( 'role' => 'user', 'content' => $prompt['user'] ),
				),
				'max_tokens'  => 2000,
				'temperature' => 0.3,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'mako_ai_request_failed', $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			$error_msg = $body['error']['message'] ?? 'API returned HTTP ' . $status;
			return new WP_Error( 'mako_ai_api_error', $error_msg );
		}

		$content = $body['choices'][0]['message']['content'] ?? '';
		$usage   = $body['usage'] ?? array();

		return array(
			'content' => $this->clean_ai_output( $content ),
			'usage'   => array(
				'input_tokens'  => $usage['prompt_tokens'] ?? 0,
				'output_tokens' => $usage['completion_tokens'] ?? 0,
				'total_tokens'  => $usage['total_tokens'] ?? 0,
			),
		);
	}

	/**
	 * Call Anthropic API.
	 *
	 * @return array{content: string, usage: array}|WP_Error
	 */
	private function call_anthropic( string $api_key, string $model, array $prompt, string $endpoint ): array|WP_Error {
		$response = wp_remote_post( $endpoint, array(
			'timeout' => 60,
			'headers' => array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			),
			'body'    => wp_json_encode( array(
				'model'      => $model,
				'max_tokens' => 2000,
				'system'     => $prompt['system'],
				'messages'   => array(
					array( 'role' => 'user', 'content' => $prompt['user'] ),
				),
				'temperature' => 0.3,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'mako_ai_request_failed', $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			$error_msg = $body['error']['message'] ?? 'API returned HTTP ' . $status;
			return new WP_Error( 'mako_ai_api_error', $error_msg );
		}

		$content = $body['content'][0]['text'] ?? '';
		$usage   = $body['usage'] ?? array();

		return array(
			'content' => $this->clean_ai_output( $content ),
			'usage'   => array(
				'input_tokens'  => $usage['input_tokens'] ?? 0,
				'output_tokens' => $usage['output_tokens'] ?? 0,
				'total_tokens'  => ( $usage['input_tokens'] ?? 0 ) + ( $usage['output_tokens'] ?? 0 ),
			),
		);
	}

	/**
	 * Clean AI output: remove code fences if present.
	 */
	private function clean_ai_output( string $content ): string {
		$content = trim( $content );

		// Remove ```yaml or ```markdown wrappers.
		$content = preg_replace( '/^```(?:yaml|markdown|mako)?\s*\n/i', '', $content );
		$content = preg_replace( '/\n```\s*$/', '', $content );

		// Ensure starts with ---.
		if ( ! str_starts_with( $content, '---' ) ) {
			$content = "---\n" . $content;
		}

		return trim( $content );
	}

	/**
	 * Get language for the post.
	 */
	private function get_language( WP_Post $post ): string {
		$lang = apply_filters( 'mako_post_language', '', $post );
		if ( '' !== $lang ) {
			return $lang;
		}
		$parts = explode( '_', get_locale() );
		return strtolower( $parts[0] );
	}

	/**
	 * Get available providers for settings UI.
	 */
	public static function get_providers(): array {
		return self::PROVIDERS;
	}
}
