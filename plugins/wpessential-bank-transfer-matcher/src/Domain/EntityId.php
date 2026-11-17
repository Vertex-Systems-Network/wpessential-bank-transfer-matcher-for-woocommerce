<?php

namespace WPEssential\BankTransferMatcher\Domain;

final readonly class EntityId {
	public function __construct( public int $value ) {
		if ( 1 > $value ) {
			throw new \InvalidArgumentException( 'Entity ID must be a positive integer.' );
		}
	}
}
