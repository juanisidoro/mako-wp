<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Validator {

	private const VALID_TYPES = array(
		'product', 'article', 'docs', 'landing', 'listing',
		'profile', 'event', 'recipe', 'faq', 'custom',
	);

	private const VALID_FRESHNESS = array(
		'realtime', 'hourly', 'daily', 'weekly', 'monthly', 'static',
	);

	private const VALID_LINK_TYPES = array(
		'parent', 'child', 'sibling', 'source', 'competitor', 'reference',
	);

	/**
	 * Validate a MAKO data array.
	 *
	 * @return array{valid: bool, errors: string[], warnings: string[]}
	 */
	public function validate( array $frontmatter, string $body = '' ): array {
		$errors   = array();
		$warnings = array();

		// Required fields.
		$required = array( 'mako', 'type', 'entity', 'updated', 'tokens', 'language' );
		foreach ( $required as $field ) {
			if ( empty( $frontmatter[ $field ] ) && 0 !== ( $frontmatter[ $field ] ?? null ) ) {
				$errors[] = sprintf( 'Missing required field: %s', $field );
			}
		}

		// Type validation.
		if ( ! empty( $frontmatter['type'] ) && ! in_array( $frontmatter['type'], self::VALID_TYPES, true ) ) {
			$errors[] = sprintf(
				'Invalid content type: "%s". Must be one of: %s',
				$frontmatter['type'],
				implode( ', ', self::VALID_TYPES )
			);
		}

		// Tokens validation.
		if ( isset( $frontmatter['tokens'] ) ) {
			if ( (int) $frontmatter['tokens'] <= 0 ) {
				$errors[] = 'Token count must be positive';
			} elseif ( (int) $frontmatter['tokens'] > 1000 ) {
				$warnings[] = sprintf( 'Token count exceeds recommended maximum of 1000 (%d)', $frontmatter['tokens'] );
			}
		}

		// Body validation.
		if ( '' === trim( $body ) ) {
			$errors[] = 'Body is empty';
		}

		// Freshness validation.
		if ( ! empty( $frontmatter['freshness'] ) && ! in_array( $frontmatter['freshness'], self::VALID_FRESHNESS, true ) ) {
			$errors[] = sprintf(
				'Invalid freshness: "%s". Must be one of: %s',
				$frontmatter['freshness'],
				implode( ', ', self::VALID_FRESHNESS )
			);
		}

		// Summary length.
		if ( ! empty( $frontmatter['summary'] ) && mb_strlen( $frontmatter['summary'] ) > 160 ) {
			$warnings[] = sprintf(
				'Summary exceeds 160 characters (%d)',
				mb_strlen( $frontmatter['summary'] )
			);
		}

		// Actions validation.
		if ( ! empty( $frontmatter['actions'] ) && is_array( $frontmatter['actions'] ) ) {
			foreach ( $frontmatter['actions'] as $i => $action ) {
				if ( empty( $action['name'] ) ) {
					$errors[] = sprintf( 'Action #%d: missing required field "name"', $i + 1 );
				} elseif ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $action['name'] ) ) {
					$warnings[] = sprintf( 'Action "%s": name should be snake_case', $action['name'] );
				}
				if ( empty( $action['description'] ) ) {
					$errors[] = sprintf( 'Action #%d: missing required field "description"', $i + 1 );
				}
			}
		}

		// Links validation.
		if ( ! empty( $frontmatter['links'] ) ) {
			foreach ( array( 'internal', 'external' ) as $group ) {
				if ( ! empty( $frontmatter['links'][ $group ] ) && is_array( $frontmatter['links'][ $group ] ) ) {
					foreach ( $frontmatter['links'][ $group ] as $i => $link ) {
						$label = ucfirst( $group ) . " link #" . ( $i + 1 );
						if ( empty( $link['url'] ) ) {
							$errors[] = "$label: missing required field \"url\"";
						}
						if ( empty( $link['context'] ) ) {
							$errors[] = "$label: missing required field \"context\"";
						}
						if ( ! empty( $link['type'] ) && ! in_array( $link['type'], self::VALID_LINK_TYPES, true ) ) {
							$errors[] = sprintf(
								'%s: invalid link type "%s". Must be one of: %s',
								$label,
								$link['type'],
								implode( ', ', self::VALID_LINK_TYPES )
							);
						}
					}
				}
			}
		}

		return array(
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}
}
