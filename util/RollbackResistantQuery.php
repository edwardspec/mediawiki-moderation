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
 * Performs database query that is not rolled back by MWException.
 */

class RollbackResistantQuery {

	/** @var bool Becomes true after initialize() */
	protected static $initialized = false;

	/** @var array All created RollbackResistantQuery objects */
	protected static $performedQueries = [];

	/** @var IDatabase */
	protected $dbw;

	/** @var string Database method, e.g. 'insert' or 'update' */
	protected $methodName;

	/** @var array Arguments to be passed to Database::insert(), etc. */
	protected $args;

	/**
	 * Perform Database::insert() that won't be undone by Database::rollback().
	 * @param IDatabase $dbw Database object.
	 * @param array $args Arguments of Database::insert call.
	 */
	public static function insert( IDatabase $dbw, array $args ) {
		new self( 'insert', $dbw, $args );
	}

	/**
	 * Perform Database::update() that won't be undone by Database::rollback().
	 * @param IDatabase $dbw Database object.
	 * @param array $args Arguments of Database::update call.
	 */
	public static function update( IDatabase $dbw, array $args ) {
		new self( 'update', $dbw, $args );
	}

	/**
	 * Perform Database::replace() that won't be undone by Database::rollback().
	 * @param IDatabase $dbw Database object.
	 * @param array $args Arguments of Database::replace call.
	 */
	public static function replace( IDatabase $dbw, array $args ) {
		new self( 'replace', $dbw, $args );
	}

	/**
	 * Perform Database::upsert() that won't be undone by Database::rollback().
	 * @param IDatabase $dbw Database object.
	 * @param array $args Arguments of Database::upsert call.
	 */
	public static function upsert( IDatabase $dbw, array $args ) {
		new self( 'upsert', $dbw, $args );
	}

	/**
	 * Create and immediately execute a new query.
	 * @param string $methodName One of the following: 'insert', 'update' or 'replace'.
	 * @param IDatabase $dbw Database object.
	 * @param array $args Arguments of the method.
	 */
	protected function __construct( $methodName, IDatabase $dbw, array $args ) {
		$this->dbw = $dbw;
		$this->methodName = $methodName;
		$this->args = $args;

		/* Install the hooks (only happens once) */
		$this->initialize();

		/* The query is invoked immediately.
			If rollback() happens, the query will be repeated. */
		$this->executeNow();

		self::$performedQueries[] = $this; /* All $performedQueries will be re-run after rollback() */
	}

	/**
	 * Install hooks that can detect a database rollback.
	 */
	protected function initialize() {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;
		$query = $this;

		/* MediaWiki 1.28+ calls TransactionListener callback after rollback() */
		if ( defined( 'Database::TRIGGER_ROLLBACK' ) ) {
			$this->dbw->setTransactionListener( 'moderation-on-rollback',
				function ( $trigger ) use ( $query ) {
					if ( $trigger == Database::TRIGGER_ROLLBACK ) {
						$query->onRollback();
					}
				},
				__METHOD__
			);
		} else {
			/* MediaWiki 1.27 doesn't call any callbacks after rollback(),
				but we can at least detect MWException - what usually causes the rolback
				in MWExceptionHandler::handleException() */

			Hooks::register( 'LogException', function ( $e, $suppressed ) use ( $query ) {
				if (
					!( $e instanceof DBError ) && // DBError likely means that rollback failed
					!( $e instanceof JobQueueError ) // Non-fatal error in JobQueue, doesn't cause rollback
				) {
					$query->onRollback();
				}
					return true;
			} );
		}
	}

	/**
	 * Re-run all $performedQueries. Called after the database rollback.
	 */
	protected function onRollback() {
		foreach ( self::$performedQueries as $query ) {
			$query->executeNow();
		}

		self::$performedQueries = [];
	}

	/**
	 * Run the scheduled query immediately.
	 */
	protected function executeNow() {
		call_user_func_array(
			[ $this->dbw, $this->methodName ],
			$this->args
		);
	}
}
