<?php

namespace WPEssential\BankTransferMatcherPro\Import;

final readonly class DryRunResult {
	public int $valid_count;
	public int $error_count;
	public int $warning_count;

	/** @param list<DryRunRow> $rows */
	public function __construct( public array $rows ) {
		$valid = $errors = $warnings = 0;
		foreach ( $rows as $row ) {
			$row->valid() ? ++$valid : ++$errors;
			if ( [] !== $row->warnings ) ++$warnings;
		}
		$this->valid_count = $valid; $this->error_count = $errors; $this->warning_count = $warnings;
	}
}
