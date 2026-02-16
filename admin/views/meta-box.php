<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var WP_Post $post */
/** @var array|null $data */
/** @var array $custom */
/** @var bool $enabled */
/** @var string $effective_type */
/** @var string $effective_entity */
/** @var string|null $effective_content */
/** @var bool $has_custom */
/** @var int $cover_id */
/** @var string $cover_url */
/** @var array $content_types */
?>
<div class="mako-meta-box">
	<!-- Enable/Disable -->
	<p>
		<label>
			<input type="checkbox" name="mako_enabled" value="1" <?php checked( $enabled ); ?>>
			<?php esc_html_e( 'Enable MAKO for this post', 'mako-wp' ); ?>
		</label>
	</p>

	<?php if ( $data || $has_custom ) : ?>

		<!-- Status Bar -->
		<div class="mako-meta-status mako-meta-status--generated">
			<span class="dashicons dashicons-yes-alt"></span>
			<?php if ( $has_custom ) : ?>
				<?php esc_html_e( 'Custom MAKO', 'mako-wp' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Auto-generated', 'mako-wp' ); ?>
			<?php endif; ?>
		</div>

		<!-- Editable Fields -->
		<table class="mako-meta-table mako-meta-table--edit">
			<tr>
				<td><label for="mako_custom_type"><?php esc_html_e( 'Type', 'mako-wp' ); ?></label></td>
				<td>
					<select name="mako_custom_type" id="mako_custom_type">
						<?php foreach ( $content_types as $ct ) : ?>
							<option value="<?php echo esc_attr( $ct ); ?>" <?php selected( $effective_type, $ct ); ?>>
								<?php echo esc_html( $ct ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<td><label for="mako_custom_entity"><?php esc_html_e( 'Entity', 'mako-wp' ); ?></label></td>
				<td>
					<input type="text" name="mako_custom_entity" id="mako_custom_entity"
						value="<?php echo esc_attr( $effective_entity ); ?>"
						class="widefat" maxlength="100">
				</td>
			</tr>
			<tr>
				<td><label><?php esc_html_e( 'Cover', 'mako-wp' ); ?></label></td>
				<td>
					<div class="mako-cover-field">
						<?php if ( $cover_url ) : ?>
							<img src="<?php echo esc_url( $cover_url ); ?>" class="mako-cover-preview" alt="">
						<?php endif; ?>
						<input type="hidden" name="mako_custom_cover" id="mako_custom_cover"
							value="<?php echo esc_attr( $cover_id ); ?>">
						<button type="button" class="button mako-btn-cover-select">
							<?php echo $cover_url ? esc_html__( 'Change', 'mako-wp' ) : esc_html__( 'Select Image', 'mako-wp' ); ?>
						</button>
						<?php if ( $cover_url ) : ?>
							<button type="button" class="button mako-btn-cover-remove">
								<?php esc_html_e( 'Remove', 'mako-wp' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</td>
			</tr>
		</table>

		<!-- MAKO Content Editor -->
		<div class="mako-editor-section">
			<div class="mako-editor-header">
				<label for="mako_custom_content">
					<strong><?php esc_html_e( 'MAKO Content', 'mako-wp' ); ?></strong>
				</label>
				<span class="mako-editor-hint">
					<?php esc_html_e( 'Full MAKO file (frontmatter + markdown). Edit or use Auto-generate.', 'mako-wp' ); ?>
				</span>
			</div>
			<textarea name="mako_custom_content" id="mako_custom_content"
				class="mako-content-editor" rows="18"
				placeholder="<?php esc_attr_e( 'Auto-generated content will appear here. Edit to customize.', 'mako-wp' ); ?>"
			><?php echo esc_textarea( $effective_content ?? '' ); ?></textarea>
		</div>

		<!-- Metrics -->
		<?php if ( $data ) : ?>
			<div class="mako-meta-metrics">
				<span title="<?php esc_attr_e( 'MAKO tokens', 'mako-wp' ); ?>">
					<span class="dashicons dashicons-editor-code"></span>
					<?php echo esc_html( number_format( $data['tokens'] ) ); ?> tokens
				</span>
				<span title="<?php esc_attr_e( 'HTML tokens', 'mako-wp' ); ?>">
					<span class="dashicons dashicons-html"></span>
					<?php echo esc_html( number_format( $data['html_tokens'] ) ); ?> HTML
				</span>
				<span title="<?php esc_attr_e( 'Savings', 'mako-wp' ); ?>">
					<span class="dashicons dashicons-performance"></span>
					<?php echo esc_html( $data['savings'] ); ?>%
				</span>
			</div>
		<?php endif; ?>

		<!-- Actions -->
		<div class="mako-meta-actions">
			<button type="button" class="button button-primary mako-btn-regenerate"
				data-post-id="<?php echo esc_attr( $post->ID ); ?>"
				title="<?php esc_attr_e( 'Re-generate from WordPress content (overwrites editor)', 'mako-wp' ); ?>">
				<span class="dashicons dashicons-update"></span>
				<?php esc_html_e( 'Auto-generate', 'mako-wp' ); ?>
			</button>
			<?php if ( '' !== get_option( 'mako_ai_api_key', '' ) ) : ?>
				<button type="button" class="button mako-btn-ai-enhance"
					data-post-id="<?php echo esc_attr( $post->ID ); ?>"
					title="<?php esc_attr_e( 'Use AI to improve the MAKO content based on the page', 'mako-wp' ); ?>">
					<span class="dashicons dashicons-superhero-alt"></span>
					<?php esc_html_e( 'Enhance with AI', 'mako-wp' ); ?>
				</button>
			<?php endif; ?>
			<button type="button" class="button mako-btn-preview"
				data-post-id="<?php echo esc_attr( $post->ID ); ?>">
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
			<button type="button" class="button button-primary mako-btn-generate"
				data-post-id="<?php echo esc_attr( $post->ID ); ?>">
				<span class="dashicons dashicons-update"></span>
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
