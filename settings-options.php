<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$options = [
	'general_box' => [
		'title'   => __( 'General', 'fw' ),
		'type'    => 'box',
		'options' => [
			'group_general' => [
				'type'    => 'group',
				'options' => [
					'mode'          => [
						'label'   => __( 'Mode', 'fw' ),
						'desc'    => __( 'In test mode every event is received, validated and logged, but no stock is changed. Leave a new install here for a full trading day and compare the log against the till\'s own report before going live.', 'fw' ),
						'type'    => 'radio',
						'value'   => 'test',
						'choices' => [
							'test' => __( 'Test — record everything, change nothing', 'fw' ),
							'live' => __( 'Live — apply events to the store', 'fw' ),
						],
					],
					'store_driver'  => [
						'label'   => __( 'Store', 'fw' ),
						'desc'    => __( 'Which e-commerce plugin owns the stock this syncs to. Only one can be active — two carts writing the same stock from one event stream would fight, and there is no sensible arbitration. Left on automatic, a single installed cart is chosen for you; with several installed you must pick.', 'fw' ),
						'type'    => 'select',
						'value'   => '',
						'choices' => class_exists( 'FW_POS_Stores' ) ? FW_POS_Stores::choices() : [ '' => __( 'Detect automatically', 'fw' ) ],
					],
					'create_orders' => [
						'label' => __( 'Record till sales as store orders', 'fw' ),
						'desc'  => __( 'Off by default. Your POS already reports its own takings, so mirroring every counter sale into the store double-counts revenue across the two systems and buries genuine online orders among walk-ins. Turn this on only if you want one order list for everything. Stock is synced either way.', 'fw' ),
						'type'  => 'switch',
						'value' => false,
					],
					'retention'     => [
						'label' => __( 'Keep events for (days)', 'fw' ),
						'desc'  => __( 'How long to keep the audit log. Applied events older than this are pruned; failed ones are always kept. Set to 0 to keep everything forever.', 'fw' ),
						'type'  => 'text',
						'value' => '90',
					],
					'batch_size'    => [
						'label' => __( 'Events per batch', 'fw' ),
						'desc'  => __( 'How many events one background run processes. Lower it on a small host if the queue times out.', 'fw' ),
						'type'  => 'text',
						'value' => '20',
					],
				],
			],
		],
	],

	'ordering_box' => [
		'title'   => __( 'Ordering &amp; safety', 'fw' ),
		'type'    => 'box',
		'options' => [
			'group_ordering' => [
				'type'    => 'group',
				'options' => [
					'clock_skew'      => [
						'label' => __( 'Clock skew warning (minutes)', 'fw' ),
						'desc'  => __( 'Warn when a till reports an event time this far from server time. Tills drift, and drift in the ordering key is what causes stock to rewind, so it is surfaced rather than silently tolerated.', 'fw' ),
						'type'  => 'text',
						'value' => '2',
					],
					'refuse_stale'    => [
						'label' => __( 'Refuse stale stock counts', 'fw' ),
						'desc'  => __( 'Reject an absolute stock count that is older than the last one already applied for that item. Keep this on: it is what stops a till reconnecting after an outage from overwriting the current count with a morning one.', 'fw' ),
						'type'  => 'switch',
						'value' => true,
					],
				],
			],
		],
	],

	'danger_box' => [
		'title'   => __( 'Data', 'fw' ),
		'type'    => 'box',
		'options' => [
			'group_danger' => [
				'type'    => 'group',
				'options' => [
					'danger_note' => [
						'type'  => 'html',
						'label' => false,
						'desc'  => false,
						'html'  => '<p>' . esc_html__( 'Turning POS Sync off leaves every recorded event in place, so you can deactivate it to test something without losing a shop\'s audit trail. Removing the data is a separate, explicit action.', 'fw' ) . '</p>',
					],
				],
			],
		],
	],
];
