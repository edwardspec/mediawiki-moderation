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
 * @brief Trait for classes that represent return value of bot methods edit(), move(), upload().
 */

trait ModerationTestsuiteBotResponse {

	/** @var bool */
	private $isIntercepted;

	/** @var bool */
	private $isBypassed;

	/** @var bool */
	private $hasFailed;

	/**
	 * @brief Construct new BotResponse object.
	 * @param array|ModerationTestsuiteResponse $nativeResponse Depends on bot type (API or not).
	 * @param bool $isIntercepted
	 * @param bool $isBypassed
	 * @param bool $hasFailed
	 */
	public static function factory( $nativeResponse, $isIntercepted, $isBypassed, $hasFailed ) {
		$r = new self( $nativeResponse );
		$r->isIntercepted = $isIntercepted;
		$r->isBypassed = $isBypassed;
		$r->hasFailed = $hasFailed;

		return $r;
	}

	/**
	 * Check if this action was intercepted (and queued) by Moderation.
	 * @return bool
	 */
	public function isIntercepted() {
		return $this->isIntercepted;
	}

	/**
	 * Check if this action has bypassed Moderation (was applied immediately).
	 * @return bool
	 */
	public function isBypassed() {
		return $this->isBypassed;
	}

	/**
	 * Check if this action has failed (wasn't saved due to an error).
	 * @return bool
	 */
	public function hasFailed() {
		return $this->hasFailed;
	}
}
