<?php

/**
 * This is a wrapper for APC's shared memory functions
 *
 * @ingroup Cache
 */
class APCBagOStuff extends BagOStuff {
	public function get( $key ) {
		$val = apc_fetch( $key );

		if ( !is_numeric( $val ) && is_string( $val ) ) {
			$val = unserialize( $val );
		}

		return $val;
	}

	public function set( $key, $value, $exptime = 0 ) {
		if ( !is_numeric( $value ) ) {
			$value = serialize( $value );
		}

		apc_store( $key, $value, $exptime );

		return true;
	}

	public function delete( $key, $time = 0 ) {
		apc_delete( $key );

		return true;
	}

	public function incr( $key, $value = 1 ) {
		return apc_inc( $key, $value, $success );
	}

	public function keys() {
		$info = apc_cache_info( 'user' );
		$list = $info['cache_list'];
		$keys = array();

		foreach ( $list as $entry ) {
			$keys[] = $entry['info'];
		}

		return $keys;
	}
}

