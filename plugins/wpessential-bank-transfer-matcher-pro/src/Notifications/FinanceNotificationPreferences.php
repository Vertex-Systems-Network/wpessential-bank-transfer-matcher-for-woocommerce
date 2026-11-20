<?php
namespace WPEssential\BankTransferMatcherPro\Notifications;

use InvalidArgumentException;

/** Exact opt-in settings; no arbitrary email addresses or HTML templates. */
final class FinanceNotificationPreferences {
	public const OPTION = 'wpessential_wbm_pro_finance_notification_settings';

	/** @return array{enabled_types:list<string>,recipient_ids:list<int>} */
	public static function normalize( array $input ): array {
		if ( array_diff( array_keys( $input ), [ 'enabled_types', 'recipient_ids' ] ) ) {
			throw new InvalidArgumentException( 'Unknown finance notification setting.' );
		}
		$types = $input['enabled_types'] ?? [];
		$recipients = $input['recipient_ids'] ?? [];
		if ( ! is_array( $types ) || ! array_is_list( $types ) ||
			! is_array( $recipients ) || ! array_is_list( $recipients ) ||
			count( $types ) > 4 || count( $recipients ) > 10 ) {
			throw new InvalidArgumentException( 'Notification list exceeds limits.' );
		}
		$unique_types = [];
		foreach ( $types as $type ) {
			if ( ! is_string( $type ) ||
				! in_array( $type, FinanceNotificationTemplate::types(), true ) ||
				in_array( $type, $unique_types, true ) ) {
				throw new InvalidArgumentException( 'Unsupported or repeated finance notification type.' );
			}
			$unique_types[] = $type;
		}
		$unique_recipients = [];
		foreach ( $recipients as $id ) {
			if ( ( ! is_int( $id ) && ( ! is_string( $id ) ||
				1 !== preg_match( '/\A[1-9][0-9]*\z/', $id ) ) ) ||
				(int) $id < 1 || (string) (int) $id !== (string) $id ||
				in_array( (int) $id, $unique_recipients, true ) ) {
				throw new InvalidArgumentException( 'Invalid or repeated finance recipient ID.' );
			}
			$unique_recipients[] = (int) $id;
		}
		if ( $unique_types && ! $unique_recipients ) {
			throw new InvalidArgumentException( 'Enabled digests require an explicit recipient.' );
		}
		sort( $unique_types, SORT_STRING );
		sort( $unique_recipients, SORT_NUMERIC );
		return [ 'enabled_types' => $unique_types, 'recipient_ids' => $unique_recipients ];
	}

	public static function disabled(): array {
		return [ 'enabled_types' => [], 'recipient_ids' => [] ];
	}
}
