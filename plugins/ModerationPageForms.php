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
	@file
	@brief Plugin for using Moderation with Extension:PageForms.
*/

class ModerationPageForms {

	/**
		@brief Preload text of pending edit into the form of Special:FormEdit.

		This is used by two hooks:
		PageForms::EditFormPreloadText (when creating new page)
		PageForms::EditFormInitialText (when editing existing page)
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
}
