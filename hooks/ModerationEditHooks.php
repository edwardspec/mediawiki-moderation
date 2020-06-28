<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2020 Edward Chernenko.

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
 * Hooks related to normal edits.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Moderation\AddLogEntryConsequence;
use MediaWiki\Moderation\InvalidatePendingTimeCacheConsequence;
use MediaWiki\Moderation\MarkAsMergedConsequence;
use MediaWiki\Moderation\QueueEditConsequence;
use MediaWiki\Moderation\TagRevisionAsMergedConsequence;

class ModerationEditHooks {
	/**
	 * PageContentSave hook handler.
	 * Intercept normal edits and queue them for moderation.
	 * @param WikiPage $page
	 * @param User $user
	 * @param Content $content
	 * @param string|CommentStoreComment $summary
	 * @param int $is_minor
	 * @param mixed $is_watch Unused.
	 * @param mixed $section Unused.
	 * @param int $flags
	 * @param Status $status
	 * @return bool
	 */
	public static function onPageContentSave(
		$page, $user, $content, $summary, $is_minor,
		$is_watch, $section, $flags, $status
	) {
		$title = $page->getTitle();
		$canSkip = MediaWikiServices::getInstance()->getService( 'Moderation.CanSkip' );
		if ( $canSkip->canEditSkip( $user, $title->getNamespace() ) ) {
			return true;
		}

		if ( $summary instanceof CommentStoreComment ) {
			$summary = $summary->text;
		}

		/*
		 * Allow to intercept moderation process
		 */
		if ( !Hooks::run( 'ModerationIntercept', [
			$page, $user, $content, $summary, $is_minor, $is_watch, $section, $flags, $status
		] ) ) {
			return true;
		}

		/* Some extensions (e.g. Extension:Flow) use customized ContentHandlers.
			They need special handling for Moderation to intercept them properly.

			For example, Flow first creates a comments page and then a comment,
			but if edit in the comments page was sent to moderation, Flow will
			report error because this comments page was not found.

			Unless we add support for the non-standard ContentHandler,
			edits to pages with it can't be queued for moderation.

			NOTE: edits to Flow discussions will bypass moderation.
		*/
		$handler = $page->getContentHandler();
		if ( !( $handler instanceof TextContentHandler ) ) {
			return true;
		}

		$editFormOptions = MediaWikiServices::getInstance()->getService( 'Moderation.EditFormOptions' );

		$manager = MediaWikiServices::getInstance()->getService( 'Moderation.ConsequenceManager' );
		$manager->add( new QueueEditConsequence(
			$page, $user, $content, $summary,
			$editFormOptions->getSection(), $editFormOptions->getSectionText(),
			(bool)( $flags & EDIT_FORCE_BOT ),
			(bool)$is_minor
		) );

		/* Watch/Unwatch the page immediately:
			watchlist is the user's own business, no reason to wait for approval of the edit */
		$editFormOptions->watchIfNeeded( $user, [ $title ] );

		/*
			We have queued this edit for moderation.
			No need to save anything at this point.
			Later (if approved) the edit will be saved via doEditContent().

			Here we just redirect the users back to the page they edited
			(as was the behavior for unmoderated edits).
			Notification "Your edit was successfully sent to moderation"
			will be shown by JavaScript.
		*/
		$out = RequestContext::getMain()->getOutput();
		$out->redirect( self::getRedirectURL( $title, $out ) );

		$status->fatal( 'moderation-edit-queued' );
		return false;
	}

	/**
	 * Returns the URL to where the user is redirected after successful edit.
	 * @param Title $title Article that was edited.
	 * @param IContextSource $context Any object that contains current context.
	 * @return string URL
	 */
	protected static function getRedirectURL( Title $title, IContextSource $context ) {
		$query = [ 'modqueued' => 1 ];

		/* Are customized "continue editing" links needed?
			E.g. Special:FormEdit or ?action=formedit from Extension:PageForms. */
		$returnto = null;
		$returntoquery = [];
		Hooks::run( 'ModerationContinueEditingLink', [ &$returnto, &$returntoquery, $title, $context ] );

		// @phan-suppress-next-line PhanImpossibleCondition
		if ( $returnto || $returntoquery ) {
			/* Pack into one parameter to simplify the JavaScript part. */
			$query['returnto'] = FormatJSON::encode( [
				$returnto,
				$returntoquery
			] );
		}

		return $title->getFullURL( $query );
	}

	/**
	 * BeforePageDisplay hook handler.
	 * @param OutputPage &$out
	 * @param Skin &$skin
	 * @return true
	 */
	public static function onBeforePageDisplay( &$out, &$skin ) {
		$canSkip = MediaWikiServices::getInstance()->getService( 'Moderation.CanSkip' );
		$isAutomoderated = $canSkip->canEditSkip(
			$out->getUser(),
			$out->getTitle()->getNamespace()
		);
		if ( !$isAutomoderated ) {
			$out->addModules( [
				'ext.moderation.notify',
				'ext.moderation.notify.desktop'
			] );
			ModerationAjaxHook::add( $out );
		}

		return true;
	}

	/**
	 * PageContentSaveComplete hook handler.
	 * If this is a merged edit, then 'wpMergeID' is the ID of moderation entry.
	 * Here we mark this entry as merged.
	 * @param WikiPage $page
	 * @param User $user
	 * @param Content $content @phan-unused-param
	 * @param string|CommentStoreComment $summary @phan-unused-param
	 * @param bool $is_minor @phan-unused-param
	 * @param mixed $is_watch @phan-unused-param
	 * @param mixed $section @phan-unused-param
	 * @param int $flags @phan-unused-param
	 * @param Revision $revision
	 * @param Status $status @phan-unused-param
	 * @param bool|int $baseRevId @phan-unused-param
	 * @return true
	 */
	public static function onPageContentSaveComplete(
		$page, $user, $content, $summary, $is_minor, $is_watch,
		$section, $flags, $revision, $status, $baseRevId
	) {
		if ( !$revision ) { # Double edit - nothing to do on the second time
			return true;
		}

		/* Only moderators can merge. If someone else adds wpMergeID to the edit form, ignore it */
		if ( !$user->isAllowed( 'moderation' ) ) {
			return true;
		}

		$mergeID = RequestContext::getMain()->getRequest()->getInt( 'wpMergeID' );
		if ( !$mergeID ) {
			return true;
		}

		$revid = $revision->getId();

		$manager = MediaWikiServices::getInstance()->getService( 'Moderation.ConsequenceManager' );
		$somethingChanged = $manager->add( new MarkAsMergedConsequence( $mergeID, $revid ) );

		if ( $somethingChanged ) {
			$manager->add( new AddLogEntryConsequence( 'merge', $user, $page->getTitle(), [
				'modid' => $mergeID,
				'revid' => $revision->getId()
			] ) );

			/* Clear the cache of "Most recent mod_timestamp of pending edit"
				- could have changed */
			$manager->add( new InvalidatePendingTimeCacheConsequence() );

			/* Tag this edit as "manually merged" */
			$manager->add( new TagRevisionAsMergedConsequence( $revid ) );
		}

		return true;
	}

	/**
	 * EditPage::showEditForm:fields hook handler.
	 * Add wpMergeID field to edit form when moderator is doing a manual merge.
	 * @param EditPage $editpage @phan-unused-param
	 * @param OutputPage $out
	 * @return true
	 */
	public static function prepareEditForm( $editpage, $out ) {
		$editFormOptions = MediaWikiServices::getInstance()->getService( 'Moderation.EditFormOptions' );
		$mergeID = $editFormOptions->getMergeID();
		if ( $mergeID ) {
			$out->addHTML( Html::hidden( 'wpMergeID', (string)$mergeID ) );
			$out->addHTML( Html::hidden( 'wpIgnoreBlankSummary', '1' ) );
		}

		return true;
	}

	/**
	 * ListDefinedTags hook handler.
	 * Registers 'moderation-merged' ChangeTag.
	 * @param string[] &$tags
	 * @return true
	 */
	public static function onListDefinedTags( &$tags ) {
		$tags[] = 'moderation-merged';
		return true;
	}
}
