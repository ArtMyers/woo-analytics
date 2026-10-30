<?php
/**
 * Фіксує тип ціни (опт / роздріб) у позиції замовлення в момент оформлення.
 */

defined( 'ABSPATH' ) || exit;

class SQWA_Tracker {

	const META_TYPE   = '_sqwa_price_type';
	const META_TIER   = '_sqwa_tier_qty';
	const META_RETAIL = '_sqwa_retail_price';

	public static function init() {
		// Спрацьовує і для класичного, і для блочного checkout (Store API).
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'tag_item' ), 20, 4 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_meta' ) );
	}

	/**
	 * @param WC_Order_Item_Product $item
	 * @param string                $cart_item_key
	 * @param array                 $values  Позиція кошика; ціну в $values['data'] вже змінив TPT.
	 * @param WC_Order              $order
	 */
	public static function tag_item( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['data'] ) || ! $values['data'] instanceof WC_Product ) {
			return;
		}

		$cart_product = $values['data'];
		// Свіжий екземпляр товару, щоб роздрібна ціна не залежала від змін, які TPT вносить у кошик.
		$product = wc_get_product( $cart_product->get_id() );
		if ( ! $product ) {
			return;
		}

		$qty    = (float) $values['quantity'];
		$unit   = (float) $cart_product->get_price();
		$retail = (float) $product->get_regular_price();
		if ( $retail <= 0 ) {
			$retail = (float) $product->get_price();
		}

		$rules = SQWA_Tiers::get_rules( $product );
		$type  = SQWA_Tiers::is_wholesale( $rules, $qty, $unit, $retail ) ? 'wholesale' : 'retail';

		$item->add_meta_data( self::META_TYPE, $type, true );
		$item->add_meta_data( self::META_TIER, 'wholesale' === $type ? SQWA_Tiers::applied_tier( $rules, $qty ) : 0, true );
		$item->add_meta_data( self::META_RETAIL, wc_format_decimal( $retail ), true );
	}

	public static function hide_meta( $keys ) {
		return array_merge( $keys, array( self::META_TYPE, self::META_TIER, self::META_RETAIL ) );
	}
}
