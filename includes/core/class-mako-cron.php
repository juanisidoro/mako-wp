<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Async MAKO generation via WP-Cron.
 */
class Mako_Cron {

	private const BATCH_SIZE   = 10;
	private const HOOK_BULK    = 'mako_bulk_generate_cron';
	private const HOOK_SINGLE  = 'mako_regenerate_post';

	/**
	 * Register cron hooks.
	 */
	public function register(): void {
		add_action( self::HOOK_BULK, array( $this, 'process_bulk' ) );
		add_action( self::HOOK_SINGLE, array( $this, 'process_single' ) );

		// Custom interval for bulk processing.
		add_filter( 'cron_schedules', array( $this, 'add_interval' ) );
	}

	/**
	 * Add a 5-minute interval for bulk processing.
	 */
	public function add_interval( array $schedules ): array {
		$schedules['mako_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 Minutes (MAKO)', 'mako-wp' ),
		);
		return $schedules;
	}

	/**
	 * Schedule bulk generation.
	 */
	public function schedule_bulk(): void {
		if ( ! wp_next_scheduled( self::HOOK_BULK ) ) {
			wp_schedule_event( time(), 'mako_five_minutes', self::HOOK_BULK );
		}
	}

	/**
	 * Unschedule bulk generation.
	 */
	public function unschedule_bulk(): void {
		wp_clear_scheduled_hook( self::HOOK_BULK );
	}

	/**
	 * Process a batch of posts that need MAKO generation.
	 */
	public function process_bulk(): void {
		$enabled_types = Mako_Plugin::get_enabled_post_types();

		$post_ids = get_posts( array(
			'post_type'      => $enabled_types,
			'post_status'    => 'publish',
			'posts_per_page' => self::BATCH_SIZE,
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

		if ( empty( $post_ids ) ) {
			// All done - unschedule.
			$this->unschedule_bulk();
			return;
		}

		$generator = new Mako_Generator();
		$storage   = new Mako_Storage();

		foreach ( $post_ids as $post_id ) {
			$result = $generator->generate( (int) $post_id );
			if ( $result ) {
				$storage->save( (int) $post_id, $result );
			}
		}
	}

	/**
	 * Process a single post regeneration (used for async triggers).
	 */
	public function process_single( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		$generator = new Mako_Generator();
		$result    = $generator->generate( $post_id );

		if ( $result ) {
			$storage = new Mako_Storage();
			$storage->save( $post_id, $result );
		}
	}

	/**
	 * Schedule async generation for a post.
	 */
	public static function schedule_post( int $post_id ): void {
		if ( ! wp_next_scheduled( self::HOOK_SINGLE, array( $post_id ) ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK_SINGLE, array( $post_id ) );
		}
	}
}
