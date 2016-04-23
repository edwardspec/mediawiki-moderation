<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2015 Edward Chernenko.

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
	@brief Hooks related to normal edits.
*/

class ModerationEditHooks {
	public static $LastInsertId = null;
	public static $NewMergeID = null;

	public static $inApprove = false; /**< Set to true by ModerationActionApprove::prepareApproveHooks() */

	/*
		onPageContentSave()
		Intercept normal edits and queue them for moderation.
	*/
	public static function onPageContentSave( &$page, &$user, &$content, &$summary, $is_minor, $is_watch, $section, &$flags, &$status )
	{
		global $wgOut, $wgContLang, $wgModerationNotificationEnable, $wgModerationNotificationNewOnly,
			   $wgModerationEmail, $wgPasswordSender;

		if ( self::$inApprove ) {
			return true;
		}

		if ( ModerationCanSkip::canSkip( $user ) ) {
			return true;
		}

		/*
		 * Allow to intercept moderation process
		 */
		if ( !Hooks::run( 'ModerationIntercept', array(
			$page, $user, $content, $summary, $is_minor, $is_watch, $section, $flags, $status
		) ) ) {
			return true;
		}

		/* Some extensions (e.g. Extension:Flow) use customized ContentHandlers.
			They need special handling for Moderation to intercept them properly.

			For example, Flow first creates a comments page and then a comment,
			but if edit in the comments page was send to moderation, Flow will
			report error because this comments page was not found.

			Unless we add support for the non-standard ContentHandler,
			edits to pages with it can't be queued for moderation.

			NOTE: edits to Flow discussions will bypass moderation.
		*/
		$handler = $page->getContentHandler();
		if ( !is_a( $handler, 'TextContentHandler' ) ) {
			return true;
		}

		$old_content = $page->getContent( Revision::RAW ); // current revision's content
		$request = $user->getRequest();
		$title = $page->getTitle();

		$popts = ParserOptions::newFromUserAndLang( $user, $wgContLang );

		$dbw = wfGetDB( DB_MASTER );

		$fields = array(
			'mod_timestamp' => $dbw->timestamp( wfTimestampNow() ),
			'mod_user' => $user->getId(),
			'mod_user_text' => $user->getName(),
			'mod_cur_id' => $page->getId(),
			'mod_namespace' => $title->getNamespace(),
			'mod_title' => $title->getText(),
			'mod_comment' => $summary,
			'mod_minor' => $is_minor,
			'mod_bot' => $flags & EDIT_FORCE_BOT,
			'mod_new' => $page->exists() ? 0 : 1,
			'mod_last_oldid' => $page->getLatest(),
			'mod_ip' => $request->getIP(),
			'mod_old_len' => $old_content ? $old_content->getSize() : 0,
			'mod_new_len' => $content->getSize(),
			'mod_header_xff' => $request->getHeader( 'X-Forwarded-For' ),
			'mod_header_ua' => $request->getHeader( 'User-Agent' ),
			'mod_text' => $content->preSaveTransform( $title, $user, $popts )->getNativeData(),
			'mod_preload_id' => ModerationPreload::generatePreloadId(),
			'mod_preloadable' => 1
		);

		$mblockCheck = new ModerationBlockCheck();
		if ( $mblockCheck->isModerationBlocked( $user->getName() ) ) {
			$fields['mod_rejected'] = 1;
			$fields['mod_rejected_by_user'] = 0;
			$fields['mod_rejected_by_user_text'] = wfMessage( 'Moderation block' )->inContentLanguage()->text();
			$fields['mod_rejected_auto'] = 1;
			$fields['mod_preloadable'] = 1; # User can still edit this change, so that spammers won't notice that they are blocked
		}

		// Check if we need to update existing row (if this edit is by the same user to the same page)
		$row = ModerationPreload::loadUnmoderatedEdit( $title );
		if ( !$row ) { # No unmoderated edits
			$dbw->insert( 'moderation', $fields, __METHOD__ );
			ModerationEditHooks::$LastInsertId = $dbw->insertId();
		} else {
			$section = $request->getVal( 'wpSection', $request->getVal( 'section' ) );

			if ( $section ) {
				#
				# We must recalculate $fields['mod_text'] here.
				# Otherwise if the user adds or modifies two (or more) different sections (in consequent edits),
				# then only modification to the last one will be saved,
				# because $content is [old content] PLUS [modified section from the edit].
				#
				# Difference between $index and $section:
				# $index: section number in $content. $index can't be "new". If $section was "new", then we need to recalculate $index.
				# $section: section number in $saved_content. $section can be "new". Used when calling replaceSection().
				#
				$index = $section;
				if ( $section == 'new' ) {
					#
					# Parser doesn't allow to get the LAST section directly.
					# We have to get the entire TOC - just for a single index.
					#
					$sections = $content->getParserOutput( $title, null, null, false )->getSections();
					$new_section_content = end( $sections );
					$index = $new_section_content['index'];
				}

				# $new_section_content is exactly what the user just wrote in the edit form (one section only).
				$new_section_content = $content->getSection( $index );
				$saved_content = ContentHandler::makeContent( $row->text, null, $content->getModel() );
				$new_content = $saved_content->replaceSection( $section, $new_section_content, '' );

				$fields['mod_text'] = $new_content->preSaveTransform( $title, $user, $popts )->getNativeData();
			}

			$dbw->update( 'moderation', $fields, array( 'mod_id' => $row->id ), __METHOD__ );
			ModerationEditHooks::$LastInsertId = $row->id;
		}

		// In case the caller treats "edit-hook-aborted" as an error.
		$dbw->commit();

		// Run hook to allow other extensions be notified about pending changes
		Hooks::run( 'ModerationPending', array(
			$fields, ModerationEditHooks::$LastInsertId
		) );

		// Notify administrator about pending changes
		if ( $wgModerationNotificationEnable ) {
			/*
				$wgModerationNotificationNewOnly:
				if false, notify about all edits,
				if true, notify about new pages.
			*/
			if ( !$wgModerationNotificationNewOnly || !$page->exists() ) {
				$mailer = new UserMailer();
				$to = new MailAddress( $wgModerationEmail );
				$from = new MailAddress( $wgPasswordSender );
				$subject = wfMessage( 'moderation-notification-subject' )->text();
				$content = wfMessage( 'moderation-notification-content',
					$page->getTitle()->getBaseText(),
					$user->getName(),
					SpecialPage::getTitleFor( 'Moderation' )->getFullURL( array(
						'modaction' => 'show',
						'modid' => ModerationEditHooks::$LastInsertId
					) )
				)->text();
				$mailer->send( $to, $from, $subject, $content );
			}
		}

		/*
			We have queued this edit for moderation.
			No need to save anything at this point.
			Later (if approved) the edit will be saved via doEditContent().

			Here we just redirect the users back to the page they edited
			(as was the behavior for unmoderated edits).
			Notification "Your edit was successfully sent for moderation"
			will be shown by JavaScript.
		*/

		$wgOut->redirect( $title->getFullURL( array( 'modqueued' => 1 ) ) );
		return false;
	}

	public static function onBeforePageDisplay( &$out, &$skin ) {
		if ( $out->getContext()->getRequest()->getVal( 'modqueued' ) ) {
			$out->addModules( 'ext.moderation.notify' );
		}
	}

	/*
		onPageContentSaveComplete()

		If this is a merged edit, then 'wpMergeID' is the ID of moderation entry.
		Here we mark this entry as merged.
	*/
	public static function onPageContentSaveComplete( $page, $user, $content, $summary, $is_minor, $is_watch, $section, $flags, $revision, $status, $baseRevId )
	{
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

		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			array(
				'mod_merged_revid' => $revision->getId(),
				'mod_preloadable' => 0
			),
			array(
				'mod_id' => $mergeID,
				'mod_merged_revid' => 0 # No more than one merging
			),
			__METHOD__
		);

		if ( $dbw->affectedRows() ) {
			$logEntry = new ManualLogEntry( 'moderation', 'merge' );
			$logEntry->setPerformer( $user );
			$logEntry->setTarget( $page->getTitle() );
			$logEntry->setParameters( array(
				'modid' => $mergeID,
				'revid' => $revision->getId()
			) );
			$logid = $logEntry->insert();
			$logEntry->publish( $logid );
		}

		return true;
	}

	public static function onAuthPluginAutoCreate( $user ) {
		ModerationPreload::onAddNewAccount( $user, false );
	}

	public static function PrepareEditForm( $editpage, $out ) {
		$mergeID = ModerationEditHooks::$NewMergeID;
		if ( !$mergeID ) {
			$mergeID = $out->getRequest()->getVal( 'wpMergeID' );
		}

		if ( !$mergeID ) {
			return;
		}

		$out->addHTML( Html::hidden( 'wpMergeID', $mergeID ) );
		$out->addHTML( Html::hidden( 'wpIgnoreBlankSummary', '1' ) );
	}
}
