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
	 * @return array{content: string, model: string, provider: string}|WP_Error
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
			'content'  => $result,
			'model'    => $model,
			'provider' => $provider,
		);
	}

	/**
	 * Build the master prompt with MAKO spec, best practices, and page content.
	 */
	private function build_prompt( WP_Post $post, string $current_mako ): string {
		$type_detector = new Mako_Type_Detector();
		$type          = $type_detector->detect( $post, '' );
		$limits        = self::TOKEN_LIMITS[ $type ] ?? self::TOKEN_LIMITS['custom'];
		$permalink     = get_permalink( $post->ID );

		$prompt = <<<PROMPT
You are a MAKO content specialist. Generate a MAKO file for the following web page.

## MAKO Standard (v1.0)

A MAKO file is a UTF-8 markdown document with YAML frontmatter. It provides an AI-optimized representation of a web page.

### Required frontmatter fields:
- mako: "1.0" (MUST be quoted string)
- type: {$type}
- entity: Primary entity name (max 100 chars)
- updated: ISO 8601 date
- tokens: Estimated token count of the body
- language: BCP 47 language code

### Optional frontmatter fields:
- summary: One-line summary (max 160 chars)
- media: cover image + media counts from the source page
- freshness: realtime | daily | weekly | monthly | static
- canonical: URL of the HTML version
- tags: Content tags/categories
- actions: Available actions (name + description + endpoint)
- links: Semantic links with context (internal + external)

### Body rules:
1. Lead with the most important information
2. Use structured sections with ## headings
3. Prefer lists and key-value pairs over prose
4. Include context and comparisons (competitors, alternatives, trade-offs)
5. Omit navigation, legal boilerplate, UI text, and marketing fluff
6. Token target for type "{$type}": {$limits[0]}-{$limits[1]} tokens. NEVER exceed 1000.

### Content type "{$type}" recommended sections:
PROMPT;

		$section_map = array(
			'product' => 'Key Facts, Context, Reviews Summary',
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
		$prompt  .= "\n{$sections}\n";

		$prompt .= <<<PROMPT

### Actions format:
```yaml
actions:
  - name: action_name (snake_case)
    description: "What this action does"
    endpoint: /api/endpoint (if known)
    method: POST
```

### Links format:
```yaml
links:
  internal:
    - url: /relative-path
      context: "Why this link is relevant"
      type: parent|child|sibling|reference
  external:
    - url: https://example.com
      context: "Why this link is relevant"
      type: source|competitor|reference
```

## Page Information

- URL: {$permalink}
- Title: {$post->post_title}
- Type: {$post->post_type}
- Language: {$this->get_language($post)}
- Updated: {$post->post_modified_gmt}

## Page Content

{$post->post_content}

PROMPT;

		if ( '' !== $current_mako ) {
			$prompt .= <<<PROMPT

## Current MAKO (auto-generated, use as base to improve)

{$current_mako}

PROMPT;
		}

		$prompt .= <<<PROMPT

## Instructions

Generate a complete, valid MAKO file (frontmatter + body) for this page. Rules:
1. Output ONLY the MAKO file. No explanations, no code fences, no commentary.
2. Start with --- and end the frontmatter with ---
3. Base the content ONLY on the actual page content provided above. Do NOT invent facts.
4. Keep within {$limits[0]}-{$limits[1]} tokens for the body.
5. Include semantic links and actions if applicable to this content type.
6. The summary must be max 160 characters and describe what this page is about.
7. Quote the mako version as "1.0" (string, not number).
PROMPT;

		return $prompt;
	}

	/**
	 * Call OpenAI API.
	 */
	private function call_openai( string $api_key, string $model, string $prompt, string $endpoint ): string|WP_Error {
		$response = wp_remote_post( $endpoint, array(
			'timeout' => 60,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => wp_json_encode( array(
				'model'       => $model,
				'messages'    => array(
					array( 'role' => 'user', 'content' => $prompt ),
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

		return $this->clean_ai_output( $content );
	}

	/**
	 * Call Anthropic API.
	 */
	private function call_anthropic( string $api_key, string $model, string $prompt, string $endpoint ): string|WP_Error {
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
				'messages'   => array(
					array( 'role' => 'user', 'content' => $prompt ),
				),
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

		return $this->clean_ai_output( $content );
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
