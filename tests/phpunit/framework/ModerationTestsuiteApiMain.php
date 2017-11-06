<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2017 Edward Chernenko.

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
	@brief ApiMain subclass for testing API requests via internal invocation.
*/

class ModerationTestsuiteApiMain extends ApiMain {

	/**
		@brief Run API via internal invocation with proper error handling.
		@returns array (parsed JSON of the response).

		@note This function runs ApiResult through the ApiFormatter,
		so its return value will be exactly the same
		as if api.php was called NOT via internal invocation.
	*/
	public static function doInternalInvocation( IContextSource $context ) {
		$api = new self( $context, true );
		$result = $api->getResult();

		try {
			$api->execute();
		}
		catch ( ApiUsageException $e ) {
			$api->substituteResultWithError( $e );
		}

		/* FIXME: must run getResultData() through the ApiFormatter (result printer) */

		return $result->getResultData();
	}
}
