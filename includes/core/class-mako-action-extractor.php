<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Action_Extractor {

	private const MAX_ACTIONS = 5;

	/**
	 * Action patterns: regex => [name, description].
	 */
	private const PATTERNS = array(
		// English.
		'/add\s*to\s*cart/i'                => array( 'add_to_cart', 'Add this product to the shopping cart' ),
		'/buy\s*now/i'                      => array( 'purchase', 'Buy now' ),
		'/purchase/i'                       => array( 'purchase', 'Purchase item' ),
		'/check\s*out/i'                    => array( 'checkout', 'Proceed to checkout' ),
		'/add\s*to\s*wishlist/i'            => array( 'add_to_wishlist', 'Add to wishlist' ),
		'/subscribe/i'                      => array( 'subscribe', 'Subscribe' ),
		'/sign\s*up|get\s*started|try\s*free|start\s*trial/i' => array( 'sign_up', 'Sign up for an account' ),
		'/register|rsvp/i'                  => array( 'register', 'Register' ),
		'/log\s*in|sign\s*in/i'            => array( 'login', 'Log in' ),
		'/download/i'                       => array( 'download', 'Download' ),
		'/contact(\s*us)?/i'               => array( 'contact', 'Contact' ),
		'/book|reserve/i'                   => array( 'book', 'Book or reserve' ),
		'/donate/i'                         => array( 'donate', 'Donate' ),
		'/share/i'                          => array( 'share', 'Share' ),
		'/compare/i'                        => array( 'compare', 'Compare' ),
		'/check\s*availability/i'           => array( 'check_availability', 'Check availability' ),
		'/apply(\s+now)?/i'                => array( 'apply', 'Apply' ),
		'/learn\s*more/i'                   => array( 'learn_more', 'Learn more' ),
		'/view\s*details/i'                => array( 'view_details', 'View details' ),
		'/request\s*(demo|quote)/i'         => array( 'request_demo', 'Request a demo or quote' ),

		// Spanish.
		'/a[ñn]adir\s*al?\s*carrito/iu'    => array( 'add_to_cart', 'Add this product to the shopping cart' ),
		'/comprar(\s+ahora)?/iu'            => array( 'purchase', 'Buy now' ),
		'/tramitar\s*pedido|finalizar\s*compra/iu' => array( 'checkout', 'Proceed to checkout' ),
		'/lista\s*de\s*deseos/iu'           => array( 'add_to_wishlist', 'Add to wishlist' ),
		'/suscrib/iu'                       => array( 'subscribe', 'Subscribe' ),
		'/reg[ií]str/iu'                    => array( 'sign_up', 'Sign up for an account' ),
		'/iniciar\s*sesi[oó]n|entrar/iu'   => array( 'login', 'Log in' ),
		'/descargar/iu'                     => array( 'download', 'Download' ),
		'/contact[aáe]/iu'                  => array( 'contact', 'Contact' ),
		'/reservar/iu'                      => array( 'book', 'Book or reserve' ),
		'/donar/iu'                         => array( 'donate', 'Donate' ),
		'/compartir/iu'                     => array( 'share', 'Share' ),
		'/comparar/iu'                      => array( 'compare', 'Compare' ),
		'/ver\s*disponibilidad/iu'          => array( 'check_availability', 'Check availability' ),
		'/solicitar/iu'                     => array( 'apply', 'Apply' ),
		'/m[aá]s\s*informaci[oó]n|saber\s*m[aá]s/iu' => array( 'learn_more', 'Learn more' ),
		'/ver\s*detalles/iu'               => array( 'view_details', 'View details' ),

		// French.
		'/ajouter\s*au\s*panier/iu'        => array( 'add_to_cart', 'Add this product to the shopping cart' ),
		'/acheter(\s+maintenant)?/iu'       => array( 'purchase', 'Buy now' ),
		'/s.inscrire/iu'                    => array( 'sign_up', 'Sign up for an account' ),
		'/se\s*connecter/iu'               => array( 'login', 'Log in' ),
		'/t[eé]l[eé]charger/iu'            => array( 'download', 'Download' ),
		'/partager/iu'                      => array( 'share', 'Share' ),
		'/r[eé]server/iu'                   => array( 'book', 'Book or reserve' ),

		// Portuguese.
		'/adicionar\s*ao\s*carrinho/iu'    => array( 'add_to_cart', 'Add this product to the shopping cart' ),
		'/comprar\s*agora/iu'              => array( 'purchase', 'Buy now' ),
		'/baixar/iu'                        => array( 'download', 'Download' ),
		'/compartilhar/iu'                  => array( 'share', 'Share' ),

		// German.
		'/in\s*den\s*warenkorb/iu'         => array( 'add_to_cart', 'Add this product to the shopping cart' ),
		'/jetzt\s*kaufen/iu'               => array( 'purchase', 'Buy now' ),
		'/herunterladen/iu'                 => array( 'download', 'Download' ),
		'/teilen/iu'                        => array( 'share', 'Share' ),
	);

	/**
	 * Extract actions from post content HTML.
	 *
	 * @return array<array{name: string, description: string}>
	 */
	public function extract( string $html ): array {
		$actions = array();
		$seen    = array();

		if ( '' === trim( $html ) ) {
			return $actions;
		}

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<?xml encoding="utf-8" ?>' . mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ),
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
		);
		libxml_clear_errors();

		$xpath = new DOMXPath( $doc );

		// Search buttons, submit inputs, CTA elements.
		$selectors = array(
			'//button',
			'//input[@type="submit"]',
			'//a[contains(@class, "btn")]',
			'//a[contains(@class, "button")]',
			'//a[contains(@class, "cta")]',
			'//*[@role="button"]',
			'//*[contains(@class, "cta")]',
		);

		foreach ( $selectors as $selector ) {
			if ( count( $actions ) >= self::MAX_ACTIONS ) {
				break;
			}

			$nodes = $xpath->query( $selector );
			if ( ! $nodes ) {
				continue;
			}

			foreach ( $nodes as $node ) {
				if ( count( $actions ) >= self::MAX_ACTIONS ) {
					break 2;
				}

				$text = $this->get_element_text( $node );
				if ( '' === $text || mb_strlen( $text ) > 50 ) {
					continue;
				}

				foreach ( self::PATTERNS as $pattern => $action_data ) {
					if ( preg_match( $pattern, $text ) && ! isset( $seen[ $action_data[0] ] ) ) {
						$seen[ $action_data[0] ] = true;
						$actions[] = array(
							'name'        => $action_data[0],
							'description' => $action_data[1],
						);
						break;
					}
				}
			}
		}

		return apply_filters( 'mako_actions', $actions, $html );
	}

	/**
	 * Get text content from a button/input/link element.
	 */
	private function get_element_text( DOMNode $node ): string {
		if ( $node instanceof DOMElement ) {
			// Input elements: use value.
			if ( 'input' === strtolower( $node->tagName ) ) {
				return trim( $node->getAttribute( 'value' ) );
			}

			// Try aria-label.
			$aria = $node->getAttribute( 'aria-label' );
			if ( '' !== $aria ) {
				return trim( $aria );
			}
		}

		return trim( $node->textContent );
	}
}
