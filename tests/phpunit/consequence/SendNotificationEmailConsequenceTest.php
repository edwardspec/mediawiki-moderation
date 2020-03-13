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
 * Unit test of SendNotificationEmailConsequence.
 */

use MediaWiki\Moderation\SendNotificationEmailConsequence;

/**
 * @group Database
 */
class SendNotificationEmailConsequenceTest extends MediaWikiTestCase {

	/**
	 * Verify that SendNotificationEmailConsequence sends an outgoing email.
	 * @covers MediaWiki\Moderation\SendNotificationEmailConsequence
	 */
	public function testSendNotificationEmailConsequence() {
		$title = Title::newFromText( 'Project:Some page' );
		$user = User::newFromName( '10.11.12.13', false );
		$modid = 12345;
		$expectedRecipient = 'some.recipient@localhost';

		$this->setMwGlobals( 'wgModerationEmail', $expectedRecipient );
		$this->setContentLang( 'qqx' );

		$hookFired = false;

		\Hooks::clear( 'AlternateUserMailer' );
		\Hooks::register( 'AlternateUserMailer',
			function ( $headers, array $to, $from, $subject, $body )
				use ( &$hookFired, $expectedRecipient, $title, $user, $modid )
			{
					$hookFired = true;

					$this->assertCount( 1, $to );
					$this->assertEquals( $expectedRecipient, $to[0] );

					$this->assertEquals( '(moderation-notification-subject)', $subject );
					$this->assertEquals( '(moderation-notification-content: ' .
						$title->getFullText() . ', ' .
						$user->getName() . ', ' .
						SpecialPage::getTitleFor( 'Moderation' )->getCanonicalURL( [
							'modaction' => 'show',
							'modid' => $modid
						] ) . ')', $body );

					return false; // Don't actually send it.
			}
		);

		// Create and run the Consequence.
		$consequence = new SendNotificationEmailConsequence( $title, $user, $modid );
		$consequence->run();

		$this->assertTrue( $hookFired, "SendNotificationEmailConsequence didn't send anything." );
	}

	/**
	 * Restore original AlternateUserMailer hook that suppresses all emails during PHPUnit tests.
	 */
	public function tearDown() : void {
		\Hooks::clear( 'AlternateUserMailer' );
		\Hooks::register( 'AlternateUserMailer', function () {
			return false;
		} );

		parent::tearDown();
	}
}
