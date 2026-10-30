<?php
/**
 * Читання оптових порогів з плагіна Tiered Price Table.
 */

defined( 'ABSPATH' ) || exit;

class SQWA_Tiers {

	/** @var array<int, array<int, float>> */
	private static $cache = array();

	/**
	 * Правила товару у вигляді [мін. кількість => ціна або відсоток], відсортовані за кількістю.
	 *
	 * @param WC_Product|int $product
	 * @return array<int, float>
	 */
	public static function get_rules( $product ) {
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( $product );
		}
		if ( ! $product ) {
			return array();
		}

		$id = $product->get_id();
		if ( isset( self::$cache[ $id ] ) ) {
			return self::$cache[ $id ];
		}

		$rules = self::rules_for_id( $id );
		if ( empty( $rules ) && $product->get_parent_id() ) {
			$rules = self::rules_for_id( $product->get_parent_id() );
		}

		self::$cache[ $id ] = apply_filters( 'sqwa_tier_rules', $rules, $product );

		return self::$cache[ $id ];
	}

	/**
	 * Спершу питаємо сам TPT (враховує рольові та глобальні правила), потім читаємо мету товару.
	 */
	private static function rules_for_id( $id ) {
		$rules = array();

		if ( class_exists( '\TierPricingTable\PriceManager' ) && method_exists( '\TierPricingTable\PriceManager', 'getPricingRule' ) ) {
			try {
				$rule = \TierPricingTable\PriceManager::getPricingRule( $id );
				if ( is_object( $rule ) && method_exists( $rule, 'getRules' ) ) {
					$rules = self::normalize( $rule->getRules() );
				}
			} catch ( \Throwable $e ) {
				$rules = array();
			}
		}

		if ( empty( $rules ) ) {
			$type  = 'percentage' === get_post_meta( $id, '_tiered_price_rules_type', true ) ? 'percentage' : 'fixed';
			$rules = self::normalize( get_post_meta( $id, '_' . $type . '_price_rules', true ) );
		}

		return $rules;
	}

	private static function normalize( $rules ) {
		if ( is_string( $rules ) ) {
			$rules = json_decode( $rules, true );
		}
		if ( ! is_array( $rules ) ) {
			return array();
		}

		$out = array();
		foreach ( $rules as $qty => $value ) {
			$qty = (int) $qty;
			if ( $qty > 0 && '' !== $value && null !== $value ) {
				$out[ $qty ] = (float) $value;
			}
		}
		ksort( $out );

		return $out;
	}

	public static function min_qty( array $rules ) {
		return $rules ? (int) array_key_first( $rules ) : 0;
	}

	/**
	 * Поріг, який спрацював для цієї кількості (0, якщо жоден).
	 */
	public static function applied_tier( array $rules, $qty ) {
		$applied = 0;
		foreach ( array_keys( $rules ) as $min ) {
			if ( $qty >= $min ) {
				$applied = $min;
			}
		}
		return $applied;
	}

	/**
	 * Оптова позиція: кількість досягла порогу і ціна за одиницю нижча за роздрібну.
	 */
	public static function is_wholesale( array $rules, $qty, $unit_price, $retail_price ) {
		$min = self::min_qty( $rules );

		return $min > 0
			&& $qty >= $min
			&& $retail_price > 0
			&& $unit_price < $retail_price - 0.0001;
	}
}
