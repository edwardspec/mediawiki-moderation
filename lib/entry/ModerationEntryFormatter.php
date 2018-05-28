<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018 Edward Chernenko.

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
	@brief Formatter for displaying entry on Special:Moderation.
*/

class ModerationEntryFormatter extends ModerationEntry {
	protected $context = null; /**< IContextSource */

	public function getContext() {
		if ( is_null( $this->context ) ) {
			$this->context = RequestContext::getMain();
		}

		return $this->context;
	}

	public function setContext( IContextSource $context ) {
		$this->context = $context;
	}

	/**
		@brief Returns User object of moderator.
	*/
	public function getModerator() {
		return $this->getContext()->getUser();
	}

	/**
		@brief Add all titles needed by getHTML() to $batch.
		This method is for QueryPage::preprocessResults().
		It optimizes Linker::link() calls by detecting all redlinks in one SQL query.
	*/
	public static function addToLinkBatch( $row, LinkBatch $batch ) {
		/* Check the affected article */
		$batch->add( $row->namespace, $row->title );

		/* Check userpages - improves performance of Linker::userLink().
			Not needed for anonymous users,
			because their userLink() points to Special:Contributions.
		*/
		if ( $row->user ) {
			$batch->add( NS_USER, $row->user_text );
		}

		if ( $row->rejected_by_user ) {
			$batch->add( NS_USER, $row->rejected_by_user_text );
		}

		/* Check NewTitle for page moves.
			It will probably be a redlink, but we have to be sure. */
		if ( !empty( $row->page2_title ) ) {
			$batch->add( $row->page2_namespace, $row->page2_title );
		}
	}

	/**
		@brief Returns QueryInfo for $db->select(), as expected by QueryPage::getQueryInfo().
	*/
	public static function getQueryInfo() {
		return [
			'tables' => [ 'moderation', 'moderation_block' ],
			'fields' => self::getFields(),
			'conds' => [],
			'options' => [ 'USE INDEX' => [
				'moderation_block' => 'moderation_block_address'
			] ],
			'join_conds' => [
				'moderation_block' => [
					'LEFT JOIN',
					[ 'mb_address=mod_user_text' ]
				]
			]
		];
	}

	/**
		@brief Get the list of fields needed for selecting $row, as expected by newFromRow().
		@returns array ($fields parameter for $db->select()).
	*/
	public static function getFields() {
		$fields = [
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
			'mb_id AS blocked'
		];

		if ( ModerationVersionCheck::hasModType() ) {
			$fields = array_merge( $fields, [
				'mod_type AS type',
				'mod_page2_namespace AS page2_namespace',
				'mod_page2_title AS page2_title'
			] );
		}

		return $fields;
	}

	/**
		@brief Returns HTML of formatted line for Special:Moderation.
	*/
	public function getHTML() {
		global $wgModerationPreviewLink;

		$row = $this->getRow();

		/* Is this a page move? ("Page A renamed into B") */
		$isMove = ( isset( $row->type ) && $row->type == ModerationNewChange::MOD_TYPE_MOVE );

		$len_change = $row->new_len - $row->old_len;
		if ( $len_change > 0 ) {
			$len_change = '+' . $len_change;
		}

		$class = 'modline';
		$title = Title::makeTitle( $row->namespace, $row->title );

		$line = '';

		if ( !$isMove ) { /* Show/Preview links aren't needed for moves, because they don't change the text */
			$line .= '(' . $this->makeModerationLink( 'show', $row->id );

			if ( $wgModerationPreviewLink ) {
				$line .= ' | ' . $this->makeModerationLink( 'preview', $row->id );
			}

			$line .= ') . . ';
		}

		if ( $row->minor ) {
			$line .= wfMessage( 'minoreditletter' );
		}
		if ( $row->bot ) {
			$line .= wfMessage( 'boteditletter' );
		}
		if ( $row->new ) {
			$line .= wfMessage( 'newpageletter' );
		}
		$line .= ' ';

		$pageLink = Linker::link( $title );
		if ( $isMove ) {
			/* "Page A renamed into B" */
			$page2Title = Title::makeTitle( $row->page2_namespace, $row->page2_title );
			$page2Link = Linker::link( $page2Title );

			$line .= wfMessage( 'moderation-move' )->rawParams(
				$pageLink,
				$page2Link
			)->plain();
		}
		else {
			/* Normal edit (or upload) */
			$line .= $pageLink;
		}

		$line .= ' ';

		$line .= ModerationFormatTimestamp::format( $row->timestamp, $this->getContext() );

		$line .= ' . . ';
		$line .= ' (' . $len_change . ')';
		$line .= ' . . ';
		$line .= Linker::userLink( $row->user, $row->user_text );

		if ( $this->getModerator()->isAllowed( 'moderation-checkuser' ) ) {
			$line .= wfMessage( 'moderation-whois-link', $row->ip )->parse(); # NOTE: no space before is on purpose, this link can be in <sup></sup> tags
		}

		$line .= ' ' . Linker::commentBlock( $row->comment, $title );

		if ( !$row->merged_revid ) {
			$line .= ' [';
			if ( $row->conflict ) {
				$class .= ' modconflict';

				// In order to merge, moderator must also be automoderated
				if ( ModerationCanSkip::canSkip( $this->getModerator(), $row->namespace ) ) {
					$line .= $this->makeModerationLink( 'merge', $row->id );
				} else {
					$line .= wfMessage( 'moderation-no-merge-link-not-automoderated' );
				}
			} else {
				if ( !$row->rejected || $row->timestamp > ModerationApprovableEntry::getEarliestReapprovableTimestamp() ) {
					$line .= $this->makeModerationLink( 'approve', $row->id );
				}

				# Note: you can use "Approve all" on rejected edit,
				# but it will only affect not-yet-rejected edits.
				# To avoid confusion, link "Approve all" is not shown for rejected edits.
				if ( !$row->rejected ) {
					$line .= ' ';
					$line .= $this->makeModerationLink( 'approveall', $row->id );
				}
			}

			if ( !$row->rejected ) {
				$line .= ' . . ';
				$line .= $this->makeModerationLink( 'reject', $row->id );
				$line .= ' ';
				$line .= $this->makeModerationLink( 'rejectall', $row->id );
			}
			$line .= ']';
		} else {
			$rev = Revision::newFromId( $row->merged_revid );

			$line .= ' [' . Linker::link(
				$rev ? $rev->getTitle() : $title,
				wfMessage( 'moderation-merged-link' )->escaped(),
				[ 'title' => wfMessage( 'tooltip-moderation-merged-link' ) ],
				[ 'diff' => $row->merged_revid ],
				[ 'known', 'noclasses' ]
			) . ']';
		}

		$line .= ' . . [';
		$line .= $this->makeModerationLink(
			$row->blocked ? 'unblock' : 'block',
			$row->id
		);
		$line .= ']';

		if ( $row->rejected ) {
			$line .= ' . . ';

			if ( $row->rejected_by_user ) {
				$line .= wfMessage( 'moderation-rejected-by', Linker::userLink( $row->rejected_by_user, $row->rejected_by_user_text ) )->text();
			} elseif ( $row->rejected_auto ) {
				$line .= wfMessage( 'moderation-rejected-auto' );
			}

			if ( $row->rejected_batch ) {
				$line .= ' . . ' . wfMessage( 'moderation-rejected-batch' );
			}
		}

		$html = Xml::tags( 'span', [ 'class' => $class ], $line );

		return $html;
	}

	public static function makeModerationLink( $action, $id ) {
		global $wgUser;

		$params = [ 'modaction' => $action, 'modid' => $id ];
		if ( $action != 'show' && $action != 'preview' ) {
			$params['token'] = $wgUser->getEditToken();
		}

		return Linker::link(
			SpecialPage::getTitleFor( 'Moderation' ),
			wfMessage( 'moderation-' . $action )->escaped(),
			[ 'title' => wfMessage( 'tooltip-moderation-' . $action )->plain() ],
			$params,
			[ 'known', 'noclasses' ]
		);
	}

}
