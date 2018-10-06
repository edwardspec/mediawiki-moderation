<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2018 Edward Chernenko.

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
 * Implements [[Special:Moderation]].
 */

class SpecialModeration extends QueryPage {
	public $folder; // Currently selected folder (when viewing the moderation table)
	public $folders_list = [
		'pending' => [ # Not yet moderated
			'mod_rejected' => 0,
			'mod_merged_revid' => 0
		],
		'rejected' => [ # Rejected by the moderator
			'mod_rejected' => 1,
			'mod_rejected_auto' => 0,
			'mod_merged_revid' => 0
		],
		'merged' => [ # Manually merged (after the edit conflict on approval attempt)
			'mod_merged_revid <> 0'
		],
		'spam' => [ # Rejected automatically
			'mod_rejected_auto' => 1
		]
	];
	public $default_folder = 'pending';

	function __construct() {
		parent::__construct( 'Moderation', 'moderation' );
	}

	function getGroupName() {
		return 'spam';
	}

	function isSyndicated() {
		return false;
	}

	public function isCacheable() {
		return false;
	}

	function preprocessResults( $db, $res ) {
		/* Check all pages for whether they exist or not -
			improves performance of Linker::link() in formatResult() */
		$batch = new LinkBatch();
		foreach ( $res as $row ) {
			ModerationEntryFormatter::addToLinkBatch( $row, $batch );
		}
		$batch->execute();

		$res->seek( 0 );
	}

	function linkParameters() {
		return [ 'folder' => $this->folder ];
	}

	function getPageHeader() {
		$folderLinks = [];
		foreach ( array_keys( $this->folders_list ) as $f_name ) {
			$label = $this->msg( 'moderation-folder-' . $f_name )->plain();

			if ( $f_name == $this->folder ) {
				$folderLinks[] = Xml::element( 'strong', [ 'class' => 'selflink' ], $label );
			} else {
				$folderLinks[] = Linker::link(
					$this->getPageTitle(),
					$label,
					[ 'title' => $this->msg( 'tooltip-moderation-folder-' . $f_name )->plain() ],
					[ 'folder' => $f_name ],
					[ 'known', 'noclasses' ]
				);
			}
		}

		return Xml::tags( 'div',
			[ 'class' => 'mw-moderation-folders' ],
			implode( ' | ', $folderLinks )
		);
	}

	function execute( $unused ) {
		global $wgModerationUseAjax;

		if ( !$this->getUser()->isAllowed( 'moderation' ) ) {
			$this->displayRestrictionError();
			return;
		}

		$this->setHeaders();
		$this->outputHeader();
		$out = $this->getOutput();
		$out->preventClickjacking();

		if ( !$this->getRequest()->getVal( 'modaction' ) ) {
			/* Show the list of pending edits */
			$out->addModuleStyles( 'ext.moderation.special.css' );
			$out->addWikiMsg( 'moderation-text' );

			if ( $wgModerationUseAjax ) {
				$out->addModules( 'ext.moderation.special.ajax' );
			}

			/* Close "New changes await moderation" notification until new changes appear */
			ModerationNotifyModerator::setSeen( $this->getUser(), wfTimestampNow() );

			return parent::execute( $unused );
		}

		/* Some action was requested */
		$A = ModerationAction::factory( $this->getContext() );
		if ( $A->requiresEditToken() ) {
			$token = $this->getRequest()->getVal( 'token' );
			if ( !$this->getUser()->matchEditToken( $token ) ) {
				throw new ErrorPageError( 'sessionfailure-title', 'sessionfailure' );
			}
		}

		$result = $A->run();

		$out = $this->getOutput();
		$A->outputResult( $result, $out );
		$out->addReturnTo( SpecialPage::getTitleFor( 'Moderation' ) );
	}

	function getOrderFields() {
		return [ 'mod_timestamp' ];
	}

	function getQueryInfo() {
		$this->folder = $this->getRequest()->getVal( 'folder', $this->default_folder );
		if ( !array_key_exists( $this->folder, $this->folders_list ) ) {
			$this->folder = $this->default_folder;
		}

		$conds = $this->folders_list[$this->folder];
		$index = 'moderation_folder_' . $this->folder;

		return array_merge_recursive(
			ModerationEntryFormatter::getQueryInfo(),
			[
				'conds' => $conds,
				'options' => [ 'USE INDEX' => [
					'moderation' => $index
				] ],
				'fields' => [ 'mod_id AS value' ] // Expected by ApiQueryQueryPage
			]
		);
	}

	function formatResult( $skin, $row ) {
		$formatter = ModerationEntryFormatter::newFromRow( $row );
		$formatter->setContext( $this->getContext() );
		return $formatter->getHTML();
	}
}
