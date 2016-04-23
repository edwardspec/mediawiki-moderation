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
	@file
	@brief Parent class for all moderation actions.
*/

abstract class ModerationAction {
	protected $mSpecial;
	protected $id;

	public $actionName;
	public $moderator;

	public function __construct( SpecialModeration $m ) {
		$this->mSpecial = $m;
		$this->moderator = $m->getUser();
		$this->actionName = $m->getRequest()->getVal( 'modaction' );
	}

	public function getSpecial() {
		return $this->mSpecial;
	}

	public function run() {
		$request = $this->mSpecial->getRequest();

		$token = $request->getVal( 'token' );
		$this->id = $request->getVal( 'modid' );

		if (
			$this->requiresEditToken() &&
			!$this->moderator->matchEditToken( $token, $this->id )
		)
		{
			throw new ErrorPageError( 'sessionfailure-title', 'sessionfailure' );
		}

		return $this->execute();
	}

	/* The following methods can be overriden in the subclass */

	public function requiresEditToken() {
		return true;
	}

	abstract public function execute();

}
