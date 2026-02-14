<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Content_Converter {

	private const REMOVE_TAGS = array(
		'script', 'style', 'noscript', 'iframe', 'svg', 'form',
		'nav', 'footer', 'header', 'aside', 'canvas', 'video', 'audio',
	);

	private const REMOVE_CLASSES = array(
		'ad', 'ads', 'advertisement', 'sidebar', 'widget',
		'cookie', 'consent', 'popup', 'modal', 'overlay',
		'social-share', 'share-buttons', 'newsletter', 'subscribe',
		'comments', 'comment-form', 'related-posts', 'breadcrumb',
		'breadcrumbs', 'pagination', 'copyright',
	);

	private const REMOVE_ROLES = array(
		'navigation', 'banner', 'contentinfo', 'complementary',
	);

	/**
	 * Semantic tags whose text content should be extracted.
	 */
	private const SEMANTIC_TAGS = array(
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
		'p', 'li', 'blockquote', 'figcaption',
		'td', 'th', 'dt', 'dd', 'caption',
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

		// Primary conversion: recursive DOM → markdown.
		$markdown = $this->convert_node( $content_node, $base_url );
		$markdown = $this->clean_markdown( $markdown );

		// If thin result, fallback to semantic extraction.
		$text_length = mb_strlen( preg_replace( '/\s+/', '', $markdown ) );
		if ( $text_length < 100 ) {
			$semantic = $this->extract_semantic_content( $content_node, $base_url );
			$semantic = $this->clean_markdown( $semantic );

			$semantic_length = mb_strlen( preg_replace( '/\s+/', '', $semantic ) );
			if ( $semantic_length > $text_length ) {
				$markdown = $semantic;
			}
		}

		return $markdown;
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
		$selectors_xpath = array(
			'//main',
			'//article',
			'//*[@role="main"]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]',
			'//*[contains(concat(" ", normalize-space(@class), " "), " page-content ")]',
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
	 * Extract content from semantic HTML tags only.
	 *
	 * Fallback strategy for complex builder pages where recursive
	 * conversion produces thin results. Walks the DOM and collects
	 * text from h1-h6, p, li, td, blockquote, etc.
	 */
	private function extract_semantic_content( DOMNode $root, string $base_url ): string {
		$blocks = array();
		$seen   = array(); // For deduplication.

		$this->collect_semantic_nodes( $root, $base_url, $blocks, $seen );

		return implode( "\n\n", $blocks );
	}

	/**
	 * Recursively collect content from semantic tags.
	 */
	private function collect_semantic_nodes( DOMNode $node, string $base_url, array &$blocks, array &$seen ): void {
		if ( ! ( $node instanceof DOMElement ) ) {
			// For non-element nodes, recurse into children.
			if ( $node->hasChildNodes() ) {
				foreach ( $node->childNodes as $child ) {
					$this->collect_semantic_nodes( $child, $base_url, $blocks, $seen );
				}
			}
			return;
		}

		$tag = strtolower( $node->tagName );

		// If this is a semantic tag, convert it and stop recursion into it.
		if ( in_array( $tag, self::SEMANTIC_TAGS, true ) ) {
			$md = $this->convert_node( $node, $base_url );
			$md = trim( $md );

			// Skip empty or very short content.
			$text_only = preg_replace( '/[#*_\[\]()>\-|`\s]+/', '', $md );
			if ( mb_strlen( $text_only ) < 3 ) {
				return;
			}

			// Deduplicate: skip if we've seen very similar text.
			$hash = md5( strtolower( $text_only ) );
			if ( isset( $seen[ $hash ] ) ) {
				return;
			}
			$seen[ $hash ] = true;

			$blocks[] = $md;
			return;
		}

		// For lists (ul/ol), convert the whole list as a unit.
		if ( 'ul' === $tag || 'ol' === $tag ) {
			$md        = $this->convert_node( $node, $base_url );
			$md        = trim( $md );
			$text_only = preg_replace( '/[#*_\[\]()>\-|`\s\d.]+/', '', $md );

			if ( mb_strlen( $text_only ) >= 5 ) {
				$hash = md5( strtolower( $text_only ) );
				if ( ! isset( $seen[ $hash ] ) ) {
					$seen[ $hash ] = true;
					$blocks[]      = $md;
				}
			}
			return;
		}

		// For tables, convert the whole table.
		if ( 'table' === $tag ) {
			$md = $this->convert_node( $node, $base_url );
			$md = trim( $md );
			if ( '' !== $md ) {
				$blocks[] = $md;
			}
			return;
		}

		// For non-semantic containers, recurse into children.
		if ( $node->hasChildNodes() ) {
			foreach ( $node->childNodes as $child ) {
				$this->collect_semantic_nodes( $child, $base_url, $blocks, $seen );
			}
		}
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

		if ( str_starts_with( $href, '#' ) || str_starts_with( $href, 'javascript:' )
			|| str_starts_with( $href, 'mailto:' ) || str_starts_with( $href, 'tel:' ) ) {
			return $text;
		}

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
		$items = array();
		$index = 1;

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

		$thead = $node->getElementsByTagName( 'thead' );
		if ( $thead->length > 0 ) {
			foreach ( $thead->item( 0 )->getElementsByTagName( 'tr' ) as $tr ) {
				$rows[] = $this->extract_table_row( $tr, $base_url );
			}
		}

		$tbody       = $node->getElementsByTagName( 'tbody' );
		$body_source = $tbody->length > 0 ? $tbody->item( 0 ) : $node;

		$first_body = true;
		foreach ( $body_source->getElementsByTagName( 'tr' ) as $tr ) {
			if ( $tr->parentNode && 'thead' === strtolower( $tr->parentNode->nodeName ) ) {
				continue;
			}
			$row = $this->extract_table_row( $tr, $base_url );
			if ( $first_body && empty( $rows ) ) {
				$rows[] = $row;
			}
			if ( $first_body ) {
				$sep    = array_map( fn() => '---', $rows[0] );
				$rows[] = $sep;
				$first_body = false;
				if ( count( $rows ) === 2 ) {
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
		$text = preg_replace( '/[^\S\n]+/', ' ', $text );
		return $text;
	}

	/**
	 * Clean up generated markdown.
	 */
	private function clean_markdown( string $markdown ): string {
		$markdown = str_replace( "\r\n", "\n", $markdown );
		$markdown = str_replace( "\r", "\n", $markdown );

		// Remove unicode whitespace.
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
