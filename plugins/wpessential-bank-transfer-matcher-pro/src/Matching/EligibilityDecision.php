<?php

namespace WPEssential\BankTransferMatcherPro\Matching;

final readonly class EligibilityDecision {
	/** @param non-empty-list<ExplanationCode> $explanations */
	public function __construct(
		public bool $eligible,
		public array $explanations,
	) {}

	public function primary_code(): ExplanationCode {
		return $this->explanations[0];
	}
}
