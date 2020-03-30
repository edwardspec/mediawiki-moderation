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
 * Trait that helps to mock the result of Revision::newFromId().
 */

use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\SlotRecord;

// For backward compatibility with MW 1.31
use MediaWiki\Storage\MutableRevisionRecord as OldMutableRevisionRecord31;
use MediaWiki\Storage\RevisionLookup as OldRevisionLookup31;
use MediaWiki\Storage\SlotRecord as OldSlotRecord31;

/**
 * @method static mixed any()
 * @method static mixed identicalTo($a)
 * @method static mixed returnCallback($a)
 */
trait MockRevisionLookupTestTrait {
	/**
	 * Mock RevisionLookup service to provide $text as current text of revision $revid.
	 * @param int $revid
	 * @param string $text
	 * @param Title $title
	 */
	public function mockRevisionLookup( $revid, $text, Title $title ) {
		$revisionLookup = $this->createMock( $this->revisionLookupClass() );

		$revisionLookup->expects( $this->any() )->method( 'getRevisionById' )
			->with( $this->identicalTo( $revid ) )
			->will( $this->returnCallback( function ( $id, $flags ) use ( $text, $title ) {
				$revisionRecordClass = $this->revisionRecordClass();
				$slotRecordClass = $this->slotRecordClass();

				$rec = new $revisionRecordClass( $title );
				$rec->setSlot( $slotRecordClass::newUnsaved( 'main', new TextContent( $text ) ) );
				return $rec;
			} ) );
		$this->setService( 'RevisionLookup', $revisionLookup );
	}

	private function revisionRecordClass() {
		return class_exists( OldMutableRevisionRecord31::class ) ?
			OldMutableRevisionRecord31::class : MutableRevisionRecord::class;
	}

	private function slotRecordClass() {
		return class_exists( OldSlotRecord31::class ) ?
			OldSlotRecord31::class : SlotRecord::class;
	}

	private function revisionLookupClass() {
		return interface_exists( OldRevisionLookup31::class ) ?
			OldRevisionLookup31::class : RevisionLookup::class;
	}

	// These methods are in MediaWikiTestCase (this trait is used by its subclasses).

	/** @inheritDoc */
	abstract protected function setService( $name, $service );

	/** @inheritDoc */
	abstract protected function createMock( $originalClassName );
}
