<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2024 Edward Chernenko.

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
 * Polyfill for TempUserTestTrait (which doesn't exist in MediaWiki 1.39-1.41).
 */

use MediaWiki\Tests\User\TempUser\TempUserTestTrait;

if ( trait_exists( TempUserTestTrait::class ) ) {
	// MediaWiki 1.42+
	// @phan-suppress-next-line PhanRedefineClassAlias
	class_alias( TempUserTestTrait::class, 'ModerationTempUserTestTrait' );
} else {
	// MediaWiki 1.39-1.41.
	// Temporary accounts weren't enabled by default (or supported by Moderation) before 1.43,
	// so we don't need to implement disableAutoCreateTempUser() here.
	trait ModerationTempUserTestTrait {
		public function disableAutoCreateTempUser() {
			$this->markTestSkipped( "Temporary accounts are only supported in MediaWiki 1.43+" );
		}

		abstract public static function markTestSkipped( string $message = '' ): void;
	}
}
