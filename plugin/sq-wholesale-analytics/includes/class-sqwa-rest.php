<?php
/**
 * REST-ендпойнт звіту: GET /wp-json/wc-sqwa/v1/report
 * Доступний з ключами WooCommerce REST API (consumer key/secret) з правом читання.
 */

defined( 'ABSPATH' ) || exit;

class SQWA_Rest {

	const NS = 'wc-sqwa/v1';

	public static function init() {
		add_filter( 'woocommerce_rest_is_request_to_rest_api', array( __CLASS__, 'allow_wc_keys' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * WooCommerce перевіряє consumer key лише для своїх маршрутів; додаємо наш простір імен.
	 */
	public static function allow_wc_keys( $is_wc_request ) {
		if ( $is_wc_request || empty( $_SERVER['REQUEST_URI'] ) ) {
			return $is_wc_request;
		}
		$uri = rawurldecode( wp_unslash( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		return false !== strpos( $uri, '/' . trailingslashit( rest_get_url_prefix() ) . self::NS )
			|| false !== strpos( $uri, 'rest_route=/' . self::NS );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/report',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'report' ),
				'permission_callback' => function () {
					return current_user_can( 'view_woocommerce_reports' );
				},
				'args'                => array(
					'from'     => array(
						'type'    => 'string',
						'pattern' => '^\d{4}-\d{2}-\d{2}$',
						'default' => '2000-01-01',
					),
					'to'       => array(
						'type'    => 'string',
						'pattern' => '^\d{4}-\d{2}-\d{2}$',
					),
					'statuses' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'details'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	public static function report( WP_REST_Request $request ) {
		$to       = $request['to'] ? $request['to'] : current_datetime()->format( 'Y-m-d' );
		$statuses = array();
		foreach ( (array) $request['statuses'] as $status ) {
			$status     = sanitize_key( $status );
			$statuses[] = 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status;
		}
		$statuses = array_values( array_intersect( $statuses, array_keys( wc_get_order_statuses() ) ) );
		if ( empty( $statuses ) ) {
			$statuses = SQWA_Report::DEFAULT_STATUS;
		}

		return rest_ensure_response( SQWA_Report::get( $request['from'], $to, $statuses, (bool) $request['details'] ) );
	}
}
