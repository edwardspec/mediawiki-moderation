/**
	@brief Makes links on Special:Moderation work via Ajax.
*/

( function ( mw, $ ) {
	'use strict';

	var api = new mw.Api(),
		$allRows = $( '.modline' );

	/**
		@brief Find subset of .modline elements by their $.data().
		Note: $.data() of rows is populated in prepareRow().
	*/
	function getRowsByData( dataKey, dataVal ) {
		return $allRows.filter( function( idx, rowElem ) {
			return $( rowElem ).data( dataKey ) == dataVal;
		} );
	}

	function getRowById( modid ) {
		return getRowsByData( 'modid', modid );
	}

	function getRowsWithSameUser( $row ) {
		var $rows = getRowsByData( 'user', $row.data( 'user' ) )

		/* Approved/rejected rows are not affected by ApproveAll/RejectAll */
		return $rows.not( '.modstatus-approved,.modstatus-rejected' );
	}

	/**
		@brief Calculate modid/username for this .modline.
		Will then be used by runModaction() via getRows*() methods.
	*/
	function prepareRow( idx, rowElem ) {
		var $row = $( rowElem ),
			$difflink = $row.find( 'a' ).first(),
			modid = new mw.Uri( $difflink.attr( 'href' ) ).query.modid,
			$userlink = $row.find( '.mw-userlink' ),
			username = $userlink.text();

		$row.data( {
			user: username,
			modid: modid
		} );

		$row.addClass( 'modstatus-untouched' )
			.prepend( $( '<span/>' ).addClass( 'modicon' ) );
	}

	/**
		@brief Modify status icon of $rows.
		@param type One of the following: untouched, processing, approved, rejected, error.
		@param tooltip Text shown when hovering over the icon.
	*/
	function setRowsStatus( $rows, type, tooltip ) {
		$rows.attr( 'class', 'modline modstatus-' + type )
		$rows.find( '.modicon' ).attr( 'title', tooltip );
	}

	/**
		@brief Enable/disable action links in $rows.
		@param shouldBeEnabled If true, links will be enabled. If false, they will be disabled.
		@param actions Array, e.g. [ 'approve', 'reject' ]. If contains 'ALL', all links are affected.
	*/
	function toggleLinks( $rows, shouldBeEnabled, actions ) {
		if ( !actions.length ) {
			return; /* Nothing to do */
		}

		var $links = $rows.find( 'a[data-modaction]' );

		if ( actions[0] !== 'ALL' ) {
			$links = $links.filter( function( idx, linkElem ) {
				var action = $( linkElem ).attr( 'data-modaction' );
				return ( actions.indexOf( action ) != -1 );
			} );
		}

		/*
			We maintain a counter of how many times was the link disabled.
			If the link was first disabled by a successful action (e.g. Reject),
			and then another action (e.g. Accept) resulted in an error,
			we shouldn't re-enable the links which were disabled by the first action.
		*/
		$links.each( function( idx, linkElem ) {
			var $link = $( linkElem ),
				cnt = $link.data( 'disabled-count' );

			if ( !cnt ) {
				cnt = 0;
			}

			if ( shouldBeEnabled ) {
				if ( cnt > 0 ) {
					cnt --;
				}

				if ( cnt == 0 ) {
					$link.removeClass( 'modlink-disabled' );
				}
			}
			else {
				cnt ++;
				$link.addClass( 'modlink-disabled' );
			}

			$link.data( 'disabled-count', cnt );
		} );
	}

	/**
		@brief Determine which links should be disabled by successful action.
		@param action Action name, e.g. 'approve' or 'reject'.
	*/
	function getDisabledActions( action ) {
		var disabledActions = [];
		switch ( action ) {
			case 'approve':
			case 'approveall':
				/* Approval completely deletes the row from Special:Moderation,
					all actions (even "diff") won't be applicable */
				disabledActions = [ 'ALL' ];
				break;

			case 'reject':
			case 'rejectall':
				/* Rejected edit can still be approved, but not via "Approve all" */
				disabledActions = [
					'reject',
					'rejectall',
					'approveall'
				];
				break;
		}

		return disabledActions;
	}


	/**
		@brief Mark the edit as rejected. Called if Reject(all) returned success.
		@param $rows List of .modline elements.
	*/
	function markRejected( $rows ) {
		setRowsStatus( $rows, 'rejected', '' ); /* TODO: add tooltip */
	}

	/**
		@brief Mark the edit as approved. Called if Approve(all) returned success.
		@param $rows List of .modline elements.
	*/
	function markApproved( $rows ) {
		setRowsStatus( $rows, 'approved', '' ); /* TODO: add tooltip */
	}

	/**
		@brief Mark the row as unsuccessfully modified.
	*/
	function markError( $rows, errorText, action ) {
		setRowsStatus( $rows, 'error', errorText );

		/* Re-enable disabledActions, unless they were disabled before. */
		toggleLinks( $rows, true, getDisabledActions( action ) );
	}

	/**
		@brief Update Special:Moderation after a successful Ajax call.
		@param $row The .modline element where we clicked on the action link (e.g. Reject).
		@param q Query, e.g. { modid: 123, modaction: 'reject' }
		@param ret Parsed JSON response, as returned by the API.
	*/
	function handleSuccess( $row, q, ret ) {
		switch ( q.modaction ) {
			case 'reject':
				markRejected( $row );
				break;

			case 'rejectall':
				markRejected( getRowsWithSameUser( $row ) );
				break;

			case 'approve':
				markApproved( $row );
				break;

			case 'approveall':
				var modid;
				for ( modid in ret.moderation.failed ) {
					markError(
						getRowById( modid ),
						ret.moderation.failed[modid].info,
						'approveall'
					);
				}

				for ( modid in ret.moderation.approved ) {
					markApproved( getRowById( modid ) );
				}

				break;

			default:
				console.log( 'Post-Ajax handler: not implemented for modaction="' + q.modaction + '"' );
		}
	}

	/**
		@brief Handle the click on modaction link (e.g. "Reject").
	*/
	function runModaction( ev ) {
		ev.preventDefault();

		var $link = $( this );
		if ( $link.hasClass( 'modlink-disabled' ) ) {
			return; /* Action is no longer possible, e.g. "Reject" on already approved row */
		}

		var $row = $( this ).parent( '.modline' );

		/* Prepare API parameters */
		var q = {
			action: 'moderation',
			modaction: $link.attr( 'data-modaction' ),
			modid: $row.data( 'modid' )
		};

		var $affectedRows = $row; /* Rows that need "processing" icon */
		if ( q.modaction == 'approveall' || q.modaction == 'rejectall' ) {
			$affectedRows = getRowsWithSameUser( $row );
		}

		setRowsStatus( $affectedRows, 'processing', '...' );

		/* Disable action links that will no longer be applicable after this action.
			For example, after the row is approved, it can no longer be rejected. */
		toggleLinks( $affectedRows, false, getDisabledActions( q.modaction ) );

		api.postWithToken( 'edit', q )
			.done( function( ret ) {
				handleSuccess( $row, q, ret );
			} )
			.fail( function( code, ret ) {
				console.log( 'Moderation: ajax error: ', JSON.stringify( ret ) );
				markError( $affectedRows, ret.error.info, q.modaction );
			} );
	}

	/* Scan rows on Special:Moderation, install Ajax handlers */
	$allRows.each( prepareRow )
		.find( 'a[href*="modaction"]' )
			.each( function( idx, linkElem ) {
				/* Extract modaction from href */
				$( linkElem ).attr( 'data-modaction',
					new mw.Uri( linkElem.href ).query.modaction
				);
			} )
			.click( runModaction );

}( mediaWiki, jQuery ) );
