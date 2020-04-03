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
	protected $watchthis = null;

	/** @var IConsequenceManager */
	protected $consequenceManager;

	/**
	 * @param IConsequenceManager $consequenceManager
	 */
	public function __construct( IConsequenceManager $consequenceManager ) {
		$this->consequenceManager = $consequenceManager;
	}

	/**
	 * @param bool $watch If true, pages should be Watched. If false, they should be Unwatched.
	 */
	public function setWatch( $watch ) {
		$this->watchthis = $watch;
	}

	/**
	 * Watch or Unwatch the pages depending on the last value passed to setWatch().
	 * @param User $user
	 * @param Title[] $titles
	 */
	public function watchIfNeeded( User $user, array $titles ) {
		if ( $this->watchthis === null ) {
			// Neither Watch nor Unwatch were requested.
			return;
		}

		foreach ( $titles as $title ) {
			$this->consequenceManager->add(
				new WatchOrUnwatchConsequence( $this->watchthis, $title, $user )
			);
		}
	}

	/**
	 * Detect "watch this" checkboxes on Special:Movepage and Special:Upload.
	 * @param SpecialPage $special
	 * @param string $subPage @phan-unused-param
	 */
	public static function onSpecialPageBeforeExecute( SpecialPage $special, $subPage ) {
		$watchCheckbox = MediaWikiServices::getInstance()->getService( 'Moderation.WatchCheckbox' );
		$title = $special->getPageTitle();
		$request = $special->getRequest();

		if ( $title->isSpecial( 'Movepage' ) ) {
			$watchCheckbox->setWatch( $request->getCheck( 'wpWatch' ) );
		} elseif ( $title->isSpecial( 'Upload' ) ) {
			$watchCheckbox->setWatch( $request->getBool( 'wpWatchthis' ) );
		}
	}
}
