<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete all MAKO post meta.
global $wpdb;

$meta_keys = array(
	'_mako_content',
	'_mako_headers',
	'_mako_tokens',
	'_mako_html_tokens',
	'_mako_savings_pct',
	'_mako_type',
	'_mako_updated_at',
	'_mako_content_hash',
	'_mako_enabled',
	'_mako_custom_content',
	'_mako_custom_type',
	'_mako_custom_entity',
	'_mako_custom_cover',
);

foreach ( $meta_keys as $key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
}

// Delete all MAKO options.
$options = array(
	'mako_enabled',
	'mako_post_types',
	'mako_auto_generate',
	'mako_freshness_default',
	'mako_cache_ttl',
	'mako_max_tokens',
	'mako_include_image',
	'mako_include_tags',
	'mako_use_excerpt',
	'mako_content_negotiation',
	'mako_alternate_link',
	'mako_sitemap_enabled',
	'mako_html_embedding',
	'mako_cache_control',
	'mako_well_known',
	'mako_spec_comments',
	'mako_robots_txt',
	'mako_ai_provider',
	'mako_ai_api_key',
	'mako_ai_model',
	'mako_version',
	'mako_stats_total',
	'mako_stats_avg_savings',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Delete transients.
delete_transient( 'mako_sitemap' );
delete_transient( 'mako_global_stats' );
