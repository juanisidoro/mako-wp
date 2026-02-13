<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Sitemap {

	/**
	 * Generate the MAKO sitemap data.
	 */
	public function generate(): array {
		$cached = get_transient( 'mako_sitemap' );
		if ( false !== $cached ) {
			return $cached;
		}

		$enabled_types = get_option( 'mako_post_types', array( 'post', 'page' ) );
		$storage       = new Mako_Storage();
		$pages         = array();

		$query = new WP_Query(
			array(
				'post_type'      => $enabled_types,
				'post_status'    => 'publish',
				'posts_per_page' => 1000,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => Mako_Storage::META_CONTENT,
						'compare' => '!=',
						'value'   => '',
					),
				),
			)
		);

		foreach ( $query->posts as $post ) {
			$data = $storage->get( $post->ID );
			if ( ! $data ) {
				continue;
			}

			$permalink = get_permalink( $post->ID );
			$path      = wp_parse_url( $permalink, PHP_URL_PATH ) ?: '/';

			$pages[] = array(
				'url'     => $path,
				'type'    => $data['type'] ?? 'custom',
				'tokens'  => $data['tokens'] ?? 0,
				'updated' => gmdate( 'Y-m-d', strtotime( $post->post_modified_gmt ) ),
				'entity'  => $post->post_title,
			);
		}

		$sitemap = array(
			'mako'      => MAKO_SPEC_VERSION,
			'generator' => 'mako-wp/' . MAKO_VERSION,
			'site'      => home_url(),
			'pages'     => $pages,
		);

		set_transient( 'mako_sitemap', $sitemap, (int) get_option( 'mako_cache_ttl', 3600 ) );

		return $sitemap;
	}

	/**
	 * Serve the sitemap as JSON response.
	 */
	public function serve(): void {
		$sitemap = $this->generate();

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Access-Control-Allow-Origin: *' );

		echo wp_json_encode( $sitemap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}
}
