<?php
/**
 * Сторінка «Аналітика → Опт / Роздріб», експорт CSV і бейдж у замовленні.
 */

defined( 'ABSPATH' ) || exit;

class SQWA_Admin {

	const PAGE = 'sqwa-report';
	const CAP  = 'view_woocommerce_reports';

	const SORTABLE = array( 'name', 'retail_qty', 'retail_total', 'wholesale_qty', 'wholesale_total', 'unknown_qty', 'share', 'total_qty' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 99 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_sqwa_export', array( __CLASS__, 'export' ) );
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'order_item_badge' ), 10, 2 );
	}

	public static function menu() {
		$parent = 'yes' === get_option( 'woocommerce_analytics_enabled', 'yes' ) ? 'wc-admin&path=/analytics/overview' : 'woocommerce';
		add_submenu_page( $parent, 'Опт / Роздріб', 'Опт / Роздріб', self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function assets() {
		if ( isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_enqueue_style( 'sqwa-admin', SQWA_URL . 'assets/admin.css', array(), SQWA_VERSION );
		}
	}

	/* ------------------------------------------------------------------ Фільтри */

	private static function presets() {
		$now = current_datetime();

		return array(
			'today'      => array( 'Сьогодні', $now, $now ),
			'7d'         => array( '7 днів', $now->modify( '-6 days' ), $now ),
			'30d'        => array( '30 днів', $now->modify( '-29 days' ), $now ),
			'month'      => array( 'Цей місяць', $now->modify( 'first day of this month' ), $now ),
			'last_month' => array( 'Минулий місяць', $now->modify( 'first day of last month' ), $now->modify( 'last day of last month' ) ),
			'year'       => array( 'Цей рік', $now->setDate( (int) $now->format( 'Y' ), 1, 1 ), $now ),
		);
	}

	/**
	 * Фільтри з GET-запиту (сторінка і експорт користуються однаковими параметрами).
	 */
	private static function get_filters() {
		// phpcs:disable WordPress.Security.NonceVerification
		$presets = self::presets();
		$range   = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'month';
		$from    = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to      = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		$date_re = '/^\d{4}-\d{2}-\d{2}$/';

		if ( 'custom' !== $range || ! preg_match( $date_re, $from ) || ! preg_match( $date_re, $to ) ) {
			if ( ! isset( $presets[ $range ] ) ) {
				$range = 'month';
			}
			$from = $presets[ $range ][1]->format( 'Y-m-d' );
			$to   = $presets[ $range ][2]->format( 'Y-m-d' );
		}
		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}

		$all      = array_keys( wc_get_order_statuses() );
		$statuses = isset( $_GET['statuses'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_GET['statuses'] ) ) : array();
		$statuses = array_values( array_intersect( $statuses, $all ) );
		if ( empty( $statuses ) ) {
			$statuses = SQWA_Report::DEFAULT_STATUS;
		}

		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'total_qty';
		$orderby = in_array( $orderby, self::SORTABLE, true ) ? $orderby : 'total_qty';
		$order   = isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : 'desc';
		// phpcs:enable

		return compact( 'range', 'from', 'to', 'statuses', 'orderby', 'order' );
	}

	private static function filter_args( array $f ) {
		return array(
			'range'    => $f['range'],
			'from'     => $f['from'],
			'to'       => $f['to'],
			'statuses' => $f['statuses'],
		);
	}

	private static function sort_products( array $products, $orderby, $order ) {
		usort(
			$products,
			function ( $a, $b ) use ( $orderby ) {
				return 'name' === $orderby ? strcasecmp( $a['name'], $b['name'] ) : $a[ $orderby ] <=> $b[ $orderby ];
			}
		);
		return 'desc' === $order ? array_reverse( $products ) : $products;
	}

	/* ------------------------------------------------------------------ Сторінка */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Недостатньо прав.' );
		}

		$f        = self::get_filters();
		$data     = SQWA_Report::get( $f['from'], $f['to'], $f['statuses'] );
		$products = self::sort_products( $data['products'], $f['orderby'], $f['order'] );
		$t        = $data['totals'];
		$known    = $t['retail']['qty'] + $t['wholesale']['qty'];
		$base_url = add_query_arg( array_merge( array( 'page' => self::PAGE ), self::filter_args( $f ) ), admin_url( 'admin.php' ) );
		$export   = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'sqwa_export' ), self::filter_args( $f ) ), admin_url( 'admin-post.php' ) ), 'sqwa_export' );
		$show_unk = $t['unknown']['qty'] > 0;
		?>
		<div class="wrap sqwa">
			<h1 class="wp-heading-inline">Продажі: опт / роздріб</h1>
			<a href="<?php echo esc_url( $export ); ?>" class="page-title-action">Експорт CSV</a>
			<hr class="wp-header-end">

			<form method="get" class="sqwa-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<div class="sqwa-filters__row">
					<label>Період
						<select name="range" id="sqwa-range">
							<?php foreach ( self::presets() as $key => $preset ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $f['range'], $key ); ?>><?php echo esc_html( $preset[0] ); ?></option>
							<?php endforeach; ?>
							<option value="custom" <?php selected( $f['range'], 'custom' ); ?>>Довільний</option>
						</select>
					</label>
					<label>з <input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>" class="sqwa-date"></label>
					<label>по <input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>" class="sqwa-date"></label>
					<button type="submit" class="button button-primary">Показати</button>
				</div>
				<fieldset class="sqwa-filters__row sqwa-statuses">
					<legend>Статуси замовлень:</legend>
					<?php foreach ( wc_get_order_statuses() as $key => $label ) : ?>
						<label><input type="checkbox" name="statuses[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $f['statuses'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</fieldset>
			</form>
			<script>
				document.querySelectorAll( '.sqwa-date' ).forEach( function ( input ) {
					input.addEventListener( 'change', function () { document.getElementById( 'sqwa-range' ).value = 'custom'; } );
				} );
			</script>

			<div class="sqwa-cards">
				<?php
				self::card( 'retail', 'Роздріб', $t['retail'] );
				self::card( 'wholesale', 'Опт', $t['wholesale'] );
				?>
				<div class="sqwa-card sqwa-card--share">
					<div class="sqwa-card__title">Частка опту</div>
					<div class="sqwa-card__value"><?php echo esc_html( self::num( $data['share'] ) ); ?>%</div>
					<div class="sqwa-card__meta">від <?php echo esc_html( self::num( $known ) ); ?> шт. проданого товару</div>
				</div>
				<?php
				if ( $show_unk ) {
					self::card( 'unknown', 'Невизначено', $t['unknown'], 'Товар видалено з магазину, тип ціни визначити неможливо.' );
				}
				?>
			</div>

			<?php if ( $known > 0 ) : ?>
				<div class="sqwa-split" role="img" aria-label="<?php echo esc_attr( sprintf( 'Опт %s%%, роздріб %s%%', $data['share'], 100 - $data['share'] ) ); ?>">
					<span class="sqwa-split__part sqwa-bg--retail" style="width:<?php echo esc_attr( 100 - $data['share'] ); ?>%"></span>
					<span class="sqwa-split__part sqwa-bg--wholesale" style="width:<?php echo esc_attr( $data['share'] ); ?>%"></span>
				</div>
			<?php endif; ?>

			<?php self::render_timeline( $data ); ?>

			<h2>По товарах</h2>
			<?php if ( empty( $products ) ) : ?>
				<p class="sqwa-empty">За вибраний період продажів немає.</p>
			<?php else : ?>
				<table class="widefat striped sqwa-table">
					<thead>
						<tr>
							<?php
							self::th( 'name', 'Товар', $f, $base_url );
							echo '<th>Поріг опту</th>';
							self::th( 'retail_qty', 'Роздріб, шт.', $f, $base_url, true );
							self::th( 'retail_total', 'Роздріб, сума', $f, $base_url, true );
							self::th( 'wholesale_qty', 'Опт, шт.', $f, $base_url, true );
							self::th( 'wholesale_total', 'Опт, сума', $f, $base_url, true );
							if ( $show_unk ) {
								self::th( 'unknown_qty', 'Невизнач., шт.', $f, $base_url, true );
							}
							self::th( 'share', 'Частка опту', $f, $base_url, true );
							?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $products as $row ) : ?>
							<tr>
								<td>
									<?php if ( $row['edit_id'] ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $row['edit_id'] ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $row['name'] ); ?> <span class="sqwa-muted">(видалено)</span>
									<?php endif; ?>
									<?php if ( $row['sku'] ) : ?>
										<div class="sqwa-muted">Артикул: <?php echo esc_html( $row['sku'] ); ?></div>
									<?php endif; ?>
								</td>
								<td><?php echo $row['min_qty'] ? '<span class="sqwa-tag">від ' . esc_html( $row['min_qty'] ) . ' шт.</span>' : '<span class="sqwa-muted">—</span>'; ?></td>
								<td class="num"><?php echo esc_html( self::num( $row['retail_qty'] ) ); ?></td>
								<td class="num"><?php echo wp_kses_post( wc_price( $row['retail_total'] ) ); ?></td>
								<td class="num"><strong><?php echo esc_html( self::num( $row['wholesale_qty'] ) ); ?></strong></td>
								<td class="num"><?php echo wp_kses_post( wc_price( $row['wholesale_total'] ) ); ?></td>
								<?php if ( $show_unk ) : ?>
									<td class="num"><?php echo esc_html( self::num( $row['unknown_qty'] ) ); ?></td>
								<?php endif; ?>
								<td class="num"><?php echo esc_html( self::num( $row['share'] ) ); ?>%</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th colspan="2">Разом</th>
							<th class="num"><?php echo esc_html( self::num( $t['retail']['qty'] ) ); ?></th>
							<th class="num"><?php echo wp_kses_post( wc_price( $t['retail']['total'] ) ); ?></th>
							<th class="num"><?php echo esc_html( self::num( $t['wholesale']['qty'] ) ); ?></th>
							<th class="num"><?php echo wp_kses_post( wc_price( $t['wholesale']['total'] ) ); ?></th>
							<?php if ( $show_unk ) : ?>
								<th class="num"><?php echo esc_html( self::num( $t['unknown']['qty'] ) ); ?></th>
							<?php endif; ?>
							<th class="num"><?php echo esc_html( self::num( $data['share'] ) ); ?>%</th>
						</tr>
					</tfoot>
				</table>
			<?php endif; ?>

			<p class="sqwa-note">
				Оптова позиція — кількість у рядку досягла порогу Tiered Price Table, а ціна за одиницю нижча за звичайну.
				Суми вказано без податків, з урахуванням знижок і повернень. Дані оновлюються при зміні замовлень (кеш до 1 год.).
			</p>
		</div>
		<?php
	}

	private static function card( $type, $title, array $totals, $note = '' ) {
		?>
		<div class="sqwa-card sqwa-card--<?php echo esc_attr( $type ); ?>">
			<div class="sqwa-card__title"><?php echo esc_html( $title ); ?></div>
			<div class="sqwa-card__value"><?php echo esc_html( self::num( $totals['qty'] ) ); ?> <small>шт.</small></div>
			<div class="sqwa-card__meta">
				<?php echo wp_kses_post( wc_price( $totals['total'] ) ); ?> · замовлень: <?php echo esc_html( $totals['orders'] ); ?>
			</div>
			<?php if ( $note ) : ?>
				<div class="sqwa-card__meta sqwa-muted"><?php echo esc_html( $note ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_timeline( array $data ) {
		$max = 0;
		foreach ( $data['timeline'] as $point ) {
			$max = max( $max, $point['retail'] + $point['wholesale'] + $point['unknown'] );
		}
		if ( $max <= 0 ) {
			return;
		}

		$titles = array(
			'day'   => 'По днях',
			'week'  => 'По тижнях',
			'month' => 'По місяцях',
		);
		?>
		<h2><?php echo esc_html( $titles[ $data['bucket'] ] ); ?>, шт.</h2>
		<div class="sqwa-legend">
			<span><i class="sqwa-bg--retail"></i>Роздріб</span>
			<span><i class="sqwa-bg--wholesale"></i>Опт</span>
		</div>
		<div class="sqwa-chart">
			<?php foreach ( $data['timeline'] as $point ) : ?>
				<?php
				$title = sprintf( "%s\nРоздріб: %s шт.\nОпт: %s шт.", $point['label'], self::num( $point['retail'] ), self::num( $point['wholesale'] ) );
				if ( $point['unknown'] > 0 ) {
					$title .= "\nНевизначено: " . self::num( $point['unknown'] ) . ' шт.';
				}
				?>
				<div class="sqwa-chart__col" title="<?php echo esc_attr( $title ); ?>">
					<div class="sqwa-chart__stack">
						<span class="sqwa-bg--unknown" style="height:<?php echo esc_attr( round( $point['unknown'] / $max * 100, 2 ) ); ?>%"></span>
						<span class="sqwa-bg--wholesale" style="height:<?php echo esc_attr( round( $point['wholesale'] / $max * 100, 2 ) ); ?>%"></span>
						<span class="sqwa-bg--retail" style="height:<?php echo esc_attr( round( $point['retail'] / $max * 100, 2 ) ); ?>%"></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<div class="sqwa-chart__axis">
			<span><?php echo esc_html( $data['timeline'][0]['label'] ); ?></span>
			<span>макс. <?php echo esc_html( self::num( $max ) ); ?> шт.</span>
			<span><?php echo esc_html( end( $data['timeline'] )['label'] ); ?></span>
		</div>
		<?php
	}

	private static function th( $key, $label, array $f, $base_url, $num = false ) {
		$active = $f['orderby'] === $key;
		$order  = $active && 'desc' === $f['order'] ? 'asc' : 'desc';
		$url    = add_query_arg( array( 'orderby' => $key, 'order' => $order ), $base_url );
		$arrow  = $active ? ( 'desc' === $f['order'] ? ' ↓' : ' ↑' ) : '';
		printf(
			'<th class="%s"><a href="%s">%s%s</a></th>',
			esc_attr( $num ? 'num' : '' ),
			esc_url( $url ),
			esc_html( $label ),
			esc_html( $arrow )
		);
	}

	private static function num( $value ) {
		$value = (float) $value;
		return number_format_i18n( $value, floor( $value ) == $value ? 0 : 1 ); // phpcs:ignore Universal.Operators.StrictComparisons
	}

	/* ------------------------------------------------------------------ CSV */

	public static function export() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Недостатньо прав.', 403 );
		}
		check_admin_referer( 'sqwa_export' );

		$f    = self::get_filters();
		$data = SQWA_Report::get( $f['from'], $f['to'], $f['statuses'] );
		$dec  = wc_get_price_decimals();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=opt-rozdrib-' . $f['from'] . '_' . $f['to'] . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM, щоб Excel правильно відкрив кирилицю.
		fputcsv( $out, array( 'ID', 'Товар', 'Артикул', 'Поріг опту', 'Роздріб, шт.', 'Роздріб, сума', 'Опт, шт.', 'Опт, сума', 'Невизначено, шт.', 'Невизначено, сума', 'Частка опту, %' ), ';' );

		foreach ( $data['products'] as $row ) {
			fputcsv(
				$out,
				array(
					$row['product_id'],
					$row['name'],
					$row['sku'],
					$row['min_qty'] ? $row['min_qty'] : '',
					$row['retail_qty'],
					number_format( $row['retail_total'], $dec, '.', '' ),
					$row['wholesale_qty'],
					number_format( $row['wholesale_total'], $dec, '.', '' ),
					$row['unknown_qty'],
					number_format( $row['unknown_total'], $dec, '.', '' ),
					$row['share'],
				),
				';'
			);
		}

		$t = $data['totals'];
		fputcsv(
			$out,
			array(
				'',
				'Разом',
				'',
				'',
				$t['retail']['qty'],
				number_format( $t['retail']['total'], $dec, '.', '' ),
				$t['wholesale']['qty'],
				number_format( $t['wholesale']['total'], $dec, '.', '' ),
				$t['unknown']['qty'],
				number_format( $t['unknown']['total'], $dec, '.', '' ),
				$data['share'],
			),
			';'
		);
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ------------------------------------------------------------------ Замовлення */

	/**
	 * Бейдж «Опт / Роздріб» під позицією на сторінці редагування замовлення.
	 */
	public static function order_item_badge( $item_id, $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		list( $type, $source ) = SQWA_Report::classify_item( $item );
		$styles                = array(
			'retail'    => array( 'Роздріб', '#2271b1' ),
			'wholesale' => array( 'Опт', '#008a20' ),
			'unknown'   => array( 'Тип ціни невідомий', '#787c82' ),
		);
		$tier                  = (int) $item->get_meta( SQWA_Tracker::META_TIER, true );

		printf(
			'<div style="margin-top:4px"><span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;color:#fff;background:%s">%s%s</span>%s</div>',
			esc_attr( $styles[ $type ][1] ),
			esc_html( $styles[ $type ][0] ),
			$tier ? esc_html( ' · від ' . $tier . ' шт.' ) : '',
			'rules' === $source ? ' <small style="color:#787c82">(за поточними правилами)</small>' : ''
		);
	}
}
