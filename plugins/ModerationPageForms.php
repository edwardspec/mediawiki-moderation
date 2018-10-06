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
 * Plugin for using Moderation with Extension:PageForms.
 */

class ModerationPageForms {

	public static function install() {
		Hooks::register( 'ModerationContinueEditingLink', new self );
	}

	/**
	 * Preload text of pending edit into the form of Special:FormEdit.
	 *
	 * This is used by two hooks:
	 * PageForms::EditFormPreloadText (when creating new page)
	 * PageForms::EditFormInitialText (when editing existing page)
	 */
	public static function preloadText( &$preloadContent, $targetTitle, $formTitle ) {
		if ( !$targetTitle ) {
			// We are on [[Special:FormEdit/A]], where A is the name of form.
			// Unlike [[Special:FormEdit/A/B]], user is currently not editing
			// a particular page "B", so there is nothing to preload.
			return true;
		}

		ModerationPreload::onEditFormPreloadText( $preloadContent, $targetTitle );
		return true;
	}

	/**
	 * ModerationContinueEditingLink hook.
	 * Here we point "continue editing" link to FormEdit after using FormEdit.
	 */
	public function onModerationContinueEditingLink(
		&$returnto,
		array &$returntoquery,
		Title $title,
		IContextSource $context
	) {
		$request = $context->getRequest();

		// Are we editing via ?action=formedit?
		$action = Action::getActionName( $context );
		if ( $action == 'formedit' ) {
			$returntoquery = [ 'action' => 'formedit' ];
		} else {
			// Are we editing via Special:FormEdit?
			$specialTitle = Title::newFromText( $request->getVal( 'title' ) );
			if ( $specialTitle && $specialTitle->isSpecial( 'FormEdit' ) ) {
				$returnto = $specialTitle->getFullText();
			}
		}

		return true;
	}
}
