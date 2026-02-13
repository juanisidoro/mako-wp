<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Response {

	/**
	 * Send a full MAKO response.
	 */
	public function send( array $data, string $etag = '', bool $head_only = false ): void {
		// Set MAKO headers.
		if ( ! empty( $data['headers'] ) && is_array( $data['headers'] ) ) {
			foreach ( $data['headers'] as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}

		// ETag.
		if ( '' !== $etag ) {
			header( 'ETag: ' . $etag );
		}

		// Set status.
		status_header( 200 );

		if ( $head_only ) {
			// HEAD request: headers only, no body.
			exit;
		}

		// Send body.
		echo $data['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MAKO content is pre-sanitized markdown.
		exit;
	}

	/**
	 * Send 304 Not Modified response.
	 */
	public function send_not_modified( string $etag ): void {
		status_header( 304 );
		header( 'ETag: ' . $etag );
		header( 'Vary: Accept' );
		exit;
	}
}
