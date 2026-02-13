<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Link_Extractor {

	private const MAX_INTERNAL = 10;
	private const MAX_EXTERNAL = 5;
	private const MAX_CONTEXT  = 120;

	private const SKIP_PATTERNS = array(
		'/privac/i', '/cookie/i', '/legal/i', '/terms/i',
		'/condiciones/i', '/aviso-legal/i', '/politica-de/i',
		'/wp-admin/i', '/wp-login/i', '/wp-content/i',
		'/feed\/?$/i', '/xmlrpc/i', '/wp-json/i',
		'/login/i', '/register/i', '/cart/i', '/checkout/i',
		'/my-account/i',
	);

	/**
	 * Extract semantic links from post content HTML.
	 *
	 * @return array{internal: array, external: array}
	 */
	public function extract( string $html, string $site_url ): array {
		$internal = array();
		$external = array();
		$seen     = array();

		if ( '' === trim( $html ) ) {
			return compact( 'internal', 'external' );
		}

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<?xml encoding="utf-8" ?>' . mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ),
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
		);
		libxml_clear_errors();

		$links     = $doc->getElementsByTagName( 'a' );
		$site_host = wp_parse_url( $site_url, PHP_URL_HOST );
		$site_host = preg_replace( '/^www\./', '', $site_host );

		foreach ( $links as $link ) {
			if ( count( $internal ) >= self::MAX_INTERNAL && count( $external ) >= self::MAX_EXTERNAL ) {
				break;
			}

			$href = $link->getAttribute( 'href' );
			if ( '' === $href ) {
				continue;
			}

			// Skip anchors, javascript, mailto, tel.
			if ( str_starts_with( $href, '#' ) || str_starts_with( $href, 'javascript:' )
				|| str_starts_with( $href, 'mailto:' ) || str_starts_with( $href, 'tel:' )
				|| str_starts_with( $href, 'data:' ) ) {
				continue;
			}

			// Resolve relative URLs.
			if ( ! preg_match( '#^https?://#', $href ) ) {
				$href = rtrim( $site_url, '/' ) . '/' . ltrim( $href, '/' );
			}

			// Skip blocked patterns.
			if ( $this->should_skip( $href ) ) {
				continue;
			}

			// Normalize: remove fragment, trailing slash.
			$parsed = wp_parse_url( $href );
			$normalized = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' )
				. rtrim( $parsed['path'] ?? '', '/' );
			if ( ! empty( $parsed['query'] ) ) {
				$normalized .= '?' . $parsed['query'];
			}

			if ( isset( $seen[ $normalized ] ) ) {
				continue;
			}
			$seen[ $normalized ] = true;

			// Get context.
			$context = $this->get_context( $link );
			if ( '' === $context ) {
				continue;
			}

			$link_host = preg_replace( '/^www\./', '', $parsed['host'] ?? '' );

			if ( $link_host === $site_host && count( $internal ) < self::MAX_INTERNAL ) {
				// Internal: store relative path.
				$path = rtrim( $parsed['path'] ?? '/', '/' );
				if ( '' === $path ) {
					$path = '/';
				}
				if ( ! empty( $parsed['query'] ) ) {
					$path .= '?' . $parsed['query'];
				}

				$internal[] = array(
					'url'     => $path,
					'context' => $context,
				);
			} elseif ( $link_host !== $site_host && count( $external ) < self::MAX_EXTERNAL ) {
				$external[] = array(
					'url'     => $normalized,
					'context' => $context,
				);
			}
		}

		$result = compact( 'internal', 'external' );

		return apply_filters( 'mako_links', $result, $html, $site_url );
	}

	/**
	 * Check if a URL should be skipped.
	 */
	private function should_skip( string $url ): bool {
		foreach ( self::SKIP_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $url ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get context text for a link.
	 */
	private function get_context( DOMElement $link ): string {
		// 1. Link text.
		$text = trim( $link->textContent );
		if ( mb_strlen( $text ) >= 2 && mb_strlen( $text ) <= self::MAX_CONTEXT ) {
			return $text;
		}

		// 2. aria-label.
		$aria = $link->getAttribute( 'aria-label' );
		if ( '' !== $aria ) {
			return mb_substr( trim( $aria ), 0, self::MAX_CONTEXT );
		}

		// 3. title attribute.
		$title = $link->getAttribute( 'title' );
		if ( '' !== $title ) {
			return mb_substr( trim( $title ), 0, self::MAX_CONTEXT );
		}

		// 4. If link text is too long, truncate.
		if ( mb_strlen( $text ) > self::MAX_CONTEXT ) {
			return mb_substr( $text, 0, self::MAX_CONTEXT - 3 ) . '...';
		}

		return '';
	}
}
