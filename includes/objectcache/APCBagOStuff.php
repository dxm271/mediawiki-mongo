<?php
/**
 * Object caching using PHP's APC accelerator.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Cache
 */

/**
 * This is a wrapper for APC's shared memory functions
 *
 * @ingroup Cache
 */
class APCBagOStuff extends BagOStuff {
	public function get( $key ) {
		$val = apc_fetch( $key );

		if ( is_string( $val ) ) {
			$val = unserialize( $val );
		}

		return $val;
	}

	public function set( $key, $value, $exptime = 0 ) {
		apc_store( $key, serialize( $value ), $exptime );

		return true;
	}

	public function delete( $key, $time = 0 ) {
		apc_delete( $key );

		return true;
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

