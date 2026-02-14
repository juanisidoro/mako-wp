<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Meta_Box {

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );
	}

	public function add_meta_box(): void {
		$enabled_types = Mako_Plugin::get_enabled_post_types();

		foreach ( $enabled_types as $post_type ) {
			add_meta_box(
				'mako-status',
				__( 'MAKO - AI Content', 'mako-wp' ),
				array( $this, 'render' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	public function render( WP_Post $post ): void {
		wp_nonce_field( 'mako_meta_box', 'mako_meta_box_nonce' );

		$storage = new Mako_Storage();
		$data    = $storage->get( $post->ID );
		$enabled = $storage->is_enabled_for_post( $post->ID );

		require MAKO_PLUGIN_DIR . 'admin/views/meta-box.php';
	}

	public function save_meta_box( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['mako_meta_box_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mako_meta_box_nonce'] ) ), 'mako_meta_box' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$storage = new Mako_Storage();
		$enabled = isset( $_POST['mako_enabled'] ) ? true : false;
		$storage->set_enabled( $post_id, $enabled );
	}
}
