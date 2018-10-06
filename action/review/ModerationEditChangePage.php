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
 * Subclass of EditPage used by modaction=editchange
 */

class ModerationEditChangePage extends EditPage {

	/**
	 * When user clicks Submit, handle this back to Special:Moderation.
	 */
	protected function getActionURL( Title $title ) {
		global $wgOut;
		$context = $wgOut->getContext(); // MediaWiki 1.27 doesn't have EditPage::getContext()

		return SpecialPage::getTitleFor( 'Moderation' )->getLocalURL( [
			'modid' => $context->getRequest()->getVal( 'modid' ),
			'modaction' => 'editchangesubmit'
		] );
	}

	/**
	 * Add CSRF token.
	 */
	protected function showFormAfterText() {
		global $wgOut; // MediaWiki 1.27 doesn't have EditPage::getContext()

		$wgOut->addHTML(
			Html::hidden( "token", $wgOut->getContext()->getUser()->getEditToken() )
		);
	}

	/**
	 * Remove "Preview" and "Show changes" buttons (not yet implemented).
	 */
	public function getEditButtons( &$tabindex ) {
		$buttons = parent::getEditButtons( $tabindex );

		unset( $buttons['preview'] );
		unset( $buttons['diff'] );

		return $buttons;
	}

	/**
	 * Point "Cancel" button to Special:Moderation, not to the nonexistent article.
	 */
	public function getContextTitle() {
		return SpecialPage::getTitleFor( 'Moderation' );
	}
}
