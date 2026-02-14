<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$storage = new Mako_Storage();
$stats   = $storage->get_stats();
$posts   = $storage->get_generated_posts( 20 );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'MAKO Dashboard', 'mako-wp' ); ?></h1>

	<!-- Stats Cards -->
	<div class="mako-stats-grid">
		<div class="mako-stat-card">
			<span class="mako-stat-number"><?php echo esc_html( $stats['total'] ); ?></span>
			<span class="mako-stat-label"><?php esc_html_e( 'Pages Generated', 'mako-wp' ); ?></span>
		</div>
		<div class="mako-stat-card">
			<span class="mako-stat-number"><?php echo esc_html( $stats['avg_savings'] ); ?>%</span>
			<span class="mako-stat-label"><?php esc_html_e( 'Avg. Token Savings', 'mako-wp' ); ?></span>
		</div>
		<div class="mako-stat-card">
			<span class="mako-stat-number"><?php echo esc_html( number_format( $stats['total_tokens_saved'] ) ); ?></span>
			<span class="mako-stat-label"><?php esc_html_e( 'Total Tokens Saved', 'mako-wp' ); ?></span>
		</div>
		<div class="mako-stat-card">
			<span class="mako-stat-number">v<?php echo esc_html( MAKO_SPEC_VERSION ); ?></span>
			<span class="mako-stat-label"><?php esc_html_e( 'MAKO Spec Version', 'mako-wp' ); ?></span>
		</div>
	</div>

	<!-- Generation Controls -->
	<div class="mako-generation-panel">
		<h2><?php esc_html_e( 'Content Generation', 'mako-wp' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'MAKO fetches the public URL of each page to capture the final rendered HTML, then converts it to optimized markdown. This process is safe and does not modify your content.', 'mako-wp' ); ?>
		</p>

		<div class="mako-controls-row">
			<button type="button" class="button" id="mako-test-one">
				<?php esc_html_e( 'Test 1 Post', 'mako-wp' ); ?>
			</button>

			<button type="button" class="button button-primary" id="mako-start-bulk">
				<?php esc_html_e( 'Start Generation', 'mako-wp' ); ?>
			</button>

			<button type="button" class="button" id="mako-pause-bulk" disabled>
				<?php esc_html_e( 'Pause', 'mako-wp' ); ?>
			</button>

			<button type="button" class="button" id="mako-stop-bulk" disabled>
				<?php esc_html_e( 'Stop', 'mako-wp' ); ?>
			</button>

			<span class="mako-controls-separator">|</span>

			<label for="mako-delay">
				<?php esc_html_e( 'Delay:', 'mako-wp' ); ?>
			</label>
			<select id="mako-delay">
				<option value="2000">2s</option>
				<option value="3000" selected>3s</option>
				<option value="5000">5s</option>
				<option value="10000">10s</option>
			</select>

			<span class="mako-controls-separator">|</span>

			<button type="button" class="button" id="mako-flush-cache">
				<?php esc_html_e( 'Flush Cache', 'mako-wp' ); ?>
			</button>

			<?php if ( get_option( 'mako_sitemap_enabled', true ) ) : ?>
			<a href="<?php echo esc_url( home_url( '/mako-sitemap.json' ) ); ?>" target="_blank" class="button">
				<?php esc_html_e( 'View Sitemap', 'mako-wp' ); ?>
			</a>
			<?php endif; ?>
		</div>

		<!-- Progress Bar -->
		<div class="mako-progress-container" id="mako-progress-container" style="display:none">
			<div class="mako-progress-info">
				<span id="mako-progress-text"></span>
				<span id="mako-progress-count"></span>
			</div>
			<div class="mako-progress-bar">
				<div class="mako-progress-fill" id="mako-progress-fill" style="width:0%"></div>
			</div>
		</div>

		<!-- Log -->
		<div class="mako-log-container" id="mako-log-container" style="display:none">
			<div class="mako-log-header">
				<strong><?php esc_html_e( 'Generation Log', 'mako-wp' ); ?></strong>
				<button type="button" class="button-link" id="mako-clear-log">
					<?php esc_html_e( 'Clear', 'mako-wp' ); ?>
				</button>
			</div>
			<div class="mako-log" id="mako-log"></div>
		</div>
	</div>

	<!-- Posts Table -->
	<?php if ( ! empty( $posts ) ) : ?>
	<h2><?php esc_html_e( 'Generated Content', 'mako-wp' ); ?></h2>
	<table class="wp-list-table widefat fixed striped mako-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Post', 'mako-wp' ); ?></th>
				<th><?php esc_html_e( 'Type', 'mako-wp' ); ?></th>
				<th><?php esc_html_e( 'MAKO Type', 'mako-wp' ); ?></th>
				<th><?php esc_html_e( 'HTML Tokens', 'mako-wp' ); ?></th>
				<th><?php esc_html_e( 'MAKO Tokens', 'mako-wp' ); ?></th>
				<th><?php esc_html_e( 'Savings', 'mako-wp' ); ?></th>
				<th><?php esc_html_e( 'Updated', 'mako-wp' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $posts as $item ) : ?>
			<tr>
				<td>
					<strong>
						<a href="<?php echo esc_url( get_edit_post_link( $item['post_id'] ) ); ?>">
							<?php echo esc_html( $item['title'] ); ?>
						</a>
					</strong>
				</td>
				<td><code><?php echo esc_html( $item['post_type'] ); ?></code></td>
				<td><span class="mako-badge mako-badge-<?php echo esc_attr( $item['type'] ); ?>"><?php echo esc_html( $item['type'] ); ?></span></td>
				<td><?php echo esc_html( number_format( $item['html_tokens'] ) ); ?></td>
				<td><?php echo esc_html( number_format( $item['tokens'] ) ); ?></td>
				<td><strong><?php echo esc_html( $item['savings'] ); ?>%</strong></td>
				<td><?php echo esc_html( $item['updated_at'] ? wp_date( 'Y-m-d H:i', strtotime( $item['updated_at'] ) ) : '-' ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php else : ?>
		<div class="mako-empty-state">
			<p><?php esc_html_e( 'No MAKO content generated yet. Use "Test 1 Post" to verify everything works, then "Start Generation" to process all pending pages.', 'mako-wp' ); ?></p>
		</div>
	<?php endif; ?>
</div>
