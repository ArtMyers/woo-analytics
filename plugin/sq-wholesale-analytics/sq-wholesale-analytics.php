<?php
/**
 * Plugin Name:       SweetQueen — Опт / Роздріб
 * Description:       Аналітика: скільки товару продано за роздрібною, а скільки за оптовою ціною (WooCommerce Tiered Price Table).
 * Version:           1.0.0
 * Author:            SweetQueen
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * Text Domain:       sq-wholesale-analytics
 */

defined( 'ABSPATH' ) || exit;

define( 'SQWA_VERSION', '1.0.0' );
define( 'SQWA_FILE', __FILE__ );
define( 'SQWA_DIR', plugin_dir_path( __FILE__ ) );
define( 'SQWA_URL', plugin_dir_url( __FILE__ ) );

require_once SQWA_DIR . 'includes/class-sqwa-tiers.php';
require_once SQWA_DIR . 'includes/class-sqwa-tracker.php';
require_once SQWA_DIR . 'includes/class-sqwa-report.php';
require_once SQWA_DIR . 'includes/class-sqwa-admin.php';
require_once SQWA_DIR . 'includes/class-sqwa-rest.php';

// Сумісність з HPOS (High-Performance Order Storage).
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SQWA_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		SQWA_Tracker::init();
		SQWA_Report::init();
		SQWA_Admin::init();
		SQWA_Rest::init();
	}
);
