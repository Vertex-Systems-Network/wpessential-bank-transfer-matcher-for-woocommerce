<?php

namespace WPEssential\BankTransferMatcherPro\Import;

final readonly class DryRunRow {
	/** @param list<string> $warnings */
	public function __construct(
		public int $source_row,
		public ?CanonicalStatementRow $canonical,
		public ?string $error,
		public array $warnings = array(),
	) {}

	public function valid(): bool { return null !== $this->canonical && null === $this->error; }

	/** @param list<string> $warnings */
	public function with_warnings( array $warnings ): self { return new self( $this->source_row, $this->canonical, $this->error, array_values( array_unique( $warnings ) ) ); }
}
