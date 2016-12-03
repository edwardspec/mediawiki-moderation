<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2016 Edward Chernenko.

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
	@brief Implements [[Special:Moderation]].
*/

class SpecialModeration extends QueryPage {
	public $folder; // Currently selected folder (when viewing the moderation table)
	public $folders_list = array(
		'pending' => array( # Not yet moderated
			'mod_rejected' => 0,
			'mod_merged_revid' => 0
		),
		'rejected' => array( # Rejected by the moderator
			'mod_rejected' => 1,
			'mod_rejected_auto' => 0,
			'mod_merged_revid' => 0
		),
		'merged' => array( # Manually merged (after the edit conflict on approval attempt)
			'mod_merged_revid <> 0'
		),
		'spam' => array( # Rejected automatically
			'mod_rejected_auto' => 1
		)
	);
	public $default_folder = 'pending';

	public static function makeModerationLink( $action, $id ) {
		global $wgUser;

		$params = array( 'modaction' => $action, 'modid' => $id );
		if ( $action != 'show' && $action != 'preview' ) {
			$params['token'] = $wgUser->getEditToken( $id );
		}

		return Linker::link(
			SpecialPage::getTitleFor( 'Moderation' ),
			wfMessage( 'moderation-' . $action )->escaped(),
			array( 'title' => wfMessage( 'tooltip-moderation-' . $action )->plain() ),
			$params,
			array( 'known', 'noclasses' )
		);
	}
	protected static $earliestTs = false; /**< Cache used by getEarliestReapprovableTimestamp() */

	/**
		@brief Get the oldest timestamp for the rejected edit which would still allow it to be approved.
		@returns String (timestamp in MediaWiki format).
	*/
	public static function getEarliestReapprovableTimestamp() {
		if ( self::$earliestTs === false ) {
			global $wgModerationTimeToOverrideRejection;

			$mw_ts = new MWTimestamp( time() );
			$mw_ts->timestamp->modify( '-' . intval( $wgModerationTimeToOverrideRejection ) . ' seconds' );
			self::$earliestTs = $mw_ts->getTimestamp( TS_MW );
		}

		return self::$earliestTs;
	}

	function __construct() {
		parent::__construct( 'Moderation', 'moderation' );
	}

	function isSyndicated() {
		return false;
	}

	public function isCacheable() {
		return false;
	}

	function linkParameters() {
		return array( 'folder' => $this->folder );
	}

	function getPageHeader() {
		$folderLinks = array();
		foreach ( array_keys( $this->folders_list ) as $f_name ) {
			$msg = wfMessage( 'moderation-folder-' . $f_name );

			if ( $f_name == $this->folder ) {
				$folderLinks[] = Xml::element( 'strong', array( 'class' => 'selflink' ), $msg );
			} else {
				$folderLinks[] = Linker::link(
					$this->getTitle(),
					$msg->escaped(),
					array( 'title' => wfMessage( 'tooltip-moderation-folder-' . $f_name ) ),
					array( 'folder' => $f_name ),
					array( 'known', 'noclasses' )
				);
			}
		}

		return Xml::tags( 'div',
			array( 'class' => 'mw-moderation-folders' ),
			join( ' | ', $folderLinks )
		);
	}

	function execute( $unused ) {
		if ( !$this->getUser()->isAllowed( 'moderation' ) ) {
			$this->displayRestrictionError();
			return;
		}

		$this->setHeaders();
		$this->outputHeader();
		$out = $this->getOutput();
		$out->preventClickjacking();

		$A = ModerationAction::factory( $this->getContext() );
		if($A) {
			// Some action was requested
			$A->run();
		}
		else {
			$out->addModules( 'ext.moderation' );
			$out->addWikiMsg( 'moderation-text' );

			return parent::execute( '' ); # '' suppresses warning in QueryPage.php
		}
	}

	function getOrderFields() {
		return array( 'mod_timestamp' );
	}

	function getQueryInfo() {
		$this->folder = $this->getRequest()->getVal( 'folder', $this->default_folder );
		if ( !array_key_exists( $this->folder, $this->folders_list ) ) {
			$this->folder = $this->default_folder;
		}

		$conds = $this->folders_list[$this->folder];
		$index = 'moderation_folder_' . $this->folder;

		return array(
			'tables' => array( 'moderation', 'moderation_block' ),
			'fields' => array(
				'mod_id AS id',
				'mod_timestamp AS timestamp',
				'mod_user AS user',
				'mod_user_text AS user_text',
				'mod_namespace AS namespace',
				'mod_title AS title',
				'mod_comment AS comment',
				'mod_minor AS minor',
				'mod_bot AS bot',
				'mod_new AS new',
				'mod_ip AS ip',
				'mod_old_len AS old_len',
				'mod_new_len AS new_len',
				'mod_rejected AS rejected',
				'mod_rejected_by_user AS rejected_by_user',
				'mod_rejected_by_user_text AS rejected_by_user_text',
				'mod_rejected_batch AS rejected_batch',
				'mod_rejected_auto AS rejected_auto',
				'mod_conflict AS conflict',
				'mod_merged_revid AS merged_revid',
				'mb_id AS moderation_blocked'
			),
			'conds' => $conds,
			'options' => array( 'USE INDEX' => array(
				'moderation' => $index,
				'moderation_block' => 'moderation_block_address'
			)),
			'join_conds' => array(
				'moderation_block' => array(
					'LEFT JOIN',
					array('mb_address=mod_user_text')
				)
			)
		);
	}

	function formatResult( $skin, $result ) {
		global $wgModerationPreviewLink;

		$len_change = $result->new_len - $result->old_len;
		if ( $len_change > 0 ) {
			$len_change = '+' . $len_change;
		}

		$class = 'modline';
		$title = Title::makeTitle( $result->namespace, $result->title );

		$line = '';
		$line .= '(' . $this->makeModerationLink( 'show', $result->id );

		if ( $wgModerationPreviewLink ) {
			$line .= ' | ' . $this->makeModerationLink( 'preview', $result->id );
		}

		$line .= ') . . ';
		if ( $result->minor ) {
			$line .= wfMessage( 'minoreditletter' );
		}
		if ( $result->bot ) {
			$line .= wfMessage( 'boteditletter' );
		}
		if ( $result->new ) {
			$line .= wfMessage( 'newpageletter' );
		}
		$line .= ' ';
		$line .= Linker::link( $title );
		$line .= ' ';

		$time = $this->getLanguage()->userTime( $result->timestamp, $this->getUser() );
		$date = $this->getLanguage()->userDate( $result->timestamp, $this->getUser() );
		$line .= Xml::tags( 'span', array( 'title' => $date ), $time );

		$line .= ' . . ';
		$line .= ' (' . $len_change . ')';
		$line .= ' . . ';
		$line .= Linker::userLink( $result->user, $result->user_text );

		if ( $this->getUser()->isAllowed( 'moderation-checkuser' ) ) {
			$line .= wfMessage( 'moderation-whois-link', $result->ip )->parse(); # NOTE: no space before is on purpose, this link can be in <sup></sup> tags
		}

		$line .= ' (' . $result->comment . ')';

		if ( !$result->merged_revid ) {
			$line .= ' [';
			if ( $result->conflict ) {
				$class .= ' modconflict';

				if ( ModerationCanSkip::canSkip( $this->getUser() ) ) { // In order to merge, moderator must also be automoderated
					$line .= $this->makeModerationLink( 'merge', $result->id );
				} else {
					$line .= wfMessage( 'moderation-no-merge-link-not-automoderated' );
				}
			} else {
				if ( !$result->rejected || $result->timestamp > self::getEarliestReapprovableTimestamp() ) {
					$line .= $this->makeModerationLink( 'approve', $result->id );
				}

				# Note: you can use "Approve all" on rejected edit,
				# but it will only affect not-yet-rejected edits.
				# To avoid confusion, link "Approve all" is not shown for rejected edits.
				if ( !$result->rejected ) {
					$line .= ' ';
					$line .= $this->makeModerationLink( 'approveall', $result->id );
				}
			}

			if ( !$result->rejected ) {
				$line .= ' . . ';
				$line .= $this->makeModerationLink( 'reject', $result->id );
				$line .= ' ';
				$line .= $this->makeModerationLink( 'rejectall', $result->id );
			}
			$line .= ']';
		} else {
			$rev = Revision::newFromId( $result->merged_revid );

			$line .= ' [' . Linker::link(
				$rev ? $rev->getTitle() : $title,
				wfMessage( 'moderation-merged-link' )->escaped(),
				array( 'title' => wfMessage( 'tooltip-moderation-merged-link' ) ),
				array( 'diff' => $result->merged_revid ),
				array( 'known', 'noclasses' )
			) . ']';
		}

		$line .= ' . . [';
		$line .= $this->makeModerationLink(
			$result->moderation_blocked ? 'unblock' : 'block',
			$result->id
		);
		$line .= ']';

		if ( $result->rejected ) {
			$line .= ' . . ';

			if ( $result->rejected_by_user ) {
				$line .= wfMessage( 'moderation-rejected-by', Linker::userLink( $result->rejected_by_user, $result->rejected_by_user_text ) )->text();
			} elseif ( $result->rejected_auto ) {
				$line .= wfMessage( 'moderation-rejected-auto' );
			}

			if ( $result->rejected_batch ) {
				$line .= ' . . ' . wfMessage( 'moderation-rejected-batch' );
			}
		}

		$html = Xml::tags( 'span', array( 'class' => $class ), $line );

		return $html;
	}
}

class ModerationError extends ErrorPageError {
	public function __construct( $message ) {
		parent::__construct( 'moderation', $message );
	}

	/* Completely override report() from ErrorPageError
		in order to wrap the message in <div id='mw-mod-error'></div> */
	public function report() {
		global $wgOut;

		$msg = ( $this->msg instanceof Message ) ?
			$this->msg : $wgOut->msg( $this->msg );

		$wgOut->prepareErrorPage( $wgOut->msg( $this->title ) );
		$wgOut->addWikiText( '<div id="mw-mod-error" class="error">' .
			$msg->plain() . '</div>' );
		$wgOut->addReturnTo($wgOut->getTitle());
		$wgOut->output();
	}
}
