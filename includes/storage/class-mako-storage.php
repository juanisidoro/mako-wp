<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Storage {

	// Auto-generated data.
	const META_CONTENT     = '_mako_content';
	const META_HEADERS     = '_mako_headers';
	const META_TOKENS      = '_mako_tokens';
	const META_HTML_TOKENS = '_mako_html_tokens';
	const META_SAVINGS     = '_mako_savings_pct';
	const META_TYPE        = '_mako_type';
	const META_UPDATED     = '_mako_updated_at';
	const META_HASH        = '_mako_content_hash';
	const META_ENABLED     = '_mako_enabled';

	// User custom overrides (take precedence over auto-generated).
	const META_CUSTOM_CONTENT = '_mako_custom_content';
	const META_CUSTOM_TYPE    = '_mako_custom_type';
	const META_CUSTOM_ENTITY  = '_mako_custom_entity';
	const META_CUSTOM_COVER   = '_mako_custom_cover';

	/**
	 * Save generated MAKO data for a post.
	 */
	public function save( int $post_id, array $data ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		update_post_meta( $post_id, self::META_CONTENT, $data['content'] );
		update_post_meta( $post_id, self::META_HEADERS, wp_json_encode( $data['headers'] ) );
		update_post_meta( $post_id, self::META_TOKENS, (int) $data['tokens'] );
		update_post_meta( $post_id, self::META_HTML_TOKENS, (int) $data['html_tokens'] );
		update_post_meta( $post_id, self::META_SAVINGS, (float) $data['savings'] );
		update_post_meta( $post_id, self::META_TYPE, $data['type'] ?? 'custom' );
		update_post_meta( $post_id, self::META_UPDATED, gmdate( 'c' ) );
		update_post_meta( $post_id, self::META_HASH, $this->compute_hash( $post ) );

		// Invalidate stats cache.
		delete_transient( 'mako_global_stats' );
	}

	/**
	 * Get stored MAKO data for a post.
	 */
	public function get( int $post_id ): ?array {
		$content = get_post_meta( $post_id, self::META_CONTENT, true );
		if ( '' === $content ) {
			return null;
		}

		$headers_json = get_post_meta( $post_id, self::META_HEADERS, true );
		$headers      = $headers_json ? json_decode( $headers_json, true ) : array();

		return array(
			'content'     => $content,
			'headers'     => $headers ?: array(),
			'tokens'      => (int) get_post_meta( $post_id, self::META_TOKENS, true ),
			'html_tokens' => (int) get_post_meta( $post_id, self::META_HTML_TOKENS, true ),
			'savings'     => (float) get_post_meta( $post_id, self::META_SAVINGS, true ),
			'type'        => get_post_meta( $post_id, self::META_TYPE, true ) ?: 'custom',
			'updated_at'  => get_post_meta( $post_id, self::META_UPDATED, true ),
		);
	}

	/**
	 * Get the effective MAKO content: custom override if exists, otherwise auto-generated.
	 */
	public function get_effective_content( int $post_id ): ?string {
		$custom = get_post_meta( $post_id, self::META_CUSTOM_CONTENT, true );
		if ( '' !== $custom ) {
			return $custom;
		}

		return get_post_meta( $post_id, self::META_CONTENT, true ) ?: null;
	}

	/**
	 * Check if a post has custom (user-edited) MAKO content.
	 */
	public function has_custom_content( int $post_id ): bool {
		return '' !== get_post_meta( $post_id, self::META_CUSTOM_CONTENT, true );
	}

	/**
	 * Save custom overrides from the meta box editor.
	 */
	public function save_custom( int $post_id, array $overrides ): void {
		if ( isset( $overrides['content'] ) && '' !== trim( $overrides['content'] ) ) {
			update_post_meta( $post_id, self::META_CUSTOM_CONTENT, $overrides['content'] );
		} else {
			delete_post_meta( $post_id, self::META_CUSTOM_CONTENT );
		}

		if ( isset( $overrides['type'] ) && '' !== trim( $overrides['type'] ) ) {
			update_post_meta( $post_id, self::META_CUSTOM_TYPE, sanitize_text_field( $overrides['type'] ) );
		} else {
			delete_post_meta( $post_id, self::META_CUSTOM_TYPE );
		}

		if ( isset( $overrides['entity'] ) && '' !== trim( $overrides['entity'] ) ) {
			update_post_meta( $post_id, self::META_CUSTOM_ENTITY, sanitize_text_field( $overrides['entity'] ) );
		} else {
			delete_post_meta( $post_id, self::META_CUSTOM_ENTITY );
		}

		if ( isset( $overrides['cover'] ) && '' !== trim( $overrides['cover'] ) ) {
			update_post_meta( $post_id, self::META_CUSTOM_COVER, absint( $overrides['cover'] ) );
		} else {
			delete_post_meta( $post_id, self::META_CUSTOM_COVER );
		}

		delete_transient( 'mako_global_stats' );
	}

	/**
	 * Get custom overrides for a post.
	 */
	public function get_custom( int $post_id ): array {
		return array(
			'content' => get_post_meta( $post_id, self::META_CUSTOM_CONTENT, true ),
			'type'    => get_post_meta( $post_id, self::META_CUSTOM_TYPE, true ),
			'entity'  => get_post_meta( $post_id, self::META_CUSTOM_ENTITY, true ),
			'cover'   => get_post_meta( $post_id, self::META_CUSTOM_COVER, true ),
		);
	}

	/**
	 * Delete MAKO data for a post.
	 */
	public function delete( int $post_id ): void {
		$keys = array(
			self::META_CONTENT, self::META_HEADERS, self::META_TOKENS,
			self::META_HTML_TOKENS, self::META_SAVINGS, self::META_TYPE,
			self::META_UPDATED, self::META_HASH,
			self::META_CUSTOM_CONTENT, self::META_CUSTOM_TYPE,
			self::META_CUSTOM_ENTITY, self::META_CUSTOM_COVER,
		);

		foreach ( $keys as $key ) {
			delete_post_meta( $post_id, $key );
		}

		delete_transient( 'mako_global_stats' );
	}

	/**
	 * Check if MAKO is enabled for a specific post.
	 */
	public function is_enabled_for_post( int $post_id ): bool {
		$value = get_post_meta( $post_id, self::META_ENABLED, true );
		// Default to enabled if not explicitly set.
		return '' === $value || '1' === $value;
	}

	/**
	 * Enable/disable MAKO for a specific post.
	 */
	public function set_enabled( int $post_id, bool $enabled ): void {
		update_post_meta( $post_id, self::META_ENABLED, $enabled ? '1' : '0' );
	}

	/**
	 * Check if a post needs MAKO regeneration.
	 */
	public function needs_regeneration( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$stored_hash = get_post_meta( $post_id, self::META_HASH, true );
		if ( '' === $stored_hash ) {
			return true; // Never generated.
		}

		return $this->compute_hash( $post ) !== $stored_hash;
	}

	/**
	 * Get global statistics across all posts.
	 */
	public function get_stats(): array {
		$cached = get_transient( 'mako_global_stats' );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
			self::META_CONTENT
		);

		$avg_savings = 0.0;
		$total_tokens_saved = 0;

		if ( $total > 0 ) {
			$avg_savings = (float) $wpdb->get_var(
				"SELECT AVG(CAST(meta_value AS DECIMAL(10,2))) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_SAVINGS
			);

			$html_sum = (int) $wpdb->get_var(
				"SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_HTML_TOKENS
			);

			$mako_sum = (int) $wpdb->get_var(
				"SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_TOKENS
			);

			$total_tokens_saved = max( 0, $html_sum - $mako_sum );
		}

		$stats = array(
			'total'              => $total,
			'avg_savings'        => round( $avg_savings, 2 ),
			'total_tokens_saved' => $total_tokens_saved,
		);

		set_transient( 'mako_global_stats', $stats, 300 ); // 5 min cache.

		return $stats;
	}

	/**
	 * Get posts that have MAKO content generated.
	 *
	 * @param int      $limit     Max posts to return.
	 * @param int      $offset    Offset for pagination.
	 * @param string[] $post_types Filter by post types (empty = all).
	 */
	public function get_generated_posts( int $limit = 50, int $offset = 0, array $post_types = array() ): array {
		global $wpdb;

		$where = $wpdb->prepare(
			"pm.meta_key = %s AND pm.meta_value != ''",
			self::META_CONTENT
		);

		if ( ! empty( $post_types ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
			$where .= $wpdb->prepare(
				" AND p.post_type IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
				...$post_types
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE {$where}
				ORDER BY pm.post_id DESC
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		$results = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post ) {
				continue;
			}

			$results[] = array(
				'post_id'     => (int) $post_id,
				'title'       => $post->post_title,
				'post_type'   => $post->post_type,
				'type'        => get_post_meta( (int) $post_id, self::META_TYPE, true ),
				'tokens'      => (int) get_post_meta( (int) $post_id, self::META_TOKENS, true ),
				'html_tokens' => (int) get_post_meta( (int) $post_id, self::META_HTML_TOKENS, true ),
				'savings'     => (float) get_post_meta( (int) $post_id, self::META_SAVINGS, true ),
				'updated_at'  => get_post_meta( (int) $post_id, self::META_UPDATED, true ),
			);
		}

		return $results;
	}

	/**
	 * Count posts that have MAKO content generated.
	 *
	 * @param string[] $post_types Filter by post types (empty = all).
	 */
	public function count_generated_posts( array $post_types = array() ): int {
		global $wpdb;

		$where = $wpdb->prepare(
			"pm.meta_key = %s AND pm.meta_value != ''",
			self::META_CONTENT
		);

		if ( ! empty( $post_types ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
			$where .= $wpdb->prepare(
				" AND p.post_type IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
				...$post_types
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE {$where}"
		);
	}

	/**
	 * Compute content hash for change detection.
	 */
	private function compute_hash( WP_Post $post ): string {
		return md5( $post->post_content . '|' . $post->post_title . '|' . $post->post_modified_gmt );
	}
}
