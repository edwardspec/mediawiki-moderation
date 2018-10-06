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
 * @file
 * Formatter for displaying entry on Special:Moderation.
 */

class ModerationEntryFormatter extends ModerationEntry {
	/** @var IContextSource */
	protected $context = null;

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
	 * Returns User object of moderator.
	 */
	public function getModerator() {
		return $this->getContext()->getUser();
	}

	/**
	 * Same as wfMessage(), but respects local context.
	 * @return Message
	 */
	public function msg() {
		// We still support MediaWiki 1.27, which supports PHP 5.5.9,
		// so we can't use ...$args syntax (appeared in PHP 5.6).
		return call_user_func_array( [ $this->getContext(), 'msg' ], func_get_args() );
	}

	/**
	 * Add all titles needed by getHTML() to $batch.
	 * This method is for QueryPage::preprocessResults().
	 * It optimizes Linker::link() calls by detecting all redlinks in one SQL query.
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
	 * Returns QueryInfo for $db->select(), as expected by QueryPage::getQueryInfo().
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
	 * Get the list of fields needed for selecting $row, as expected by newFromRow().
	 * @return array ($fields parameter for $db->select()).
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

		if ( RequestContext::getMain()->getUser()->isAllowed( 'moderation-checkuser' ) ) {
			$fields[] = 'mod_ip AS ip';
		}

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
	 * Returns HTML of formatted line for Special:Moderation.
	 */
	public function getHTML() {
		global $wgModerationPreviewLink, $wgModerationEnableEditChange;

		$row = $this->getRow();

		$class = 'modline';
		$title = $this->getTitle();

		$line = '';

		// Show/Preview links. Not needed for moves, because they don't change the text.
		if ( !$this->isMove() ) {
			$line .= '(' . $this->makeModerationLink( 'show', $row->id );

			if ( $wgModerationPreviewLink ) {
				$line .= ' | ' . $this->makeModerationLink( 'preview', $row->id );
			}

			if ( $wgModerationEnableEditChange ) {
				$line .= ' | ' . $this->makeModerationLink( 'editchange', $row->id );
			}

			$line .= ') . . ';
		}

		if ( $row->minor ) {
			$line .= $this->msg( 'minoreditletter' )->plain();
		}
		if ( $row->bot ) {
			$line .= $this->msg( 'boteditletter' )->plain();
		}
		if ( $row->new ) {
			$line .= $this->msg( 'newpageletter' )->plain();
		}
		$line .= ' ';

		$pageLink = Linker::link( $title );
		if ( $this->isMove() ) {
			/* "Page A renamed into B" */
			$page2Link = Linker::link( $this->getPage2Title() );

			$line .= $this->msg( 'moderation-move' )->rawParams(
				$pageLink,
				$page2Link
			)->plain();
		} else {
			/* Normal edit (or upload) */
			$line .= $pageLink;
		}

		$line .= ' ';

		$line .= ModerationFormatTimestamp::format( $row->timestamp, $this->getContext() );

		$line .= ' . . ';
		$line .= ChangesList::showCharacterDifference(
			$row->old_len,
			$row->new_len,
			$this->getContext()
		);
		$line .= ' . . ';
		$line .= Linker::userLink( $row->user, $row->user_text );

		$ip = null;
		if ( isset( $row->ip ) ) {
			$ip = $row->ip;
		} elseif ( $row->user == 0 && IP::isValid( $row->user_text ) ) {
			$ip = $row->user_text;
		}

		if ( $ip ) {
			/* Add Whois link to this IP. */
			$url = $this->msg( 'moderation-whois-link-url', $ip )->plain();
			$text = $this->msg( 'moderation-whois-link-text' )->plain();

			$link = Linker::makeExternalLink( $url, $text );
			$line .= Xml::tags( 'sup', [ 'class' => 'whois plainlinks' ], "[$link]" );
		}

		$line .= ' ' . Linker::commentBlock( $row->comment, $title );

		if ( !$row->merged_revid ) {
			$line .= ' [';
			if ( $row->conflict ) {
				$class .= ' modconflict';

				// In order to merge, moderator must also be automoderated
				if ( ModerationCanSkip::canEditSkip( $this->getModerator(), $row->namespace ) ) {
					$line .= $this->makeModerationLink( 'merge', $row->id );
				} else {
					$line .= $this->msg(
						'moderation-no-merge-link-not-automoderated' )->plain();
				}
			} else {
				if ( !$row->rejected || $this->canReapproveRejected() ) {
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
			$line .= ' [' . Linker::link(
				$title,
				$this->msg( 'moderation-merged-link' )->plain(),
				[ 'title' => $this->msg( 'tooltip-moderation-merged-link' )->plain() ],
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
				$line .= $this->msg( 'moderation-rejected-by',
					Linker::userLink( $row->rejected_by_user, $row->rejected_by_user_text ),
					$row->rejected_by_user_text // plain username for {{gender:}} syntax
				)->text();
			} elseif ( $row->rejected_auto ) {
				$line .= $this->msg( 'moderation-rejected-auto' )->plain();
			}

			if ( $row->rejected_batch ) {
				$line .= ' . . ' . $this->msg( 'moderation-rejected-batch' )->plain();
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
			wfMessage( 'moderation-' . $action )->plain(),
			[ 'title' => wfMessage( 'tooltip-moderation-' . $action )->plain() ],
			$params,
			[ 'known', 'noclasses' ]
		);
	}

}
