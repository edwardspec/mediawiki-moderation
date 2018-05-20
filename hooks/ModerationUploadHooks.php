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
	@file
	@brief Hooks related to file uploads.
*/

class ModerationUploadHooks {

	/**
		@brief Intercept image uploads and queue them for moderation.
	*/
	public static function onUploadVerifyUpload( $upload, $user, $__unused, $comment, $pageText, &$error ) {
		if ( ModerationCanSkip::canSkip( $user, NS_FILE ) ) {
			return true;
		}

		/* Step 1. Upload the file into the user's stash */
		try {
			$file = $upload->stashFile( $user );
		} catch ( MWException $e ) {
			$error = [ 'api-error-stashfailed' ];
			return true;
		}

		/* Step 2. Create a page in File namespace (it will be queued for moderation) */
		$title = $upload->getTitle();
		$page = new WikiPage( $title );
		$status = $page->doEditContent(
			ContentHandler::makeContent( $pageText, $title ),
			$comment,
			0,
			$title->getLatestRevID(),
			$user
		);
		RequestContext::getMain()->getOutput()->redirect( '' ); # Disable redirection after doEditContent()

		/*
			Step 3. Populate mod_stash_key field in newly inserted row
			of the moderation table (to indicate that this is an upload,
			not just editing the text on the image page)
		*/
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			[ 'mod_stash_key' => $file->getFileKey() ],
			[ 'mod_id' => ModerationNewChange::$LastInsertId ],
			__METHOD__
		);

		$error = [ 'moderation-image-queued' ];
		return true;
	}

	/**
		@brief Returns true if UploadVerifyUpload hook exists, false otherwise.
	*/
	public static function haveUploadVerifyUpload() {
		global $wgVersion;
		return version_compare( $wgVersion, '1.28', '>=' );
	}

	/**
		@brief Polyfill to call onUploadVerifyUpload in MediaWiki 1.27.
		Not needed in MediaWiki 1.28+.
	*/
	public static function onUploadVerifyFile( $upload, $mime, &$status ) {
		if ( self::haveUploadVerifyUpload() ) {
			return true;  /* Will be handled in UploadVerifyUpload hook (MediaWiki 1.28+) */
		}

		$context = RequestContext::getMain();
		$user = $context->getUser();

		if ( ModerationCanSkip::canSkip( $user, NS_FILE ) ) {
			return true;
		}

		/* Run validateName() check that normally happens after UploadVerifyFile hook
			(we abort this hook, therefore validateName() must be called here).
		*/
		$result = $upload->validateName();
		if ( $result !== true ) {
			$status = [ $upload->getVerificationErrorCode( $result['status'] ) ];
			return true;
		}

		/* Determine parameters of the upload (e.g. description text of the uploaded image)
			from HTTP request parameters.

			This is a legacy approach for MediaWiki 1.27.
			MediaWiki 1.28+ has UploadVerifyUpload hook which already knows this information.
		*/
		$special = new ModerationSpecialUpload( $context->getRequest() );
		$special->publicLoadRequest();

		$pageText = '';
		if ( !$special->mForReUpload ) {
			$pageText = $special->getInitialPageText(
				$special->mComment,
				$special->mLicense,
				$special->mCopyrightStatus,
				$special->mCopyrightSource
			);
		}

		return self::onUploadVerifyUpload(
			$upload,
			$user,
			[], /* $props - no need to calculate, because our onUploadVerifyUpload() doesn't use it */
			$special->mComment,
			$pageText,
			$status
		);
	}

	public static function ongetUserPermissionsErrors( $title, $user, $action, &$result ) {
		/*
			action=revert bypasses doUpload(), so it is not intercepted
			and is applied without moderation.
			Therefore we don't allow it.
		*/
		$context = RequestContext::getMain();
		$exactAction = Action::getActionName( $context );
		if ( $exactAction == 'revert' && !ModerationCanSkip::canSkip( $user, NS_FILE ) ) {
			$result = 'moderation-revert-not-allowed';
			return false;
		}

		return true;
	}
}
