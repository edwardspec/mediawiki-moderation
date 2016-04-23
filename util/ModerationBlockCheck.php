<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2015 Edward Chernenko.

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
	@file
	@brief Checks if the user is blacklisted.
*/

class ModerationBlockCheck {
	private $modblocked_cache = array();

	public function isModerationBlocked( $username ) {
		# Caching works for the duration of this request only,
		# just to avoid duplicate SQL queries.
		if ( array_key_exists( $username, $this->modblocked_cache ) ) {
			return $this->modblocked_cache[$username];
		}

		$dbw = wfGetDB( DB_MASTER ); # Need actual data
		$row = $dbw->selectRow( 'moderation_block',
			array( 'mb_id' ),
			array( 'mb_address' => $username ),
			__METHOD__
		);
		$result = $row ? true : false;

		$this->modblocked_cache[$username] = $result;
		return $result;
	}
}
