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
		add_action( 'wp_ajax_mako_bulk_generate', array( $this, 'ajax_bulk_generate' ) );
		add_action( 'wp_ajax_mako_preview', array( $this, 'ajax_preview' ) );
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
				'confirm'     => __( 'Generate MAKO for all published posts?', 'mako-wp' ),
				'bulkDone'    => __( 'Bulk generation complete!', 'mako-wp' ),
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
	 * AJAX: Bulk generate MAKO.
	 */
	public function ajax_bulk_generate(): void {
		check_ajax_referer( 'mako_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$enabled_types = get_option( 'mako_post_types', array( 'post', 'page' ) );

		$posts = get_posts( array(
			'post_type'      => $enabled_types,
			'post_status'    => 'publish',
			'posts_per_page' => 50,
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

		$generator = new Mako_Generator();
		$storage   = new Mako_Storage();
		$generated = 0;

		foreach ( $posts as $post_id ) {
			$result = $generator->generate( (int) $post_id );
			if ( $result ) {
				$storage->save( (int) $post_id, $result );
				++$generated;
			}
		}

		wp_send_json_success( array(
			'generated' => $generated,
			'remaining' => max( 0, count( $posts ) - $generated ),
		) );
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
