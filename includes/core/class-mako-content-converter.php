<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Content_Converter {

	private const REMOVE_TAGS = array(
		'script', 'style', 'noscript', 'iframe', 'svg', 'form',
		'nav', 'footer', 'header', 'aside',
	);

	private const REMOVE_CLASSES = array(
		'ad', 'ads', 'advertisement', 'sidebar', 'widget',
		'cookie', 'consent', 'popup', 'modal', 'overlay',
		'social-share', 'share-buttons', 'newsletter', 'subscribe',
		'comments', 'comment-form', 'related-posts', 'breadcrumb',
		'breadcrumbs', 'pagination', 'footer', 'copyright',
	);

	private const REMOVE_ROLES = array(
		'navigation', 'banner', 'contentinfo', 'complementary',
	);

	private const CONTENT_SELECTORS = array(
		'main', 'article', '.entry-content', '.post-content',
		'.page-content', '.content', '.post', '.entry',
	);

	/**
	 * Convert rendered HTML to clean Markdown.
	 */
	public function convert( string $html, string $base_url = '' ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<?xml encoding="utf-8" ?>' . mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ),
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
		);
		libxml_clear_errors();

		$xpath = new DOMXPath( $doc );

		$this->remove_unwanted_nodes( $xpath );

		$content_node = $this->find_content_node( $xpath, $doc );

		$markdown = $this->convert_node( $content_node, $base_url );

		return $this->clean_markdown( $markdown );
	}

	/**
	 * Remove unwanted nodes from the DOM.
	 */
	private function remove_unwanted_nodes( DOMXPath $xpath ): void {
		// Remove by tag name.
		foreach ( self::REMOVE_TAGS as $tag ) {
			$nodes = $xpath->query( '//' . $tag );
			if ( $nodes ) {
				foreach ( $nodes as $node ) {
					if ( $node->parentNode ) {
						$node->parentNode->removeChild( $node );
					}
				}
			}
		}

		// Remove by ARIA role.
		foreach ( self::REMOVE_ROLES as $role ) {
			$nodes = $xpath->query( '//*[@role="' . $role . '"]' );
			if ( $nodes ) {
				foreach ( $nodes as $node ) {
					if ( $node->parentNode ) {
						$node->parentNode->removeChild( $node );
					}
				}
			}
		}

		// Remove by class name patterns.
		foreach ( self::REMOVE_CLASSES as $class ) {
			$nodes = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ") or contains(@class, "' . $class . '")]' );
			if ( $nodes ) {
				foreach ( $nodes as $node ) {
					if ( $node->parentNode ) {
						$node->parentNode->removeChild( $node );
					}
				}
			}
		}

		// Remove hidden elements.
		$hidden = $xpath->query( '//*[@hidden] | //*[@aria-hidden="true"] | //*[contains(@style, "display:none")] | //*[contains(@style, "display: none")]' );
		if ( $hidden ) {
			foreach ( $hidden as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}
	}

	/**
	 * Find the main content node using priority selectors.
	 */
	private function find_content_node( DOMXPath $xpath, DOMDocument $doc ): DOMNode {
		// Try semantic selectors.
		$selectors_xpath = array(
			'//main',
			'//article',
			'//*[@role="main"]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " page-content ")]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " content ")]',
		);

		foreach ( $selectors_xpath as $selector ) {
			$nodes = $xpath->query( $selector );
			if ( $nodes && $nodes->length > 0 ) {
				return $nodes->item( 0 );
			}
		}

		// Fallback to body.
		$body = $doc->getElementsByTagName( 'body' );
		if ( $body->length > 0 ) {
			return $body->item( 0 );
		}

		return $doc->documentElement;
	}

	/**
	 * Convert a DOM node to markdown recursively.
	 */
	private function convert_node( DOMNode $node, string $base_url ): string {
		if ( $node instanceof DOMText ) {
			return $this->normalize_text( $node->textContent );
		}

		if ( ! ( $node instanceof DOMElement ) ) {
			$result = '';
			foreach ( $node->childNodes as $child ) {
				$result .= $this->convert_node( $child, $base_url );
			}
			return $result;
		}

		$tag      = strtolower( $node->tagName );
		$children = $this->convert_children( $node, $base_url );

		return match ( $tag ) {
			'h1'         => "\n\n# " . trim( $children ) . "\n\n",
			'h2'         => "\n\n## " . trim( $children ) . "\n\n",
			'h3'         => "\n\n### " . trim( $children ) . "\n\n",
			'h4'         => "\n\n#### " . trim( $children ) . "\n\n",
			'h5'         => "\n\n##### " . trim( $children ) . "\n\n",
			'h6'         => "\n\n###### " . trim( $children ) . "\n\n",
			'p'          => "\n\n" . trim( $children ) . "\n\n",
			'br'         => "\n",
			'hr'         => "\n\n---\n\n",
			'strong', 'b' => '**' . trim( $children ) . '**',
			'em', 'i'    => '*' . trim( $children ) . '*',
			'code'       => $this->convert_inline_code( $node, $children ),
			'pre'        => $this->convert_code_block( $node ),
			'a'          => $this->convert_link( $node, $children, $base_url ),
			'img'        => $this->convert_image( $node, $base_url ),
			'ul'         => "\n\n" . $this->convert_list( $node, $base_url, false ) . "\n\n",
			'ol'         => "\n\n" . $this->convert_list( $node, $base_url, true ) . "\n\n",
			'li'         => trim( $children ),
			'blockquote' => $this->convert_blockquote( $children ),
			'table'      => "\n\n" . $this->convert_table( $node, $base_url ) . "\n\n",
			'figure'     => $children,
			'figcaption' => "\n*" . trim( $children ) . "*\n",
			'del', 's'   => '~~' . trim( $children ) . '~~',
			'mark'       => '==' . trim( $children ) . '==',
			'sup'        => '<sup>' . trim( $children ) . '</sup>',
			'sub'        => '<sub>' . trim( $children ) . '</sub>',
			'div', 'section', 'span', 'main', 'article',
			'details', 'summary', 'dl', 'dt', 'dd',
			'abbr', 'cite', 'dfn', 'small', 'time',
			'address', 'label' => $children,
			default      => $children,
		};
	}

	private function convert_children( DOMNode $node, string $base_url ): string {
		$result = '';
		foreach ( $node->childNodes as $child ) {
			$result .= $this->convert_node( $child, $base_url );
		}
		return $result;
	}

	private function convert_inline_code( DOMElement $node, string $children ): string {
		// If inside <pre>, it's handled by convert_code_block.
		if ( $node->parentNode && 'pre' === strtolower( $node->parentNode->nodeName ) ) {
			return trim( $children );
		}
		return '`' . trim( $children ) . '`';
	}

	private function convert_code_block( DOMElement $node ): string {
		$code = $node->getElementsByTagName( 'code' );
		$lang = '';

		if ( $code->length > 0 ) {
			$code_el = $code->item( 0 );
			$class   = $code_el->getAttribute( 'class' );
			if ( preg_match( '/(?:language|lang|hljs)-(\w+)/', $class, $m ) ) {
				$lang = $m[1];
			}
			$text = $code_el->textContent;
		} else {
			$text = $node->textContent;
		}

		return "\n\n```" . $lang . "\n" . trim( $text ) . "\n```\n\n";
	}

	private function convert_link( DOMElement $node, string $children, string $base_url ): string {
		$href = $node->getAttribute( 'href' );
		$text = trim( $children );

		if ( '' === $href || '' === $text ) {
			return $text;
		}

		// Skip non-http links.
		if ( str_starts_with( $href, '#' ) || str_starts_with( $href, 'javascript:' )
			|| str_starts_with( $href, 'mailto:' ) || str_starts_with( $href, 'tel:' ) ) {
			return $text;
		}

		// Resolve relative URLs.
		if ( $base_url && ! preg_match( '#^https?://#', $href ) ) {
			$href = rtrim( $base_url, '/' ) . '/' . ltrim( $href, '/' );
		}

		return '[' . $text . '](' . $href . ')';
	}

	private function convert_image( DOMElement $node, string $base_url ): string {
		$src = $node->getAttribute( 'src' );
		$alt = $node->getAttribute( 'alt' ) ?: '';

		if ( '' === $src ) {
			return '';
		}

		if ( $base_url && ! preg_match( '#^https?://#', $src ) ) {
			$src = rtrim( $base_url, '/' ) . '/' . ltrim( $src, '/' );
		}

		return '![' . $alt . '](' . $src . ')';
	}

	private function convert_list( DOMElement $node, string $base_url, bool $ordered ): string {
		$items  = array();
		$index  = 1;

		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMElement && 'li' === strtolower( $child->tagName ) ) {
				$content = trim( $this->convert_children( $child, $base_url ) );
				if ( '' !== $content ) {
					$prefix  = $ordered ? $index . '. ' : '- ';
					$items[] = $prefix . $content;
					++$index;
				}
			}
		}

		return implode( "\n", $items );
	}

	private function convert_blockquote( string $children ): string {
		$lines  = explode( "\n", trim( $children ) );
		$quoted = array_map( fn( $line ) => '> ' . $line, $lines );
		return "\n\n" . implode( "\n", $quoted ) . "\n\n";
	}

	private function convert_table( DOMElement $node, string $base_url ): string {
		$rows = array();

		// Extract header rows.
		$thead = $node->getElementsByTagName( 'thead' );
		if ( $thead->length > 0 ) {
			foreach ( $thead->item( 0 )->getElementsByTagName( 'tr' ) as $tr ) {
				$rows[] = $this->extract_table_row( $tr, $base_url );
			}
		}

		// Extract body rows.
		$tbody = $node->getElementsByTagName( 'tbody' );
		$body_source = $tbody->length > 0 ? $tbody->item( 0 ) : $node;

		$first_body = true;
		foreach ( $body_source->getElementsByTagName( 'tr' ) as $tr ) {
			// Skip rows already captured in thead.
			if ( $tr->parentNode && 'thead' === strtolower( $tr->parentNode->nodeName ) ) {
				continue;
			}
			$row = $this->extract_table_row( $tr, $base_url );
			if ( $first_body && empty( $rows ) ) {
				// First body row becomes header.
				$rows[] = $row;
			}
			if ( $first_body ) {
				// Add separator after header.
				$sep    = array_map( fn() => '---', $rows[0] );
				$rows[] = $sep;
				$first_body = false;
				if ( count( $rows ) === 2 ) {
					// First body row was used as header, now add this row as data.
					continue;
				}
			}
			$rows[] = $row;
		}

		if ( empty( $rows ) ) {
			return '';
		}

		$lines = array_map(
			fn( $row ) => '| ' . implode( ' | ', $row ) . ' |',
			$rows
		);

		return implode( "\n", $lines );
	}

	private function extract_table_row( DOMElement $tr, string $base_url ): array {
		$cells = array();
		foreach ( $tr->childNodes as $cell ) {
			if ( $cell instanceof DOMElement && in_array( strtolower( $cell->tagName ), array( 'td', 'th' ), true ) ) {
				$cells[] = trim( $this->convert_children( $cell, $base_url ) );
			}
		}
		return $cells;
	}

	private function normalize_text( string $text ): string {
		// Replace multiple whitespace with single space (but preserve newlines).
		$text = preg_replace( '/[^\S\n]+/', ' ', $text );
		return $text;
	}

	/**
	 * Clean up generated markdown.
	 */
	private function clean_markdown( string $markdown ): string {
		// Normalize line endings.
		$markdown = str_replace( "\r\n", "\n", $markdown );
		$markdown = str_replace( "\r", "\n", $markdown );

		// Remove unicode whitespace characters.
		$markdown = preg_replace( '/[\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', ' ', $markdown );

		// Remove zero-width characters.
		$markdown = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $markdown );

		// Remove boilerplate patterns.
		$boilerplate = array(
			'/©\s*\d{4}[^\n]*/i',
			'/all\s+rights\s+reserved[^\n]*/i',
			'/cookie\s+(?:policy|notice|consent)[^\n]*/i',
			'/privacy\s+(?:policy|notice)[^\n]*/i',
			'/terms\s+(?:of\s+(?:service|use)|and\s+conditions)[^\n]*/i',
			'/powered\s+by\s+\w+[^\n]*/i',
		);
		$markdown = preg_replace( $boilerplate, '', $markdown );

		// Trim each line.
		$lines = explode( "\n", $markdown );
		$lines = array_map( 'trim', $lines );

		// Remove consecutive duplicate lines.
		$deduped = array();
		$prev    = null;
		foreach ( $lines as $line ) {
			if ( $line !== $prev || '' === $line ) {
				$deduped[] = $line;
			}
			$prev = $line;
		}

		$markdown = implode( "\n", $deduped );

		// Collapse 3+ newlines to 2.
		$markdown = preg_replace( '/\n{3,}/', "\n\n", $markdown );

		return trim( $markdown );
	}
}
