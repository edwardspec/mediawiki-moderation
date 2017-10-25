<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015-2017 Edward Chernenko.

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
	@brief Implements HTML parsing methods for the automated testsuite.
*/

class ModerationTestsuiteHTML extends DOMDocument {

	/** @brief Libxml error code for "unknown tag", see http://www.xmlsoft.org/html/libxml-xmlerror.html */
	const XML_HTML_UNKNOWN_TAG = 801;

	private $t; # ModerationTestsuite
	function __construct( ModerationTestsuite $t ) {
		$this->t = $t;
	}

	public function loadFromURL( $url ) {
		if ( !$url ) return;

		$this->t->ignoreHttpError[404] = true;
		$req = $this->t->httpGet( $url );
		$this->t->ignoreHttpError[404] = false;

		return $this->loadFromReq( $req );
	}

	public function loadFromReq( MWHttpRequest $req ) {
		return $this->loadFromString( $req->getContent() );
	}

	/**
		@brief Utility function to warn about Libxml errors.
		Ignores the fact that some HTML5 tags are unknown to libxml2.
	*/
	public static function checkLibxmlErrors() {
		$errors = libxml_get_errors();
		foreach ( $errors as $error ) {
			if ( $error->code == self::XML_HTML_UNKNOWN_TAG ) {
				/* Ignore: libxml considers modern tags like <bdi> to be errors */
				continue;
			}

			print "LibXML error: " . $error->message . "\n";
		}

		libxml_clear_errors();
	}

	public function loadFromString( $string )
	{
		/* Ignore "unknown tag" error, see checkLibxmlErrors() for details */
		libxml_use_internal_errors( true );

		$this->loadHTML( $string );
		self::checkLibxmlErrors();

		return $this;
	}

	public function getTitle( $url = null )
	{
		$this->loadFromURL( $url );

		return $this->
			getElementsByTagName( 'title' )->item( 0 )->textContent;
	}

	public function getModerationError( $url = null )
	{
		$this->loadFromURL( $url );

		$elem = $this->getElementById( 'mw-mod-error' );
		if ( !$elem )
			return null;

		return $elem->textContent;
	}

	public function getMainContent( $url = null )
	{
		$this->loadFromURL( $url );

		return $this->getElementById( 'mw-content-text' );
	}

	public function getMainText( $url = null )
	{
		$elem = $this->getMainContent( $url );

		return trim( $elem->textContent );
	}

	/**
		@brief Find the DOM element by XPath selector.
		E.g. $t->html->getElementsByXPath( '//row[@name="wpSummary"]' )
		@returns DOMElement
	*/
	public function getElementByXPath( $selector ) {
		$xpath = new DomXpath( $this );
		return $xpath->query( $selector )->item( 0 );
	}


	/**
		@brief Fetch the edit form and return the text in #wpTextbox1.
		@param title The page to be opened for editing.
	*/
	public function getPreloadedText( $title )
	{
		$url = wfAppendQuery( wfScript( 'index' ), [
			'title' => $title,
			'action' => 'edit'
		] );
		$this->loadFromURL( $url );

		$elem = $this->getElementById( 'wpTextbox1' );
		if ( !$elem )
			return null;

		return trim( $elem->textContent );
	}

	/**
		@brief Return the list of ResourceLoader modules
			which are used in the last fetched HTML.
	*/
	public function getLoaderModulesList( $url = null )
	{
		$this->loadFromURL( $url );
		$scripts = $this->getElementsByTagName( 'script' );

		$list = [];
		foreach ( $scripts as $script )
		{
			$matches = null;
			if ( preg_match( '/mw\.loader\.load\(\[([^]]+)\]/', $script->textContent, $matches ) )
			{
				$items = explode( ',', $matches[1] );

				foreach ( $items as $item )
				{
					$list[] = preg_replace( '/^"(.*)"$/', '$1', $item );
				}
			}
		}
		return array_unique( $list );
	}

	/**
		@brief Return the array of <input> elements in the form
			(name => value).
	*/
	public function getFormElements( $formElement = null, $url = null )
	{
		$this->loadFromURL( $url );

		if ( !$formElement ) {
			$formElement = $this;
		}

		$inputs = $formElement->getElementsByTagName( 'input' );
		$result = [];
		foreach ( $inputs as $input )
		{
			$name = $input->getAttribute( 'name' );
			$value = $input->getAttribute( 'value' );

			$result[$name] = $value;
		}
		return $result;
	}
}
