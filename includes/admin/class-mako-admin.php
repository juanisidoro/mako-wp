<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Admin {

	public function register(): void {
		$settings = new Mako_Admin_Settings();
		$settings->register();

		$meta_box = new Mako_Meta_Box();
		$meta_box->register();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . MAKO_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_mako_generate', array( $this, 'ajax_generate' ) );
		add_action( 'wp_ajax_mako_generate_next', array( $this, 'ajax_generate_next' ) );
		add_action( 'wp_ajax_mako_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_mako_flush_cache', array( $this, 'ajax_flush_cache' ) );
		add_action( 'wp_ajax_mako_get_queue', array( $this, 'ajax_get_queue' ) );
	}

	public function enqueue_assets( string $hook ): void {
		$screens = array( 'post.php', 'post-new.php', 'settings_page_mako-settings', 'toplevel_page_mako-dashboard' );

		if ( ! in_array( $hook, $screens, true ) ) {
			return;
		}

		wp_enqueue_style(
			'mako-admin',
			MAKO_PLUGIN_URL . 'admin/css/mako-admin.css',
			array(),
			MAKO_VERSION
		);

		wp_enqueue_script(
			'mako-admin',
			MAKO_PLUGIN_URL . 'admin/js/mako-admin.js',
			array( 'jquery' ),
			MAKO_VERSION,
			true
		);

		wp_localize_script( 'mako-admin', 'makoAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'mako_admin' ),
			'i18n'    => array(
				'generating'  => __( 'Generating...', 'mako-wp' ),
				'generated'   => __( 'Generated', 'mako-wp' ),
				'error'       => __( 'Error', 'mako-wp' ),
				'bulkDone'    => __( 'All done! Generation complete.', 'mako-wp' ),
				'starting'    => __( 'Starting generation:', 'mako-wp' ),
				'pending'     => __( 'pending', 'mako-wp' ),
				'totalPosts'  => __( 'total', 'mako-wp' ),
				'savings'     => __( 'savings', 'mako-wp' ),
				'skipped'     => __( 'Skipped', 'mako-wp' ),
				'paused'      => __( 'Paused', 'mako-wp' ),
				'pause'       => __( 'Pause', 'mako-wp' ),
				'resume'      => __( 'Resume', 'mako-wp' ),
				'resumed'     => __( 'Resumed', 'mako-wp' ),
				'pausedMsg'   => __( 'Generation paused. Click Resume to continue.', 'mako-wp' ),
				'stopped'     => __( 'Generation stopped by user.', 'mako-wp' ),
				'testingOne'  => __( 'Testing with 1 post...', 'mako-wp' ),
				'noPending'   => __( 'No pending posts to generate.', 'mako-wp' ),
				'cacheFlushed' => __( 'Cache flushed successfully.', 'mako-wp' ),
			),
		) );
	}

	public function add_settings_link( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'options-general.php?page=mako-settings' ),
			__( 'Settings', 'mako-wp' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * AJAX: Generate MAKO for a single post.
	 */
	public function ajax_generate(): void {
		check_ajax_referer( 'mako_admin', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID' );
		}

		$generator = new Mako_Generator();
		$result    = $generator->generate( $post_id );

		if ( ! $result ) {
			wp_send_json_error( 'Failed to generate MAKO content' );
		}

		$storage = new Mako_Storage();
		$storage->save( $post_id, $result );

		wp_send_json_success( array(
			'tokens'      => $result['tokens'],
			'html_tokens' => $result['html_tokens'],
			'savings'     => $result['savings'],
			'type'        => $result['type'],
			'validation'  => $result['validation'] ?? null,
		) );
	}

	/**
	 * AJAX: Get the generation queue (posts pending MAKO).
	 */
	public function ajax_get_queue(): void {
		check_ajax_referer( 'mako_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$enabled_types = Mako_Plugin::get_enabled_post_types();

		$pending = get_posts( array(
			'post_type'      => $enabled_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
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

		$total = get_posts( array(
			'post_type'      => $enabled_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		wp_send_json_success( array(
			'pending'   => count( $pending ),
			'total'     => count( $total ),
			'generated' => count( $total ) - count( $pending ),
		) );
	}

	/**
	 * AJAX: Generate MAKO for the next pending post.
	 *
	 * Processes one post at a time. The client controls the loop,
	 * allowing pause/stop and rate limiting.
	 */
	public function ajax_generate_next(): void {
		check_ajax_referer( 'mako_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$enabled_types = Mako_Plugin::get_enabled_post_types();

		$posts = get_posts( array(
			'post_type'      => $enabled_types,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
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

		if ( empty( $posts ) ) {
			wp_send_json_success( array(
				'done'      => true,
				'remaining' => 0,
			) );
			return;
		}

		$post_id   = (int) $posts[0];
		$post      = get_post( $post_id );
		$generator = new Mako_Generator();
		$result    = $generator->generate( $post_id );

		if ( $result ) {
			$storage = new Mako_Storage();
			$storage->save( $post_id, $result );

			// Count remaining.
			$remaining = get_posts( array(
				'post_type'      => $enabled_types,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
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

			wp_send_json_success( array(
				'done'        => false,
				'post_id'     => $post_id,
				'title'       => $post ? $post->post_title : '#' . $post_id,
				'type'        => $result['type'],
				'tokens'      => $result['tokens'],
				'html_tokens' => $result['html_tokens'],
				'savings'     => $result['savings'],
				'remaining'   => count( $remaining ),
			) );
		} else {
			wp_send_json_success( array(
				'done'    => false,
				'post_id' => $post_id,
				'title'   => $post ? $post->post_title : '#' . $post_id,
				'skipped' => true,
				'reason'  => 'Could not generate MAKO content',
			) );
		}
	}

	/**
	 * AJAX: Flush MAKO cache.
	 */
	public function ajax_flush_cache(): void {
		check_ajax_referer( 'mako_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		delete_transient( 'mako_global_stats' );
		delete_transient( 'mako_sitemap' );

		wp_send_json_success( array( 'flushed' => true ) );
	}

	/**
	 * AJAX: Preview MAKO content.
	 */
	public function ajax_preview(): void {
		check_ajax_referer( 'mako_admin', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID' );
		}

		$storage = new Mako_Storage();
		$data    = $storage->get( $post_id );

		if ( ! $data ) {
			// Generate on-the-fly for preview.
			$generator = new Mako_Generator();
			$result    = $generator->generate( $post_id );
			if ( ! $result ) {
				wp_send_json_error( 'Failed to generate MAKO preview' );
			}
			$data = $result;
		}

		wp_send_json_success( array(
			'content' => $data['content'],
			'headers' => $data['headers'],
		) );
	}
}
