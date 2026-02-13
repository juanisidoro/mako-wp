<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Cache {

	/**
	 * Get cached MAKO response for a post.
	 */
	public function get( int $post_id ): ?array {
		$key    = $this->cache_key( $post_id );
		$cached = get_transient( $key );

		if ( false === $cached ) {
			return null;
		}

		return $cached;
	}

	/**
	 * Cache a MAKO response.
	 */
	public function set( int $post_id, array $data ): void {
		$key = $this->cache_key( $post_id );
		$ttl = (int) get_option( 'mako_cache_ttl', 3600 );

		set_transient( $key, $data, $ttl );
	}

	/**
	 * Invalidate cache for a post.
	 */
	public function invalidate( int $post_id ): void {
		delete_transient( $this->cache_key( $post_id ) );
	}

	/**
	 * Invalidate all MAKO caches.
	 */
	public function flush_all(): void {
		global $wpdb;

		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_mako_response_%'
			OR option_name LIKE '_transient_timeout_mako_response_%'"
		);

		delete_transient( 'mako_sitemap' );
		delete_transient( 'mako_global_stats' );
	}

	/**
	 * Generate ETag for MAKO content.
	 */
	public function generate_etag( string $content ): string {
		return '"mako-' . substr( md5( $content ), 0, 12 ) . '"';
	}

	/**
	 * Generate cache key for a post.
	 */
	private function cache_key( int $post_id ): string {
		return 'mako_response_' . $post_id;
	}
}
