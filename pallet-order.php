<?php
/**
 * WooCommerce pallet ordering logic.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Portfolio configuration.
 *
 * These values reflect the business rules used by the demonstration workflow.
 * Change them to suit the store implementing the extension.
 */
defined( 'WPO_MIXED_PALLET_MIN_LBS' ) || define( 'WPO_MIXED_PALLET_MIN_LBS', 500 );
defined( 'WPO_MIXED_PALLET_MAX_LBS' ) || define( 'WPO_MIXED_PALLET_MAX_LBS', 1500 );
defined( 'WPO_PALLET_DISCOUNT_RATE' ) || define( 'WPO_PALLET_DISCOUNT_RATE', 0.035 );
defined( 'WPO_CARD_HANDLING_RATE' ) || define( 'WPO_CARD_HANDLING_RATE', 0.03 );
defined( 'WPO_PALLET_PAGE_SLUG' ) || define( 'WPO_PALLET_PAGE_SLUG', 'pallet-order' );
defined( 'WPO_QUALIFICATION_PAGE_SLUG' ) || define( 'WPO_QUALIFICATION_PAGE_SLUG', 'pallet-qualification' );

function wpo_pallet_page_url() {
	return home_url( '/' . trim( WPO_PALLET_PAGE_SLUG, '/' ) . '/' );
}

function wpo_qualification_page_url() {
	return home_url( '/' . trim( WPO_QUALIFICATION_PAGE_SLUG, '/' ) . '/' );
}

add_action( 'wp_loaded', 'wpo_handle_prepacked_pallet_submission', 5 );

add_action( 'woocommerce_single_product_summary', 'wpo_show_pallet_options_on_product_page', 31 );
add_action( 'template_redirect', 'wpo_handle_add_to_pallet_submission' );
add_action( 'template_redirect', 'wpo_handle_remove_from_pallet_submission' );
add_action( 'template_redirect', 'wpo_handle_pallet_checkout_submission' );

add_action( 'wp_ajax_wpo_add_to_mixed_pallet', 'wpo_ajax_add_to_mixed_pallet' );

add_shortcode( 'wpo_pallet_order', 'wpo_render_pallet_order_shortcode' );

add_action( 'woocommerce_checkout_create_order', 'wpo_mark_order_as_pallet_order', 10, 2 );
add_action( 'woocommerce_payment_complete', 'wpo_clear_pallet_order_after_checkout', 10, 1 );
add_action( 'woocommerce_thankyou', 'wpo_clear_pallet_order_after_checkout', 10, 1 );
add_action( 'woocommerce_before_checkout_form', 'wpo_add_continue_building_pallet_button_to_checkout', 5 );

add_filter( 'woocommerce_blocks_enabled', '__return_false' );

/**
 * Check whether a user is approved for pallet ordering.
 */
function wpo_is_pallet_approved( $user_id = 0 ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id ) {
		return false;
	}

	return get_user_meta( $user_id, 'pallet_status', true ) === 'approved';
}

/**
 * Can the current user buy pallet products/options?
 */
function wpo_user_can_buy_pallets(): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}

	$status = get_user_meta( get_current_user_id(), 'pallet_status', true );

	return 'approved' === strtolower( (string) $status );
}

/**
 * Return the pre-packed pallet attribute value.
 */
function wpo_get_prepacked_pallet_value() {
	return 'pre-packed-pallet';
}

/**
 * Normalize pallet order type.
 */
function wpo_normalize_pallet_order_type( $order_type ) {
	return in_array( $order_type, array( 'mixed', 'prepacked' ), true ) ? $order_type : 'mixed';
}

/**
 * Check whether a set of attributes contains the pre-packed pallet option.
 */
function wpo_attributes_include_prepacked_pallet( $attributes ) {
	if ( empty( $attributes ) || ! is_array( $attributes ) ) {
		return false;
	}

	$matches = array(
		'pre-packed-pallet',
		'pre-packed pallet',
		'pre packed pallet',
	);

	foreach ( $attributes as $attribute_value ) {
		$normalized = strtolower( trim( (string) $attribute_value ) );
		$normalized = str_replace( '_', '-', $normalized );

		if ( in_array( $normalized, $matches, true ) ) {
			return true;
		}

		$collapsed = str_replace( array( '-', ' ' ), '', $normalized );

		if ( 'prepackedpallet' === $collapsed ) {
			return true;
		}
	}

	return false;
}

/**
 * Check whether a variation is the pre-packed pallet variation.
 */
function wpo_variation_is_prepacked_pallet( $variation_id ) {
	$variation_id = absint( $variation_id );

	if ( ! $variation_id ) {
		return false;
	}

	$variation = wc_get_product( $variation_id );

	if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
		return false;
	}

	$attributes = $variation->get_attributes();

	if ( wpo_attributes_include_prepacked_pallet( $attributes ) ) {
		return true;
	}

	if ( method_exists( $variation, 'get_variation_attributes' ) ) {
		$variation_attributes = $variation->get_variation_attributes();

		if ( wpo_attributes_include_prepacked_pallet( $variation_attributes ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Check whether the current product form submission is for a Pre-Packed Pallet variation.
 */
function wpo_is_prepacked_pallet_request() {
	if ( empty( $_POST['add-to-cart'] ) ) {
		return false;
	}

	$posted_attributes = array();

	foreach ( $_POST as $key => $value ) {
		if ( 0 !== strpos( $key, 'attribute_' ) ) {
			continue;
		}

		$posted_attributes[ sanitize_text_field( $key ) ] = sanitize_text_field( wp_unslash( $value ) );
	}

	if ( wpo_attributes_include_prepacked_pallet( $posted_attributes ) ) {
		return true;
	}

	$variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;

	return wpo_variation_is_prepacked_pallet( $variation_id );
}

/**
 * Get current pallet order from Woo session.
 */
function wpo_get_pallet_order() {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return array();
	}

	$order = WC()->session->get( 'wpo_pallet_order', array() );

	return is_array( $order ) ? $order : array();
}

/**
 * Save pallet order to Woo session.
 */
function wpo_set_pallet_order( $order ) {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	WC()->session->set( 'wpo_pallet_order', $order );
}

/**
 * Add or merge an item into the pallet order.
 */
function wpo_add_item_to_pallet_order( $product_id, $variation_id = 0, $quantity = 1, $attributes = array(), $order_type = 'mixed' ) {
	$product_id   = absint( $product_id );
	$variation_id = absint( $variation_id );
	$quantity     = max( 1, absint( $quantity ) );
	$order_type   = wpo_normalize_pallet_order_type( $order_type );

	if ( ! $product_id ) {
		return false;
	}

	$order = wpo_get_pallet_order();

	$attribute_hash = '';
	if ( ! empty( $attributes ) && is_array( $attributes ) ) {
		ksort( $attributes );
		$attribute_hash = md5( wp_json_encode( $attributes ) );
	}

	$key = $product_id . ':' . $variation_id . ':' . $attribute_hash . ':' . $order_type;

	if ( isset( $order[ $key ] ) ) {
		$order[ $key ]['quantity'] += $quantity;
	} else {
		$order[ $key ] = array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'quantity'     => $quantity,
			'attributes'   => is_array( $attributes ) ? $attributes : array(),
			'order_type'   => $order_type,
		);
	}

	wpo_set_pallet_order( $order );

	return true;
}

/**
 * Remove an item from pallet order.
 */
function wpo_remove_item_from_pallet_order( $key ) {
	$order = wpo_get_pallet_order();

	if ( isset( $order[ $key ] ) ) {
		unset( $order[ $key ] );
		wpo_set_pallet_order( $order );
	}
}

/**
 * Get pallet order items by type.
 */
function wpo_get_pallet_order_items_by_type( $type = 'mixed' ) {
	$type  = wpo_normalize_pallet_order_type( $type );
	$order = wpo_get_pallet_order();

	if ( empty( $order ) ) {
		return array();
	}

	$items = array();

	foreach ( $order as $key => $item ) {
		$item_type = ! empty( $item['order_type'] ) ? wpo_normalize_pallet_order_type( $item['order_type'] ) : 'mixed';

		if ( $item_type === $type ) {
			$items[ $key ] = $item;
		}
	}

	return $items;
}

/**
 * Get total weight for a given pallet item list.
 */
function wpo_get_pallet_items_total_weight( $items ) {
	if ( empty( $items ) || ! is_array( $items ) ) {
		return 0;
	}

	$total_weight = 0;

	foreach ( $items as $item ) {
		$product_id   = ! empty( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
		$variation_id = ! empty( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
		$quantity     = ! empty( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;

		if ( ! $product_id || ! $quantity ) {
			continue;
		}

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );

		if ( ! $product ) {
			continue;
		}

		$weight = (float) $product->get_weight();

		if ( $weight > 0 ) {
			$total_weight += ( $weight * $quantity );
		}
	}

	return (float) $total_weight;
}

/**
 * Get total mixed pallet weight.
 */
function wpo_get_mixed_pallet_total_weight() {
	return wpo_get_pallet_items_total_weight( wpo_get_pallet_order_items_by_type( 'mixed' ) );
}

/**
 * Get total pre-packed pallet weight.
 */
function wpo_get_prepacked_pallet_total_weight() {
	return wpo_get_pallet_items_total_weight( wpo_get_pallet_order_items_by_type( 'prepacked' ) );
}

/**
 * Get total weight of the current pallet order.
 */
function wpo_get_pallet_order_total_weight() {
	return wpo_get_mixed_pallet_total_weight() + wpo_get_prepacked_pallet_total_weight();
}

/**
 * Get pre-packed pallet count.
 *
 * Each quantity of a pre-packed pallet counts as one pallet unit.
 */
function wpo_get_prepacked_pallet_count() {
	$items = wpo_get_pallet_order_items_by_type( 'prepacked' );

	if ( empty( $items ) ) {
		return 0;
	}

	$count = 0;

	foreach ( $items as $item ) {
		$count += ! empty( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;
	}

	return $count;
}

/**
 * Calculate mixed pallet split data using:
 * - configurable minimum weight per pallet
 * - configurable maximum weight per pallet
 * - automatic even split across the minimum number of pallets needed
 */
function wpo_get_mixed_pallet_split_data( $total_weight = null ) {
	if ( null === $total_weight ) {
		$total_weight = wpo_get_mixed_pallet_total_weight();
	}

	$total_weight = (float) $total_weight;
	$min_weight   = (float) WPO_MIXED_PALLET_MIN_LBS;
	$max_weight   = (float) WPO_MIXED_PALLET_MAX_LBS;

	$data = array(
		'has_items'         => $total_weight > 0,
		'valid'             => false,
		'total_weight'      => $total_weight,
		'pallet_count'      => 0,
		'per_pallet_weight' => 0,
		'message'           => '',
		'status_class'      => 'is-empty',
	);

	if ( $total_weight <= 0 ) {
		$data['message'] = 'No mixed-pallet items have been added yet.';
		return $data;
	}

	$pallet_count = (int) ceil( $total_weight / $max_weight );
	$per_pallet   = $total_weight / $pallet_count;

	$data['pallet_count']      = $pallet_count;
	$data['per_pallet_weight'] = $per_pallet;

	if ( $per_pallet < $min_weight ) {
		$needed               = ( $min_weight * $pallet_count ) - $total_weight;
		$data['message']      = 'Current mixed-pallet weight: ' . wc_format_decimal( $total_weight, 0 ) . ' lbs. Add ' . wc_format_decimal( $needed, 0 ) . ' lbs. to qualify this mixed order across ' . $pallet_count . ' pallet' . ( 1 === $pallet_count ? '' : 's' ) . '.';
		$data['status_class'] = 'is-under';
		return $data;
	}

	$data['valid'] = true;

	if ( 1 === $pallet_count ) {
		$data['message'] = 'Current mixed-pallet weight: ' . wc_format_decimal( $total_weight, 0 ) . ' lbs. This mixed order qualifies as 1 pallet.';
	} else {
		$data['message'] = 'Current mixed-pallet weight: ' . wc_format_decimal( $total_weight, 0 ) . ' lbs. This mixed order will ship as ' . $pallet_count . ' pallets at approximately ' . wc_format_decimal( $per_pallet, 0 ) . ' lbs. each.';
	}

	$data['status_class'] = 'is-qualified';

	return $data;
}

/**
 * Backward-compatible alias.
 */
function wpo_get_pallet_split_data( $total_weight = null ) {
	return wpo_get_mixed_pallet_split_data( $total_weight );
}


/**
 * Get product-page mixed pallet status data.
 */
function wpo_get_mixed_pallet_product_status_data() {
	$split_data = wpo_get_mixed_pallet_split_data();

	return array(
		'current_weight' => (float) $split_data['total_weight'],
		'remaining'      => max( 0, (float) WPO_MIXED_PALLET_MIN_LBS - (float) $split_data['total_weight'] ),
		'pallets'        => (int) max( 1, $split_data['pallet_count'] ),
		'qualified'      => ! empty( $split_data['valid'] ),
		'message'        => $split_data['message'],
		'status_class'   => $split_data['status_class'],
		'checkout_url'   => wc_get_checkout_url(),
		'pallet_url'     => wpo_pallet_page_url(),
		'nav_count'      => wpo_get_pallet_nav_count(),
	);
}

/**
 * Get the total shipping pallet count.
 */
function wpo_get_total_shipping_pallet_count() {
	$mixed_split     = wpo_get_mixed_pallet_split_data();
	$prepacked_count = wpo_get_prepacked_pallet_count();
	$mixed_count     = ! empty( $mixed_split['valid'] ) ? (int) $mixed_split['pallet_count'] : 0;

	return $mixed_count + $prepacked_count;
}

/**
 * Get the visible standard cart count for header display.
 *
 * During pallet checkout, WooCommerce cart is used as a temporary transport layer
 * for pallet items. Those should not be counted in the main cart icon.
 */
function wpo_get_visible_cart_count() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return 0;
	}

	if ( WC()->session && 'yes' === WC()->session->get( 'wpo_pallet_checkout' ) ) {
		return 0;
	}

	return (int) WC()->cart->get_cart_contents_count();
}

/**
 * Route approved Pre-Packed Pallet add-to-cart submissions into the pallet-order session
 * instead of the normal WooCommerce cart.
 */
function wpo_handle_prepacked_pallet_submission() {
	if ( empty( $_POST['add-to-cart'] ) ) {
		return;
	}

	if ( ! wpo_is_prepacked_pallet_request() ) {
		return;
	}

	if ( ! wpo_user_can_buy_pallets() ) {
		return;
	}

	$product_id   = isset( $_POST['add-to-cart'] ) ? absint( wp_unslash( $_POST['add-to-cart'] ) ) : 0;
	$variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;
	$quantity     = isset( $_POST['quantity'] ) ? absint( wp_unslash( $_POST['quantity'] ) ) : 1;

	if ( ! $product_id ) {
		return;
	}

	$attributes = array();

	foreach ( $_POST as $key => $value ) {
		if ( 0 !== strpos( $key, 'attribute_' ) ) {
			continue;
		}

		$attributes[ sanitize_text_field( $key ) ] = sanitize_text_field( wp_unslash( $value ) );
	}

	$added = wpo_add_item_to_pallet_order(
		$product_id,
		$variation_id,
		max( 1, $quantity ),
		$attributes,
		'prepacked'
	);

	if ( ! $added ) {
		wc_add_notice( 'That pallet item could not be added to your pallet order.', 'error' );
		return;
	}

	wc_add_notice( 'Pre-packed pallet added to your pallet order.', 'success' );

	wp_safe_redirect( wpo_pallet_page_url() );
	exit;
}

/**
 * Render pallet box on single product pages.
 */
function wpo_show_pallet_options_on_product_page() {
	if ( ! is_product() ) {
		return;
	}

	global $product;

	if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
		return;
	}

	$product_id = $product->get_id();

	if ( ! wpo_product_allows_mixed_pallet( $product_id ) ) {
		return;
	}

	$pallet_min_qty = get_post_meta( $product_id, 'pallet_min_qty', true );
	$pallet_message = get_post_meta( $product_id, 'pallet_message', true );

	$user_id     = get_current_user_id();
	$is_approved = $user_id ? wpo_is_pallet_approved( $user_id ) : false;

	echo '<div class="wpo-pallet-box">';

	if ( $is_approved ) {
		$status = wpo_get_mixed_pallet_product_status_data();

		echo '<h3>Your account is approved for pallet purchasing.</h3>';

		if ( $pallet_min_qty ) {
			echo '<p><strong>Minimum mixed pallet quantity:</strong> ' . esc_html( $pallet_min_qty ) . '</p>';
		} else {
			echo '<p><strong>Minimum mixed pallet quantity:</strong> 500 lbs.</p>';
		}

		if ( $pallet_message ) {
			echo '<p>' . esc_html( $pallet_message ) . '</p>';
		} else {
			echo '<p>Build a mixed-pallet or pre-packed pallet order and submit it separately from standard checkout.</p>';
		}

    /**
     * IMPORTANT:
     * This hidden form is required for pallet-order submissions.
     * It must NOT be repurposed or made visible.
     * JS submits this form to bypass WooCommerce cart.
     */
     echo '<form method="post" class="wpo-add-to-pallet-form" style="display:none;">';
            wp_nonce_field( 'wpo_add_to_pallet_action', 'wpo_add_to_pallet_nonce' );
            echo '<input type="hidden" name="wpo_add_to_pallet" value="1">';
            echo '<input type="hidden" name="product_id" value="' . esc_attr( $product_id ) . '">';
            echo '<input type="hidden" name="variation_id" value="0" class="wpo-pallet-variation-id">';
            echo '<input type="hidden" name="quantity" value="1" class="wpo-pallet-quantity">';
            echo '</form>';

            echo '<div class="wpo-product-pallet-status ' . esc_attr( $status['status_class'] ) . '" data-qualified="' . esc_attr( $status['qualified'] ? '1' : '0' ) . '">';
            echo '<p class="wpo-pallet-status-message">' . esc_html( $status['message'] ) . '</p>';

            echo '<div class="wpo-pallet-cta-group wpo-pallet-inline-actions">';
            echo '<a class="button wpo-add-to-pallet-shop-btn" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">Add More Product</a>';
            echo '<a class="button wpo-pallet-view-btn" href="' . esc_url( wpo_pallet_page_url() ) . '">View Pallet Order</a>';
            echo '</div>';

            echo '<div class="wpo-pallet-or-divider">-- OR --</div>';

            echo '<form method="post" class="wpo-product-pallet-checkout-form">';
            wp_nonce_field( 'wpo_pallet_checkout_action', 'wpo_pallet_checkout_nonce' );
            echo '<input type="hidden" name="wpo_pallet_checkout" value="1">';

            if ( $status['qualified'] ) {
                echo '<button type="submit" class="button alt wpo-pallet-checkout-button">Proceed To Checkout</button>';
            } else {
                echo '<button type="submit" class="button alt wpo-pallet-checkout-button is-disabled" disabled>Proceed To Checkout</button>';
            }

            echo '</form>';
            echo '</div>';

        } else {
            echo '<h3>Pallet ordering is available for approved accounts.</h3>';
            echo '<p>Pre-packed and mixed pallets are available only to approved pallet buyers. If you are interested in pallet quantities, please <a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">log in</a> or <a href="' . esc_url( wpo_qualification_page_url() ) . '">register for pallet purchasing</a>.</p>';
            echo '<a class="button wpo-pallet-request-btn" href="' . esc_url( wpo_qualification_page_url() ) . '">Request Pallet Access</a>';
        }

        echo '</div>';
    }

/**
 * Validate and add a mixed-pallet item from a request array.
 */
function wpo_validate_and_add_mixed_pallet_item_from_request( $request ) {
	if ( ! is_user_logged_in() || ! wpo_is_pallet_approved() ) {
		return new WP_Error( 'not_approved', 'Your account is not approved for pallet ordering.' );
	}

	$product_id   = isset( $request['product_id'] ) ? absint( wp_unslash( $request['product_id'] ) ) : 0;
	$variation_id = isset( $request['variation_id'] ) ? absint( wp_unslash( $request['variation_id'] ) ) : 0;
	$quantity     = isset( $request['quantity'] ) ? absint( wp_unslash( $request['quantity'] ) ) : 1;

	if ( $variation_id && wpo_variation_is_prepacked_pallet( $variation_id ) ) {
		return new WP_Error( 'prepacked_in_mixed', 'Pre-Packed Pallet items cannot be added through the mixed pallet form.' );
	}

	if ( ! $product_id ) {
		return new WP_Error( 'invalid_product', 'Invalid product.' );
	}

	$parent_product = wc_get_product( $product_id );
	if ( ! $parent_product ) {
		return new WP_Error( 'invalid_product', 'Invalid product.' );
	}

	if ( ! wpo_product_allows_mixed_pallet( $product_id ) ) {
		return new WP_Error( 'mixed_not_allowed', 'This product is not available for mixed pallet ordering.' );
	}

	if ( $parent_product->is_type( 'variable' ) && ! $variation_id ) {
		return new WP_Error( 'missing_variation', 'Please choose an option before adding this item to your pallet order.' );
	}

	$product = wc_get_product( $variation_id ? $variation_id : $product_id );
	if ( ! $product ) {
		return new WP_Error( 'invalid_product', 'That product could not be added to the pallet order.' );
	}

	$attributes = array();

	if ( isset( $request['variation'] ) && is_array( $request['variation'] ) ) {
		$raw_attributes = wp_unslash( $request['variation'] );

		foreach ( $raw_attributes as $attribute_name => $attribute_value ) {
			$attributes[ sanitize_text_field( $attribute_name ) ] = sanitize_text_field( $attribute_value );
		}
	} else {
		foreach ( $request as $key => $value ) {
			if ( 0 !== strpos( $key, 'attribute_' ) ) {
				continue;
			}

			$attributes[ sanitize_text_field( $key ) ] = sanitize_text_field( wp_unslash( $value ) );
		}
	}

	$added = wpo_add_item_to_pallet_order( $product_id, $variation_id, max( 1, $quantity ), $attributes, 'mixed' );

	if ( ! $added ) {
		return new WP_Error( 'add_failed', 'That product could not be added to the pallet order.' );
	}

	return true;
}

/**
 * AJAX: Add mixed-pallet item without leaving the product page.
 */
function wpo_ajax_add_to_mixed_pallet() {
	if (
		empty( $_POST['nonce'] ) ||
		! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['nonce'] ) ),
			'wpo_pallet_nonce'
		)
	) {
		wp_send_json_error(
			array(
				'message' => 'Security check failed. Please refresh the page and try again.',
			),
			403
		);
	}

	$result = wpo_validate_and_add_mixed_pallet_item_from_request( $_POST );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error(
			array(
				'message' => $result->get_error_message(),
			),
			400
		);
	}

	$status = wpo_get_mixed_pallet_product_status_data();

	wp_send_json_success(
		array(
			'message'        => $status['message'],
			'status_class'   => $status['status_class'],
			'qualified'      => $status['qualified'],
			'current_weight' => $status['current_weight'],
			'remaining'      => $status['remaining'],
			'pallets'        => $status['pallets'],
			'checkout_url'   => $status['checkout_url'],
			'pallet_url'     => $status['pallet_url'],
			'nav_count'      => $status['nav_count'],
		)
	);
}

/**
 * Handle Add to Pallet submission for mixed-pallet items.
 */
function wpo_handle_add_to_pallet_submission() {
	if ( empty( $_POST['wpo_add_to_pallet'] ) ) {
		return;
	}

	if (
		empty( $_POST['wpo_add_to_pallet_nonce'] ) ||
		! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['wpo_add_to_pallet_nonce'] ) ),
			'wpo_add_to_pallet_action'
		)
	) {
		wc_add_notice( 'Security check failed. Please try again.', 'error' );
		return;
	}

	$result = wpo_validate_and_add_mixed_pallet_item_from_request( $_POST );

	if ( is_wp_error( $result ) ) {
		wc_add_notice( $result->get_error_message(), 'error' );
		return;
	}

	$status = wpo_get_mixed_pallet_product_status_data();

	wc_add_notice( 'Item added to your pallet order. ' . wp_strip_all_tags( $status['message'] ), 'success' );

	$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
	$redirect   = $product_id ? get_permalink( $product_id ) : wp_get_referer();

	if ( ! $redirect ) {
		$redirect = wc_get_page_permalink( 'shop' );
	}

	wp_safe_redirect( $redirect );
	exit;
}

/**
 * Handle remove item from pallet order.
 */
function wpo_handle_remove_from_pallet_submission() {
	if ( empty( $_GET['wpo_remove_pallet_item'] ) ) {
		return;
	}

	if ( ! is_user_logged_in() || ! wpo_is_pallet_approved() ) {
		return;
	}

	$key = sanitize_text_field( wp_unslash( $_GET['wpo_remove_pallet_item'] ) );

	if (
		empty( $_GET['_wpononce'] ) ||
		! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_GET['_wpononce'] ) ),
			'wpo_remove_pallet_item_' . $key
		)
	) {
		return;
	}

	wpo_remove_item_from_pallet_order( $key );

	wc_add_notice( 'Item removed from your pallet order.', 'success' );

	wp_safe_redirect( wpo_pallet_page_url() );
	exit;
}

/**
 * Shortcode: [wpo_pallet_order]
 * Use on /pallet-order/
 */
function wpo_render_pallet_order_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '<p>Please log in to view your pallet order.</p>';
	}

	if ( ! wpo_is_pallet_approved() ) {
		return '<p>Your account is not approved for pallet ordering.</p>';
	}

	$order            = wpo_get_pallet_order();
	$mixed_items      = wpo_get_pallet_order_items_by_type( 'mixed' );
	$prepacked_items  = wpo_get_pallet_order_items_by_type( 'prepacked' );
	$mixed_split_data = wpo_get_mixed_pallet_split_data();
	$prepacked_count  = wpo_get_prepacked_pallet_count();
	$total_pallets    = wpo_get_total_shipping_pallet_count();

	$has_mixed_items     = ! empty( $mixed_items );
	$has_prepacked_items = ! empty( $prepacked_items );
	$has_any_items       = ! empty( $order );

	$can_checkout = $has_any_items && ( ! $has_mixed_items || ! empty( $mixed_split_data['valid'] ) );

	ob_start();

	echo '<div class="wpo-pallet-order-wrap">';
	echo '<h2>Your Pallet Order</h2>';
	echo '<p>Mixed Pallet Minimum: 500lbs. | Mixed Pallet Maximum: 1500lbs.</p>';

	if ( wpo_standard_cart_has_items() ) {
		echo '<div class="woocommerce-info wpo-split-order-notice wpo-split-order-notice--top">';
		echo 'You currently have items in your standard cart. Please complete or empty your standard cart before starting pallet checkout. Your pallet order will be saved. ';
		echo '<a href="' . esc_url( wc_get_cart_url() ) . '">View Standard Cart</a>';
		echo '</div>';
	}

	if ( ! $has_any_items ) {
		echo '<div class="wpo-pallet-weight-banner is-empty">';
		echo '<p>Your pallet order is currently empty.</p>';
		echo '</div>';

		echo '<p><a class="button" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">Add To Pallet</a></p>';
		echo '</div>';

		return ob_get_clean();
	}

	if ( $has_mixed_items ) {
		echo '<div class="wpo-pallet-weight-banner ' . esc_attr( $mixed_split_data['status_class'] ) . '">';
		echo '<p>' . esc_html( $mixed_split_data['message'] ) . '</p>';

		if ( ! empty( $mixed_split_data['valid'] ) && ! empty( $mixed_split_data['pallet_count'] ) ) {
			echo '<p><strong>Mixed pallet count:</strong> ' . esc_html( $mixed_split_data['pallet_count'] ) . '</p>';
			echo '<p><strong>Approximate weight per mixed pallet:</strong> ' . esc_html( wc_format_decimal( $mixed_split_data['per_pallet_weight'], 0 ) ) . ' lbs.</p>';
		}

		echo '</div>';
	}

	if ( $has_prepacked_items ) {
		echo '<div class="wpo-pallet-weight-banner is-qualified">';
		echo '<p><strong>Pre-packed pallets in order:</strong> ' . esc_html( $prepacked_count ) . '</p>';

		$prepacked_weight = wpo_get_prepacked_pallet_total_weight();
		if ( $prepacked_weight > 0 ) {
			echo '<p><strong>Total pre-packed pallet weight:</strong> ' . esc_html( wc_format_decimal( $prepacked_weight, 0 ) ) . ' lbs.</p>';
		}

		echo '<p>Pre-packed pallets are counted as fixed pallet units and are not included in mixed-pallet weight splitting.</p>';
		echo '</div>';
	}

	if ( $total_pallets > 0 ) {
		echo '<div class="wpo-pallet-weight-banner is-qualified">';
		echo '<p><strong>Total shipping pallets:</strong> ' . esc_html( $total_pallets ) . '</p>';
		echo '</div>';
	}

	echo '<table class="shop_table shop_table_responsive wpo-pallet-order-table">';
	echo '<thead><tr>';
	echo '<th>Product</th>';
	echo '<th>Type</th>';
	echo '<th>Option</th>';
	echo '<th>Quantity</th>';
	echo '<th>Weight</th>';
	echo '<th>Action</th>';
	echo '</tr></thead>';
	echo '<tbody>';

	foreach ( $order as $key => $item ) {
		$product_id   = ! empty( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
		$variation_id = ! empty( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
		$quantity     = ! empty( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;
		$attributes   = ! empty( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array();
		$order_type   = ! empty( $item['order_type'] ) ? wpo_normalize_pallet_order_type( $item['order_type'] ) : 'mixed';

		$display_product = wc_get_product( $variation_id ? $variation_id : $product_id );
		$base_product    = wc_get_product( $product_id );

		if ( ! $display_product || ! $base_product ) {
			continue;
		}

		$product_name = $base_product->get_name();
		$type_label   = ( 'prepacked' === $order_type ) ? 'Pre-Packed' : 'Mixed';
		$option_label = '&mdash;';

		if ( ! empty( $attributes ) ) {
			$parts = array();

			foreach ( $attributes as $attribute_name => $attribute_value ) {
				if ( '' === $attribute_value ) {
					continue;
				}

				$clean_name = wc_attribute_label( str_replace( 'attribute_', '', $attribute_name ) );
				$parts[]    = $clean_name . ': ' . $attribute_value;
			}

			if ( ! empty( $parts ) ) {
				$option_label = implode( ', ', $parts );
			}
		} elseif ( $variation_id && method_exists( $display_product, 'get_variation_attributes' ) ) {
			$parts = array();

			foreach ( $display_product->get_variation_attributes() as $attribute_name => $attribute_value ) {
				if ( '' === $attribute_value ) {
					continue;
				}

				$clean_name = wc_attribute_label( str_replace( 'attribute_', '', $attribute_name ) );
				$parts[]    = $clean_name . ': ' . $attribute_value;
			}

			if ( ! empty( $parts ) ) {
				$option_label = implode( ', ', $parts );
			}
		}

		$unit_weight  = (float) $display_product->get_weight();
		$line_weight  = $unit_weight > 0 ? ( $unit_weight * $quantity ) : 0;
		$weight_label = $line_weight > 0 ? wc_format_decimal( $line_weight, 0 ) . ' lbs.' : '&mdash;';

		$remove_url = wp_nonce_url(
			add_query_arg(
				array(
					'wpo_remove_pallet_item' => rawurlencode( $key ),
				),
				wpo_pallet_page_url()
			),
			'wpo_remove_pallet_item_' . $key,
			'_wpononce'
		);

		echo '<tr>';
		echo '<td>' . esc_html( $product_name ) . '</td>';
		echo '<td>' . esc_html( $type_label ) . '</td>';
		echo '<td>' . wp_kses_post( $option_label ) . '</td>';
		echo '<td>' . esc_html( $quantity ) . '</td>';
		echo '<td>' . wp_kses_post( $weight_label ) . '</td>';
		echo '<td><a href="' . esc_url( $remove_url ) . '">Remove</a></td>';
		echo '</tr>';
	}

	echo '</tbody>';
	echo '</table>';

	echo '<div class="wpo-pallet-order-actions">';
	echo '<a class="button" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">Add To Pallet Order</a>';

	echo '<form method="post" class="wpo-pallet-checkout-form" style="display:inline-block; margin-left:12px;">';
	wp_nonce_field( 'wpo_pallet_checkout_action', 'wpo_pallet_checkout_nonce' );
	echo '<input type="hidden" name="wpo_pallet_checkout" value="1">';

	if ( $can_checkout ) {
		if ( wpo_standard_cart_has_items() ) {
			echo '<button type="submit" class="button alt" onclick="return confirm(\'You also have items in your standard cart. Starting pallet checkout will replace your current standard cart. Please complete your standard purchase first. Continue anyway?\');">Proceed to Checkout</button>';
		} else {
			echo '<button type="submit" class="button alt">Proceed to Checkout</button>';
		}
	} else {
		echo '<button type="button" class="button alt disabled" disabled>Proceed to Checkout</button>';
	}

	echo '</form>';
	echo '</div>';
	echo '</div>';

	return ob_get_clean();
}

/**
 * Load the current pallet order into the WooCommerce cart.
 */
function wpo_load_pallet_order_into_cart() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
		return new WP_Error( 'missing_wc', 'WooCommerce cart is not available.' );
	}

	$order = wpo_get_pallet_order();

	if ( empty( $order ) || ! is_array( $order ) ) {
		return new WP_Error( 'empty_pallet', 'Your pallet order is empty.' );
	}
    
    if ( wpo_standard_cart_has_items() && 'yes' !== WC()->session->get( 'wpo_pallet_checkout' ) ) {
	wc_add_notice(
		'You currently have items in your standard cart. Please complete or empty your standard cart before starting pallet checkout. Your pallet order has been saved.',
		'error'
	);

	wp_safe_redirect( wc_get_cart_url() );
	exit;
}

	WC()->cart->empty_cart();

	foreach ( $order as $item ) {
		$product_id   = ! empty( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
		$variation_id = ! empty( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
		$quantity     = ! empty( $item['quantity'] ) ? max( 1, absint( $item['quantity'] ) ) : 1;
		$attributes   = ! empty( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array();
		$order_type   = ! empty( $item['order_type'] ) ? wpo_normalize_pallet_order_type( $item['order_type'] ) : 'mixed';

		if ( ! $product_id ) {
			continue;
		}

		$cart_item_key = WC()->cart->add_to_cart(
			$product_id,
			$quantity,
			$variation_id,
			$attributes,
			array(
				'wpo_pallet_order_type' => $order_type,
			)
		);

		if ( ! $cart_item_key ) {
			return new WP_Error( 'cart_add_failed', 'One or more pallet items could not be added to the cart.' );
		}
	}

	WC()->session->set( 'wpo_pallet_checkout', 'yes' );

	return true;
}

/**
 * Handle "Proceed to Checkout" from the pallet-order page.
 */
function wpo_handle_pallet_checkout_submission() {
	if ( empty( $_POST['wpo_pallet_checkout'] ) ) {
		return;
	}

	if ( ! is_user_logged_in() || ! wpo_is_pallet_approved() ) {
		wc_add_notice( 'Your account is not approved for pallet ordering.', 'error' );
		return;
	}

	if (
		empty( $_POST['wpo_pallet_checkout_nonce'] ) ||
		! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['wpo_pallet_checkout_nonce'] ) ),
			'wpo_pallet_checkout_action'
		)
	) {
		wc_add_notice( 'Security check failed. Please try again.', 'error' );
		return;
	}

	$order = wpo_get_pallet_order();

	if ( empty( $order ) ) {
		wc_add_notice( 'Your pallet order is empty.', 'error' );
		return;
	}

	$mixed_items = wpo_get_pallet_order_items_by_type( 'mixed' );
	$split_data  = wpo_get_mixed_pallet_split_data();

	if ( ! empty( $mixed_items ) && empty( $split_data['valid'] ) ) {
		wc_add_notice( wp_strip_all_tags( $split_data['message'] ), 'error' );
		return;
	}

	$result = wpo_load_pallet_order_into_cart();

	if ( is_wp_error( $result ) ) {
		wc_add_notice( $result->get_error_message(), 'error' );
		return;
	}

	wp_safe_redirect( wc_get_checkout_url() );
	exit;
}

/**
 * Mark checkout-created orders as pallet orders.
 */
function wpo_mark_order_as_pallet_order( $order, $data ) {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	if ( 'yes' === WC()->session->get( 'wpo_pallet_checkout' ) ) {
		$order->update_meta_data( '_wpo_pallet_order', 'yes' );
		$order->update_meta_data( '_wpo_prepacked_pallet_count', wpo_get_prepacked_pallet_count() );

		$mixed_split = wpo_get_mixed_pallet_split_data();
		if ( ! empty( $mixed_split['valid'] ) ) {
			$order->update_meta_data( '_wpo_mixed_pallet_count', (int) $mixed_split['pallet_count'] );
		}

		WC()->session->set( 'wpo_pallet_checkout', null );
	}
}

/**
 * Clear pallet session after successful pallet checkout.
 */
function wpo_clear_pallet_order_after_checkout( $order_id ) {
	if ( ! $order_id || ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	if ( 'yes' !== $order->get_meta( '_wpo_pallet_order', true ) ) {
		return;
	}

	// Remove pallet-session data completely.
	WC()->session->__unset( 'wpo_pallet_order' );
	WC()->session->__unset( 'wpo_pallet_checkout' );

	// Clear the temporary Woo cart used during pallet checkout.
	if ( WC()->cart ) {
		WC()->cart->empty_cart();
	}
}

/**
 * Add a return-to-pallet action on checkout when in pallet mode.
 */
function wpo_add_continue_building_pallet_button_to_checkout() {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	if ( 'yes' !== WC()->session->get( 'wpo_pallet_checkout' ) ) {
		return;
	}

	echo '<div class="wpo-checkout-pallet-actions">';
	echo '<p><strong>Pallet Order Checkout</strong><br>You are checking out a pallet order. Need to add more items before placing the order?</p>';
	echo '<p><a class="button wpo-return-to-pallet-btn" href="' . esc_url( wpo_pallet_page_url() ) . '">Continue Building Pallet</a></p>';
	echo '</div>';
}

/**
 * Get product pallet mode.
 *
 * Defaults to mixed for legacy behavior unless explicitly overridden.
 */
function wpo_get_product_pallet_mode( $product_id ) {
	$mode = get_post_meta( $product_id, '_wpo_pallet_mode', true );

	if ( ! in_array( $mode, array( 'none', 'mixed', 'prepacked', 'both' ), true ) ) {
		$mode = 'mixed';
	}

	return $mode;
}

/**
 * Whether a product can be added to a mixed pallet.
 */
function wpo_product_allows_mixed_pallet( $product_id ) {
	$mode = wpo_get_product_pallet_mode( $product_id );

	return in_array( $mode, array( 'mixed', 'both' ), true );
}

/**
 * Hide the Pre-Packed Pallet option from variation dropdowns
 * for users who are not logged in or not pallet approved.
 */
add_action( 'wp_footer', function() {
	if ( ! is_product() || wpo_user_can_buy_pallets() ) {
		return;
	}
	?>
	<script>
	document.addEventListener('DOMContentLoaded', function () {
		const palletValue = '<?php echo esc_js( wpo_get_prepacked_pallet_value() ); ?>';

		document.querySelectorAll('form.variations_form select').forEach(function(select) {
			const option = select.querySelector('option[value="' + palletValue + '"]');

			if (!option) {
				return;
			}

			if (select.value === palletValue) {
				select.value = '';
				select.dispatchEvent(new Event('change', { bubbles: true }));
			}

			option.remove();
		});
	});
	</script>
	<?php
}, 99 );

/**
 * Prevent unauthorized users from adding Pre-Packed Pallet variations to cart.
 */
add_filter( 'woocommerce_add_to_cart_validation', function( $passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
	if ( wpo_user_can_buy_pallets() ) {
		return $passed;
	}

	$pallet_value = wpo_get_prepacked_pallet_value();

	$message = is_user_logged_in()
		? 'Pre-Packed Pallet is available only to approved pallet buyers. Your account is not yet approved for pallet purchases.'
		: 'Pre-Packed Pallet is available only to approved pallet buyers. Please log in or contact us to request access.';

	if ( ! empty( $variations ) && is_array( $variations ) ) {
		foreach ( $variations as $attribute_value ) {
			if ( (string) $attribute_value === $pallet_value ) {
				wc_add_notice( $message, 'error' );
				return false;
			}
		}
	}

	if ( $variation_id && wpo_variation_is_prepacked_pallet( $variation_id ) ) {
		wc_add_notice( $message, 'error' );
		return false;
	}

	return $passed;
}, 10, 6 );

/**
 * Prevent approved pre-packed pallet submissions from also entering
 * the standard WooCommerce cart.
 */
add_filter( 'woocommerce_add_to_cart_validation', function( $passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {

	// Do not block when loading pallet items into Woo cart for final checkout.
	if ( function_exists( 'WC' ) && WC()->session && 'yes' === WC()->session->get( 'wpo_pallet_checkout' ) ) {
		return $passed;
	}

	if ( ! wpo_user_can_buy_pallets() ) {
		return $passed;
	}

	if ( $variation_id && wpo_variation_is_prepacked_pallet( $variation_id ) ) {
		return false;
	}

	if ( ! empty( $variations ) && is_array( $variations ) && wpo_attributes_include_prepacked_pallet( $variations ) ) {
		return false;
	}

	return $passed;

}, 9, 6 );

/**
 * Get pallet nav count.
 */
function wpo_get_pallet_nav_count() {
	$order = wpo_get_pallet_order();

	if ( empty( $order ) || ! is_array( $order ) ) {
		return 0;
	}

	$count = 0;

	foreach ( $order as $item ) {
		$count += ! empty( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;
	}

	return $count;
}

/**
 * Whether the current Woo cart has items.
 */
function wpo_standard_cart_has_items() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return false;
	}

	foreach ( WC()->cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['wpo_pallet_order_type'] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether the current pallet order has items.
 */
function wpo_pallet_order_has_items() {
	$order = wpo_get_pallet_order();
	return ! empty( $order ) && is_array( $order );
}

/**
 * Show split-order notices.
 */
add_action( 'woocommerce_before_cart', 'wpo_show_split_order_notice_on_cart', 5 );
add_action( 'woocommerce_before_checkout_form', 'wpo_show_split_order_notice_on_checkout', 5 );

function wpo_show_split_order_notice_on_cart() {
	if ( ! wpo_standard_cart_has_items() || ! wpo_pallet_order_has_items() ) {
		return;
	}

	wc_print_notice(
		'You also have an active pallet order. Pallet orders ship differently from standard parcel orders and must be checked out separately. <a href="' . esc_url( wpo_pallet_page_url() ) . '">View Pallet Order</a>',
		'notice'
	);
}

function wpo_show_split_order_notice_on_checkout() {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	if ( 'yes' === WC()->session->get( 'wpo_pallet_checkout' ) ) {
		return;
	}

	if ( ! wpo_standard_cart_has_items() || ! wpo_pallet_order_has_items() ) {
		return;
	}

	wc_print_notice(
		'You also have an active pallet order. Standard items and pallet orders require separate checkout. <a href="' . esc_url( wpo_pallet_page_url() ) . '">View Pallet Order</a>',
		'notice'
	);
}

/**
 * Hide Pre-Packed Pallet variation prices from unauthorized users
 * by excluding those variations from variable product price calculations.
 */
add_filter( 'woocommerce_variation_prices', 'wpo_hide_prepacked_prices_for_unauthorized_users', 10, 2 );

function wpo_hide_prepacked_prices_for_unauthorized_users( $prices, $product ) {
	if ( wpo_user_can_buy_pallets() ) {
		return $prices;
	}

	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		return $prices;
	}

	foreach ( $prices['price'] as $variation_id => $price ) {
		if ( wpo_variation_is_prepacked_pallet( $variation_id ) ) {
			unset( $prices['price'][ $variation_id ] );
			unset( $prices['regular_price'][ $variation_id ] );
			unset( $prices['sale_price'][ $variation_id ] );
		}
	}

	return $prices;
}

/**
 * Remove Pre-Packed Pallet variations from variation data
 * for users who are not logged in or not pallet approved.
 */
add_filter( 'woocommerce_available_variation', 'wpo_hide_prepacked_variation_data_for_unauthorized_users', 10, 3 );

function wpo_hide_prepacked_variation_data_for_unauthorized_users( $data, $product, $variation ) {
	if ( wpo_user_can_buy_pallets() ) {
		return $data;
	}

	if ( $variation && wpo_variation_is_prepacked_pallet( $variation->get_id() ) ) {
		return false;
	}

	return $data;
}

/**
 * Make WooCommerce variation price caching aware of pallet-approval state.
 *
 * Without this, a variable product price range cached for an approved pallet buyer
 * can be reused for a non-approved user, exposing pre-packed pallet prices.
 */
add_filter( 'woocommerce_get_variation_prices_hash', 'wpo_variation_prices_hash_by_pallet_access', 10, 3 );

function wpo_variation_prices_hash_by_pallet_access( $price_hash, $product, $for_display ) {
	$price_hash['wpo_pallet_access'] = wpo_user_can_buy_pallets() ? 'approved' : 'standard';

	return $price_hash;
}

/**
 * Apply the configured pallet-order discount.
 */
add_action( 'woocommerce_cart_calculate_fees', function( $cart ) {

	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	if ( ! $cart || $cart->is_empty() ) {
		return;
	}

	// Only apply during pallet checkout.
	if ( WC()->session->get( 'wpo_pallet_checkout' ) !== 'yes' ) {
		return;
	}

	$discount_rate = (float) WPO_PALLET_DISCOUNT_RATE;

	// Use cart subtotal before shipping/tax.
	$subtotal = (float) $cart->get_subtotal();

	if ( $subtotal <= 0 ) {
		return;
	}

	$discount = round( $subtotal * $discount_rate, wc_get_price_decimals() );

	if ( $discount <= 0 ) {
		return;
	}

	$cart->add_fee(
		__( 'Pallet Order Discount ', 'wpo' ),
		-1 * $discount,
		false
	);

}, 20 );

/**
 * Apply the configured credit/debit card handling fee to pallet orders.
 */
add_action( 'woocommerce_cart_calculate_fees', function( $cart ) {

	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	if ( ! $cart || $cart->is_empty() ) {
		return;
	}

	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	// Only apply during pallet checkout.
	if ( 'yes' !== WC()->session->get( 'wpo_pallet_checkout' ) ) {
		return;
	}

	$chosen_payment_method = WC()->session->get( 'chosen_payment_method' );

	if ( 'stripe' !== $chosen_payment_method ) {
		return;
	}

	$subtotal = (float) $cart->get_subtotal();

	if ( $subtotal <= 0 ) {
		return;
	}

	$fee = round( $subtotal * (float) WPO_CARD_HANDLING_RATE, wc_get_price_decimals() );

	if ( $fee <= 0 ) {
		return;
	}

	$cart->add_fee(
		__( 'Credit/Debit Card Handling Fee', 'wpo' ),
		$fee,
		false
	);

}, 25 );

/**
 * Refresh checkout totals when payment method changes.
 */
add_action( 'wp_footer', function() {
	if ( ! is_checkout() ) {
		return;
	}
	?>
	<script>
	jQuery(function($) {
		$(document.body).on('change', 'input[name="payment_method"]', function() {
			$(document.body).trigger('update_checkout');
		});
	});
	</script>
	<?php
});

/**
 * Pallet checkout should only show freight shipping.
 */
add_filter( 'woocommerce_package_rates', 'wpo_remove_flat_rate_during_pallet_checkout', 999999, 2 );

function wpo_remove_flat_rate_during_pallet_checkout( $rates, $package ) {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return $rates;
	}

	if ( 'yes' !== WC()->session->get( 'wpo_pallet_checkout' ) ) {
		return $rates;
	}

	foreach ( $rates as $rate_id => $rate ) {
		if (
			'flat_rate' === $rate->get_method_id() ||
			false !== strpos( strtolower( $rate_id ), 'flat_rate' ) ||
			false !== strpos( strtolower( $rate->get_label() ), 'flat rate' )
		) {
			unset( $rates[ $rate_id ] );
		}
	}

	return $rates;
}

add_filter( 'woocommerce_shipping_chosen_method', 'wpo_force_freight_as_chosen_method_for_pallet_checkout', 999999, 3 );

function wpo_force_freight_as_chosen_method_for_pallet_checkout( $chosen_method, $available_methods, $package ) {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return $chosen_method;
	}

	if ( 'yes' !== WC()->session->get( 'wpo_pallet_checkout' ) ) {
		return $chosen_method;
	}

	foreach ( $available_methods as $method_id => $method ) {
		if (
			false !== strpos( strtolower( $method_id ), 'freight' ) ||
			false !== strpos( strtolower( $method->get_label() ), 'freight' )
		) {
			return $method_id;
		}
	}

	return $chosen_method;
}