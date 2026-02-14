<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Admin_Settings {

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function add_menu_pages(): void {
		// Dashboard top-level menu.
		add_menu_page(
			__( 'MAKO Dashboard', 'mako-wp' ),
			'MAKO',
			'manage_options',
			'mako-dashboard',
			array( $this, 'render_dashboard' ),
			self::get_menu_icon(),
			80
		);

		// Settings as submenu under Settings.
		add_options_page(
			__( 'MAKO Settings', 'mako-wp' ),
			__( 'MAKO', 'mako-wp' ),
			'manage_options',
			'mako-settings',
			array( $this, 'render_settings' )
		);
	}

	public function register_settings(): void {
		// General section.
		add_settings_section( 'mako_general', __( 'General', 'mako-wp' ), null, 'mako-settings' );

		$this->add_checkbox(
			'mako_enabled',
			__( 'Enable Plugin', 'mako-wp' ),
			'mako_general',
			__( 'Activate MAKO content generation and serving for your site.', 'mako-wp' )
		);
		$this->add_checkbox(
			'mako_auto_generate',
			__( 'Auto-generate on Publish', 'mako-wp' ),
			'mako_general',
			__( 'Automatically generate MAKO content when a post is published or updated. When disabled, use the Dashboard to generate manually.', 'mako-wp' )
		);

		register_setting( 'mako_settings', 'mako_enabled', array( 'type' => 'boolean', 'default' => true ) );
		register_setting( 'mako_settings', 'mako_auto_generate', array( 'type' => 'boolean', 'default' => false ) );

		// Post types.
		add_settings_field(
			'mako_post_types',
			__( 'Enabled Post Types', 'mako-wp' ),
			array( $this, 'render_post_types_field' ),
			'mako-settings',
			'mako_general'
		);
		register_setting( 'mako_settings', 'mako_post_types', array(
			'type'              => 'array',
			'default'           => array( 'post', 'page' ),
			'sanitize_callback' => array( $this, 'sanitize_post_types' ),
		) );

		// Content section.
		add_settings_section( 'mako_content', __( 'Content', 'mako-wp' ), null, 'mako-settings' );

		add_settings_field(
			'mako_max_tokens',
			__( 'Max Tokens per Page', 'mako-wp' ),
			array( $this, 'render_number_field' ),
			'mako-settings',
			'mako_content',
			array(
				'name'        => 'mako_max_tokens',
				'default'     => 1000,
				'min'         => 100,
				'max'         => 2000,
				'description' => __( 'Maximum number of tokens per MAKO file. The MAKO spec recommends 1000 tokens. Lower values produce lighter responses.', 'mako-wp' ),
			)
		);
		register_setting( 'mako_settings', 'mako_max_tokens', array( 'type' => 'integer', 'default' => 1000 ) );

		add_settings_field(
			'mako_freshness_default',
			__( 'Default Freshness', 'mako-wp' ),
			array( $this, 'render_freshness_field' ),
			'mako-settings',
			'mako_content'
		);
		register_setting( 'mako_settings', 'mako_freshness_default', array( 'type' => 'string', 'default' => 'weekly' ) );

		$this->add_checkbox(
			'mako_include_tags',
			__( 'Include Tags/Categories', 'mako-wp' ),
			'mako_content',
			__( 'Include WordPress tags and categories as MAKO tags in the frontmatter.', 'mako-wp' )
		);
		$this->add_checkbox(
			'mako_use_excerpt',
			__( 'Use Excerpt as Summary', 'mako-wp' ),
			'mako_content',
			__( 'Use the post excerpt as the MAKO summary. If empty, the first paragraph is used instead.', 'mako-wp' )
		);
		register_setting( 'mako_settings', 'mako_include_tags', array( 'type' => 'boolean', 'default' => true ) );
		register_setting( 'mako_settings', 'mako_use_excerpt', array( 'type' => 'boolean', 'default' => true ) );

		// Headers & Discovery section.
		add_settings_section( 'mako_headers', __( 'Headers & Discovery', 'mako-wp' ), null, 'mako-settings' );

		$this->add_checkbox(
			'mako_content_negotiation',
			__( 'Enable Content Negotiation', 'mako-wp' ),
			'mako_headers',
			__( 'Serve MAKO content when the request includes Accept: text/mako+markdown. This is how LLM agents request optimized content.', 'mako-wp' )
		);
		$this->add_checkbox(
			'mako_alternate_link',
			__( 'Add &lt;link rel="alternate"&gt;', 'mako-wp' ),
			'mako_headers',
			__( 'Add a discovery tag in the HTML &lt;head&gt; so agents know MAKO content is available for this page.', 'mako-wp' )
		);
		$this->add_checkbox(
			'mako_sitemap_enabled',
			__( 'Enable /mako-sitemap.json', 'mako-wp' ),
			'mako_headers',
			__( 'Publish a JSON sitemap listing all pages with MAKO content, accessible at /mako-sitemap.json.', 'mako-wp' )
		);
		register_setting( 'mako_settings', 'mako_content_negotiation', array( 'type' => 'boolean', 'default' => true ) );
		register_setting( 'mako_settings', 'mako_alternate_link', array( 'type' => 'boolean', 'default' => true ) );
		register_setting( 'mako_settings', 'mako_sitemap_enabled', array( 'type' => 'boolean', 'default' => true ) );

		add_settings_field(
			'mako_cache_control',
			__( 'Cache-Control Header', 'mako-wp' ),
			array( $this, 'render_text_field' ),
			'mako-settings',
			'mako_headers',
			array(
				'name'        => 'mako_cache_control',
				'default'     => 'public, max-age=3600',
				'class'       => 'regular-text',
				'description' => __( 'HTTP Cache-Control header sent with MAKO responses.', 'mako-wp' ),
			)
		);
		register_setting( 'mako_settings', 'mako_cache_control', array( 'type' => 'string', 'default' => 'public, max-age=3600' ) );

		add_settings_field(
			'mako_cache_ttl',
			__( 'Cache TTL (seconds)', 'mako-wp' ),
			array( $this, 'render_number_field' ),
			'mako-settings',
			'mako_headers',
			array(
				'name'        => 'mako_cache_ttl',
				'default'     => 3600,
				'min'         => 0,
				'max'         => 86400,
				'description' => __( 'How long to cache generated MAKO content internally (in seconds). 0 = no cache.', 'mako-wp' ),
			)
		);
		register_setting( 'mako_settings', 'mako_cache_ttl', array( 'type' => 'integer', 'default' => 3600 ) );
	}

	public function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require MAKO_PLUGIN_DIR . 'admin/views/settings.php';
	}

	public function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require MAKO_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	// --- Field renderers ---

	/**
	 * Get available post types for the settings UI.
	 *
	 * Only shows content types (post, page, product) by default.
	 * Developers can add custom post types via the `mako_available_post_types` filter.
	 */
	private function get_available_post_types(): array {
		$types = array(
			'post' => array(
				'label'       => __( 'Posts', 'mako-wp' ),
				'description' => __( 'Blog entries, news articles, and editorial content.', 'mako-wp' ),
			),
			'page' => array(
				'label'       => __( 'Pages', 'mako-wp' ),
				'description' => __( 'Static pages like About, Contact, Services, Landing pages.', 'mako-wp' ),
			),
		);

		// Add WooCommerce products if available.
		if ( post_type_exists( 'product' ) ) {
			$types['product'] = array(
				'label'       => __( 'Products', 'mako-wp' ),
				'description' => __( 'WooCommerce product pages with prices, descriptions, and attributes.', 'mako-wp' ),
			);
		}

		/**
		 * Filter available post types in the MAKO settings UI.
		 *
		 * @param array $types Associative array of post_type => ['label' => ..., 'description' => ...].
		 */
		return apply_filters( 'mako_available_post_types', $types );
	}

	public function render_post_types_field(): void {
		$selected  = Mako_Plugin::get_enabled_post_types();
		$available = $this->get_available_post_types();

		echo '<fieldset>';
		foreach ( $available as $type_name => $type_info ) {
			printf(
				'<label style="display:block;margin-bottom:8px">'
				. '<input type="checkbox" name="mako_post_types[]" value="%s" %s> '
				. '<strong>%s</strong>'
				. '<span class="description" style="display:block;margin-left:24px;color:#646970">%s</span>'
				. '</label>',
				esc_attr( $type_name ),
				checked( in_array( $type_name, $selected, true ), true, false ),
				esc_html( $type_info['label'] ),
				esc_html( $type_info['description'] )
			);
		}
		echo '</fieldset>';
		echo '<p class="description" style="margin-top:8px">';
		echo esc_html__( 'Need more post types? Developers can add custom types via the mako_available_post_types filter.', 'mako-wp' );
		echo '</p>';
	}

	public function render_freshness_field(): void {
		$current = get_option( 'mako_freshness_default', 'weekly' );
		$options = array( 'realtime', 'hourly', 'daily', 'weekly', 'monthly', 'static' );

		echo '<select name="mako_freshness_default">';
		foreach ( $options as $opt ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $opt ),
				selected( $current, $opt, false ),
				esc_html( ucfirst( $opt ) )
			);
		}
		echo '</select>';
	}

	public function render_number_field( array $args ): void {
		$value = get_option( $args['name'], $args['default'] );
		printf(
			'<input type="number" name="%s" value="%s" min="%s" max="%s" class="small-text">',
			esc_attr( $args['name'] ),
			esc_attr( $value ),
			esc_attr( $args['min'] ),
			esc_attr( $args['max'] )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	public function render_text_field( array $args ): void {
		$value = get_option( $args['name'], $args['default'] );
		printf(
			'<input type="text" name="%s" value="%s" class="%s">',
			esc_attr( $args['name'] ),
			esc_attr( $value ),
			esc_attr( $args['class'] ?? 'regular-text' )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	public function sanitize_post_types( $value ): array {
		if ( ! is_array( $value ) ) {
			return array( 'post', 'page' );
		}

		$allowed = array_keys( $this->get_available_post_types() );

		return array_values( array_intersect(
			array_map( 'sanitize_key', $value ),
			$allowed
		) );
	}

	/**
	 * SVG icon for the admin menu (base64-encoded).
	 *
	 * Uses fill-rule="evenodd" so the M letter is knocked out of
	 * the rounded rectangle. WordPress colorizes the single fill.
	 */
	private static function get_menu_icon(): string {
		// Rounded rect with M knocked out via evenodd.
		// Outer: rounded rect 20x20 r4.
		// Inner: M letterform - two diagonal strokes meeting at center peak.
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
			. '<path fill-rule="evenodd" d="'
			// Outer rounded rect.
			. 'M4 0h12a4 4 0 014 4v12a4 4 0 01-4 4H4a4 4 0 01-4-4V4a4 4 0 014-4z'
			// M letterform (counter-clockwise = knockout with evenodd).
			. 'M4.5 15.5V4.5h2.2L10 10l3.3-5.5h2.2v11h-2.2V8.2L10 13.2 6.7 8.2v7.3z'
			. '" fill="black"/>'
			. '</svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	private function add_checkbox( string $name, string $label, string $section, string $description = '' ): void {
		add_settings_field(
			$name,
			$label,
			function () use ( $name, $description ) {
				$value = get_option( $name, true );
				printf(
					'<input type="checkbox" name="%s" value="1" %s>',
					esc_attr( $name ),
					checked( $value, true, false )
				);
				if ( '' !== $description ) {
					printf( '<p class="description">%s</p>', esc_html( $description ) );
				}
			},
			'mako-settings',
			$section
		);
	}
}
