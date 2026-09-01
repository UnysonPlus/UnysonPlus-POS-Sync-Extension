<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Square webhook verification and normalization.
 *
 * ## Square signs differently from us, and the difference matters
 *
 * Our own endpoint signs `{timestamp}\n{body}`. Square signs
 * **`notification_url + raw_body`**, HMAC-SHA256 with the subscription's
 * signature key, base64-encoded, in `x-square-hmacsha256-signature`.
 *
 * Two consequences that cost real time if you do not know them:
 *
 *  - **The URL is part of the signature.** It must be byte-identical to what
 *    was registered in the Square dashboard — including the scheme, any
 *    trailing slash, and, on a dev tunnel, the tunnel host rather than
 *    `localhost`. A site behind a reverse proxy that rewrites the host will
 *    fail every signature until the configured URL is used instead of the one
 *    WordPress infers.
 *  - **There is no timestamp in the scheme**, so Square's signature has no
 *    natural expiry the way ours does. Idempotency is what protects us, which
 *    is the same reason our own endpoint needs no nonce cache.
 *
 * ## Payments and orders arrive separately
 *
 * `payment.created` carries the money but not the line items; the items live on
 * the Order, which may arrive in a later `order.updated` — or not at all if the
 * seller took a quick cash amount with no itemisation. So a payment is fetched
 * against its order before being recorded, and a payment with no resolvable
 * order is skipped with a reason rather than recorded as a sale of nothing.
 */
class FW_POS_Square_Webhooks {

	const SIGNATURE_HEADER = 'x-square-hmacsha256-signature';

	/** The event types worth acting on. Everything else is ignored, correctly. */
	const HANDLED = [
		'payment.created',
		'payment.updated',
		'refund.created',
		'refund.updated',
		'inventory.count.updated',
	];

	/**
	 * Verify Square's signature.
	 *
	 * @param string $signature_key
	 * @param string $raw_body
	 * @param array  $headers  Lowercased.
	 * @param string $url      The notification URL as registered with Square.
	 *
	 * @return array{ok:bool,code:string}
	 */
	public static function verify( $signature_key, $raw_body, array $headers, $url ) {
		$provided = isset( $headers[ self::SIGNATURE_HEADER ] ) ? (string) $headers[ self::SIGNATURE_HEADER ] : '';

		if ( '' === $provided ) {
			return [
				'ok'   => false,
				'code' => 'missing_square_signature',
			];
		}

		if ( '' === (string) $signature_key ) {
			return [
				'ok'   => false,
				'code' => 'no_signature_key',
			];
		}

		$expected = base64_encode( hash_hmac( 'sha256', $url . $raw_body, $signature_key, true ) );

		if ( ! hash_equals( $expected, $provided ) ) {
			return [
				'ok'   => false,
				'code' => 'square_signature_mismatch',
			];
		}

		return [
			'ok'   => true,
			'code' => '',
		];
	}

	/**
	 * Turn a Square webhook body into normalized ledger events.
	 *
	 * @param array                  $connection
	 * @param array                  $payload
	 * @param FW_POS_Square_API|null $api Injected so tests can drive it offline.
	 *
	 * @return array[]
	 */
	public static function normalize( array $connection, array $payload, $api = null ) {
		$type = isset( $payload['type'] ) ? (string) $payload['type'] : '';

		if ( ! in_array( $type, self::HANDLED, true ) ) {
			// Not an error. Square sends a great many event types and ignoring
			// the ones we did not subscribe to is the correct behaviour.
			return [];
		}

		$api = $api ? $api : new FW_POS_Square_API( $connection );

		switch ( $type ) {
			case 'payment.created':
			case 'payment.updated':
				return self::normalize_payment( $connection, $payload, $api );

			case 'refund.created':
			case 'refund.updated':
				return self::normalize_refund( $connection, $payload, $api );

			case 'inventory.count.updated':
				return self::normalize_inventory( $connection, $payload );
		}

		return [];
	}

	/**
	 * @param array             $connection
	 * @param array             $payload
	 * @param FW_POS_Square_API $api
	 *
	 * @return array[]
	 */
	private static function normalize_payment( array $connection, array $payload, $api ) {
		$payment = isset( $payload['data']['object']['payment'] ) ? $payload['data']['object']['payment'] : [];

		if ( empty( $payment['id'] ) ) {
			return [];
		}

		// A declined or pending card must not move stock. Only a completed
		// payment is a sale.
		$status = isset( $payment['status'] ) ? strtoupper( (string) $payment['status'] ) : '';

		if ( 'COMPLETED' !== $status ) {
			return [];
		}

		$lines = self::line_items_for_order(
			$api,
			isset( $payment['order_id'] ) ? (string) $payment['order_id'] : ''
		);

		if ( empty( $lines ) ) {
			// A payment with no itemised order — a quick cash amount, or an
			// order we could not fetch. Recording a sale of nothing would move
			// no stock while looking like a success, so it is skipped visibly.
			return [];
		}

		return [
			[
				'connection_id' => (int) $connection['id'],
				'external_id'   => 'sq-pay-' . $payment['id'],
				'type'          => FW_POS_Ledger::TYPE_SALE,
				'occurred_at'   => isset( $payment['created_at'] ) ? $payment['created_at'] : '',
				'location_ref'  => isset( $payment['location_id'] ) ? (string) $payment['location_id'] : '',
				'payload'       => [
					'external_id'  => 'sq-pay-' . $payment['id'],
					'occurred_at'  => isset( $payment['created_at'] ) ? $payment['created_at'] : gmdate( 'Y-m-d\TH:i:s\Z' ),
					'location_ref' => isset( $payment['location_id'] ) ? (string) $payment['location_id'] : '',
					'currency'     => isset( $payment['amount_money']['currency'] ) ? $payment['amount_money']['currency'] : 'USD',
					'total'        => isset( $payment['amount_money']['amount'] ) ? (int) $payment['amount_money']['amount'] : 0,
					'line_items'   => $lines,
					'meta'         => [
						'provider' => 'square',
						'order_id' => isset( $payment['order_id'] ) ? $payment['order_id'] : '',
					],
				],
			],
		];
	}

	/**
	 * @param array             $connection
	 * @param array             $payload
	 * @param FW_POS_Square_API $api
	 *
	 * @return array[]
	 */
	private static function normalize_refund( array $connection, array $payload, $api ) {
		$refund = isset( $payload['data']['object']['refund'] ) ? $payload['data']['object']['refund'] : [];

		if ( empty( $refund['id'] ) ) {
			return [];
		}

		$status = isset( $refund['status'] ) ? strtoupper( (string) $refund['status'] ) : '';

		if ( 'COMPLETED' !== $status ) {
			return [];
		}

		$lines = self::line_items_for_order(
			$api,
			isset( $refund['order_id'] ) ? (string) $refund['order_id'] : ''
		);

		return [
			[
				'connection_id' => (int) $connection['id'],
				'external_id'   => 'sq-ref-' . $refund['id'],
				'type'          => FW_POS_Ledger::TYPE_REFUND,
				'occurred_at'   => isset( $refund['created_at'] ) ? $refund['created_at'] : '',
				'location_ref'  => isset( $refund['location_id'] ) ? (string) $refund['location_id'] : '',
				'payload'       => [
					'external_id'      => 'sq-ref-' . $refund['id'],
					'sale_external_id' => ! empty( $refund['payment_id'] ) ? 'sq-pay-' . $refund['payment_id'] : '',
					'occurred_at'      => isset( $refund['created_at'] ) ? $refund['created_at'] : gmdate( 'Y-m-d\TH:i:s\Z' ),
					'location_ref'     => isset( $refund['location_id'] ) ? (string) $refund['location_id'] : '',
					'restock'          => true,
					'partial'          => ! empty( $lines ),
					'line_items'       => $lines,
					'meta'             => [ 'provider' => 'square' ],
				],
			],
		];
	}

	/**
	 * @param array $connection
	 * @param array $payload
	 *
	 * @return array[]
	 */
	private static function normalize_inventory( array $connection, array $payload ) {
		$counts = isset( $payload['data']['object']['inventory_counts'] )
			? (array) $payload['data']['object']['inventory_counts']
			: [];

		$resolved = [];
		$occurred = '';

		foreach ( $counts as $count ) {
			// Square tracks several states — IN_STOCK is the one that means
			// "on the shelf". SOLD, WASTE and IN_TRANSIT are movements between
			// states and would double-count if treated as levels.
			if ( empty( $count['state'] ) || 'IN_STOCK' !== strtoupper( (string) $count['state'] ) ) {
				continue;
			}

			$sku = self::sku_for_variation(
				(int) $connection['id'],
				isset( $count['catalog_object_id'] ) ? (string) $count['catalog_object_id'] : ''
			);

			if ( '' === $sku ) {
				continue;
			}

			$resolved[] = [
				'sku'      => $sku,
				'quantity' => isset( $count['quantity'] ) ? (int) $count['quantity'] : 0,
			];

			if ( '' === $occurred && ! empty( $count['calculated_at'] ) ) {
				$occurred = (string) $count['calculated_at'];
			}
		}

		if ( empty( $resolved ) ) {
			return [];
		}

		$id = 'sq-inv-' . substr( md5( wp_json_encode( $resolved ) . $occurred ), 0, 24 );

		return [
			[
				'connection_id' => (int) $connection['id'],
				'external_id'   => $id,
				'type'          => FW_POS_Ledger::TYPE_INVENTORY,
				'occurred_at'   => $occurred,
				'location_ref'  => '',
				'payload'       => [
					'external_id' => $id,
					'occurred_at' => $occurred ? $occurred : gmdate( 'Y-m-d\TH:i:s\Z' ),
					'mode'        => 'absolute',
					'counts'      => $resolved,
					'meta'        => [ 'provider' => 'square' ],
				],
			],
		];
	}

	/**
	 * Fetch an order and turn its line items into our shape.
	 *
	 * SKUs live on the catalog VARIATION, not on the order line, so each line's
	 * `catalog_object_id` is resolved through the mapping the catalog import
	 * built. A line we cannot resolve is dropped here and will surface as an
	 * unmatched item downstream — which is where it belongs.
	 *
	 * @param FW_POS_Square_API $api
	 * @param string            $order_id
	 *
	 * @return array[]
	 */
	private static function line_items_for_order( $api, $order_id ) {
		if ( '' === $order_id ) {
			return [];
		}

		$result = $api->request( '/v2/orders/' . rawurlencode( $order_id ) );

		if ( ! $result['ok'] || empty( $result['data']['order']['line_items'] ) ) {
			return [];
		}

		$lines = [];

		foreach ( (array) $result['data']['order']['line_items'] as $line ) {
			// Modifiers ("oat milk") are not stock items and would produce
			// phantom unmatched entries for every coffee sold.
			if ( ! empty( $line['item_type'] ) && 'ITEM' !== strtoupper( (string) $line['item_type'] ) ) {
				continue;
			}

			$sku = '';

			if ( ! empty( $line['catalog_object_id'] ) ) {
				$sku = FW_POS_Square_Catalog::sku_for( (string) $line['catalog_object_id'] );
			}

			// A line whose variation we cannot resolve to a SKU keeps its name
			// and no SKU, so the matcher flags the whole event as unmatched
			// rather than this line quietly vanishing from the sale.

			$lines[] = array_filter(
				[
					'sku'        => $sku,
					'name'       => isset( $line['name'] ) ? (string) $line['name'] : '',
					'quantity'   => isset( $line['quantity'] ) ? (int) $line['quantity'] : 1,
					'unit_price' => isset( $line['base_price_money']['amount'] ) ? (int) $line['base_price_money']['amount'] : null,
				],
				function ( $value ) {
					return null !== $value && '' !== $value;
				}
			);
		}

		return $lines;
	}

	/**
	 * @param int    $connection_id
	 * @param string $catalog_object_id
	 *
	 * @return string
	 */
	private static function sku_for_variation( $connection_id, $catalog_object_id ) {
		if ( '' === $catalog_object_id ) {
			return '';
		}

		return FW_POS_Square_Catalog::sku_for( $catalog_object_id );
	}
}
