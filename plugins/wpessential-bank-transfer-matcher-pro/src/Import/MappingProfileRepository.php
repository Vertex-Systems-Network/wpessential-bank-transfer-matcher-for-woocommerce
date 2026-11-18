<?php

namespace WPEssential\BankTransferMatcherPro\Import;

use InvalidArgumentException;
use RuntimeException;

final class MappingProfileRepository {
	private const OPTION = 'wpessential_wbm_pro_mapping_profiles';

	public function create( array $data ): MappingProfile {
		$data['id'] = wp_generate_uuid4();
		$profile = MappingProfile::from_array( $data );
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) throw new RuntimeException( 'Mapping profile storage is invalid.' );
		$stored[ $profile->id ] = $profile->to_array();
		if ( ! update_option( self::OPTION, $stored, false ) ) {
			$actual = get_option( self::OPTION, array() );
			if ( ! is_array( $actual ) || ! isset( $actual[ $profile->id ] ) ) throw new RuntimeException( 'Could not save mapping profile.' );
		}
		return $profile;
	}

	/** @return list<MappingProfile> */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) throw new RuntimeException( 'Mapping profile storage is invalid.' );
		$profiles = array();
		foreach ( $stored as $data ) {
			if ( ! is_array( $data ) ) throw new RuntimeException( 'A stored mapping profile is invalid.' );
			try { $profiles[] = MappingProfile::from_array( $data ); }
			catch ( InvalidArgumentException $error ) { throw new RuntimeException( 'A stored mapping profile is invalid.', 0, $error ); }
		}
		usort( $profiles, static fn ( MappingProfile $a, MappingProfile $b ): int => strcasecmp( $a->name, $b->name ) );
		return $profiles;
	}
}
