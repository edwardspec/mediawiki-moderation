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
	@file
	@brief Helper script to run MediaWiki as a command line script.

	@usage
		$wiki = new ModerationTestsuiteInternallyInvokedWiki( ... );
		$wiki->execute();
*/

class InternallyInvoke extends Maintenance {

	public function execute() {
		/* Trick to load all Testsuite classes without listing them
			in the extension.json (which is used in production) */
		global $wgAutoloadClasses;
		$wgAutoloadClasses['ModerationTestsuiteInternallyInvokedWiki'] = __DIR__ . "/../../ModerationTestsuite.php";

		$inputFilename = $this->getArg( 0 );
		$outputFilename = $this->getArg( 1 );

		if ( !$inputFilename || !$outputFilename ) {
			$this->error( "internallyInvoke.php: input/output files must be specified." );
			exit( 1 );
		}

		$wiki = ModerationTestsuiteInternallyInvokedWiki::newFromFile( $inputFilename );
		$result = $wiki->invokeFromScript();

		file_put_contents( $outputFilename, serialize( $result ) );
	}
}

$maintClass = InternallyInvoke::class;
require_once RUN_MAINTENANCE_IF_MAIN;
