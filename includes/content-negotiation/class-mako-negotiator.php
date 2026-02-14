<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Negotiator {

	private Mako_Storage $storage;
	private Mako_Cache $cache;

	public function __construct() {
		$this->storage = new Mako_Storage();
		$this->cache   = new Mako_Cache();
	}

	/**
	 * Register content negotiation hooks.
	 */
	public function register(): void {
		if ( ! get_option( 'mako_content_negotiation', true ) ) {
			return;
		}

		// Hook early in template_redirect to intercept before theme loads.
		add_action( 'template_redirect', array( $this, 'handle_request' ), 1 );

		// Handle /.well-known/mako.json.
		if ( get_option( 'mako_sitemap_enabled', true ) ) {
			add_action( 'parse_request', array( $this, 'handle_sitemap_request' ) );
		}

		// Always set Vary header on singular pages.
		add_action( 'send_headers', array( $this, 'set_vary_header' ) );
	}

	/**
	 * Handle incoming request - check for MAKO Accept header.
	 */
	public function handle_request(): void {
		if ( ! $this->accepts_mako() ) {
			return;
		}

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

		if ( ! $this->storage->is_enabled_for_post( $post_id ) ) {
			return;
		}

		$this->send_mako_response( $post_id );
	}

	/**
	 * Send MAKO response with proper headers.
	 */
	private function send_mako_response( int $post_id ): void {
		do_action( 'mako_before_response', $post_id );

		// Try cache first.
		$data = $this->cache->get( $post_id );

		if ( ! $data ) {
			// Try stored data.
			$data = $this->storage->get( $post_id );

			if ( ! $data ) {
				// Generate on-the-fly.
				$generator = new Mako_Generator();
				$result    = $generator->generate( $post_id );

				if ( ! $result ) {
					return; // Fall through to normal WP response.
				}

				$this->storage->save( $post_id, $result );
				$data = $result;
			}

			// Cache for next request.
			$this->cache->set( $post_id, $data );
		}

		$response = new Mako_Response();

		// Handle ETag / conditional requests.
		$etag = $this->cache->generate_etag( $data['content'] );

		$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) )
			: '';

		if ( $etag === $if_none_match ) {
			$response->send_not_modified( $etag );
			return;
		}

		// Check if HEAD request.
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		$is_head = 'HEAD' === $method;

		$response->send( $data, $etag, $is_head );

		do_action( 'mako_after_response', $post_id );
	}

	/**
	 * Handle /.well-known/mako.json request.
	 */
	public function handle_sitemap_request( WP $wp ): void {
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		$path = wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( '/.well-known/mako.json' !== $path ) {
			return;
		}

		$sitemap = new Mako_Sitemap();
		$sitemap->serve();
	}

	/**
	 * Set Vary: Accept header on singular pages.
	 */
	public function set_vary_header(): void {
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			if ( $post_id ) {
				$enabled_types = Mako_Plugin::get_enabled_post_types();
				if ( in_array( get_post_type( $post_id ), $enabled_types, true ) ) {
					header( 'Vary: Accept', false ); // Append, don't replace.
				}
			}
		}
	}

	/**
	 * Check if current request accepts MAKO format.
	 */
	private function accepts_mako(): bool {
		$accept = isset( $_SERVER['HTTP_ACCEPT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) )
			: '';

		return str_contains( $accept, 'text/mako+markdown' );
	}
}
