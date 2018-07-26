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
 * @brief Subclass of EditPage used by modaction=editchange
 */

class ModerationEditChangePage extends EditPage {

	/**
	 * @brief When user clicks Submit, handle this back to Special:Moderation.
	 */
	protected function getActionURL( Title $title ) {
		return SpecialPage::getTitleFor( 'Moderation' )->getLocalURL( [
			'modid' => $this->getContext()->getRequest()->getVal( 'modid' ),
			'modaction' => 'editchange'
		] );
	}

	/**
	 * @brief Add CSRF token.
	 */
	protected function showFormAfterText() {
		$this->context->getOutput()->addHTML(
			Html::hidden( "token", $this->context->getUser()->getEditToken() )
		);
	}

	/**
	 * @brief Remove "Preview" and "Show changes" buttons (not yet implemented).
	 */
	public function getEditButtons( &$tabindex ) {
		$buttons = parent::getEditButtons( $tabindex );

		unset( $buttons['preview'] );
		unset( $buttons['diff'] );

		return $buttons;
	}

	/**
	 * @brief Point "Cancel" button to Special:Moderation, not to the nonexistent article.
	 */
	public function getContextTitle() {
		return SpecialPage::getTitleFor( 'Moderation' );
	}
}
