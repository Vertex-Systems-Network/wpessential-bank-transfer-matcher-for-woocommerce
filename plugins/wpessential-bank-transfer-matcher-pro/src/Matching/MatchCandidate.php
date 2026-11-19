<?php

namespace WPEssential\BankTransferMatcherPro\Matching;

use InvalidArgumentException;

/** Advisory suggestion only. A candidate can never approve an allocation or mark an order paid. */
final readonly class MatchCandidate {
	/** @param non-empty-list<ExplanationCode> $explanations */
	public function __construct(
		public int $transaction_id,
		public int $order_id,
		public string $status,
		public array $explanations,
	) {
		if ( $transaction_id < 1 || $order_id < 1 ) {
			throw new InvalidArgumentException( 'Candidate identifiers must be positive.' );
		}
		if ( ! in_array( $status, [ 'suggested', 'review_required', 'blocked' ], true ) ) {
			throw new InvalidArgumentException( 'Unsupported candidate status.' );
		}
		if ( [] === $explanations ) {
			throw new InvalidArgumentException( 'A candidate requires at least one explanation code.' );
		}
		foreach ( $explanations as $explanation ) {
			if ( ! $explanation instanceof ExplanationCode ) {
				throw new InvalidArgumentException( 'Candidate explanations must be typed codes.' );
			}
		}
	}

	public static function from_eligibility( EligibilityContext $context, EligibilityDecision $decision ): self {
		return new self(
			$context->transaction_id,
			$context->order_id,
			$decision->eligible ? 'review_required' : 'blocked',
			$decision->explanations,
		);
	}
}
