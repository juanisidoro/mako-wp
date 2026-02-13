<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Token_Counter {

	/**
	 * Count estimated tokens for a text string.
	 *
	 * Uses two heuristics and returns the maximum:
	 * - Word count * 1.3 (English average)
	 * - Character count / 4 (fallback for non-English/dense text)
	 */
	public function count( string $text ): int {
		$text = trim( $text );
		if ( '' === $text ) {
			return 0;
		}

		$words          = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$word_estimate  = (int) ceil( count( $words ) * 1.3 );
		$char_estimate  = (int) ceil( mb_strlen( $text ) / 4 );

		return max( $word_estimate, $char_estimate );
	}

	/**
	 * Count tokens in raw HTML content.
	 */
	public function count_html( string $html ): int {
		return $this->count( $html );
	}

	/**
	 * Calculate savings percentage between HTML and MAKO tokens.
	 */
	public function savings_percent( int $html_tokens, int $mako_tokens ): float {
		if ( $html_tokens <= 0 ) {
			return 0.0;
		}

		$savings = ( ( $html_tokens - $mako_tokens ) / $html_tokens ) * 100;

		return round( max( 0.0, $savings ), 2 );
	}
}
