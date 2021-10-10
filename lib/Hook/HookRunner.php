<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2021 Edward Chernenko.

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
 * Methods to run hooks that are provided by Extension:Moderation itself.
 */

namespace MediaWiki\Moderation\Hook;

use IContextSource;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Linker\LinkTarget;

class HookRunner implements ModerationContinueEditingLinkHook {
	/** @var HookContainer */
	protected $container;

	/**
	 * @param HookContainer $container
	 */
	public function __construct( HookContainer $container ) {
		$this->container = $container;
	}

	/**
	 * @inheritDoc
	 */
	public function onModerationContinueEditingLink(
		string &$returnto,
		array &$returntoquery,
		LinkTarget $title,
		IContextSource $context
	) {
		$this->container->run(
			'ModerationContinueEditingLink',
			[ &$returnto, &$returntoquery, $title, $context ]
		);
	}
}
