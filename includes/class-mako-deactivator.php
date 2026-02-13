<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Deactivator {

	public static function deactivate(): void {
		// Clear scheduled events.
		wp_clear_scheduled_hook( 'mako_bulk_generate_cron' );

		// Clear transients.
		delete_transient( 'mako_sitemap' );
		delete_transient( 'mako_global_stats' );

		flush_rewrite_rules();
	}
}
