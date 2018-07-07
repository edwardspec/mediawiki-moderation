<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2017-2018 Edward Chernenko.

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
 * @brief ApiMain subclass for testing API requests via internal invocation.
 */

class ModerationTestsuiteApiMain extends ApiMain {

	/**
	 * @brief Run API via internal invocation with proper error handling.
	 * @returns array (parsed JSON of the response).

	 * @note This function runs ApiResult through the ApiFormatter,
		so its return value will be exactly the same
		as if api.php was called NOT via internal invocation.
	*/
	public static function invoke( IContextSource $context ) {
		$api = new self( $context, true );
		return $api->doInternalInvocation();
	}

	protected function doInternalInvocation() {
		$this->executeActionWithErrorHandling(); /* Run the API. Automatically prints errors */

		if ( !$this->getResult()->getResultData( 'error' ) ) {
			/* Not an error: print the result */
			$this->setupExternalResponse(
				$this->getModule(),
				$this->extractRequestParams()
			);
			$this->printResult( false );
		}
	}
}
