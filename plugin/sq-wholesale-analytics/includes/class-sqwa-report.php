<?php
/**
 * Збір і агрегація продажів за типом ціни.
 */

defined( 'ABSPATH' ) || exit;

class SQWA_Report {

	const TYPES          = array( 'retail', 'wholesale', 'unknown' );
	const CACHE_VER_OPT  = 'sqwa_cache_ver';
	const DEFAULT_STATUS = array( 'wc-completed', 'wc-processing', 'wc-on-hold' );

	private static $cache_bumped = false;

	public static function init() {
		foreach ( array(
			'woocommerce_new_order',
			'woocommerce_update_order',
			'woocommerce_order_status_changed',
			'woocommerce_order_refunded',
			'woocommerce_delete_order',
			'woocommerce_trash_order',
		) as $hook ) {
			add_action( $hook, array( __CLASS__, 'bump_cache' ) );
		}
	}

	public static function bump_cache() {
		if ( self::$cache_bumped ) {
			return;
		}
		self::$cache_bumped = true;
		update_option( self::CACHE_VER_OPT, (string) microtime( true ), false );
	}

	/**
	 * @param string   $from     Y-m-d (часовий пояс сайту).
	 * @param string   $to       Y-m-d включно.
	 * @param string[] $statuses Статуси у форматі wc-completed.
	 * @param bool     $details  Додати список позицій (для перевірки), без кешу.
	 */
	public static function get( $from, $to, array $statuses, $details = false ) {
		if ( $details ) {
			return self::build( $from, $to, $statuses, true );
		}

		$key  = 'sqwa_' . md5( wp_json_encode( array( $from, $to, $statuses, get_option( self::CACHE_VER_OPT, '0' ) ) ) );
		$data = get_transient( $key );
		if ( false === $data ) {
			$data = self::build( $from, $to, $statuses, false );
			set_transient( $key, $data, HOUR_IN_SECONDS );
		}

		return $data;
	}

	/**
	 * @return array{0: string, 1: string} [тип, джерело: meta | rules | none]
	 */
	public static function classify_item( WC_Order_Item_Product $item ) {
		$saved = $item->get_meta( SQWA_Tracker::META_TYPE, true );
		if ( in_array( $saved, array( 'wholesale', 'retail' ), true ) ) {
			return array( $saved, 'meta' );
		}

		// Позиції, оформлені до встановлення плагіна: рахуємо за поточними правилами TPT.
		$product = $item->get_product();
		if ( ! $product ) {
			return array( 'unknown', 'none' );
		}

		$qty   = (float) $item->get_quantity();
		$rules = SQWA_Tiers::get_rules( $product );
		if ( $qty <= 0 || empty( $rules ) ) {
			return array( 'retail', 'rules' );
		}

		$unit    = (float) $item->get_subtotal() / $qty;
		$regular = (float) $product->get_regular_price();
		$retail  = $regular > 0 ? (float) wc_get_price_excluding_tax( $product, array( 'qty' => 1, 'price' => $regular ) ) : 0;

		return array( SQWA_Tiers::is_wholesale( $rules, $qty, $unit, $retail ) ? 'wholesale' : 'retail', 'rules' );
	}

	private static function build( $from, $to, array $statuses, $with_details ) {
		$tz     = wp_timezone();
		$start  = new DateTimeImmutable( $from . ' 00:00:00', $tz );
		$end    = new DateTimeImmutable( $to . ' 23:59:59', $tz );
		$bucket = self::bucket_size( $start, $end );

		$totals = array();
		foreach ( self::TYPES as $type ) {
			$totals[ $type ] = array( 'qty' => 0.0, 'total' => 0.0, 'orders' => 0 );
		}

		$timeline = self::empty_timeline( $start, $end, $bucket );
		$products = array();
		$details  = array();
		$page     = 1;

		do {
			$orders = wc_get_orders(
				array(
					'type'         => 'shop_order',
					'status'       => $statuses,
					'date_created' => $start->getTimestamp() . '...' . $end->getTimestamp(),
					'limit'        => 200,
					'page'         => $page,
					'orderby'      => 'date',
					'order'        => 'ASC',
				)
			);

			foreach ( $orders as $order ) {
				$order_types = array();
				$created     = $order->get_date_created();
				$bucket_key  = $created ? self::bucket_key( $created->setTimezone( $tz ), $bucket ) : '';

				foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
					list( $type, $source ) = self::classify_item( $item );

					$qty   = (float) $item->get_quantity() + (float) $order->get_qty_refunded_for_item( $item_id );
					$total = (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $item_id );

					if ( $with_details ) {
						$details[] = array(
							'order_id'   => $order->get_id(),
							'item_id'    => $item_id,
							'product_id' => $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(),
							'name'       => $item->get_name(),
							'qty'        => (float) $item->get_quantity(),
							'net_qty'    => $qty,
							'subtotal'   => (float) $item->get_subtotal(),
							'type'       => $type,
							'source'     => $source,
						);
					}

					if ( $qty <= 0 && $total <= 0 ) {
						continue; // Повністю повернено.
					}

					$order_types[ $type ]       = true;
					$totals[ $type ]['qty']    += $qty;
					$totals[ $type ]['total']  += $total;

					if ( isset( $timeline[ $bucket_key ] ) ) {
						$timeline[ $bucket_key ][ $type ] += $qty;
					}

					$key = self::product_key( $item );
					if ( ! isset( $products[ $key ] ) ) {
						$products[ $key ] = self::product_row( $item );
					}
					$products[ $key ][ $type . '_qty' ]   += $qty;
					$products[ $key ][ $type . '_total' ] += $total;
				}

				foreach ( array_keys( $order_types ) as $type ) {
					++$totals[ $type ]['orders'];
				}
			}

			++$page;
		} while ( count( $orders ) === 200 );

		foreach ( $products as &$row ) {
			$row['total_qty'] = $row['retail_qty'] + $row['wholesale_qty'] + $row['unknown_qty'];
			$row['share']     = self::share( $row['wholesale_qty'], $row['retail_qty'] );
		}
		unset( $row );

		usort(
			$products,
			function ( $a, $b ) {
				return $b['total_qty'] <=> $a['total_qty'];
			}
		);

		$data = array(
			'from'      => $from,
			'to'        => $to,
			'statuses'  => array_values( $statuses ),
			'bucket'    => $bucket,
			'currency'  => get_woocommerce_currency(),
			'totals'    => $totals,
			'share'     => self::share( $totals['wholesale']['qty'], $totals['retail']['qty'] ),
			'timeline'  => array_values( $timeline ),
			'products'  => $products,
			'generated' => time(),
		);

		if ( $with_details ) {
			$data['details'] = $details;
		}

		return $data;
	}

	/**
	 * Частка опту у відсотках від проданих одиниць з відомим типом.
	 */
	public static function share( $wholesale, $retail ) {
		$sum = $wholesale + $retail;
		return $sum > 0 ? round( $wholesale / $sum * 100, 1 ) : 0.0;
	}

	private static function product_key( WC_Order_Item_Product $item ) {
		$id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		return $id ? 'p' . $id : 'n' . md5( $item->get_name() );
	}

	private static function product_row( WC_Order_Item_Product $item ) {
		$product = $item->get_product();
		$rules   = $product ? SQWA_Tiers::get_rules( $product ) : array();

		return array(
			'product_id'      => $product ? $product->get_id() : 0,
			'edit_id'         => $product ? ( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) : 0,
			'name'            => $product ? $product->get_name() : $item->get_name(),
			'sku'             => $product ? $product->get_sku() : '',
			'exists'          => (bool) $product,
			'min_qty'         => SQWA_Tiers::min_qty( $rules ),
			'retail_qty'      => 0.0,
			'retail_total'    => 0.0,
			'wholesale_qty'   => 0.0,
			'wholesale_total' => 0.0,
			'unknown_qty'     => 0.0,
			'unknown_total'   => 0.0,
		);
	}

	private static function bucket_size( DateTimeImmutable $start, DateTimeImmutable $end ) {
		$days = (int) $start->diff( $end )->days + 1;
		if ( $days <= 62 ) {
			return 'day';
		}
		return $days <= 400 ? 'week' : 'month';
	}

	private static function bucket_start( DateTimeInterface $date, $bucket ) {
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date->format( 'Y-m-d 00:00:00' ), $date->getTimezone() );
		if ( 'week' === $bucket ) {
			return $date->modify( 'monday this week' );
		}
		if ( 'month' === $bucket ) {
			return $date->modify( 'first day of this month' );
		}
		return $date;
	}

	private static function bucket_key( DateTimeInterface $date, $bucket ) {
		return self::bucket_start( $date, $bucket )->format( 'Y-m-d' );
	}

	private static function empty_timeline( DateTimeImmutable $start, DateTimeImmutable $end, $bucket ) {
		$step     = array(
			'day'   => '+1 day',
			'week'  => '+1 week',
			'month' => '+1 month',
		);
		$timeline = array();
		$cursor   = self::bucket_start( $start, $bucket );

		while ( $cursor <= $end ) {
			$key = $cursor->format( 'Y-m-d' );
			if ( 'day' === $bucket ) {
				$label = $cursor->format( 'd.m.Y' );
			} elseif ( 'week' === $bucket ) {
				$label = $cursor->format( 'd.m' ) . ' – ' . $cursor->modify( '+6 days' )->format( 'd.m.Y' );
			} else {
				$label = $cursor->format( 'm.Y' );
			}

			$timeline[ $key ] = array(
				'key'       => $key,
				'label'     => $label,
				'retail'    => 0.0,
				'wholesale' => 0.0,
				'unknown'   => 0.0,
			);
			$cursor           = $cursor->modify( $step[ $bucket ] );
		}

		return $timeline;
	}
}
