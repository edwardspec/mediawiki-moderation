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
 * Handles "Watch this" checkboxes when editing, on Special:Movepage, etc.
 */

namespace MediaWiki\Moderation;

use MediaWiki\MediaWikiServices;
use SpecialPage;
use Title;
use User;

class WatchCheckbox {
	/**
	 * @var bool|null
	 * Value of "Watch this page" checkbox, if any.
	 * If true, pages passed to watchIfNeeded() will be Watched, if false, Unwatched.
	 * If null, then neither Watching nor Unwatching is necessary.
	 */
	protected static $watchthis = null;

	/**
	 * @param bool $watch If true, pages should be Watched. If false, they should be Unwatched.
	 */
	public static function setWatch( $watch ) {
		self::$watchthis = $watch;
	}

	/**
	 * Disable Watching/Unwatching in watchIfNeeded() until the next setWatch() call.
	 */
	public static function clear() {
		self::$watchthis = null;
	}

	/**
	 * Watch or Unwatch the pages depending on the last value passed to setWatch().
	 * @param User $user
	 * @param Title[] $titles
	 */
	public static function watchIfNeeded( User $user, array $titles ) {
		if ( self::$watchthis === null ) {
			// Neither Watch nor Unwatch were requested.
			return;
		}

		$manager = MediaWikiServices::getInstance()->getService( 'Moderation.ConsequenceManager' );
		foreach ( $titles as $title ) {
			$manager->add( new WatchOrUnwatchConsequence( self::$watchthis, $title, $user ) );
		}
	}

	/**
	 * Detect "watch this" checkboxes on Special:Movepage and Special:Upload.
	 * @param SpecialPage $special
	 * @param string $subPage
	 */
	public static function onSpecialPageBeforeExecute( SpecialPage $special, $subPage ) {
		$title = $special->getPageTitle();
		$request = $special->getRequest();

		if ( $title->isSpecial( 'Movepage' ) ) {
			self::setWatch( $request->getCheck( 'wpWatch' ) );
		} elseif ( $title->isSpecial( 'Upload' ) ) {
			self::setWatch( $request->getBool( 'wpWatchthis' ) );
		}
	}
}
