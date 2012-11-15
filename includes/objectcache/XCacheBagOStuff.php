<?php

/**
 * Wrapper for XCache object caching functions; identical interface
 * to the APC wrapper
 *
 * @ingroup Cache
 */
class XCacheBagOStuff extends BagOStuff {
	/**
	 * Get a value from the XCache object cache
	 *
	 * @param $key String: cache key
	 * @return mixed
	 */
	public function get( $key ) {
		$val = xcache_get( $key );

		if ( !is_numeric( $val ) && is_string( $val ) ) {
			$val = unserialize( $val );
		}

		return $val;
	}

	/**
	 * Store a value in the XCache object cache
	 *
	 * @param $key String: cache key
	 * @param $value Mixed: object to store
	 * @param $expire Int: expiration time
	 * @return bool
	 */
	public function set( $key, $value, $expire = 0 ) {
		if ( !is_numeric( $value ) ) {
			$value = serialize( $value );
		}

		xcache_set( $key, $value, $expire );
		return true;
	}

	/**
	 * Remove a value from the XCache object cache
	 *
	 * @param $key String: cache key
	 * @param $time Int: not used in this implementation
	 * @return bool
	 */
	public function delete( $key, $time = 0 ) {
		xcache_unset( $key );
		return true;
	}

	/**
	 * Increase stored value of $key by $value while preserving its TTL (!)
	 */
	public function incr( $key, $value = 1 ) {
		return xcache_inc( $key, $value );
	}

}

