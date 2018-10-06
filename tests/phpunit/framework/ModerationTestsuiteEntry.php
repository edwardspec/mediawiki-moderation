<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015-2018 Edward Chernenko.

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
 * Methods to parse/analyze single entry on Special:Moderation.
 */

/**
 * @class ModerationTestsuiteEntry
 * Represents one line on [[Special:Moderation]]
 */
class ModerationTestsuiteEntry {
	public $id = null;
	public $user = null;
	public $title = null;
	public $page2Title = null;
	public $commentHtml = null;

	public $showLink = null;
	public $previewLink = null;
	public $editChangeLink = null;
	public $approveLink = null;
	public $approveAllLink = null;
	public $rejectLink = null;
	public $rejectAllLink = null;
	public $blockLink = null;
	public $unblockLink = null;
	public $mergeLink = null;
	public $mergedDiffLink = null;
	public $ip = null;

	public $rejected_by_user = null;
	public $rejected_batch = false;
	public $rejected_auto = false;

	public $conflict = false;
	public $isMove = false;

	public $minor = false;
	public $bot = false;
	public $new = false;

	/** @var string Time of the timestamp, e.g. '08:30' */
	public $time = null;

	/** @var string Full human-readable timestamp, e.g. '12:20, 22 June 2018' */
	public $datetime = null;

	public $noMergeNotAutomoderated = false;

	/** @var int Difference between old_len and new_len, e.g. -25 or +600 */
	public $charChange = null;

	/** @var bool True if the character change is highlighted (due to being large) */
	public $charChangeBold = false;

	public function __construct( DomElement $span ) {
		if ( strpos( $span->getAttribute( 'class' ), 'modconflict' ) !== false ) {
			$this->conflict = true;
		}

		foreach ( $span->childNodes as $child ) {
			$text = $child->textContent;
			if ( strpos( $text, '(moderation-rejected-auto)' ) !== false ) {
				$this->rejected_auto = true;
			}

			if ( strpos( $text, '(moderation-rejected-batch)' ) !== false ) {
				$this->rejected_batch = true;
			}

			if ( strpos( $text, '(moderation-move: ' ) !== false ) {
				$this->isMove = true;
			}

			if ( strpos( $text, '(minoreditletter)' ) !== false ) {
				$this->minor = true;
			}

			if ( strpos( $text, '(boteditletter)' ) !== false ) {
				$this->bot = true;
			}

			if ( strpos( $text, '(newpageletter)' ) !== false ) {
				$this->new = true;
			}

			if ( strpos( $text, '(moderation-no-merge-link-not-automoderated)' ) !== false ) {
				$this->noMergeNotAutomoderated = true;
			}

			$matches = null;
			if ( preg_match( '/([0-9]{2}:[0-9]{2})[^.]*/', $text, $matches ) ) {
				$this->time = $matches[1];
				$this->datetime = trim( $matches[0] );
			}

			$matches = null;
			if ( preg_match( '/\(rc-change-size: ([\-0-9,]+)\)/', $text, $matches ) ) {
				$this->charChange = str_replace( ',', '', $matches[1] );
				$this->charChangeBold = ( $child->tagName != 'span' );
			}

			if ( !( $child instanceof DOMText ) && $child->getAttribute( 'class' ) == 'comment' ) {
				$this->commentHtml = '';
				foreach ( $child->childNodes as $grandchild ) {
					$this->commentHtml .=
						$grandchild->ownerDocument->saveXML( $grandchild );
				}

				$this->commentHtml = preg_replace(
					[ '/^\(parentheses: /', '/\)$/' ],
					[],
					$this->commentHtml
				);
			}
		}

		$links = $span->getElementsByTagName( 'a' );
		foreach ( $links as $link ) {
			if ( strpos( $link->getAttribute( 'class' ), 'mw-userlink' ) !== false ) {
				$text = $link->textContent;

				# This is
				# 1) either the user who made an edit,
				# 2) or the moderator who rejected it.
				# Let's check the text BEFORE this link for
				# the presence of 'moderation-rejected-by'.

				if ( strpos( $link->previousSibling->textContent,
					"moderation-rejected-by" ) !== false ) {
					$this->rejected_by_user = $text;
				} else {
					$this->user = $text;
				}

				continue;
			}

			$href = $link->getAttribute( 'href' );
			switch ( $link->nodeValue ) {
				case '(moderation-show)':
					$this->showLink = $href;
					break;

				case '(moderation-preview)':
					$this->previewLink = $href;
					break;

				case '(moderation-editchange)':
					$this->editChangeLink = $href;
					break;

				case '(moderation-approve)':
					$this->approveLink = $href;
					break;

				case '(moderation-approveall)':
					$this->approveAllLink = $href;
					break;

				case '(moderation-reject)':
					$this->rejectLink = $href;
					break;

				case '(moderation-rejectall)':
					$this->rejectAllLink = $href;
					break;

				case '(moderation-merge)':
					$this->mergeLink = $href;
					break;

				case '(moderation-merged-link)':
					$this->mergedDiffLink = $href;

				case '(moderation-block)':
					$this->blockLink = $href;
					break;

				case '(moderation-unblock)':
					$this->unblockLink = $href;
					break;

				case '(moderation-whois-link-text)':
					$matches = null;
					if ( preg_match( '/\(moderation-whois-link-url: ([^)]*)\)/', $href, $matches ) ) {
						$this->ip = $matches[1];
					}
					break;

				default:
					if ( !$this->title ) {
						$this->title = $link->textContent;
					} else {
						$this->page2Title = $link->textContent;
					}
			}
		}

		$matches = null;
		preg_match( '/modid=([0-9]+)/', $this->getAnyLink(), $matches );
		$this->id = $matches[1];
	}

	/**
	 * Get any link, assuming at least one exists.
	 */
	public function getAnyLink() {
		/* Either Block or Unblock link always exists */
		$url = $this->blockLink ? $this->blockLink : $this->unblockLink;
		if ( !$url ) {
			throw new MWException( 'getAnyLink(): no links found' );
		}

		return $url;
	}

	/**
	 * Get URL of the link $modaction.
	 * @param string $modaction Name of modaction (e.g. 'rejectall') or 'mergedDiff'.
	 */
	public function getActionLink( $modaction ) {
		switch ( $modaction ) {
			case 'show':
				return $this->showLink;
			case 'preview':
				return $this->previewLink;
			case 'editchange':
				return $this->editChangeLink;
			case 'approve':
				return $this->approveLink;
			case 'approveall':
				return $this->approveAllLink;
			case 'reject':
				return $this->rejectLink;
			case 'rejectall':
				return $this->rejectAllLink;
			case 'block':
				return $this->blockLink;
			case 'unblock':
				return $this->unblockLink;
			case 'merge':
				return $this->mergeLink;
			case 'mergedDiff':
				return $this->mergedDiffLink;
		}

		throw new Exception( __METHOD__ . ": unknown modaction='$modaction'" );
	}

	public static function findById( array $array, $id ) {
		foreach ( $array as $e ) {
			if ( $e->id == $id )
				return $e;
		}
		return null;
	}

	public static function findByUser( array $array, $user ) {
		if ( get_class( $user ) == 'User' )
			$user = $user->getName();

		$entries = [];
		foreach ( $array as $entry ) {
			if ( $entry->user == $user )
				$entries[] = $entry;
		}
		return $entries;
	}

	/**
	 * Populates both $e->blockLink and $e->unblockLink,
			even though only one link exists on Special:Moderation
	 */
	public function fakeBlockLink() {
		$bl = $this->blockLink;
		$ul = $this->unblockLink;

		if ( $bl && !$ul ) {
			$this->unblockLink = preg_replace( '/modaction=block/', 'modaction=unblock', $bl );
		} elseif ( $ul && !$bl ) {
			$this->blockLink = preg_replace( '/modaction=unblock/', 'modaction=block', $ul );
		}
	}

	/**
	 * Returns the URL of modaction=showimg for this entry.
	 */
	public function expectedShowImgLink() {
		return $this->expectedActionLink( 'showimg', false );
	}

	/**
	 * Returns the URL of modaction=$action for this entry.
	 */
	public function expectedActionLink( $action, $needsToken = true ) {
		/* Either block or unblock link always exists */
		$url = $this->getAnyLink();
		if ( !$needsToken ) {
			$url = preg_replace( '/[&?]token=(.*?)(&|$)/', '', $url );
		}

		return preg_replace( '/modaction=(block|unblock)/', 'modaction=' . $action, $url );
	}

	/**
	 * Fetches this entry from the database and returns $field.
	 * @param string $field Field name, e.g. "mod_len_new".
	 */
	public function getDbField( $field ) {
		$dbw = wfGetDB( DB_MASTER );
		return $dbw->selectField(
			'moderation',
			$field,
			[ 'mod_id' => $this->id ],
			__METHOD__
		);
	}

	/**
	 * Returns mod_text of this entry (loaded from the database).
	 */
	public function getDbText() {
		return $this->getDbField( 'mod_text' );
	}

	/**
	 * Modified this entry in the database.
	 * @param array $updates List of updates, as expected by Database::update
	 */
	public function updateDbRow( array $updates ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			$updates,
			[ 'mod_id' => $this->id ],
			__METHOD__
		);
	}
}
