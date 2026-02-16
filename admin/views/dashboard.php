<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$storage       = new Mako_Storage();
$stats         = $storage->get_stats();
$enabled_types = Mako_Plugin::get_enabled_post_types();

// Pagination.
$per_page    = 20;
$paged       = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
$offset      = ( $paged - 1 ) * $per_page;
$filter_type = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
$filter_arr  = '' !== $filter_type ? array( $filter_type ) : array();

$total_items = $storage->count_generated_posts( $filter_arr );
$total_pages = (int) ceil( $total_items / $per_page );
$posts       = $storage->get_generated_posts( $per_page, $offset, $filter_arr );

$base_url = admin_url( 'admin.php?page=mako-dashboard' );
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

	<!-- Generation Panel -->
	<div class="mako-generation-panel">
		<h2><?php esc_html_e( 'Content Generation', 'mako-wp' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'MAKO fetches the public URL of each page to capture the final rendered HTML, then converts it to optimized markdown.', 'mako-wp' ); ?>
		</p>

		<!-- Post Type Filter -->
		<div class="mako-type-filter">
			<strong><?php esc_html_e( 'Generate for:', 'mako-wp' ); ?></strong>
			<?php
			$type_labels = array(
				'post'    => __( 'Posts', 'mako-wp' ),
				'page'    => __( 'Pages', 'mako-wp' ),
				'product' => __( 'Products', 'mako-wp' ),
			);
			foreach ( $enabled_types as $pt ) :
				$label = $type_labels[ $pt ] ?? $pt;
			?>
				<label class="mako-type-checkbox">
					<input type="checkbox" class="mako-generate-type" value="<?php echo esc_attr( $pt ); ?>" checked>
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</div>

		<div class="mako-controls-row">
			<button type="button" class="button" id="mako-test-one">
				<?php esc_html_e( 'Test 1 Post', 'mako-wp' ); ?>
			</button>

			<span class="mako-controls-separator">|</span>

			<!-- Batch generation -->
			<span class="mako-batch-label"><?php esc_html_e( 'Generate:', 'mako-wp' ); ?></span>
			<button type="button" class="button mako-btn-batch" data-batch="10">+10</button>
			<button type="button" class="button mako-btn-batch" data-batch="20">+20</button>
			<button type="button" class="button mako-btn-batch" data-batch="50">+50</button>
			<button type="button" class="button button-primary mako-btn-batch" data-batch="0">
				<?php esc_html_e( 'All Pending', 'mako-wp' ); ?>
			</button>

			<span class="mako-controls-separator">|</span>

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
		</div>

		<div class="mako-controls-row" style="margin-top:8px">
			<button type="button" class="button" id="mako-flush-cache">
				<?php esc_html_e( 'Flush Cache', 'mako-wp' ); ?>
			</button>

			<?php if ( get_option( 'mako_sitemap_enabled', true ) ) : ?>
			<a href="<?php echo esc_url( home_url( '/mako-sitemap.json' ) ); ?>" target="_blank" class="button">
				<?php esc_html_e( 'View Sitemap', 'mako-wp' ); ?>
			</a>
			<?php endif; ?>

			<?php if ( get_option( 'mako_well_known', true ) ) : ?>
			<a href="<?php echo esc_url( home_url( '/.well-known/mako' ) ); ?>" target="_blank" class="button">
				<?php esc_html_e( 'View .well-known/mako', 'mako-wp' ); ?>
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

	<!-- Generated Content Table -->
	<h2>
		<?php esc_html_e( 'Generated Content', 'mako-wp' ); ?>
		<span class="mako-table-count">(<?php echo esc_html( $total_items ); ?>)</span>
	</h2>

	<?php if ( ! empty( $posts ) ) : ?>

	<!-- Type filter tabs -->
	<ul class="subsubsub">
		<li>
			<a href="<?php echo esc_url( $base_url ); ?>" <?php echo '' === $filter_type ? 'class="current"' : ''; ?>>
				<?php esc_html_e( 'All', 'mako-wp' ); ?>
				<span class="count">(<?php echo esc_html( $storage->count_generated_posts() ); ?>)</span>
			</a>
		</li>
		<?php foreach ( $enabled_types as $pt ) :
			$count = $storage->count_generated_posts( array( $pt ) );
			if ( 0 === $count ) continue;
			$label = $type_labels[ $pt ] ?? $pt;
		?>
		<li>
			| <a href="<?php echo esc_url( add_query_arg( 'post_type', $pt, $base_url ) ); ?>" <?php echo $filter_type === $pt ? 'class="current"' : ''; ?>>
				<?php echo esc_html( $label ); ?>
				<span class="count">(<?php echo esc_html( $count ); ?>)</span>
			</a>
		</li>
		<?php endforeach; ?>
	</ul>

	<!-- Bulk Actions (top) -->
	<div class="tablenav top">
		<div class="alignleft actions bulkactions">
			<select id="mako-bulk-action-top">
				<option value=""><?php esc_html_e( 'Bulk Actions', 'mako-wp' ); ?></option>
				<option value="regenerate"><?php esc_html_e( 'Regenerate', 'mako-wp' ); ?></option>
				<option value="delete"><?php esc_html_e( 'Delete MAKO', 'mako-wp' ); ?></option>
			</select>
			<button type="button" class="button mako-btn-apply-bulk" data-selector="#mako-bulk-action-top">
				<?php esc_html_e( 'Apply', 'mako-wp' ); ?>
			</button>
		</div>

		<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav-pages">
			<span class="displaying-num">
				<?php
				printf(
					/* translators: %s: number of items */
					esc_html( _n( '%s item', '%s items', $total_items, 'mako-wp' ) ),
					esc_html( number_format_i18n( $total_items ) )
				);
				?>
			</span>
			<span class="pagination-links">
				<?php if ( $paged > 1 ) : ?>
					<a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1, $base_url ) . ( $filter_type ? '&post_type=' . $filter_type : '' ) ); ?>">&laquo;</a>
					<a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, $base_url ) . ( $filter_type ? '&post_type=' . $filter_type : '' ) ); ?>">&lsaquo;</a>
				<?php else : ?>
					<span class="tablenav-pages-navspan button disabled">&laquo;</span>
					<span class="tablenav-pages-navspan button disabled">&lsaquo;</span>
				<?php endif; ?>

				<span class="paging-input">
					<span class="tablenav-paging-text">
						<?php echo esc_html( $paged ); ?> / <span class="total-pages"><?php echo esc_html( $total_pages ); ?></span>
					</span>
				</span>

				<?php if ( $paged < $total_pages ) : ?>
					<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, $base_url ) . ( $filter_type ? '&post_type=' . $filter_type : '' ) ); ?>">&rsaquo;</a>
					<a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $total_pages, $base_url ) . ( $filter_type ? '&post_type=' . $filter_type : '' ) ); ?>">&raquo;</a>
				<?php else : ?>
					<span class="tablenav-pages-navspan button disabled">&rsaquo;</span>
					<span class="tablenav-pages-navspan button disabled">&raquo;</span>
				<?php endif; ?>
			</span>
		</div>
		<?php endif; ?>
	</div>

	<table class="wp-list-table widefat fixed striped mako-table">
		<thead>
			<tr>
				<td class="manage-column column-cb check-column">
					<input type="checkbox" id="mako-select-all">
				</td>
				<th><?php esc_html_e( 'Post', 'mako-wp' ); ?></th>
				<th><?php esc_html_e( 'Type', 'mako-wp' ); ?></th>
				<th><?php esc_html_e( 'MAKO Type', 'mako-wp' ); ?></th>
				<th><?php esc_html_e( 'HTML Tokens', 'mako-wp' ); ?></th>
				<th><?php esc_html_e( 'MAKO Tokens', 'mako-wp' ); ?></th>
				<th><?php esc_html_e( 'Savings', 'mako-wp' ); ?></th>
				<th><?php esc_html_e( 'Updated', 'mako-wp' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'mako-wp' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $posts as $item ) : ?>
			<tr id="mako-row-<?php echo esc_attr( $item['post_id'] ); ?>">
				<th scope="row" class="check-column">
					<input type="checkbox" class="mako-row-check" value="<?php echo esc_attr( $item['post_id'] ); ?>">
				</th>
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
				<td>
					<button type="button" class="button-link mako-btn-preview" data-post-id="<?php echo esc_attr( $item['post_id'] ); ?>">
						<?php esc_html_e( 'Preview', 'mako-wp' ); ?>
					</button>
					<span class="mako-action-sep">|</span>
					<button type="button" class="button-link mako-btn-regenerate" data-post-id="<?php echo esc_attr( $item['post_id'] ); ?>">
						<?php esc_html_e( 'Regen', 'mako-wp' ); ?>
					</button>
					<span class="mako-action-sep">|</span>
					<button type="button" class="button-link mako-btn-delete-mako" data-post-id="<?php echo esc_attr( $item['post_id'] ); ?>">
						<span style="color:#b32d2e"><?php esc_html_e( 'Delete', 'mako-wp' ); ?></span>
					</button>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<!-- Bulk Actions (bottom) -->
	<?php if ( $total_pages > 1 ) : ?>
	<div class="tablenav bottom">
		<div class="alignleft actions bulkactions">
			<select id="mako-bulk-action-bottom">
				<option value=""><?php esc_html_e( 'Bulk Actions', 'mako-wp' ); ?></option>
				<option value="regenerate"><?php esc_html_e( 'Regenerate', 'mako-wp' ); ?></option>
				<option value="delete"><?php esc_html_e( 'Delete MAKO', 'mako-wp' ); ?></option>
			</select>
			<button type="button" class="button mako-btn-apply-bulk" data-selector="#mako-bulk-action-bottom">
				<?php esc_html_e( 'Apply', 'mako-wp' ); ?>
			</button>
		</div>
		<div class="tablenav-pages">
			<span class="pagination-links">
				<?php if ( $paged > 1 ) : ?>
					<a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, $base_url ) . ( $filter_type ? '&post_type=' . $filter_type : '' ) ); ?>">&lsaquo;</a>
				<?php endif; ?>
				<span class="paging-input"><?php echo esc_html( $paged ); ?> / <?php echo esc_html( $total_pages ); ?></span>
				<?php if ( $paged < $total_pages ) : ?>
					<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, $base_url ) . ( $filter_type ? '&post_type=' . $filter_type : '' ) ); ?>">&rsaquo;</a>
				<?php endif; ?>
			</span>
		</div>
	</div>
	<?php endif; ?>

	<?php else : ?>
		<div class="mako-empty-state">
			<p><?php esc_html_e( 'No MAKO content generated yet. Use "Test 1 Post" to verify everything works, then generate posts in batches.', 'mako-wp' ); ?></p>
		</div>
	<?php endif; ?>
</div>

<!-- Preview Modal -->
<div id="mako-preview-modal" class="mako-modal" style="display:none">
	<div class="mako-modal-overlay"></div>
	<div class="mako-modal-content">
		<div class="mako-modal-header">
			<h3><?php esc_html_e( 'MAKO Preview', 'mako-wp' ); ?></h3>
			<div class="mako-modal-actions">
				<button type="button" class="button button-small" id="mako-copy-preview">
					<?php esc_html_e( 'Copy', 'mako-wp' ); ?>
				</button>
				<button class="mako-modal-close">&times;</button>
			</div>
		</div>
		<div class="mako-modal-body">
			<pre class="mako-preview-code" id="mako-preview-content"></pre>
		</div>
	</div>
</div>
