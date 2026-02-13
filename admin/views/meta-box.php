<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var WP_Post $post */
/** @var array|null $data */
/** @var bool $enabled */
?>
<div class="mako-meta-box">
	<!-- Enable/Disable -->
	<p>
		<label>
			<input type="checkbox" name="mako_enabled" value="1" <?php checked( $enabled ); ?>>
			<?php esc_html_e( 'Enable MAKO for this post', 'mako-wp' ); ?>
		</label>
	</p>

	<?php if ( $data ) : ?>
		<!-- Status: Generated -->
		<div class="mako-meta-status mako-meta-status--generated">
			<span class="dashicons dashicons-yes-alt"></span>
			<?php esc_html_e( 'MAKO Generated', 'mako-wp' ); ?>
		</div>

		<table class="mako-meta-table">
			<tr>
				<td><?php esc_html_e( 'Type', 'mako-wp' ); ?></td>
				<td><span class="mako-badge mako-badge-<?php echo esc_attr( $data['type'] ); ?>"><?php echo esc_html( $data['type'] ); ?></span></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'HTML Tokens', 'mako-wp' ); ?></td>
				<td><?php echo esc_html( number_format( $data['html_tokens'] ) ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'MAKO Tokens', 'mako-wp' ); ?></td>
				<td><?php echo esc_html( number_format( $data['tokens'] ) ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Savings', 'mako-wp' ); ?></td>
				<td><strong><?php echo esc_html( $data['savings'] ); ?>%</strong></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Updated', 'mako-wp' ); ?></td>
				<td><?php echo esc_html( $data['updated_at'] ? wp_date( 'Y-m-d H:i', strtotime( $data['updated_at'] ) ) : '-' ); ?></td>
			</tr>
		</table>

		<div class="mako-meta-actions">
			<button type="button" class="button mako-btn-regenerate" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
				<?php esc_html_e( 'Regenerate', 'mako-wp' ); ?>
			</button>
			<button type="button" class="button mako-btn-preview" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
				<?php esc_html_e( 'Preview', 'mako-wp' ); ?>
			</button>
		</div>

	<?php else : ?>
		<!-- Status: Not Generated -->
		<div class="mako-meta-status mako-meta-status--pending">
			<span class="dashicons dashicons-clock"></span>
			<?php esc_html_e( 'Not generated yet', 'mako-wp' ); ?>
		</div>

		<?php if ( 'publish' === $post->post_status ) : ?>
			<button type="button" class="button button-primary mako-btn-generate" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
				<?php esc_html_e( 'Generate Now', 'mako-wp' ); ?>
			</button>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'MAKO will be generated when this post is published.', 'mako-wp' ); ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</div>

<!-- Preview Modal -->
<div id="mako-preview-modal" class="mako-modal" style="display:none;">
	<div class="mako-modal-overlay"></div>
	<div class="mako-modal-content">
		<div class="mako-modal-header">
			<h3><?php esc_html_e( 'MAKO Preview', 'mako-wp' ); ?></h3>
			<button type="button" class="mako-modal-close">&times;</button>
		</div>
		<div class="mako-modal-body">
			<pre class="mako-preview-code"><code id="mako-preview-content"></code></pre>
		</div>
	</div>
</div>
