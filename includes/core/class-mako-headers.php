<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Headers {

	/**
	 * Build HTTP response headers from frontmatter data.
	 *
	 * @return array<string, string>
	 */
	public static function build( array $frontmatter, string $canonical = '' ): array {
		$headers = array(
			'Content-Type'    => 'text/mako+markdown; charset=utf-8',
			'X-Mako-Version'  => $frontmatter['mako'] ?? MAKO_SPEC_VERSION,
			'X-Mako-Tokens'   => (string) ( $frontmatter['tokens'] ?? 0 ),
			'X-Mako-Type'     => $frontmatter['type'] ?? 'custom',
			'X-Mako-Lang'     => $frontmatter['language'] ?? 'en',
			'Vary'            => 'Accept',
			'Cache-Control'   => get_option( 'mako_cache_control', 'public, max-age=3600' ),
		);

		if ( ! empty( $frontmatter['entity'] ) && 'Unknown' !== $frontmatter['entity'] ) {
			$headers['X-Mako-Entity'] = $frontmatter['entity'];
		}

		if ( ! empty( $frontmatter['updated'] ) ) {
			$headers['X-Mako-Updated'] = $frontmatter['updated'] . 'T00:00:00Z';
		}

		if ( ! empty( $frontmatter['freshness'] ) ) {
			$headers['X-Mako-Freshness'] = $frontmatter['freshness'];
		}

		if ( ! empty( $frontmatter['actions'] ) && is_array( $frontmatter['actions'] ) ) {
			$action_names = array_column( $frontmatter['actions'], 'name' );
			if ( ! empty( $action_names ) ) {
				$headers['X-Mako-Actions'] = implode( ', ', $action_names );
			}
		}

		if ( '' !== $canonical ) {
			$headers['X-Mako-Canonical'] = $canonical;
		} elseif ( ! empty( $frontmatter['canonical'] ) ) {
			$headers['X-Mako-Canonical'] = $frontmatter['canonical'];
		}

		return $headers;
	}
}
