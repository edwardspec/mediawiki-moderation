<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020-2021 Edward Chernenko.

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
 * Trait that helps to mock the result of RevisionLookup::getRevisionById()
 */

use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\SlotRecord;

/**
 * @method static \PHPUnit\Framework\MockObject\Rule\AnyInvokedCount any()
 * @method static \PHPUnit\Framework\Constraint\IsIdentical identicalTo($a)
 * @method static \PHPUnit\Framework\MockObject\Stub\ReturnCallback returnCallback($a)
 */
trait MockRevisionLookupTestTrait {
	/**
	 * Mock RevisionLookup service to provide $text as current text of revision $revid.
	 * @param int $revid
	 * @param string $text
	 * @param Title $title
	 */
	public function mockRevisionLookup( $revid, $text, Title $title ) {
		$revisionLookup = $this->createMock( RevisionLookup::class );

		$revisionLookup->expects( $this->any() )->method( 'getRevisionById' )
			->with( $this->identicalTo( $revid ) )
			->will( $this->returnCallback( static function ( $id, $flags ) use ( $text, $title ) {
				$rec = new MutableRevisionRecord( $title );
				$rec->setContent( SlotRecord::MAIN, new TextContent( $text ) );
				return $rec;
			} ) );
		$this->setService( 'RevisionLookup', $revisionLookup );
	}

	// These methods are in MediaWikiTestCase (this trait is used by its subclasses).

	/** @inheritDoc */
	abstract protected function setService( $name, $service );

	/** @inheritDoc */
	abstract protected function createMock( $originalClassName );
}
