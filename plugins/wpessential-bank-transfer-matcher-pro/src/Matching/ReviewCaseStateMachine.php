<?php

namespace WPEssential\BankTransferMatcherPro\Matching;

use InvalidArgumentException;

final class ReviewCaseStateMachine {
	private const TRANSITIONS = [
		'open' => [ 'assigned', 'dismissed' ],
		'assigned' => [ 'investigating', 'dismissed' ],
		'investigating' => [ 'awaiting_approval', 'resolved', 'dismissed' ],
		'awaiting_approval' => [ 'investigating', 'resolved', 'dismissed' ],
		'resolved' => [ 'reopened' ],
		'dismissed' => [ 'reopened' ],
		'reopened' => [ 'assigned', 'investigating', 'dismissed' ],
	];

	public static function assert_transition( string $from, string $to ): void {
		if ( ! in_array( $to, self::TRANSITIONS[ $from ] ?? [], true ) ) {
			throw new InvalidArgumentException( 'Invalid review case transition.' );
		}
	}

	public static function assert_priority( string $priority ): void {
		if ( ! in_array( $priority, [ 'normal', 'high', 'critical' ], true ) ) {
			throw new InvalidArgumentException( 'Invalid review case priority.' );
		}
	}
}
