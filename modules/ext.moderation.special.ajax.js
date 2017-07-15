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

		/* FIXME: ignore rows with 'approved' or 'rejected' status,
			because they are not affected by ApproveAll/RejectAll.
		*/

		return getRowsByData( 'user', $row.data( 'user' ) );
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
		} )

		/* Prepare status icon */
		$row.prepend( $( '<span/>' ).addClass( 'modstatus' ) );
	}

	/**
		@brief Modify status icon of $rows.
		@param type One of the following: untouched, processing, approved, rejected, error.
		@param tooltip Text shown when hovering over the icon.
	*/
	function setRowsStatus( $rows, type, tooltip ) {
		$rows.find( '.modstatus' )
			.attr( 'class', 'modstatus modstatus-' + type )
			.attr( 'title', tooltip );

		if ( type != 'untouched' && type != 'processing' && type != 'error' ) {
			$rows.addClass( 'modline-done' );
		}
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
					setRowsStatus(
						getRowById( modid ),
						'error',
						ret.moderation.failed[modid].info
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

		var $row = $( this ).parent( '.modline' );

		/* Translate the URI (e.g. modaction=approve&modid=123) into the API parameters */
		var q = new mw.Uri( this.href ).query;
		q.action = 'moderation';
		delete q.title;
		delete q.token;

		var $affectedRows = $row; /* Rows that need "processing" icon */
		if ( q.modaction == 'approveall' || q.modaction == 'rejectall' ) {
			$affectedRows = getRowsWithSameUser( $row );
		}

		setRowsStatus( $affectedRows, 'processing', '...' );

		api.postWithToken( 'edit', q )
			.done( function( ret ) {
				handleSuccess( $row, q, ret );
			} )
			.fail( function( code, ret ) {
				console.log( 'Moderation: ajax error: ', JSON.stringify( ret ) );
				setRowsStatus( $affectedRows, 'error', ret.error.info );
			} );
	}

	/* Scan rows on Special:Moderation, install Ajax handlers */
	$allRows.each( prepareRow )
		.find( 'a[href*="modaction"]' ).click( runModaction );

	setRowsStatus( $allRows, 'untouched', '' );

}( mediaWiki, jQuery ) );
