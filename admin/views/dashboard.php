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

	<!-- Actions -->
	<div class="mako-actions-bar">
		<button type="button" class="button button-primary" id="mako-bulk-generate">
			<?php esc_html_e( 'Generate All Missing', 'mako-wp' ); ?>
		</button>
		<button type="button" class="button" id="mako-flush-cache">
			<?php esc_html_e( 'Flush Cache', 'mako-wp' ); ?>
		</button>
		<?php if ( get_option( 'mako_sitemap_enabled', true ) ) : ?>
			<a href="<?php echo esc_url( home_url( '/.well-known/mako.json' ) ); ?>" target="_blank" class="button">
				<?php esc_html_e( 'View Sitemap', 'mako-wp' ); ?>
			</a>
		<?php endif; ?>
		<span id="mako-bulk-status" class="mako-status-message"></span>
	</div>

	<!-- Posts Table -->
	<?php if ( ! empty( $posts ) ) : ?>
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
			<p><?php esc_html_e( 'No MAKO content generated yet. Click "Generate All Missing" to start.', 'mako-wp' ); ?></p>
		</div>
	<?php endif; ?>
</div>
