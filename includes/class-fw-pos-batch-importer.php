<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CSV batch importer — for tills with no network integration at all.
 *
 * Plenty of shops run a register that cannot call a webhook but can export a
 * day's takings to a file. This turns that file into ordinary ledger events, so
 * such a shop gets idempotency, event-time ordering, matching and the audit log
 * exactly like a live integration — just at the granularity of a daily export
 * rather than a sale.
 *
 * ## Everything goes through the ledger
 *
 * Nothing here writes stock. Each row becomes an event, and the queue applies
 * it. That is what makes re-importing the same file harmless: the external id
 * is the idempotency key, so the second import records duplicates and changes
 * nothing. A merchant who is not sure whether yesterday's file went through can
 * simply import it again, which is exactly the reassurance an ops tool should
 * offer.
 *
 * ## The external id must come from the file
 *
 * If the CSV has no transaction id, one is derived from the row's own content
 * (date + SKU + quantity + line number). That is deterministic, so a re-import
 * still de-duplicates — but it also means **editing a file and re-importing it
 * creates new events for the edited rows**, which is the honest behaviour and is
 * said plainly in the UI rather than discovered.
 */
class FW_POS_Batch_Importer {

	/** Columns we understand. Everything else in the file is ignored. */
	const COLUMNS = [
		'external_id',
		'occurred_at',
		'sku',
		'gtin',
		'name',
		'quantity',
		'unit_price',
		'type',
		'location_ref',
	];

	/**
	 * Parse and record a CSV.
	 *
	 * @param string $csv           Raw file contents.
	 * @param int    $connection_id
	 * @param bool   $dry_run       Parse and report, record nothing.
	 *
	 * @return array{ok:bool,rows:int,recorded:int,duplicates:int,errors:string[],preview:array[]}
	 */
	public static function import( $csv, $connection_id, $dry_run = false ) {
		$result = [
			'ok'         => true,
			'rows'       => 0,
			'recorded'   => 0,
			'duplicates' => 0,
			'errors'     => [],
			'preview'    => [],
		];

		$rows = self::parse( $csv, $result['errors'] );

		if ( empty( $rows ) ) {
			$result['ok'] = false;

			if ( empty( $result['errors'] ) ) {
				$result['errors'][] = __( 'No usable rows found. The first line must be a header naming at least a SKU and a quantity column.', 'fw' );
			}

			return $result;
		}

		// Group by transaction, so a multi-line sale in the file becomes ONE
		// event with several line items rather than several single-line sales.
		// Getting this wrong would make every refund a partial refund.
		$grouped = [];

		foreach ( $rows as $index => $row ) {
			$result['rows']++;

			$error = self::validate( $row, $index );

			if ( $error ) {
				$result['errors'][] = $error;

				continue;
			}

			$id = '' !== $row['external_id']
				? $row['external_id']
				: self::derive_id( $row, $index );

			if ( ! isset( $grouped[ $id ] ) ) {
				$grouped[ $id ] = [
					'external_id'  => $id,
					'occurred_at'  => $row['occurred_at'],
					'type'         => $row['type'],
					'location_ref' => $row['location_ref'],
					'lines'        => [],
				];
			}

			$grouped[ $id ]['lines'][] = array_filter(
				[
					'sku'        => $row['sku'],
					'gtin'       => $row['gtin'],
					'name'       => $row['name'],
					'quantity'   => (int) $row['quantity'],
					'unit_price' => '' === $row['unit_price'] ? null : (int) $row['unit_price'],
				],
				function ( $value ) {
					return null !== $value && '' !== $value;
				}
			);
		}

		foreach ( $grouped as $transaction ) {
			$result['preview'][] = [
				'external_id' => $transaction['external_id'],
				'type'        => $transaction['type'],
				'occurred_at' => $transaction['occurred_at'],
				'lines'       => count( $transaction['lines'] ),
			];

			if ( $dry_run ) {
				continue;
			}

			$recorded = FW_POS_Ledger::record_event(
				[
					'connection_id' => (int) $connection_id,
					'external_id'   => $transaction['external_id'],
					'type'          => $transaction['type'],
					'occurred_at'   => $transaction['occurred_at'],
					'location_ref'  => $transaction['location_ref'],
					'payload'       => [
						'external_id'  => $transaction['external_id'],
						'occurred_at'  => $transaction['occurred_at'],
						'location_ref' => $transaction['location_ref'],
						'line_items'   => $transaction['lines'],
						'meta'         => [ 'source' => 'csv-import' ],
					],
				]
			);

			if ( ! $recorded['ok'] ) {
				$result['errors'][] = sprintf(
					/* translators: %s: transaction id */
					__( 'Could not record %s.', 'fw' ),
					$transaction['external_id']
				);

				continue;
			}

			if ( $recorded['duplicate'] ) {
				$result['duplicates']++;
			} else {
				$result['recorded']++;
			}
		}

		if ( $result['recorded'] > 0 ) {
			FW_POS_Queue::schedule();
		}

		return $result;
	}

	/* ---------------------------------------------------------------------- *
	 * Parsing
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string   $csv
	 * @param string[] $errors
	 *
	 * @return array[]
	 */
	private static function parse( $csv, array &$errors ) {
		$csv = trim( (string) $csv );

		if ( '' === $csv ) {
			return [];
		}

		// Strip a UTF-8 BOM. Excel writes one, and it silently corrupts the
		// first header name — which then looks like a missing SKU column.
		$csv = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );

		$handle = fopen( 'php://temp', 'r+' );

		if ( ! $handle ) {
			$errors[] = __( 'Could not read the file.', 'fw' );

			return [];
		}

		fwrite( $handle, $csv );
		rewind( $handle );

		$header = fgetcsv( $handle );

		if ( ! $header ) {
			fclose( $handle );

			return [];
		}

		$map = self::map_header( $header );

		if ( ! isset( $map['sku'] ) && ! isset( $map['gtin'] ) ) {
			$errors[] = __( 'The file needs a "sku" or "gtin" column — there is nothing to match a product on without one.', 'fw' );
			fclose( $handle );

			return [];
		}

		if ( ! isset( $map['quantity'] ) ) {
			$errors[] = __( 'The file needs a "quantity" column.', 'fw' );
			fclose( $handle );

			return [];
		}

		$rows = [];

		while ( false !== ( $line = fgetcsv( $handle ) ) ) {
			if ( ! array_filter( $line, 'strlen' ) ) {
				continue; // Blank line.
			}

			$row = [];

			foreach ( self::COLUMNS as $column ) {
				$row[ $column ] = isset( $map[ $column ], $line[ $map[ $column ] ] )
					? trim( (string) $line[ $map[ $column ] ] )
					: '';
			}

			$rows[] = $row;
		}

		fclose( $handle );

		return $rows;
	}

	/**
	 * Match header names loosely — a shop's export will say "SKU", "Sku Code"
	 * or "product_sku", and rejecting the file over a space would be a poor
	 * reason to fail.
	 *
	 * @param array $header
	 *
	 * @return array<string,int>
	 */
	private static function map_header( array $header ) {
		$aliases = [
			'external_id'  => [ 'external_id', 'externalid', 'transaction', 'transactionid', 'transaction_id', 'id', 'receipt', 'receiptno' ],
			'occurred_at'  => [ 'occurred_at', 'occurredat', 'date', 'datetime', 'timestamp', 'time', 'soldat' ],
			'sku'          => [ 'sku', 'skucode', 'productsku', 'itemsku', 'code' ],
			'gtin'         => [ 'gtin', 'barcode', 'ean', 'upc' ],
			'name'         => [ 'name', 'item', 'product', 'description', 'itemname' ],
			'quantity'     => [ 'quantity', 'qty', 'units', 'count' ],
			'unit_price'   => [ 'unit_price', 'unitprice', 'price', 'amount' ],
			'type'         => [ 'type', 'kind', 'transactiontype' ],
			'location_ref' => [ 'location_ref', 'location', 'store', 'till', 'register' ],
		];

		$map = [];

		foreach ( $header as $index => $label ) {
			$normalised = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $label ) );

			foreach ( $aliases as $column => $names ) {
				if ( isset( $map[ $column ] ) ) {
					continue;
				}

				foreach ( $names as $name ) {
					if ( $normalised === preg_replace( '/[^a-z0-9]/', '', $name ) ) {
						$map[ $column ] = $index;

						break 2;
					}
				}
			}
		}

		return $map;
	}

	/**
	 * @param array $row
	 * @param int   $index
	 *
	 * @return string Empty when valid.
	 */
	private static function validate( array &$row, $index ) {
		$line = $index + 2; // Header is line 1.

		if ( '' === $row['sku'] && '' === $row['gtin'] ) {
			return sprintf(
				/* translators: %d: line number */
				__( 'Line %d has no SKU or barcode.', 'fw' ),
				$line
			);
		}

		if ( '' === $row['quantity'] || ! is_numeric( $row['quantity'] ) ) {
			return sprintf(
				/* translators: %d: line number */
				__( 'Line %d has no usable quantity.', 'fw' ),
				$line
			);
		}

		$row['type'] = in_array( strtolower( $row['type'] ), [ 'refund', 'return' ], true )
			? FW_POS_Ledger::TYPE_REFUND
			: FW_POS_Ledger::TYPE_SALE;

		// A negative quantity in a sales export almost always means a return.
		// Honouring that is more useful than rejecting the row, but it must be
		// turned into a proper refund rather than a negative sale.
		if ( (int) $row['quantity'] < 0 ) {
			$row['type']     = FW_POS_Ledger::TYPE_REFUND;
			$row['quantity'] = abs( (int) $row['quantity'] );
		}

		if ( '' === $row['occurred_at'] ) {
			return sprintf(
				/* translators: %d: line number */
				__( 'Line %d has no date. Without one there is no way to order it against other events, and an event with no order can rewind stock.', 'fw' ),
				$line
			);
		}

		$timestamp = strtotime( $row['occurred_at'] );

		if ( false === $timestamp ) {
			return sprintf(
				/* translators: 1: line number, 2: the value */
				__( 'Line %1$d has an unreadable date (%2$s).', 'fw' ),
				$line,
				$row['occurred_at']
			);
		}

		$row['occurred_at'] = gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );

		return '';
	}

	/**
	 * A deterministic id for a row that carries none.
	 *
	 * Deterministic so a re-import of the same file de-duplicates. Content-based
	 * so an edited row is correctly treated as a different event — which is the
	 * honest reading of "this line changed".
	 *
	 * @param array $row
	 * @param int   $index
	 *
	 * @return string
	 */
	private static function derive_id( array $row, $index ) {
		return 'csv-' . substr(
			md5(
				$row['occurred_at'] . '|' . $row['sku'] . '|' . $row['gtin'] . '|'
				. $row['quantity'] . '|' . $row['type'] . '|' . $index
			),
			0,
			24
		);
	}

	/**
	 * An example file, so nobody has to guess the format.
	 *
	 * @return string
	 */
	public static function example() {
		return implode(
			"\n",
			[
				'transaction_id,date,sku,quantity,unit_price,type',
				'R-1001,2026-09-05T14:32:11Z,HOODIE-BLU-M,1,3500,sale',
				'R-1001,2026-09-05T14:32:11Z,SOCKS-GRY,2,625,sale',
				'R-1002,2026-09-05T15:10:00Z,SOCKS-GRY,1,625,refund',
			]
		) . "\n";
	}
}
