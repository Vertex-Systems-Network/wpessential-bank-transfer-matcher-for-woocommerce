<?php

namespace WPEssential\BankTransferMatcherPro\Matching;

use InvalidArgumentException;

final readonly class GeneratedCase {
	/** @param non-empty-list<ExplanationCode> $explanations */
	public function __construct(
		public string $type,
		public int $transaction_id,
		public ?int $order_id,
		public string $priority,
		public array $explanations,
	) {
		if ( $transaction_id < 1 || ( null !== $order_id && $order_id < 1 ) ) {
			throw new InvalidArgumentException( 'Generated case identifiers must be positive.' );
		}
		if ( ! in_array( $type, [ 'duplicate_suspect', 'reversal', 'conflict' ], true ) ) {
			throw new InvalidArgumentException( 'Unsupported generated case type.' );
		}
		if ( ! in_array( $priority, [ 'normal', 'high', 'critical' ], true ) || [] === $explanations ) {
			throw new InvalidArgumentException( 'Generated cases require valid priority and explanations.' );
		}
	}
}
