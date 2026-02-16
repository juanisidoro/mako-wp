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
				'normal',
				'default'
			);
		}
	}

	public function render( WP_Post $post ): void {
		wp_nonce_field( 'mako_meta_box', 'mako_meta_box_nonce' );

		$storage = new Mako_Storage();
		$data    = $storage->get( $post->ID );
		$custom  = $storage->get_custom( $post->ID );
		$enabled = $storage->is_enabled_for_post( $post->ID );

		// Determine effective values for display.
		$effective_type    = $custom['type'] ?: ( $data['type'] ?? '' );
		$effective_entity  = $custom['entity'] ?: ( $data ? $this->extract_entity_from_content( $data['content'] ) : $post->post_title );
		$effective_content = $storage->get_effective_content( $post->ID );
		$has_custom        = $storage->has_custom_content( $post->ID );

		// Cover image.
		$cover_id  = $custom['cover'] ? (int) $custom['cover'] : 0;
		$cover_url = $cover_id ? wp_get_attachment_url( $cover_id ) : '';

		if ( ! $cover_url && $data && $data['content'] ) {
			// Try to extract cover from auto-generated content.
			if ( preg_match( '/cover:\s*\n\s*url:\s*(.+)/m', $data['content'], $m ) ) {
				$cover_url = trim( $m[1], '"\' ' );
			}
		}

		$content_types = array(
			'product', 'article', 'docs', 'landing', 'listing',
			'profile', 'event', 'recipe', 'faq', 'custom',
		);

		require MAKO_PLUGIN_DIR . 'admin/views/meta-box.php';
	}

	/**
	 * Extract entity name from MAKO content frontmatter.
	 */
	private function extract_entity_from_content( string $content ): string {
		if ( preg_match( '/^entity:\s*"?([^"\n]+)"?\s*$/m', $content, $m ) ) {
			return trim( $m[1] );
		}
		return '';
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
		$enabled = isset( $_POST['mako_enabled'] );
		$storage->set_enabled( $post_id, $enabled );

		// Save custom overrides.
		$overrides = array(
			'type'    => isset( $_POST['mako_custom_type'] )
				? sanitize_text_field( wp_unslash( $_POST['mako_custom_type'] ) )
				: '',
			'entity'  => isset( $_POST['mako_custom_entity'] )
				? sanitize_text_field( wp_unslash( $_POST['mako_custom_entity'] ) )
				: '',
			'cover'   => isset( $_POST['mako_custom_cover'] )
				? sanitize_text_field( wp_unslash( $_POST['mako_custom_cover'] ) )
				: '',
			'content' => isset( $_POST['mako_custom_content'] )
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Markdown content, sanitize_textarea strips structure.
				? wp_unslash( $_POST['mako_custom_content'] )
				: '',
		);

		$storage->save_custom( $post_id, $overrides );
	}
}
