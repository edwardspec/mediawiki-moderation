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
 * Register services like ActionFactory in MediaWikiServices container.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Moderation\ActionFactory;
use MediaWiki\Moderation\ActionLinkRenderer;
use MediaWiki\Moderation\EntryFactory;

return [
	'Moderation.ActionFactory' => function () {
		return new ActionFactory();
	},
	'Moderation.ActionLinkRenderer' => function ( MediaWikiServices $services ) {
		return new ActionLinkRenderer(
			RequestContext::getMain(),
			$services->getLinkRenderer(),
			SpecialPage::getTitleFor( 'Moderation' )
		);
	},
	'Moderation.EntryFactory' => function ( MediaWikiServices $services ) {
		return new EntryFactory(
			$services->getLinkRenderer(),
			$services->getService( 'Moderation.ActionLinkRenderer' )
		);
	},
];
