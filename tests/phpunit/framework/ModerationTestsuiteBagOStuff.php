<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

/**
 * @file
 * Selectively cleanable BagOStuff. Used for parallel PHPUnit testing.
 *
 * NOTE: this is highly inefficient in terms of SET performance (the entire file is rewritten),
 * but it's OK enough for unit tests (where there aren't enough cache keys to begin with),
 * because it allows us to utilize 2 CPU cores instead of 1 during the Travis tests.
 */

use Cdb\Reader as CdbReader;
use Cdb\Writer as CdbWriter;

if ( !class_exists( 'MediumSpecificBagOStuff' ) ) {
	// For MediaWiki 1.31-1.33.
	class_alias( BagOStuff::class, 'MediumSpecificBagOStuff' );
}

class ModerationTestsuiteBagOStuff extends MediumSpecificBagOStuff {
	/** @var string */
	protected $filename;

	public function __construct( $params = [] ) {
		$this->filename = self::defaultFileName();

		if ( !file_exists( $this->filename ) ) {
			CdbWriter::open( $this->filename )->close();
		}

		parent::__construct( $params );
	}

	/**
	 * Get name of the file where this cache will be stored.
	 * @return string
	 */
	protected static function defaultFileName() {
		$filename = '/dev/shm/modtest.cache';

		// When running multiple PHPUnit tests in parallel via Fastest,
		// this environment variable will be set to 1, 2, 3, ... for different threads.
		$thread = getenv( 'ENV_TEST_CHANNEL' );
		if ( $thread ) {
			$filename .= ".thread$thread";
		}

		return $filename;
	}

	/**
	 * Empty the CDB file. This is used by ModerationTestsuite for cache invalidation.
	 */
	public static function flushAll() {
		CdbWriter::open( self::defaultFileName() )->close();
	}

	protected function doGet( $key, $flags = 0, &$casToken = null ) {
		$casToken = null;

		$reader = CdbReader::open( $this->filename );
		$ret = $reader->get( $key );
		$reader->close();

		return $ret === false ? false : $this->unserialize( $ret );
	}

	protected function doSet( $key, $value, $exptime = 0, $flags = 0 ) {
		$this->modifyAndSave( function ( array &$data ) use ( $key, $value ) {
			$data[$key] = $value;
		} );
		return true;
	}

	// Same as doSet(), but if the key already exist, then do nothing.
	protected function doAdd( $key, $value, $exptime = 0, $flags = 0 ) {
		return $this->modifyAndSave( function ( array &$data ) use ( $key, $value, &$added ) {
			if ( isset( $data[$key] ) ) {
				return false;
			}

			$data[$key] = $value;
			return true;
		} );
	}

	protected function doDelete( $key, $flags = 0 ) {
		$this->modifyAndSave( function ( array &$data ) use ( $key ) {
			unset( $data[$key] );
		} );
		return true;
	}

	public function incr( $key, $value = 1, $flags = 0 ) {
		$this->modifyAndSave( function ( array &$data ) use ( $key, $value ) {
			// Unserializing is not needed, because integer-only values were not serialized.
			if ( !isset( $data[$key] ) ) {
				$data[$key] = 0;
			}
			$data[$key] += $value;
		} );
		return true;
	}

	public function decr( $key, $value = 1, $flags = 0 ) {
		$this->modifyAndSave( function ( array &$data ) use ( $key, $value ) {
			if ( !isset( $data[$key] ) ) {
				$data[$key] = 0;
			}
			$data[$key] -= $value;
		} );
		return true;
	}

	// Backward compatibility methods for MediaWiki 1.31-1.33: delete(), add(), set().
	// Not needed in MediaWiki 1.34+ (MediumSpecificBagOStuff class implements them for us).
	public function delete( $key, $flags = 0 ) {
		return $this->doDelete( $key, $flags );
	}

	public function add( $key, $value, $exptime = 0, $flags = 0 ) {
		return $this->doAdd( $key, $value, $exptime, $flags );
	}

	public function set( $key, $value, $exptime = 0, $flags = 0 ) {
		return $this->doSet( $key, $value, $exptime, $flags );
	}

	// Backward compatibility methods for MediaWiki 1.31-1.33: serialize(), unserialize().
	// These are only used in our modifyAndSave() and doGet().
	// Not needed in MediaWiki 1.34+ (MediumSpecificBagOStuff class implements them for us).
	protected function serialize( $value ) {
		return is_int( $value ) ? $value : serialize( $value );
	}

	protected function unserialize( $value ) {
		return $this->isInteger( $value ) ? (int)$value : unserialize( $value );
	}

	/**
	 * A highly inefficient procedure (see disclaimer comment at the top of this class)
	 * that reads the entire CDB file into memory, calls $modifyCallback( &$keyValueArray ),
	 * and then writes the modified $keyValueArray into the CDB file.
	 * Normally we'd want to use QDBM or something, but should the testsuite require DBA?
	 * @param callable $modifyCallback
	 * @return mixed Return value of $modifyCallback
	 */
	private function modifyAndSave( callable $modifyCallback ) {
		// Obtain all stored data as [ key => value ] array.
		// This is needed because CdbWriter overwrites the file completely (can't append).
		$keyval = [];
		$reader = CdbReader::open( $this->filename );
		for ( $key = $reader->firstkey(); $key !== false; $key = $reader->nextkey() ) {
			$keyval[$key] = $this->unserialize( $reader->get( $key ) );
		}
		$reader->close();

		// Give the callback a chance to add/modify/delete some key/value pairs.
		$callbackResult = call_user_func_array( $modifyCallback, [ &$keyval ] );

		// Write the modified key-value pairs back into the CDB file.
		$writer = CdbWriter::open( $this->filename );
		foreach ( $keyval as $key => $value ) {
			$writer->set( $key, $this->serialize( $value ) );
		}
		$writer->close();

		return $callbackResult;
	}
}
