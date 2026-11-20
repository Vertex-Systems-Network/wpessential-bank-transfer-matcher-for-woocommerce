<?php
namespace WPEssential\BankTransferMatcherPro\Notifications;

use InvalidArgumentException;

/**
 * Fixed, plain-text finance digest templates. No sensitive bank statement,
 * customer claim, account, payment reference or order payload interpolation.
 * This class never sends email or changes accounting state.
 */
final class FinanceNotificationTemplate {
	private const LABELS = [
		'exception_digest' => 'Open finance exceptions require review',
		'pending_review_digest' => 'Bank matching reviews are awaiting action',
		'mismatch_digest' => 'Bank statement mismatches require finance review',
		'reminder_digest' => 'Older unresolved bank reviews need attention',
	];

	public static function types(): array {
		return array_keys( self::LABELS );
	}

	/** @return array{subject:string,body:string} */
	public static function render( string $type, string $date, string $workspace_url,
		bool $preview = false ): array {
		$label = self::LABELS[$type] ?? null;
		if ( null === $label || ! preg_match( '/\A\d{4}-\d{2}-\d{2}\z/', $date ) ||
			! preg_match( '~\Ahttps?://[a-z0-9.-]+(?::[0-9]+)?(?:/|$)~i', $workspace_url ) ||
			strpbrk( $workspace_url, "\r\n" ) !== false ||
			strlen( $workspace_url ) > 600 ) {
			throw new InvalidArgumentException( 'Unsupported finance notification context.' );
		}
		$subject = 'Bank Transfer Matcher — ' . $label;
		if ( $preview ) $subject = '[PREVIEW] ' . $subject;
		$body = "Finance workspace notification\n\n" .
			$label . "\n" .
			"Review date (UTC): " . $date . "\n\n" .
			"Sign in with your authorized finance account to review the workspace:\n" .
			$workspace_url . "\n\n" .
			"Imported statement entries and customer claims are not confirmation of cleared funds.\n" .
			"This message does not approve an allocation, mark any order paid, or release a hold.\n" .
			"No bank statement data, customer details or attachments are included.\n";
		return [ 'subject' => $subject, 'body' => $body ];
	}
}
