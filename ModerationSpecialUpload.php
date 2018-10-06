<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2015 Edward Chernenko.

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
 * Proxy class for SpecialUpload.
 */

/*
	This class is only needed to gain access to the
	protected function SpecialUpload::loadRequest().
*/
class ModerationSpecialUpload extends SpecialUpload {
	public function __construct( $request = null ) {
		parent::__construct( $request );
	}

	public function publicLoadRequest() {
		$this->loadRequest();
	}
}
