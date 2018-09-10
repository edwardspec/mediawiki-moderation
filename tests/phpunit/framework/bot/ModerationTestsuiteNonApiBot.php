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
 * @brief Performs editing methods of the testsuite (edit/upload/move) NOT via API.
 */

class ModerationTestsuiteNonApiBot extends ModerationTestsuiteBot {
	/**
	 * @brief Make an edit via the usual interface, as real users do.
	 * @param ModerationTestsuite $t
	 * @param string $title
	 * @param string $text
	 * @param string $summary
	 * @param string|int $section
	 * @return ModerationTestsuiteNonApiBotResponse
	 */
	public function doEdit( ModerationTestsuite $t,
		$title, $text, $summary, $section, array $extraParams
	) {
		if ( $section !== '' ) {
			$extraParams['wpSection'] = $section;
		}

		$params = $extraParams + [
			'action' => 'submit',
			'title' => $title,
			'wpTextbox1' => $text,
			'wpSummary' => $summary,
			'wpEditToken' => $t->getEditToken(),
			'wpSave' => 'Save',
			'wpIgnoreBlankSummary' => '',
			'wpRecreate' => ''
		];

		if ( defined( 'EditPage::UNICODE_CHECK' ) ) { // MW 1.30+
			$params['wpUnicodeCheck'] = EditPage::UNICODE_CHECK;
		}

		/* Determine wpEdittime (timestamp of the current revision of $title),
			otherwise edit conflict will occur. */
		$rev = $t->getLastRevision( $title );
		$params['wpEdittime'] = $rev ? wfTimestamp( TS_MW, $rev['timestamp'] ) : '';

		$req = $t->httpPost( wfScript( 'index' ), $params );
		$location = $req->getResponseHeader( 'Location' );

		return ModerationTestsuiteNonApiBotResponse::factory( $req,
			( $location && strpos( $location, 'modqueued=1' ) !== false ),
			( $location && strpos( $location, 'modqueued=1' ) === false ),
			!$location
		);
	}

	/**
	 * @brief Make a move via the usual interface, as real users do.
	 * @param ModerationTestsuite $t
	 * @param string $oldTitle
	 * @param string $newTitle
	 * @param string $reason
	 * @param array $extraParams
	 * @return ModerationTestsuiteNonApiBotResponse
	 */
	public function doMove( ModerationTestsuite $t,
		$oldTitle, $newTitle, $reason, array $extraParams
	) {
		$newTitleObj = Title::newFromText( $newTitle );
		$req = $t->httpPost( wfScript( 'index' ), $extraParams + [
			'action' => 'submit',
			'title' => 'Special:MovePage',
			'wpOldTitle' => $oldTitle,
			'wpNewTitleMain' => $newTitleObj->getText(),
			'wpNewTitleNs' => $newTitleObj->getNamespace(),
			'wpMove' => 'Move',
			'wpEditToken' => $t->getEditToken(),
			'wpReason' => $reason
		] );

		// TODO: eliminate ModerationTestsuiteSubmitResult class completely,
		// its logic should be internal to ModerationTestsuiteNonApiBot.
		$submitRes = ModerationTestsuiteSubmitResult::newFromResponse( $req, $t );

		return ModerationTestsuiteNonApiBotResponse::factory( $req,
			( $submitRes && !$submitRes->getError() &&
				strpos( $submitRes->getSuccessText(), '(moderation-move-queued)' ) !== false ),
			!$submitRes,
			$submitRes && $submitRes->getError()
		);
	}
}
