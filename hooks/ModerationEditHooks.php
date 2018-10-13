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
 * Hooks related to normal edits.
 */

class ModerationEditHooks {
	/**
	 * @var int
	 * mod_id of the pending edit which is currently being merged (during modaction=merge)
	 */
	public static $NewMergeID = null;

	/** @var int|string Number of edited section, if any (populated in onEditFilter) */
	protected static $section = '';

	/** @var string Text of edited section, if any (populated in onEditFilter) */
	protected static $sectionText = null;

	/** @var bool|null Checkbox "Watch this page", if found (populated in onEditFilter) */
	protected static $watchthis = null;

	/*
		onEditFilter()
		Save sections-related information, which will then be used in onPageContentSave.
	*/
	public static function onEditFilter( $editor, $text, $section, &$error, $summary ) {
		if ( $section != '' ) {
			self::$section = $section;
			self::$sectionText = $text;
		}

		self::$watchthis = $editor->watchthis;

		return true;
	}

	/*
		onPageContentSave()
		Intercept normal edits and queue them for moderation.
	*/
	public static function onPageContentSave(
		&$page, &$user, &$content, &$summary, $is_minor,
		$is_watch, $section, &$flags, &$status
	) {
		$title = $page->getTitle();
		if ( ModerationCanSkip::canEditSkip( $user, $title->getNamespace() ) ) {
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
		if ( !is_a( $handler, 'TextContentHandler' ) ) {
			return true;
		}

		$change = new ModerationNewChange( $title, $user );
		$change->edit( $page, $content, self::$section, self::$sectionText )
			->setBot( $flags & EDIT_FORCE_BOT )
			->setMinor( $is_minor )
			->setSummary( $summary )
			->queue();

		if ( !is_null( self::$watchthis ) ) {
			/* Watch/Unwatch the page immediately:
				watchlist is the user's own business,
				no reason to wait for approval of the edit */
			$watch = (bool)self::$watchthis;
			WatchAction::doWatchOrUnwatch( $watch, $title, $user );
		}

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
	 */
	protected static function getRedirectURL( Title $title, IContextSource $context ) {
		$query = [ 'modqueued' => 1 ];

		/* Are customized "continue editing" links needed?
			E.g. Special:FormEdit or ?action=formedit from Extension:PageForms. */
		$returnto = null;
		$returntoquery = [];
		Hooks::run( 'ModerationContinueEditingLink', [ &$returnto, &$returntoquery, $title, $context ] );

		if ( $returnto || $returntoquery ) {
			/* Pack into one parameter to simplify the JavaScript part. */
			$query['returnto'] = FormatJSON::encode( [
				$returnto,
				$returntoquery
			] );
		}

		return $title->getFullURL( $query );
	}

	public static function onBeforePageDisplay( &$out, &$skin ) {
		$isAutomoderated = ModerationCanSkip::canEditSkip(
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

	/*
		onPageContentSaveComplete()

		If this is a merged edit, then 'wpMergeID' is the ID of moderation entry.
		Here we mark this entry as merged.
	*/
	public static function onPageContentSaveComplete(
		$page, $user, $content, $summary, $is_minor, $is_watch,
		$section, $flags, $revision, $status, $baseRevId
	) {
		global $wgRequest;

		if ( !$revision ) { # Double edit - nothing to do on the second time
			return true;
		}

		/* Only moderators can merge. If someone else adds wpMergeID to the edit form, ignore it */
		if ( !$user->isAllowed( 'moderation' ) ) {
			return true;
		}

		$mergeID = $wgRequest->getVal( 'wpMergeID' );
		if ( !$mergeID ) {
			return true;
		}

		$revid = $revision->getId();

		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			[
				'mod_merged_revid' => $revid,
				ModerationVersionCheck::setPreloadableToNo()
			],
			[
				'mod_id' => $mergeID,
				'mod_merged_revid' => 0 # No more than one merging
			],
			__METHOD__
		);

		if ( $dbw->affectedRows() ) {
			$logEntry = new ManualLogEntry( 'moderation', 'merge' );
			$logEntry->setPerformer( $user );
			$logEntry->setTarget( $page->getTitle() );
			$logEntry->setParameters( [
				'modid' => $mergeID,
				'revid' => $revision->getId()
			] );
			$logid = $logEntry->insert();
			$logEntry->publish( $logid );

			/* Clear the cache of "Most recent mod_timestamp of pending edit"
				- could have changed */
			ModerationNotifyModerator::invalidatePendingTime();

			/* Tag this edit as "manually merged" */
			DeferredUpdates::addCallableUpdate( function () use ( $revid ) {
				ChangeTags::addTags( 'moderation-merged', null, $revid, null );
			} );
		}

		return true;
	}

	public static function PrepareEditForm( $editpage, $out ) {
		$mergeID = self::$NewMergeID;
		if ( !$mergeID ) {
			$mergeID = $out->getRequest()->getVal( 'wpMergeID' );
		}

		if ( !$mergeID ) {
			return;
		}

		$out->addHTML( Html::hidden( 'wpMergeID', $mergeID ) );
		$out->addHTML( Html::hidden( 'wpIgnoreBlankSummary', '1' ) );

		return true;
	}

	/**
	 * Registers 'moderation-merged' ChangeTag.
	 */
	public static function onListDefinedTags( &$tags ) {
		$tags[] = 'moderation-merged';
		return true;
	}
}
