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
	@brief Hooks related to file uploads.
*/

class ModerationUploadHooks {
	/**
		@brief Intercept image uploads and queue them for moderation.
	*/
	public static function onUploadVerifyFile( $upload, $mime, &$status ) {
		global $wgRequest, $wgUser, $wgOut;

		if ( ModerationCanSkip::canSkip( $wgUser ) ) {
			return;
		}

		$result = $upload->validateName();
		if ( $result !== true ) {
			$status = array( $upload->getVerificationErrorCode( $result['status'] ) );
			return;
		}

		$special = new ModerationSpecialUpload( $wgRequest );
		$special->publicLoadRequest();

		$title = $upload->getTitle();
		$model = $title->getContentModel();

		try {
			$file = $upload->stashFile( $wgUser );
		} catch ( MWException $e ) {
			$status = array( 'api-error-stashfailed' );
			return;
		}

		$key = $file->getFileKey();

		$pageText = '';
		if ( !$special->mForReUpload ) {
			$pageText = $special->getInitialPageText(
				$special->mComment,
				$special->mLicense,
				$special->mCopyrightStatus,
				$special->mCopyrightSource
			);
		}

		$content = ContentHandler::makeContent( $pageText, null, $model );

		/* Step 1. Create a page in File namespace (it will be queued for moderation) */
		$page = new WikiPage( $title );
		$status = $page->doEditContent(
			$content,
			$special->mComment,
			0,
			$title->getLatestRevID(),
			$wgUser
		);
		$wgOut->redirect( '' ); # Disable redirection after doEditContent()

		/*
			Step 2. Populate mod_stash_key field in newly inserted row
			of the moderation table (to indicate that this is an upload,
			not just editing the text on image page)
		*/
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			array( 'mod_stash_key' => $key ),
			array( 'mod_id' => ModerationEditHooks::$LastInsertId ),
			__METHOD__
		);

		$status = array( 'moderation-image-queued' );
	}

	public static function onApiCheckCanExecute( $module, $user, &$message ) {
		if ( $module == 'upload' && !ModerationCanSkip::canSkip( $user ) ) {
			$message = 'nouploadmodule';
			return false;
		}

		return true;
	}

	public static function ongetUserPermissionsErrors( $title, $user, $action, &$result ) {
		/*
			action=revert bypasses doUpload(), so it is not intercepted
			and is applied without moderation.
			Therefore we don't allow it.
		*/
		$context = RequestContext::getMain();
		$exactAction = Action::getActionName( $context );
		if ( $exactAction == 'revert' && !ModerationCanSkip::canSkip( $user ) ) {
			$result = 'moderation-revert-not-allowed';
			return false;
		}

		return true;
	}
}
