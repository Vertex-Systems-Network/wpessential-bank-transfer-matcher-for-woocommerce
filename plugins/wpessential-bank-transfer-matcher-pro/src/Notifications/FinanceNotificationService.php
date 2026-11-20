<?php
namespace WPEssential\BankTransferMatcherPro\Notifications;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcher\Database\MigrationRunner;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use wpdb;

/**
 * Opt-in, finance-recipient-only informational notifications.
 * No customer recipients, attachments, raw bank references, paid status,
 * allocations, approved clearance, or payment transitions.
 */
final class FinanceNotificationService {
	public const HOOK = 'wpessential_wbm_pro_daily_finance_digest';
	private const VERSION = 'wpessential_wbm_pro_notification_delivery_v1';
	private const GROUP = 'wpessential-wbm-pro-finance-digest';
	private const MAX_PER_USER_DAY = 2;

	public function __construct( private readonly wpdb $db ) {}

	public static function register(): void {
		add_action( self::HOOK, static function (): void {
			global $wpdb;
			( new self( $wpdb ) )->deliver_due();
		} );
	}

	/** @return array{enabled_types:list<string>,recipient_ids:list<int>} */
	public function settings(): array {
		$stored = get_option( FinanceNotificationPreferences::OPTION, false );
		if ( false === $stored ) return FinanceNotificationPreferences::disabled();
		if ( ! is_array( $stored ) ) {
			throw new RuntimeException( 'Invalid stored finance notification settings.' );
		}
		try {
			return FinanceNotificationPreferences::normalize( $stored );
		} catch ( InvalidArgumentException $error ) {
			throw new RuntimeException( 'Invalid stored finance notification settings.', 0, $error );
		}
	}

	public function save( array $input, int $actor_id, string $nonce ): array {
		$this->authorize( $actor_id, $nonce, 'wbm_pro_save_finance_notifications' );
		$settings = FinanceNotificationPreferences::normalize( $input );
		foreach ( $settings['recipient_ids'] as $user_id ) {
			if ( null === $this->validated_recipient( $user_id ) ) {
				throw new InvalidArgumentException( 'Choose only users with both finance capabilities and a valid email.' );
			}
		}
		if ( $settings['enabled_types'] && ! function_exists( 'as_schedule_recurring_action' ) ) {
			throw new RuntimeException( 'Cannot enable email digests without Action Scheduler.' );
		}
		$previous = $this->settings();
		update_option( FinanceNotificationPreferences::OPTION, $settings, false );
		if ( $this->settings() !== $settings ) {
			throw new RuntimeException( 'Could not persist finance notification preferences.' );
		}
		if ( $settings['enabled_types'] ) {
			// Only explicitly enabled by a privileged finance operator.
			if ( function_exists( 'as_has_scheduled_action' ) &&
				! as_has_scheduled_action( self::HOOK, [], self::GROUP ) ) {
				$id = as_schedule_recurring_action( time() + HOUR_IN_SECONDS,
					DAY_IN_SECONDS, self::HOOK, [], self::GROUP, true );
				if ( ! is_int( $id ) || $id < 1 ) {
					update_option( FinanceNotificationPreferences::OPTION, $previous, false );
					throw new RuntimeException( 'Cannot schedule the explicitly opted-in finance digest.' );
				}
			}
		} elseif ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [], self::GROUP );
		}
		return $settings;
	}

	/** Preview never sends email, even for disabled types. */
	public function preview( string $type, int $actor_id ): array {
		$this->authorize( $actor_id );
		return FinanceNotificationTemplate::render(
			$type, gmdate( 'Y-m-d' ), $this->workspace_url(), true
		);
	}

	/**
	 * Explicit administrator-initiated test. No arbitrary recipient address,
	 * no attachments, and the same daily anti-spam limit as actual digests.
	 */
	public function send_test( string $type, int $actor_id, string $nonce ): array {
		$this->authorize( $actor_id, $nonce, 'wbm_pro_test_finance_notification' );
		if ( ! in_array( $type, FinanceNotificationTemplate::types(), true ) ) {
			throw new InvalidArgumentException( 'Unknown finance notification type.' );
		}
		$preferences = $this->settings();
		if ( ! in_array( $type, $preferences['enabled_types'], true ) ) {
			throw new RuntimeException( 'Enable this digest and save recipients before sending a test.' );
		}
		return $this->deliver( $type, $preferences['recipient_ids'], true );
	}

	/** Safe background job: never sends without persisted opt-in and live work. */
	public function deliver_due(): array {
		$preferences = $this->settings();
		$results = [];
		foreach ( $preferences['enabled_types'] as $type ) {
			if ( ! $this->has_unresolved_work( $type ) ) continue;
			$results[$type] = $this->deliver( $type, $preferences['recipient_ids'], false );
		}
		return $results;
	}

	/** @return array{sent:int,skipped:int,failed:int} */
	private function deliver( string $type, array $recipients, bool $test ): array {
		$counts = [ 'sent' => 0, 'skipped' => 0, 'failed' => 0 ];
		$this->install();
		$template = FinanceNotificationTemplate::render(
			$type, gmdate( 'Y-m-d' ), $this->workspace_url(), $test
		);
		$key = $test ? 'test_' . $type : $type;
		foreach ( $recipients as $id ) {
			$email = $this->validated_recipient( $id );
			if ( null === $email ) {
				++$counts['skipped'];
				continue;
			}
			$lock = 'wbm-digest-' . substr(
				hash( 'sha256', $this->db->prefix . '/' . $id ), 0, 32
			);
			$held = $this->db->get_var( $this->db->prepare(
				'SELECT GET_LOCK(%s, 3)', $lock
			) );
			$this->check_db();
			if ( '1' !== (string) $held ) {
				++$counts['skipped'];
				continue;
			}
			try {
				$table = $this->db->prefix . 'wbm_pro_notification_delivery';
				$recent = $this->db->get_var( $this->db->prepare(
					"SELECT COUNT(*) FROM $table WHERE recipient_id = %d
					 AND created_at > UTC_TIMESTAMP() - INTERVAL 24 HOUR",
					$id
				) );
				$duplicate = $this->db->get_var( $this->db->prepare(
					"SELECT COUNT(*) FROM $table WHERE recipient_id = %d AND event_key = %s
					 AND created_at > UTC_TIMESTAMP() - INTERVAL 24 HOUR",
					$id, $key
				) );
				$this->check_db();
				if ( null === $recent || null === $duplicate ||
					(int) $recent >= self::MAX_PER_USER_DAY || (int) $duplicate > 0 ) {
					++$counts['skipped'];
					continue;
				}
				// Reserve before wp_mail. An uncertain provider response or
				// crash must NOT allow a rapid duplicate finance email.
				$created = $this->db->query( $this->db->prepare(
					"INSERT INTO $table
					 (recipient_id,event_key,event_day,delivery_status,created_at)
					 VALUES (%d,%s,%s,'reserved',UTC_TIMESTAMP())",
					$id, $key, gmdate( 'Y-m-d' )
				) );
				$this->check_db();
				if ( 1 !== $created ) throw new RuntimeException( 'Could not reserve notification.' );
				$delivery_id = (int) $this->db->insert_id;
				$sent = false;
				try {
					$sent = wp_mail( $email, $template['subject'], $template['body'],
						[ 'Content-Type: text/plain; charset=UTF-8' ], [] );
				} catch ( Throwable $failure ) {
					$sent = false;
				}
				$changed = $this->db->query( $this->db->prepare(
					"UPDATE $table SET delivery_status=%s,
					 completed_at=UTC_TIMESTAMP() WHERE id=%d AND delivery_status='reserved'",
					$sent ? 'sent' : 'failed', $delivery_id
				) );
				$this->check_db();
				if ( 1 !== $changed ) {
					// Reservation remains, preventing repeat delivery.
					throw new RuntimeException( 'Mail provider result could not be audited.' );
				}
				++$counts[$sent ? 'sent' : 'failed'];
			} finally {
				$this->db->get_var( $this->db->prepare(
					'SELECT RELEASE_LOCK(%s)', $lock
				) );
				$this->check_db();
			}
		}
		return $counts;
	}

	private function has_unresolved_work( string $type ): bool {
		$tables = match ( $type ) {
			'exception_digest' => [
				[ 'wbm_pro_exceptions', "status='open'" ],
				[ 'wbm_pro_mismatch_cases', "status IN ('open','reviewed')" ],
				[ 'wbm_pro_correction_cases', "status='open'" ],
			],
			'pending_review_digest' => [
				[ 'wbm_pro_review_cases', "status IN ('open','assigned','investigating','awaiting_approval','reopened')" ],
			],
			'mismatch_digest' => [
				[ 'wbm_pro_mismatch_cases', "status IN ('open','reviewed')" ],
			],
			'reminder_digest' => [
				[ 'wbm_pro_review_cases', "status IN ('open','assigned','investigating','awaiting_approval','reopened')
				 AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 48 HOUR)" ],
			],
			default => [],
		};
		foreach ( $tables as [ $suffix, $condition ] ) {
			$table = $this->db->prefix . $suffix;
			$exists = $this->db->get_var( $this->db->prepare(
				'SHOW TABLES LIKE %s', $table
			) );
			$this->check_db();
			if ( $exists !== $table ) continue;
			$has = $this->db->get_var(
				"SELECT EXISTS(SELECT 1 FROM $table WHERE $condition LIMIT 1)"
			);
			$this->check_db();
			if ( '1' === (string) $has ) return true;
		}
		return false;
	}

	private function validated_recipient( int $user_id ): ?string {
		if ( $user_id < 1 || ! user_can( $user_id, FreeCaps::APPROVE ) ||
			! user_can( $user_id, ProCaps::IMPORT ) ) return null;
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_string( $user->user_email ) ||
			! is_email( $user->user_email ) ) return null;
		return $user->user_email;
	}

	private function workspace_url(): string {
		return add_query_arg( 'page', 'wpessential-wbm-pro-review-queue',
			admin_url( 'admin.php' ) );
	}

	private function authorize( int $actor_id, string $nonce = '',
		string $nonce_action = '' ): void {
		if ( $actor_id < 1 || get_current_user_id() !== $actor_id ||
			! current_user_can( FreeCaps::APPROVE ) ||
			! current_user_can( ProCaps::IMPORT ) ||
			( '' !== $nonce_action && ! wp_verify_nonce( $nonce, $nonce_action ) ) ) {
			throw new RuntimeException( 'Finance notifications require a verified actor, nonce and capabilities.' );
		}
	}

	private function install(): void {
		( new MigrationRunner( $this->db, self::VERSION ) )->upgrade( [
			static function ( wpdb $db ): bool {
				$table = $db->prefix . 'wbm_pro_notification_delivery';
				$collation = $db->get_charset_collate();
				return false !== $db->query( "CREATE TABLE IF NOT EXISTS $table (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					recipient_id BIGINT UNSIGNED NOT NULL,
					event_key VARCHAR(40) NOT NULL,
					event_day CHAR(10) NOT NULL,
					delivery_status VARCHAR(12) NOT NULL,
					created_at DATETIME NOT NULL,
					completed_at DATETIME NULL,
					KEY recipient_window (recipient_id, created_at),
					KEY type_window (event_key, created_at)
				) ENGINE=InnoDB $collation" );
			},
		] );
	}

	private function check_db(): void {
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Finance notification audit database unavailable.' );
		}
	}
}
