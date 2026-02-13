<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Activator {

	public static function activate(): void {
		// Default options.
		add_option( 'mako_enabled', true );
		add_option( 'mako_post_types', array( 'post', 'page' ) );
		add_option( 'mako_auto_generate', true );
		add_option( 'mako_freshness_default', 'weekly' );
		add_option( 'mako_cache_ttl', 3600 );
		add_option( 'mako_max_tokens', 1000 );
		add_option( 'mako_include_image', false );
		add_option( 'mako_include_tags', true );
		add_option( 'mako_use_excerpt', true );
		add_option( 'mako_content_negotiation', true );
		add_option( 'mako_alternate_link', true );
		add_option( 'mako_sitemap_enabled', true );
		add_option( 'mako_cache_control', 'public, max-age=3600' );
		add_option( 'mako_version', MAKO_VERSION );

		flush_rewrite_rules();
	}
}
