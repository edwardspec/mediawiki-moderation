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
 * @brief Performs editing methods of the testsuite (edit/upload/move) via API.
 */

class ModerationTestsuiteApiBot extends ModerationTestsuiteBot {
	/**
	 * @brief Make an edit via API.
	 * @param ModerationTestsuite $t
	 * @param string $title
	 * @param string $text
	 * @param string $summary
	 * @param string|int $section
	 * @return ModerationTestsuiteApiBotResponse
	 */
	public function doEdit( ModerationTestsuite $t,
		$title, $text, $summary, $section, array $extraParams
	) {
		if ( $section !== '' ) {
			$extraParams['section'] = $section;
		}

		$ret = $t->query( [
			'action' => 'edit',
			'title' => $title,
			'text' => $text,
			'summary' => $summary,
			'token' => null
		] + $extraParams );

		return ModerationTestsuiteApiBotResponse::factory( $ret,
			isset( $ret['error'] ) && $ret['error']['code'] == 'moderation-edit-queued',
			!isset( $ret['error'] ),
			isset( $ret['error'] ) && $ret['error']['code'] != 'moderation-edit-queued'
		);
	}
}
