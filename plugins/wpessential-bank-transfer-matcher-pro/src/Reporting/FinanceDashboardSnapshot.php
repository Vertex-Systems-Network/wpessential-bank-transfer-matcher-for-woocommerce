<?php
namespace WPEssential\BankTransferMatcherPro\Reporting;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use wpdb;

/**
 * Source-backed, bounded Pro finance dashboard.
 *
 * Each KPI is explicitly a REPORT PAGE count / exact minor-unit total.
 * It is never represented as a full-ledger snapshot, cash flow, bank clearance,
 * payment authorization or multi-currency sum.
 */
final class FinanceDashboardSnapshot {
	public const REPORTS = [
		'outstanding_orders', 'partial_funding', 'unmatched_credits',
		'exception_aging', 'import_batches', 'allocation_audit',
	];
	private const LIMITS = [ 20, 50, 100 ];

	public function __construct( private readonly wpdb $db ) {}

	/**
	 * @return array{definition:array,groups:array,focused:array|null}
	 */
	public function read( array $filters ): array {
		if ( get_current_user_id() < 1 || ! current_user_can( ProCaps::IMPORT ) ||
			! current_user_can( FreeCaps::APPROVE ) ) {
			throw new RuntimeException( 'Source-backed finance dashboard requires both finance capabilities.' );
		}
		if ( array_diff( array_keys( $filters ), [
			'from', 'to', 'currency', 'bank_account_id', 'focus_report',
			'focus_state', 'limit', 'before_id',
		] ) ) {
			throw new InvalidArgumentException( 'Unknown finance dashboard filter.' );
		}
		$report = $filters['focus_report'] ?? 'outstanding_orders';
		if ( ! is_string( $report ) ||
			! in_array( $report, self::REPORTS, true ) ) {
			throw new InvalidArgumentException( 'Unsupported focused finance report.' );
		}
		$state = $filters['focus_state'] ?? 'all';
		$limit = $filters['limit'] ?? 20;
		if ( ! in_array( $limit, self::LIMITS, true ) ) {
			throw new InvalidArgumentException( 'Finance dashboard page limit must be 20, 50 or 100.' );
		}
		$base = [
			'from' => $filters['from'] ?? null,
			'to' => $filters['to'] ?? null,
			'currency' => $filters['currency'] ?? 'ALL',
			'bank_account_id' => $filters['bank_account_id'] ?? 0,
			'limit' => $limit,
		];
		// Validate the focused state and cursor with ReportDefinition before
		// running ANY query. This prevents silently widening a bad filter.
		$focus = ReportDefinition::from_array( array_merge( $base, [
			'report' => $report, 'state' => $state,
			'before_id' => $filters['before_id'] ?? 0,
		] ) );
		$groups = [];
		$repository = new ReportQueryRepository( $this->db );
		$focused = null;
		foreach ( self::REPORTS as $type ) {
			$definition = ''.$type === $report ? $focus :
				ReportDefinition::from_array( array_merge( $base, [ 'report' => $type ] ) );
			try {
				$result = $repository->run( $definition );
				if ( ( $result['fingerprint'] ?? null ) !== $definition->fingerprint() ||
					( $result['definition'] ?? null ) !== $definition->canonical() ||
					( $result['payment_authorized'] ?? null ) !== false ||
					( $result['ledger_mutated'] ?? null ) !== false ||
					! is_array( $result['rows'] ?? null ) ||
					! array_is_list( $result['rows'] ) ||
					count( $result['rows'] ) > $limit ||
					! is_array( $result['page_totals'] ?? null ) ||
					! is_bool( $result['has_more'] ?? null ) ) {
					throw new RuntimeException( 'Unsafe or malformed source-backed dashboard response.' );
				}
				$groups[$type] = [
					'available' => true,
					'row_count' => count( $result['rows'] ),
					'has_more' => $result['has_more'],
					'page_totals' => $result['page_totals'],
					'next_before_id' => $result['next_before_id'],
					'page_scoped' => true,
					'generated_at_utc' => $result['generated_at_utc'],
				];
				if ( $type === $report ) $focused = $result;
			} catch ( InvalidArgumentException|RuntimeException $error ) {
				$groups[$type] = [
					'available' => false, 'row_count' => null,
					'has_more' => null, 'page_totals' => [],
					'next_before_id' => 0, 'page_scoped' => true,
					'generated_at_utc' => null,
				];
			}
		}
		return [
			'definition' => [
				'from' => $focus->from, 'to' => $focus->to,
				'currency' => $focus->currency,
				'bank_account_id' => $focus->bank_account_id,
				'focus_report' => $focus->report,
				'focus_state' => $focus->state,
				'limit' => $focus->limit,
				'before_id' => $focus->before_id,
			],
			'groups' => $groups,
			'focused' => $focused,
		];
	}
}
