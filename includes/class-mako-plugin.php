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
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
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

		// Auto-generate on publish.
		if ( get_option( 'mako_auto_generate', true ) ) {
			add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		}

		// Alternate link in HTML head.
		if ( get_option( 'mako_alternate_link', true ) ) {
			add_action( 'wp_head', array( $this, 'render_alternate_link' ) );
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
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_queried_object_id();
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
}
