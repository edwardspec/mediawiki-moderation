<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018-2020 Edward Chernenko.

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
 * Object that represents one preloadable edit that is currently awaiting moderation.
 */

namespace MediaWiki\Moderation;

class PendingEdit {
	/**
	 * @var int
	 * mod_id of this pending edit.
	 */
	protected $id;

	/** @var string */
	protected $text;

	/** @var string */
	protected $comment;

	/**
	 * @param int $id
	 * @param string $text
	 * @param string $comment
	 */
	public function __construct( $id, $text, $comment ) {
		$this->id = $id;
		$this->text = $text;
		$this->comment = $comment;
	}

	/**
	 * Get mod_id of this pending edit.
	 * @return int
	 */
	public function getId() {
		return $this->id;
	}

	/**
	 * Get text of this pending edit.
	 * @return string
	 */
	public function getText() {
		return $this->text;
	}

	/**
	 * Get edit summary of this pending edit.
	 * @return string
	 */
	public function getComment() {
		return $this->comment;
	}
}
