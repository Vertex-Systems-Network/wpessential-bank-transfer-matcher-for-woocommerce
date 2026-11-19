<?php

namespace WPEssential\BankTransferMatcherPro\Matching;

use InvalidArgumentException;

final readonly class HintAssessment {
	public function __construct(
		public MatchCandidate $candidate,
		public int $rank_score,
	) {
		if ( $rank_score < 0 || $rank_score > 3 ) {
			throw new InvalidArgumentException( 'Secondary hint rank must be between zero and three.' );
		}
	}
}
