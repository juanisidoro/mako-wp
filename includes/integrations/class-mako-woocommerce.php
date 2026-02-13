<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_WooCommerce {

	/**
	 * Check if WooCommerce is active.
	 */
	public static function is_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Register WooCommerce hooks.
	 */
	public function register(): void {
		if ( ! self::is_active() ) {
			return;
		}

		// Add product to enabled post types.
		add_filter( 'mako_enabled_post_types', array( $this, 'add_product_type' ) );

		// Override content type for products.
		add_filter( 'mako_content_type', array( $this, 'detect_product_type' ), 10, 3 );

		// Enrich frontmatter with product data.
		add_filter( 'mako_frontmatter', array( $this, 'enrich_frontmatter' ), 10, 2 );

		// Enrich body with product details.
		add_filter( 'mako_body', array( $this, 'enrich_body' ), 10, 2 );

		// Add product-specific actions.
		add_filter( 'mako_actions', array( $this, 'add_product_actions' ), 10, 2 );
	}

	/**
	 * Ensure 'product' is in enabled post types.
	 */
	public function add_product_type( array $types ): array {
		if ( ! in_array( 'product', $types, true ) ) {
			$types[] = 'product';
		}
		return $types;
	}

	/**
	 * Force content type to 'product' for WC products.
	 */
	public function detect_product_type( ?string $type, WP_Post $post, string $markdown ): ?string {
		if ( 'product' === $post->post_type ) {
			return 'product';
		}
		return $type;
	}

	/**
	 * Enrich frontmatter with WooCommerce product data.
	 */
	public function enrich_frontmatter( array $fm, WP_Post $post ): array {
		if ( 'product' !== $post->post_type ) {
			return $fm;
		}

		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return $fm;
		}

		// Summary from short description.
		$short_desc = $product->get_short_description();
		if ( $short_desc && empty( $fm['summary'] ) ) {
			$summary = wp_strip_all_tags( $short_desc );
			$summary = preg_replace( '/\s+/', ' ', trim( $summary ) );
			if ( mb_strlen( $summary ) > 160 ) {
				$summary = mb_substr( $summary, 0, 157 ) . '...';
			}
			$fm['summary'] = $summary;
		}

		// Tags from product categories and tags.
		$tags = $fm['tags'] ?? array();

		$product_cats = get_the_terms( $post->ID, 'product_cat' );
		if ( $product_cats && ! is_wp_error( $product_cats ) ) {
			foreach ( $product_cats as $cat ) {
				$tags[] = strtolower( $cat->name );
			}
		}

		$product_tags = get_the_terms( $post->ID, 'product_tag' );
		if ( $product_tags && ! is_wp_error( $product_tags ) ) {
			foreach ( $product_tags as $tag ) {
				$tags[] = strtolower( $tag->name );
			}
		}

		$fm['tags'] = array_unique( array_slice( $tags, 0, 10 ) );

		// Freshness: products update frequently.
		$fm['freshness'] = 'daily';

		return $fm;
	}

	/**
	 * Enrich body with structured product information.
	 */
	public function enrich_body( string $body, WP_Post $post ): string {
		if ( 'product' !== $post->post_type ) {
			return $body;
		}

		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return $body;
		}

		$key_facts = $this->build_key_facts( $product );
		$reviews   = $this->build_reviews_section( $product );
		$attrs     = $this->build_attributes_section( $product );

		// Check if body already has Key Facts section.
		if ( ! str_contains( $body, '## Key Facts' ) ) {
			// Insert Key Facts after the first heading.
			$pos = strpos( $body, "\n", strpos( $body, '#' ) );
			if ( false !== $pos ) {
				$body = substr( $body, 0, $pos ) . "\n\n## Key Facts\n" . $key_facts . substr( $body, $pos );
			}
		}

		// Append attributes if present.
		if ( '' !== $attrs && ! str_contains( $body, '## Attributes' ) ) {
			$body .= "\n\n## Attributes\n" . $attrs;
		}

		// Append reviews if present.
		if ( '' !== $reviews && ! str_contains( $body, '## Reviews' ) ) {
			$body .= "\n\n## Reviews Summary\n" . $reviews;
		}

		return $body;
	}

	/**
	 * Add WooCommerce-specific actions.
	 */
	public function add_product_actions( array $actions, string $html ): array {
		// Check if this is being called for a product.
		// We add standard e-commerce actions.
		$existing = array_column( $actions, 'name' );

		if ( ! in_array( 'add_to_cart', $existing, true ) ) {
			array_unshift( $actions, array(
				'name'        => 'add_to_cart',
				'description' => 'Add this product to the shopping cart',
				'endpoint'    => '/wp-json/wc/store/v1/cart/add-item',
				'method'      => 'POST',
				'params'      => array(
					array(
						'name'        => 'id',
						'type'        => 'integer',
						'required'    => true,
						'description' => 'Product ID',
					),
					array(
						'name'        => 'quantity',
						'type'        => 'integer',
						'required'    => false,
						'description' => 'Quantity (default: 1)',
					),
				),
			) );
		}

		return array_slice( $actions, 0, 5 );
	}

	/**
	 * Build Key Facts section from product data.
	 */
	private function build_key_facts( $product ): string {
		$facts = array();

		// Price.
		$price = $product->get_price();
		$currency = get_woocommerce_currency_symbol();
		if ( $product->is_on_sale() ) {
			$regular = $product->get_regular_price();
			$sale    = $product->get_sale_price();
			if ( $regular && $sale ) {
				$discount = round( ( ( (float) $regular - (float) $sale ) / (float) $regular ) * 100 );
				$facts[]  = "- Price: {$currency}{$sale} (was {$currency}{$regular}, {$discount}% off)";
			}
		} elseif ( $price ) {
			$facts[] = "- Price: {$currency}{$price}";
		}

		// Stock.
		if ( $product->is_in_stock() ) {
			$qty = $product->get_stock_quantity();
			$facts[] = '- Availability: In stock' . ( $qty ? " ({$qty} available)" : '' );
		} else {
			$facts[] = '- Availability: Out of stock';
		}

		// SKU.
		$sku = $product->get_sku();
		if ( $sku ) {
			$facts[] = "- SKU: {$sku}";
		}

		// Weight.
		$weight = $product->get_weight();
		if ( $weight ) {
			$unit    = get_option( 'woocommerce_weight_unit', 'kg' );
			$facts[] = "- Weight: {$weight} {$unit}";
		}

		// Dimensions.
		$dims = $product->get_dimensions( false );
		if ( $dims && ( $dims['length'] || $dims['width'] || $dims['height'] ) ) {
			$unit    = get_option( 'woocommerce_dimension_unit', 'cm' );
			$facts[] = "- Dimensions: {$dims['length']} x {$dims['width']} x {$dims['height']} {$unit}";
		}

		// Categories.
		$cats = wc_get_product_category_list( $product->get_id() );
		if ( $cats ) {
			$facts[] = '- Categories: ' . wp_strip_all_tags( $cats );
		}

		// Rating.
		$rating = $product->get_average_rating();
		$count  = $product->get_review_count();
		if ( $rating > 0 ) {
			$facts[] = "- Rating: {$rating}/5 ({$count} reviews)";
		}

		return implode( "\n", $facts ) . "\n";
	}

	/**
	 * Build product attributes section.
	 */
	private function build_attributes_section( $product ): string {
		$attributes = $product->get_attributes();
		if ( empty( $attributes ) ) {
			return '';
		}

		$lines = array();
		foreach ( $attributes as $attr ) {
			if ( $attr instanceof WC_Product_Attribute ) {
				$name    = wc_attribute_label( $attr->get_name() );
				$values  = $attr->is_taxonomy()
					? implode( ', ', wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) ) )
					: implode( ', ', $attr->get_options() );
				$lines[] = "- {$name}: {$values}";
			}
		}

		return empty( $lines ) ? '' : implode( "\n", $lines ) . "\n";
	}

	/**
	 * Build reviews summary section.
	 */
	private function build_reviews_section( $product ): string {
		$rating = (float) $product->get_average_rating();
		$count  = (int) $product->get_review_count();

		if ( $count === 0 ) {
			return '';
		}

		$sentiment = match ( true ) {
			$rating >= 4.5 => 'Excellent',
			$rating >= 4.0 => 'Very Good',
			$rating >= 3.5 => 'Good',
			$rating >= 3.0 => 'Average',
			default        => 'Mixed',
		};

		return "Overall: {$sentiment} ({$rating}/5 based on {$count} reviews)\n";
	}
}
