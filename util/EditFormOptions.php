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
 * Keeps track of things like section= parameter in EditForm or wpMergeID field.
 */

namespace MediaWiki\Moderation;

use EditPage;
use MediaWiki\MediaWikiServices;
use RequestContext;

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
	 * EditFilter hook handler.
	 * Save sections-related information, which will then be used in onPageContentSave.
	 * @param EditPage $editor
	 * @param string $text
	 * @param string $section
	 * @param string &$error @phan-unused-param
	 * @param string $summary @phan-unused-param
	 * @return true
	 */
	public static function onEditFilter( EditPage $editor, $text, $section, &$error, $summary ) {
		$editFormOptions = MediaWikiServices::getInstance()->getService( 'Moderation.EditFormOptions' );
		if ( $section != '' ) {
			$editFormOptions->section = $section;
			$editFormOptions->sectionText = $text;
		}

		// TODO: WatchCheckbox should also be a service (we could theoretically place it here,
		// but does watchIfNeeded() really belong here?)
		WatchCheckbox::setWatch( (bool)$editor->watchthis );
		return true;
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
}
