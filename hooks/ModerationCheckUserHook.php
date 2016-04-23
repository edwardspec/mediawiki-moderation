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
	@brief Corrects rc_ip and checkuser logs when edit is approved.
*/

class ModerationCheckUserHook {
	private $cu_hook_id; // For deinstall()
	private $rc_hook_id;
	private $ip, $xff, $ua;

	/*
		onCheckUserInsertForRecentChange()
		This hook is temporarily installed when approving the edit.

		It modifies the IP, user-agent and XFF in the checkuser database,
		so that they match the user who made the edit, not the moderator.
	*/
	public function onCheckUserInsertForRecentChange( $rc, &$fields ) {
		$fields['cuc_ip'] = IP::sanitizeIP( $this->ip );
		$fields['cuc_ip_hex'] = $this->ip ? IP::toHex( $this->ip ) : null;
		$fields['cuc_agent'] = $this->ua;

		if ( method_exists( 'CheckUserHooks', 'getClientIPfromXFF' ) ) {
			list( $xff_ip, $isSquidOnly ) = CheckUserHooks::getClientIPfromXFF( $this->xff );

			$fields['cuc_xff'] = !$isSquidOnly ? $this->xff : '';
			$fields['cuc_xff_hex'] = ( $xff_ip && !$isSquidOnly ) ? IP::toHex( $xff_ip ) : null;
		} else {
			$fields['cuc_xff'] = '';
			$fields['cuc_xff_hex'] = null;
		}
	}

	/*
		onRecentChange_save()
		This hook is temporarily installed when approving the edit.

		It modifies the IP in the recentchanges table,
		so that it matches the user who made the edit, not the moderator.
	*/
	public function onRecentChange_save( &$rc ) {
		global $wgPutIPinRC;
		if ( !$wgPutIPinRC ) {
			return;
		}

		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'recentchanges',
			array(
				'rc_ip' => IP::sanitizeIP( $this->ip )
			),
			array( 'rc_id' => $rc->mAttribs['rc_id'] ),
			__METHOD__
		);
	}

	public function install( $ip, $xff, $ua ) {
		global $wgHooks;

		$this->ip = $ip;
		$this->xff = $xff;
		$this->ua = $ua;

		$wgHooks['CheckUserInsertForRecentChange'][] = array( $this, 'onCheckUserInsertForRecentChange' );
		end( $wgHooks['CheckUserInsertForRecentChange'] );
		$this->cu_hook_id = key( $wgHooks['CheckUserInsertForRecentChange'] );

		$wgHooks['RecentChange_save'][] = array( $this, 'onRecentChange_save' );
		end( $wgHooks['RecentChange_save'] );
		$this->rc_hook_id = key( $wgHooks['RecentChange_save'] );
	}

	public function deinstall() {
		global $wgHooks;
		unset( $wgHooks['CheckUserInsertForRecentChange'][$this->cu_hook_id] );
		unset( $wgHooks['RecentChange_save'][$this->rc_hook_id] );
	}
}
