<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mako_Plugin {

	private static ?Mako_Plugin $instance = null;

	public static function instance(): Mako_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	private function load_dependencies(): void {
		$dir = MAKO_PLUGIN_DIR . 'includes/';

		// Lifecycle.
		require_once $dir . 'class-mako-activator.php';
		require_once $dir . 'class-mako-deactivator.php';

		// Core pipeline.
		require_once $dir . 'core/class-mako-token-counter.php';
		require_once $dir . 'core/class-mako-content-converter.php';
		require_once $dir . 'core/class-mako-type-detector.php';
		require_once $dir . 'core/class-mako-entity-extractor.php';
		require_once $dir . 'core/class-mako-link-extractor.php';
		require_once $dir . 'core/class-mako-action-extractor.php';
		require_once $dir . 'core/class-mako-frontmatter.php';
		require_once $dir . 'core/class-mako-headers.php';
		require_once $dir . 'core/class-mako-validator.php';
		require_once $dir . 'core/class-mako-generator.php';
		require_once $dir . 'core/class-mako-ai-generator.php';
		require_once $dir . 'core/class-mako-cron.php';

		// Storage.
		require_once $dir . 'storage/class-mako-storage.php';
		require_once $dir . 'storage/class-mako-cache.php';

		// Content negotiation.
		require_once $dir . 'content-negotiation/class-mako-negotiator.php';
		require_once $dir . 'content-negotiation/class-mako-response.php';

		// Sitemap.
		require_once $dir . 'storage/class-mako-sitemap.php';

		// Integrations.
		require_once $dir . 'integrations/class-mako-woocommerce.php';
		require_once $dir . 'integrations/class-mako-yoast.php';
		require_once $dir . 'integrations/class-mako-rankmath.php';
		require_once $dir . 'integrations/class-mako-acf.php';
		require_once $dir . 'integrations/class-mako-wpml.php';

		// REST API.
		require_once $dir . 'api/class-mako-rest-controller.php';

		// Admin (only in admin context).
		if ( is_admin() ) {
			require_once $dir . 'admin/class-mako-admin.php';
			require_once $dir . 'admin/class-mako-admin-settings.php';
			require_once $dir . 'admin/class-mako-meta-box.php';
		}
	}

	private function init_hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init_components' ) );
		add_action( 'init', array( $this, 'handle_well_known' ), 0 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 10, 2 );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'mako-wp', false, dirname( MAKO_PLUGIN_BASENAME ) . '/languages' );
	}

	public function init_components(): void {
		if ( ! get_option( 'mako_enabled', true ) ) {
			return;
		}

		// Content negotiation (frontend).
		if ( ! is_admin() ) {
			$negotiator = new Mako_Negotiator();
			$negotiator->register();
		}

		// Auto-generate on publish (disabled by default).
		if ( get_option( 'mako_auto_generate', false ) ) {
			add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		}

		// Alternate link in HTML head.
		if ( get_option( 'mako_alternate_link', true ) ) {
			add_action( 'wp_head', array( $this, 'render_alternate_link' ) );
		}

		// HTML embedding: output MAKO content in <script> tag.
		if ( get_option( 'mako_html_embedding', true ) ) {
			add_action( 'wp_head', array( $this, 'render_mako_embedding' ), 20 );
		}

		// Integrations.
		$this->init_integrations();

		// Admin components.
		if ( is_admin() ) {
			$admin = new Mako_Admin();
			$admin->register();
		}
	}

	public function on_save_post( int $post_id, WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		$enabled_types = Mako_Plugin::get_enabled_post_types();
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return;
		}

		$storage = new Mako_Storage();
		if ( ! $storage->is_enabled_for_post( $post_id ) ) {
			return;
		}

		if ( ! $storage->needs_regeneration( $post_id ) ) {
			return;
		}

		$generator = new Mako_Generator();
		$result    = $generator->generate( $post_id );

		if ( $result ) {
			$storage->save( $post_id, $result );

			do_action( 'mako_generated', $post_id, $result['content'], $result['headers'] );
		}
	}

	public function render_alternate_link(): void {
		$post_id = self::get_current_mako_post_id();
		if ( ! $post_id ) {
			return;
		}

		$enabled_types = Mako_Plugin::get_enabled_post_types();
		$post_type     = get_post_type( $post_id );

		if ( ! in_array( $post_type, $enabled_types, true ) ) {
			return;
		}

		$storage = new Mako_Storage();
		if ( ! $storage->get( $post_id ) ) {
			return;
		}

		$permalink = get_permalink( $post_id );
		printf(
			'<link rel="alternate" type="text/mako+markdown" href="%s" />' . "\n",
			esc_url( $permalink )
		);
	}

	/**
	 * Render MAKO content embedded in HTML via <script type="text/mako+markdown">.
	 *
	 * Follows the JSON-LD pattern: agents parsing HTML can extract MAKO content
	 * without content negotiation. Output is compact to minimize HTML bloat.
	 */
	public function render_mako_embedding(): void {
		$post_id = self::get_current_mako_post_id();
		if ( ! $post_id ) {
			return;
		}

		$enabled_types = self::get_enabled_post_types();
		$post_type     = get_post_type( $post_id );

		if ( ! in_array( $post_type, $enabled_types, true ) ) {
			return;
		}

		$storage = new Mako_Storage();

		if ( ! $storage->is_enabled_for_post( $post_id ) ) {
			return;
		}

		// Get effective content (custom override or auto-generated).
		$mako_content = $storage->get_effective_content( $post_id );

		if ( ! $mako_content ) {
			return;
		}

		// Compact the content: collapse excessive blank lines.
		$compact = preg_replace( "/\n{3,}/", "\n\n", trim( $mako_content ) );

		// Prepend spec comments for self-explanation (if enabled).
		$compact = self::prepend_spec_comments( $compact );

		// Escape </script> if it appears in content (extremely rare in markdown).
		$safe = str_replace( '</script>', '<\/script>', $compact );

		echo '<script type="text/mako+markdown" id="mako">' . "\n";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MAKO content is markdown, not HTML context.
		echo $safe . "\n";
		echo '</script>' . "\n";
	}

	public function register_rest_routes(): void {
		$controller = new Mako_REST_Controller();
		$controller->register_routes();
	}

	private function init_integrations(): void {
		$woo = new Mako_WooCommerce();
		$woo->register();

		$yoast = new Mako_Yoast();
		$yoast->register();

		$rankmath = new Mako_RankMath();
		$rankmath->register();

		$acf = new Mako_ACF();
		$acf->register();

		$wpml = new Mako_WPML();
		$wpml->register();

		$cron = new Mako_Cron();
		$cron->register();
	}

	/**
	 * Get the post ID for the current request, including WooCommerce special pages.
	 *
	 * Returns the post ID if we're on a singular page OR on a WooCommerce shop page
	 * (which WordPress treats as an archive, not singular). Returns 0 if no valid
	 * post context exists.
	 */
	public static function get_current_mako_post_id(): int {
		if ( is_singular() ) {
			return get_queried_object_id();
		}

		// WooCommerce shop page: WordPress treats it as a product archive,
		// but it has a backing page with MAKO content.
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return (int) wc_get_page_id( 'shop' );
		}

		return 0;
	}

	/**
	 * Get enabled post types (safe - always returns array).
	 */
	public static function get_enabled_post_types(): array {
		$types = get_option( 'mako_post_types', array( 'post', 'page' ) );
		if ( ! is_array( $types ) ) {
			return array( 'post', 'page' );
		}
		return $types;
	}

	public function get_generator(): Mako_Generator {
		return new Mako_Generator();
	}

	public function get_storage(): Mako_Storage {
		return new Mako_Storage();
	}

	/**
	 * Handle /.well-known/mako requests.
	 *
	 * Serves a JSON document describing the site's MAKO capabilities.
	 * Runs at init priority 0 to bypass WordPress routing.
	 */
	public function handle_well_known(): void {
		$request_uri = $_SERVER['REQUEST_URI'] ?? '';
		$path        = trim( wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );

		if ( '.well-known/mako' !== $path ) {
			return;
		}

		if ( ! get_option( 'mako_well_known', true ) ) {
			status_header( 404 );
			exit;
		}

		$domain = wp_parse_url( home_url(), PHP_URL_HOST );

		$data = array(
			'mako'     => MAKO_SPEC_VERSION,
			'site'     => $domain,
			'accept'   => 'text/mako+markdown',
			'features' => array(
				'content_negotiation' => (bool) get_option( 'mako_content_negotiation', true ),
				'html_embedding'      => (bool) get_option( 'mako_html_embedding', true ),
			),
			'sitemap'  => get_option( 'mako_sitemap_enabled', true ) ? '/mako-sitemap.json' : null,
			'spec'     => 'https://makospec.vercel.app',
		);

		// Remove null values.
		$data = array_filter( $data, fn( $v ) => null !== $v );

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: public, max-age=3600' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Add MAKO sitemap reference to robots.txt.
	 */
	public function filter_robots_txt( string $output, bool $public ): string {
		if ( ! $public || ! get_option( 'mako_robots_txt', false ) ) {
			return $output;
		}

		$sitemap_url = home_url( '/mako-sitemap.json' );

		$output .= "\n# MAKO — AI-Optimized Content\n";
		$output .= "# Spec: https://makospec.vercel.app\n";
		$output .= "Sitemap: {$sitemap_url}\n";

		return $output;
	}

	/**
	 * Prepend spec comments to MAKO content for self-explanation.
	 *
	 * Adds 3 YAML comment lines at the start of the frontmatter that help
	 * LLMs understand what MAKO is on first encounter. ~30 tokens cost.
	 */
	public static function prepend_spec_comments( string $content ): string {
		if ( ! get_option( 'mako_spec_comments', true ) ) {
			return $content;
		}

		$comments = "# @mako — Machine-Accessible Knowledge Object\n"
			. "# Structured metadata for AI agents and LLMs\n"
			. "# Spec: https://makospec.vercel.app\n";

		// Insert after the opening --- delimiter.
		if ( str_starts_with( $content, '---' ) ) {
			return "---\n" . $comments . substr( $content, 4 );
		}

		return $comments . $content;
	}
}
