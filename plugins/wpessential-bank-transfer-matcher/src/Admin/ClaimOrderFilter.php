<?php

namespace WPEssential\BankTransferMatcher\Admin;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableQuery;
use RuntimeException;
use WP_Query;
use wpdb;

defined( 'ABSPATH' ) || exit;

/** Applies a claim-state predicate before WooCommerce counts and paginates orders. */
final class ClaimOrderFilter {
	private const QUERY_ARG = 'wpessential_wbm_claim_filter';

	private readonly bool $has_schema;

	public function __construct( private readonly wpdb $db, private readonly string $state ) {
		// Claim migrations write the schema version directly while holding a database lock.
		$version = $this->db->get_var( $this->db->prepare(
			"SELECT option_value FROM {$this->db->options} WHERE option_name = %s",
			'wpessential_wbm_claim_schema'
		) );
		if ( '' !== $this->db->last_error || ( null !== $version && ! in_array( (string) $version, [ '1', '2' ], true ) ) ) {
			throw new RuntimeException( 'Could not read the payment claim schema version.' );
		}
		$this->has_schema = null !== $version;
	}

	/** @return object|array */
	public function query( array $args ): object|array {
		$args[ self::QUERY_ARG ] = $this->state;
		$cpt_args = function ( array $wp_args, array $wc_args ): array {
			if ( ( $wc_args[ self::QUERY_ARG ] ?? null ) === $this->state ) {
				$wp_args[ self::QUERY_ARG ] = $this->state;
				// A claim transition does not invalidate the WordPress post query cache.
				$wp_args['cache_results'] = false;
			}
			return $wp_args;
		};
		$cpt_where = function ( string $where, WP_Query $query ): string {
			if ( $query->get( self::QUERY_ARG ) === $this->state ) {
				$where .= ' AND ' . $this->predicate( "`{$this->db->posts}`.ID" );
			}
			return $where;
		};
		$hpos_where = function ( array $clauses, OrdersTableQuery $query, array $wc_args ): array {
			if ( ( $wc_args[ self::QUERY_ARG ] ?? null ) === $this->state ) {
				$table = $query->get_table_name( 'orders' );
				$clauses['where'] .= ' AND ' . $this->predicate( "`$table`.id" );
			}
			return $clauses;
		};

		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $cpt_args, 10, 2 );
		add_filter( 'posts_where', $cpt_where, 10, 2 );
		add_filter( 'woocommerce_orders_table_query_clauses', $hpos_where, 10, 3 );
		try {
			return wc_get_orders( $args );
		} finally {
			remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $cpt_args, 10 );
			remove_filter( 'posts_where', $cpt_where, 10 );
			remove_filter( 'woocommerce_orders_table_query_clauses', $hpos_where, 10 );
		}
	}

	public function matches( int $order_id ): bool {
		$predicate = $this->predicate( (string) $order_id );
		$matches = $this->db->get_var( "SELECT $predicate" );
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Could not read the order claim status.' );
		}
		return '1' === (string) $matches;
	}

	/** The order expression comes only from a WooCommerce table identifier or integer order ID. */
	private function predicate( string $order_id ): string {
		if ( ! $this->has_schema ) {
			return 'none' === $this->state ? '1=1' : '1=0';
		}
		$table = $this->db->prefix . 'wbm_payment_claims';
		$sql = "SELECT 1 FROM `$table` AS wbm_claim WHERE wbm_claim.order_id = $order_id";
		if ( 'none' === $this->state ) {
			return "NOT EXISTS ($sql)";
		}
		return 'EXISTS (' . $this->db->prepare( "$sql AND wbm_claim.status = %s", $this->state ) . ')';
	}
}
