<?php
namespace WPEssential\BankTransferMatcherPro\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Immutable canonical finance report filter. It is NOT an authorization
 * decision, a stored snapshot, or a payment/credit operation.
 */
final class ReportDefinition {
	/** Each report explicitly declares its supported state vocabulary. */
	private const STATES = [
		'outstanding_orders' => [ 'all', 'pending', 'on-hold' ],
		'unmatched_credits' => [ 'all', 'unmatched', 'ambiguous', 'reviewed' ],
		'partial_funding' => [ 'all', 'pending', 'on-hold' ],
		'exception_aging' => [ 'all', 'open', 'reviewed' ],
		'import_batches' => [ 'all', 'pending', 'processing', 'completed', 'failed', 'cancelled' ],
		'allocation_audit' => [ 'all', 'proposed', 'approved', 'committed', 'reversed' ],
	];
	private const VERSION = 1;

	private function __construct(
		public readonly string $report,
		public readonly string $from,
		public readonly string $to,
		public readonly string $currency,
		public readonly int $bank_account_id,
		public readonly string $state,
		public readonly int $limit,
		public readonly int $before_id
	) {}

	/** Reject unknown keys rather than silently widening a report query. */
	public static function from_array( array $input ): self {
		$allowed = [ 'report', 'from', 'to', 'currency', 'bank_account_id',
			'state', 'limit', 'before_id' ];
		if ( array_diff( array_keys( $input ), $allowed ) ) {
			throw new InvalidArgumentException( 'Unknown finance report filter was supplied.' );
		}
		$report = $input['report'] ?? null;
		if ( ! is_string( $report ) || ! array_key_exists( $report, self::STATES ) ) {
			throw new InvalidArgumentException( 'Unsupported financial report.' );
		}
		$start = self::date( $input['from'] ?? null );
		$end = self::date( $input['to'] ?? null );
		if ( $start > $end ) throw new InvalidArgumentException( 'Report date boundaries are reversed.' );
		$utc = new DateTimeZone( 'UTC' );
		$first = DateTimeImmutable::createFromFormat( '!Y-m-d', $start, $utc );
		$last = DateTimeImmutable::createFromFormat( '!Y-m-d', $end, $utc );
		if ( ! $first || ! $last ||
			(int) $first->diff( $last )->format( '%a' ) > 366 ) {
			throw new InvalidArgumentException( 'Financial report date window exceeds 367 inclusive days.' );
		}
		$currency = $input['currency'] ?? 'ALL';
		if ( ! is_string( $currency ) ||
			( 'ALL' !== $currency && 1 !== preg_match( '/\A[A-Z]{3}\z/', $currency ) ) ) {
			throw new InvalidArgumentException( 'Report currency must be exact ISO code or ALL.' );
		}
		$state = $input['state'] ?? 'all';
		if ( ! is_string( $state ) || ! in_array( $state, self::STATES[$report], true ) ) {
			throw new InvalidArgumentException( 'Unsupported financial state filter for this report.' );
		}
		$limit = self::number( $input['limit'] ?? 100 );
		if ( $limit < 1 || $limit > 500 ) {
			throw new InvalidArgumentException( 'Financial report row limit must be 1–500.' );
		}
		return new self(
			$report, $start, $end, $currency,
			self::number( $input['bank_account_id'] ?? 0 ), $state,
			$limit, self::number( $input['before_id'] ?? 0 )
		);
	}

	/**
	 * Stable canonical shape regardless of original array ordering or
	 * explicit vs implicit defaults. Does not embed user identity/permissions.
	 * @return array<string,int|string>
	 */
	public function canonical(): array {
		return [
			'version' => self::VERSION,
			'report' => $this->report, 'from' => $this->from,
			'to' => $this->to, 'currency' => $this->currency,
			'bank_account_id' => $this->bank_account_id,
			'state' => $this->state, 'limit' => $this->limit,
			'before_id' => $this->before_id,
		];
	}

	public function fingerprint(): string {
		$json = json_encode( $this->canonical(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) throw new RuntimeException( 'Cannot fingerprint report filters.' );
		return hash( 'sha256', "wbm-pro-report-def-v1\x1f" . $json );
	}

	/** The report consumer must enforce actor capabilities and its own query. */
	public function is_bank_posting_date(): bool {
		return 'unmatched_credits' === $this->report;
	}

	/** Strict date-only ISO representation; bank posting dates are not UTC timestamps. */
	private static function date( mixed $raw ): string {
		if ( ! is_string( $raw ) || 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $raw ) ) {
			throw new InvalidArgumentException( 'Financial report dates must be YYYY-MM-DD.' );
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, new DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $raw ) {
			throw new InvalidArgumentException( 'Invalid financial report calendar date.' );
		}
		return $raw;
	}

	/** Decode integers from admin GET without floats, negative IDs, or overflow. */
	private static function number( mixed $raw ): int {
		if ( is_int( $raw ) && $raw >= 0 ) return $raw;
		if ( ! is_string( $raw ) ||
			1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $raw ) ||
			strlen( $raw ) > strlen( (string) PHP_INT_MAX ) ||
			( strlen( $raw ) === strlen( (string) PHP_INT_MAX ) &&
				strcmp( $raw, (string) PHP_INT_MAX ) > 0 ) ) {
			throw new InvalidArgumentException( 'Unsafe finance report integer filter.' );
		}
		return (int) $raw;
	}
}
