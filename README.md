# WooCommerce Pallet Ordering

A portfolio version of a custom WooCommerce ordering workflow for wholesale pallet purchases.

## What it demonstrates

- A pallet order maintained separately from the standard WooCommerce cart
- Account-level authorization for pallet purchasing
- Mixed and pre-packed pallet workflows
- Weight-based pallet qualification and automatic pallet splitting
- Variable-product and variation handling
- AJAX and POST request handling with WordPress nonces
- Separate pallet checkout using the WooCommerce cart as the final transport layer
- Order metadata for identifying pallet orders
- Pallet-specific discounts and conditional card handling fees
- Freight-oriented shipping rules
- Protection against exposing restricted pallet variations and prices to unauthorized users

## Business problem

The original implementation was created for a WooCommerce store that needed to support two purchasing models at the same time: ordinary parcel orders and wholesale pallet orders. Approved wholesale customers needed to build mixed or pre-packed pallets under weight rules, while standard shoppers continued using the normal WooCommerce cart.

The solution maintains pallet selections in the WooCommerce session independently of the normal cart. Once a pallet order qualifies, its items are transferred into WooCommerce for checkout, payment, shipping, and normal order creation.

## Configuration

The portfolio version exposes the primary demonstration rules as constants near the top of `pallet-order.php`:

- `WPO_MIXED_PALLET_MIN_LBS` — 500 lbs
- `WPO_MIXED_PALLET_MAX_LBS` — 1,500 lbs
- `WPO_PALLET_DISCOUNT_RATE` — 3.5%
- `WPO_CARD_HANDLING_RATE` — 3%
- `WPO_PALLET_PAGE_SLUG`
- `WPO_QUALIFICATION_PAGE_SLUG`

Additional integration assumptions are intentionally visible in the code, including the `pallet_status` user-meta approval convention, product pallet-mode metadata, Stripe gateway ID, and freight shipping method matching.

## Notes

This repository is a sanitized portfolio sample derived from a production customization. Client-specific naming has been removed. It is intended to demonstrate WooCommerce/PHP integration work rather than serve as a universal drop-in plugin.

Before using it on another store, review the account-approval metadata, product metadata, page slugs, payment gateway IDs, shipping method IDs, styling, and business rules.

## Technologies

PHP, WordPress, WooCommerce, JavaScript, jQuery, AJAX, WordPress/WooCommerce hooks and APIs.
