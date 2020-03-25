<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

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
 * Factory that can construct ModerationEntryFormatter from Context.
 */

namespace MediaWiki\Moderation;

use IContextSource;
use MediaWiki\Linker\LinkRenderer;
use ModerationEntryFormatter;

class EntryFormatterFactory {
	/**
	 * @var LinkRenderer
	 */
	protected $linkRenderer;

	/** @var ActionLinkRenderer */
	protected $actionLinkRenderer;

	/**
	 * @param LinkRenderer $linkRenderer
	 * @param ActionLinkRenderer $actionLinkRenderer
	 */
	public function __construct( LinkRenderer $linkRenderer,
		ActionLinkRenderer $actionLinkRenderer
	) {
		$this->linkRenderer = $linkRenderer;
		$this->actionLinkRenderer = $actionLinkRenderer;
	}

	/**
	 * Construct new ModerationEntryFormatter.
	 * @param object $row
	 * @param IContextSource $context
	 * @return ModerationEntryFormatter
	 */
	public function makeFormatter( $row, IContextSource $context ) {
		return new ModerationEntryFormatter(
			$row,
			$context,
			$this->linkRenderer,
			$this->actionLinkRenderer
		);
	}
}
