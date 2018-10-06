<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2017-2018 Edward Chernenko.

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
 * Hooks related to edits/uploads via API.
 */

class ModerationApiHooks {

	/**
	 * onApiCheckCanExecute() hook
	 *
	 * Disable upload API for non-automoderated user in MediaWiki 1.27
	 * (this feature is only supported in MediaWiki 1.28+).
	 *
	 * Also disable ApiFileRevert (this API doesn't run any pre-upload
	 * hooks, thus allowing to bypass moderation).
	 */
	public static function onApiCheckCanExecute( $module, $user, &$message ) {
		if ( ModerationCanSkip::canUploadSkip( $user ) ) {
			return true; /* No need to limit automoderated users */
		}

		$moduleName = $module->getModuleName();

		if ( $moduleName == 'upload' && !ModerationUploadHooks::haveUploadVerifyUpload() ) {
			$message = 'nouploadmodule';
			return false;
		}

		if ( $moduleName == 'filerevert' ) {
			$message = 'moderation-revert-not-allowed';
			return false;
		}

		return true;
	}

	/*
		onApiBeforeMain()
		Make sure that
		1) api.php?action=edit&appendtext=... will append to the pending version.
		2) api.php?action=edit&section=N won't complain 'nosuchsection' if
		section N exists in the pending version.
	*/
	public static function onApiBeforeMain( &$main ) {
		$request = $main->getRequest();
		if ( $request->getVal( 'action' ) != 'edit' ) {
			return true; /* Nothing to do */
		}

		$section = $request->getVal( 'section', '' );
		$prepend = $request->getVal( 'prependtext', '' );
		$append = $request->getVal( 'appendtext', '' );

		if ( !$prepend && !$append && !$section ) {
			return true; /* Usual api.php?action=edit&text= works correctly with Moderation */
		}

		$pageObj = $main->getTitleOrPageId( $request->getValues( 'title', 'pageid' ) );
		$title = $pageObj->getTitle();

		$row = ModerationPreload::singleton()->loadUnmoderatedEdit( $title );
		if ( !$row ) {
			return true; /* No pending version - ApiEdit will handle this correctly */
		}

		$oldContent = ContentHandler::makeContent( $row->text, $title );
		$content = $oldContent;
		if ( $section ) {
			if ( $section == 'new' ) {
				$content = ContentHandler::makeContent( '', $title );
			} else {
				$content = $oldContent->getSection( $section );
				if ( !$content ) {
					$main->dieUsage( "There is no section {$section}.", 'nosuchsection' );
				}
			}
		}

		$text = $content->getNativeData();

		/* Now we remove appendtext/prependtext from WebRequest object
			and make ApiEdit think that this is a usual action=edit&text=... call.

			Otherwise ApiEdit will attempt to prepend/append to the last revision
			of the page, not to the preloaded revision.
		*/
		$query = $request->getValues();
		if ( !isset( $query['text'] ) ) {
			$query['text'] = $prepend . $text . $append;
		}
		unset( $query['prependtext'] );
		unset( $query['appendtext'] );

		$query['text'] = rtrim( $query['text'] );

		if ( $section ) {
			/* We also remove section=N parameter,
				because if section N doesn't exist in the page,
				ApiEditPage will incorrectly complain "nosuchsection"
				(even when section N exists in the pending version).
			*/
			$newSectionContent = ContentHandler::makeContent( $query['text'], $title );
			$newContent = $oldContent->replaceSection( $section, $newSectionContent );

			$query['text'] = $newContent->getNativeData();
			unset( $query['section'] );
		}

		$req = new DerivativeRequest( $request, $query, true );
		$main->getContext()->setRequest( $req );

		/* Let ApiEdit handle the rest */
		return true;
	}

	/**
	 * Adds qppage=Moderation to api.php?action=query&list=querypage.
	 */
	public static function onwgQueryPages( &$wgQueryPages ) {
		$wgQueryPages[] = [ SpecialModeration::class, 'Moderation' ];
		return true;
	}
}
