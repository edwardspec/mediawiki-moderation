<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015 Edward Chernenko.

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
	@brief Methods to parse/analyze single entry on Special:Moderation.
*/

/**
	@class ModerationTestsuiteEntry
	@brief Represents one line on [[Special:Moderation]]
*/
class ModerationTestsuiteEntry
{
	public $id = null;
	public $user = null;
	public $comment = null; /* TODO */
	public $title = null;

	public $showLink = null;
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

	function __construct( DomElement $span )
	{
		if ( strpos( $span->getAttribute( 'class' ), 'modconflict' ) !== false ) {
			$this->conflict = true;
		}

		foreach ( $span->childNodes as $child )
		{
			$text = $child->textContent;
			if ( strpos( $text, '(moderation-rejected-auto)' ) !== false )
				$this->rejected_auto = true;

			if ( strpos( $text, '(moderation-rejected-batch)' ) !== false )
				$this->rejected_batch = true;

			$matches = null;
			if ( preg_match( '/\(moderation-whois-link: ([^)]*)\)/', $text, $matches ) )
			{
				$this->ip = $matches[1];
			}
		}

		$links = $span->getElementsByTagName( 'a' );
		foreach ( $links as $link )
		{
			if ( strpos( $link->getAttribute( 'class' ), 'mw-userlink' ) !== false )
			{
				$text = $link->textContent;

				# This is
				# 1) either the user who made an edit,
				# 2) or the moderator who rejected it.
				# Let's check the text BEFORE this link for
				# the presence of 'moderation-rejected-by'.

				if ( strpos( $link->previousSibling->textContent,
					"moderation-rejected-by" ) !== false )
				{
					$this->rejected_by_user = $text;
				}
				else
				{
					$this->user = $text;
				}

				continue;
			}

			$href = $link->getAttribute( 'href' );
			switch( $link->nodeValue )
			{
				case '(moderation-show)':
					$this->showLink = $href;
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

				default:
					$this->title = $link->textContent;
			}
		}

		$matches = null;
		preg_match( '/modid=([0-9]+)/', $this->showLink, $matches );
		$this->id = $matches[1];
	}

	static public function findById( $array, $id )
	{
		foreach ( $array as $e )
		{
			if ( $e->id == $id )
				return $e;
		}
		return null;
	}

	static public function findByUser( $array, $user )
	{
		if ( get_class( $user ) == 'User' )
			$user = $user->getName();

		$entries = [];
		foreach ( $array as $entry )
		{
			if ( $entry->user == $user )
				$entries[] = $entry;
		}
		return $entries;
	}

	/**
		@brief Populates both $e->blockLink and $e->unblockLink,
			even though only one link exists on Special:Moderation
	*/
	public function fakeBlockLink()
	{
		$bl = $this->blockLink;
		$ul = $this->unblockLink;

		if ( ( $bl && $ul ) || ( !$bl && !$ul ) )
			return; /* Nothing to do */

		if ( $bl )
			$this->unblockLink = preg_replace( '/modaction=block/', 'modaction=unblock', $bl );
		else
			$this->blockLink = preg_replace( '/modaction=unblock/', 'modaction=block', $ul );
	}

	/**
		@brief Returns the URL of modaction=showimg for this entry.
	*/
	public function expectedShowImgLink()
	{
		return $this->expectedActionLink( 'showimg', false );
	}

	/**
		@brief Returns the URL of modaction=$action for this entry.
	*/
	public function expectedActionLink( $action, $need_token = true )
	{
		$sample = null;

		if ( $need_token ) {
			/* Either block or unblock link always exists */
			$sample = $this->blockLink ? $this->blockLink : $this->unblockLink;
		}
		else {
			$sample = $this->showLink; /* Show link always exists */
		}

		if ( !$sample ) {
			return null;
		}

		return preg_replace( '/modaction=(block|unblock|show)/', 'modaction=' . $action, $sample );
	}
}

