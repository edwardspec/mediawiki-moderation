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

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Moderation\ActionFactory;
use MediaWiki\Moderation\ActionLinkRenderer;
use MediaWiki\Moderation\ConsequenceManager;
use MediaWiki\Moderation\EditFormOptions;
use MediaWiki\Moderation\EntryFactory;
use MediaWiki\Moderation\RollbackResistantQuery;
use MediaWiki\Moderation\TimestampFormatter;

// @codeCoverageIgnoreStart
// (PHPUnit doesn't support @covers for out-of-class code)
// See also: T248172 "Allow static methods to be used for wiring"

return [
	'Moderation.ActionFactory' => function ( MediaWikiServices $services ) : ActionFactory {
		return new ActionFactory(
			$services->getService( 'Moderation.EntryFactory' ),
			$services->getService( 'Moderation.ConsequenceManager' )
		);
	},
	'Moderation.ActionLinkRenderer' => function ( MediaWikiServices $services ) : ActionLinkRenderer {
		return new ActionLinkRenderer(
			RequestContext::getMain(),
			$services->getLinkRenderer(),
			SpecialPage::getTitleFor( 'Moderation' )
		);
	},
	'Moderation.ApproveHook' => function () : ModerationApproveHook {
		return new ModerationApproveHook(
			LoggerFactory::getInstance( 'ModerationApproveHook' )
		);
	},
	'Moderation.CanSkip' => function ( MediaWikiServices $services ) : ModerationCanSkip {
		return new ModerationCanSkip(
			// Will be eventually replaced by ServiceOptions (MW 1.34+).
			$services->getMainConfig(),
			$services->getService( 'Moderation.ApproveHook' )
		);
	},
	'Moderation.ConsequenceManager' => function () : ConsequenceManager {
		return new ConsequenceManager();
	},
	'Moderation.EditFormOptions' => function ( MediaWikiServices $services ) : EditFormOptions {
		return new EditFormOptions(
			$services->getService( 'Moderation.ConsequenceManager' )
		);
	},
	'Moderation.EntryFactory' => function ( MediaWikiServices $services ) : EntryFactory {
		return new EntryFactory(
			$services->getLinkRenderer(),
			$services->getService( 'Moderation.ActionLinkRenderer' ),
			$services->getService( 'Moderation.TimestampFormatter' ),
			$services->getService( 'Moderation.ConsequenceManager' ),
			$services->getService( 'Moderation.CanSkip' ),
			$services->getService( 'Moderation.ApproveHook' )
		);
	},
	'Moderation.NotifyModerator' =>
	function ( MediaWikiServices $services ) : ModerationNotifyModerator {
		return new ModerationNotifyModerator(
			$services->getLinkRenderer(),
			$services->getService( 'Moderation.EntryFactory' ),
			wfGetMainCache()
		);
	},
	'Moderation.Preload' => function ( MediaWikiServices $services ) : ModerationPreload {
		return new ModerationPreload(
			$services->getService( 'Moderation.EntryFactory' ),
			$services->getService( 'Moderation.ConsequenceManager' )
		);
	},
	'Moderation.RollbackResistantQuery' =>
	function ( MediaWikiServices $services ) : RollbackResistantQuery {
		return new RollbackResistantQuery(
			$services->getDBLoadBalancer()
		);
	},
	'Moderation.TimestampFormatter' => function () : TimestampFormatter {
		return new TimestampFormatter();
	},
];

// @codeCoverageIgnoreEnd
