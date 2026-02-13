<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'MAKO Settings', 'mako-wp' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'Configure how MAKO generates AI-optimized content for your WordPress site.', 'mako-wp' ); ?>
		<a href="https://makospec.vercel.app" target="_blank" rel="noopener">
			<?php esc_html_e( 'Learn more about MAKO', 'mako-wp' ); ?> &rarr;
		</a>
	</p>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'mako_settings' );
		do_settings_sections( 'mako-settings' );
		submit_button();
		?>
	</form>
</div>
