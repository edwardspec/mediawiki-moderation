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
	@brief Interface for executing TestSets and calculating expected DB fields.
*/

interface IModerationQueueTestSet {
	/**
		@brief Construct TestSet from input of dataProvider.
		@param $options Parameters of test, e.g. [ 'user' => 'Bear expert', 'title' => 'Black bears' ].
	*/
	public function __construct( array $options );

	/**
		@brief Execute the TestSet, making the edit with requested parameters.
	*/
	public function performEdit();

	/**
		@brief Returns array of expected post-edit values of all mod_* fields in the database.
		@note Values like "/value/" are treated as regular expressions.
		@returns [ 'mod_user' => ..., 'mod_namespace' => ..., ... ]
	*/
	public function getExpectedRow();
}
