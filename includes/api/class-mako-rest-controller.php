<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_REST_Controller {

	private const NAMESPACE = 'mako/v1';

	/**
	 * Register all REST API routes.
	 */
	public function register_routes(): void {
		// Public: Get MAKO content for a post.
		register_rest_route( self::NAMESPACE, '/post/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_post_mako' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => fn( $val ) => is_numeric( $val ) && (int) $val > 0,
					),
				),
			),
		) );

		// Public: List posts with MAKO data.
		register_rest_route( self::NAMESPACE, '/posts', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_posts' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'page'     => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
					'per_page' => array( 'default' => 20, 'sanitize_callback' => 'absint' ),
				),
			),
		) );

		// Public: Get MAKO sitemap.
		register_rest_route( self::NAMESPACE, '/sitemap', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_sitemap' ),
				'permission_callback' => '__return_true',
			),
		) );

		// Public: Get global stats.
		register_rest_route( self::NAMESPACE, '/stats', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => '__return_true',
			),
		) );

		// Admin: Generate MAKO for a post.
		register_rest_route( self::NAMESPACE, '/generate/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_post' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => fn( $val ) => is_numeric( $val ) && (int) $val > 0,
					),
				),
			),
		) );

		// Admin: Bulk generate.
		register_rest_route( self::NAMESPACE, '/generate/bulk', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_generate' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'args'                => array(
					'post_ids' => array(
						'required' => false,
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
					),
					'limit'    => array( 'default' => 50, 'sanitize_callback' => 'absint' ),
				),
			),
		) );

		// Admin: Regenerate (force).
		register_rest_route( self::NAMESPACE, '/regenerate/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'regenerate_post' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => fn( $val ) => is_numeric( $val ) && (int) $val > 0,
					),
				),
			),
		) );

		// Admin: Delete MAKO for a post.
		register_rest_route( self::NAMESPACE, '/post/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_post_mako' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => fn( $val ) => is_numeric( $val ) && (int) $val > 0,
					),
				),
			),
		) );

		// Admin: Get/Update settings.
		register_rest_route( self::NAMESPACE, '/settings', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
			),
		) );
	}

	// --- Public Endpoints ---

	public function get_post_mako( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_REST_Response( array( 'error' => 'Post not found' ), 404 );
		}

		$storage = new Mako_Storage();
		$data    = $storage->get( $post_id );

		if ( ! $data ) {
			return new WP_REST_Response( array( 'error' => 'MAKO content not generated' ), 404 );
		}

		return new WP_REST_Response( array(
			'post_id'     => $post_id,
			'title'       => $post->post_title,
			'content'     => $data['content'],
			'headers'     => $data['headers'],
			'tokens'      => $data['tokens'],
			'html_tokens' => $data['html_tokens'],
			'savings'     => $data['savings'],
			'type'        => $data['type'],
			'updated_at'  => $data['updated_at'],
		) );
	}

	public function list_posts( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 50, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$storage = new Mako_Storage();
		$posts   = $storage->get_generated_posts( $per_page, $offset );
		$stats   = $storage->get_stats();

		return new WP_REST_Response( array(
			'posts' => $posts,
			'total' => $stats['total'],
			'page'  => $page,
			'per_page' => $per_page,
		) );
	}

	public function get_sitemap(): WP_REST_Response {
		$sitemap = new Mako_Sitemap();
		$data    = $sitemap->generate();

		return new WP_REST_Response( $data );
	}

	public function get_stats(): WP_REST_Response {
		$storage = new Mako_Storage();
		$stats   = $storage->get_stats();

		$stats['spec_version']   = MAKO_SPEC_VERSION;
		$stats['plugin_version'] = MAKO_VERSION;
		$stats['integrations']   = array(
			'woocommerce' => Mako_WooCommerce::is_active(),
			'yoast'       => Mako_Yoast::is_active(),
			'rankmath'    => Mako_RankMath::is_active(),
			'acf'         => Mako_ACF::is_active(),
		);

		return new WP_REST_Response( $stats );
	}

	// --- Admin Endpoints ---

	public function generate_post( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_REST_Response( array( 'error' => 'Post not found' ), 404 );
		}

		$generator = new Mako_Generator();
		$result    = $generator->generate( $post_id );

		if ( ! $result ) {
			return new WP_REST_Response( array( 'error' => 'Failed to generate MAKO content' ), 500 );
		}

		$storage = new Mako_Storage();
		$storage->save( $post_id, $result );

		return new WP_REST_Response( array(
			'post_id'    => $post_id,
			'tokens'     => $result['tokens'],
			'html_tokens' => $result['html_tokens'],
			'savings'    => $result['savings'],
			'type'       => $result['type'],
			'validation' => $result['validation'],
		), 201 );
	}

	public function bulk_generate( WP_REST_Request $request ): WP_REST_Response {
		$post_ids = $request->get_param( 'post_ids' );
		$limit    = min( 50, (int) $request->get_param( 'limit' ) );

		if ( empty( $post_ids ) ) {
			// Auto-detect posts without MAKO.
			$enabled_types = Mako_Plugin::get_enabled_post_types();
			$post_ids      = get_posts( array(
				'post_type'      => $enabled_types,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array(
						'key'     => Mako_Storage::META_CONTENT,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => Mako_Storage::META_CONTENT,
						'value' => '',
					),
				),
			) );
		}

		$generator = new Mako_Generator();
		$storage   = new Mako_Storage();
		$results   = array();

		foreach ( array_slice( $post_ids, 0, $limit ) as $pid ) {
			$result = $generator->generate( (int) $pid );
			if ( $result ) {
				$storage->save( (int) $pid, $result );
				$results[] = array(
					'post_id' => (int) $pid,
					'tokens'  => $result['tokens'],
					'savings' => $result['savings'],
				);
			}
		}

		return new WP_REST_Response( array(
			'generated' => count( $results ),
			'results'   => $results,
		), 200 );
	}

	public function regenerate_post( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'id' );

		// Clear hash to force regeneration.
		delete_post_meta( $post_id, Mako_Storage::META_HASH );

		// Invalidate cache.
		$cache = new Mako_Cache();
		$cache->invalidate( $post_id );

		return $this->generate_post( $request );
	}

	public function delete_post_mako( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'id' );

		$storage = new Mako_Storage();
		$storage->delete( $post_id );

		$cache = new Mako_Cache();
		$cache->invalidate( $post_id );

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	public function get_settings(): WP_REST_Response {
		$settings = array(
			'enabled'              => (bool) get_option( 'mako_enabled', true ),
			'post_types'           => Mako_Plugin::get_enabled_post_types(),
			'auto_generate'        => (bool) get_option( 'mako_auto_generate', true ),
			'freshness_default'    => get_option( 'mako_freshness_default', 'weekly' ),
			'cache_ttl'            => (int) get_option( 'mako_cache_ttl', 3600 ),
			'max_tokens'           => (int) get_option( 'mako_max_tokens', 1000 ),
			'include_tags'         => (bool) get_option( 'mako_include_tags', true ),
			'use_excerpt'          => (bool) get_option( 'mako_use_excerpt', true ),
			'content_negotiation'  => (bool) get_option( 'mako_content_negotiation', true ),
			'alternate_link'       => (bool) get_option( 'mako_alternate_link', true ),
			'sitemap_enabled'      => (bool) get_option( 'mako_sitemap_enabled', true ),
			'cache_control'        => get_option( 'mako_cache_control', 'public, max-age=3600' ),
		);

		return new WP_REST_Response( $settings );
	}

	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$allowed = array(
			'enabled'              => 'boolval',
			'post_types'           => null,
			'auto_generate'        => 'boolval',
			'freshness_default'    => 'sanitize_text_field',
			'cache_ttl'            => 'absint',
			'max_tokens'           => 'absint',
			'include_tags'         => 'boolval',
			'use_excerpt'          => 'boolval',
			'content_negotiation'  => 'boolval',
			'alternate_link'       => 'boolval',
			'sitemap_enabled'      => 'boolval',
			'cache_control'        => 'sanitize_text_field',
		);

		$params = $request->get_json_params();

		foreach ( $params as $key => $value ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}

			$option_name = 'mako_' . $key;

			if ( 'post_types' === $key && is_array( $value ) ) {
				$value = array_map( 'sanitize_key', $value );
			} elseif ( null !== $allowed[ $key ] ) {
				$value = call_user_func( $allowed[ $key ], $value );
			}

			update_option( $option_name, $value );
		}

		return $this->get_settings();
	}
}
