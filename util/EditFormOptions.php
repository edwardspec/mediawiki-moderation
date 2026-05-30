<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020-2026 Edward Chernenko.

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
 * Keeps track of things like section= parameter in EditForm, wpMergeID and "Watch this" checkbox.
 */

namespace MediaWiki\Moderation;

use MediaWiki\EditPage\EditPage;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use RequestContext;
use SpecialPage;
use User;
use WebRequest;

class EditFormOptions {
	/**
	 * @var int|null
	 * mod_id of the pending edit which is currently being merged (during modaction=merge)
	 */
	protected $newMergeID = null;

	/** @var int|string Number of edited section, if any (populated in onEditFilter) */
	protected $section = '';

	/** @var string Text of edited section, if any (populated in onEditFilter) */
	protected $sectionText = '';

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
	 * Used in extension.json to obtain this service as HookHandler.
	 * @return EditFormOptions
	 */
	public static function hookHandlerFactory() {
		return MediaWikiServices::getInstance()->getService( 'Moderation.EditFormOptions' );
	}

	/**
	 * EditPage::importFormData hook handler.
	 * Save sections-related information, which will then be used in onMultiContentSave.
	 * @param EditPage $editor
	 * @param WebRequest $request
	 * @return bool|void
	 */
	// phpcs:ignore MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName, MediaWiki.Commenting.FunctionComment.MissingDocumentationPublic
	public function onEditPage__importFormData( $editor, $request ) {
		if ( $editor->section !== '' ) {
			$this->section = $editor->section;
			$this->sectionText = $editor->textbox1;
		}

		$this->watchthis = $request->getCheck( 'wpWatchthis' );
	}

	/**
	 * Detect "watch this" checkboxes on Special:Movepage and Special:Upload.
	 * @param SpecialPage $special
	 * @param string $subPage @phan-unused-param
	 * @return bool|void
	 */
	public function onSpecialPageBeforeExecute( SpecialPage $special, $subPage ) {
		$title = $special->getPageTitle();
		$request = $special->getRequest();

		if ( $title->isSpecial( 'Movepage' ) ) {
			$this->watchthis = $request->getCheck( 'wpWatch' );
		} elseif ( $title->isSpecial( 'Upload' ) ) {
			$this->watchthis = $request->getBool( 'wpWatchthis' );
		}
	}

	/**
	 * @param int $modid
	 */
	public function setMergeID( $modid ) {
		$this->newMergeID = $modid;
	}

	/**
	 * @return int
	 */
	public function getMergeID() {
		if ( $this->newMergeID ) {
			return $this->newMergeID;
		}

		return RequestContext::getMain()->getRequest()->getInt( 'wpMergeID', 0 );
	}

	/**
	 * @return string
	 */
	public function getSection() {
		return $this->section;
	}

	/**
	 * @return string
	 */
	public function getSectionText() {
		return $this->sectionText;
	}

	/**
	 * Watch or Unwatch the pages depending on the current value of $watchthis.
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
}
