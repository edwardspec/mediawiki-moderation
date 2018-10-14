<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2016-2018 Edward Chernenko.

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
 * API to preload edits which are pending moderation.
 *
 * This can be used by API-based JavaScript editors,
 * for example Extension:VisualEditor or Extension:MobileFrontend.
 */
class ApiQueryModerationPreload extends ApiQueryBase {

	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'mp' );
	}

	public function execute() {
		$params = $this->extractRequestParams();

		$page = $this->getTitleOrPageId( $params );
		$title = $page->getTitle();

		/* Prepare the result array */
		$r = [
			'user' => $this->getUser()->getName(),
			'title' => $title->getFullText(),
			'pageid' => $title->getArticleID()
		];

		/* Load text which is currently awaiting moderation */
		$row = ModerationPreload::singleton()->loadUnmoderatedEdit( $title );
		if ( !$row ) {
			$r['missing'] = ''; /* There is no pending edit */
		} else {
			$wikitext = $row->text;

			if ( isset( $params['section'] ) ) {
				/* Only one section should be preloaded */
				$fullContent = ContentHandler::makeContent( $wikitext, $title );
				$sectionContent = $fullContent->getSection( $params['section'] );

				if ( $sectionContent ) {
					$wikitext = $sectionContent->getNativeData();
				}
			}

			if ( $params['mode'] == 'wikitext' ) {
				$r['wikitext'] = $wikitext;
			} elseif ( $params['mode'] == 'parsed' ) {
				$r['parsed'] = $this->parse( $title, $wikitext );
			}

			$r['comment'] = $row->comment;
		}

		$this->getResult()->addValue( 'query', $this->getModuleName(), $r );
	}

	/**
	 * Parse $wikitext and return the results.
	 * @return array with keys 'text', 'categorieshtml', 'displaytitle'
	 */
	protected function parse( Title $title, $wikitext ) {
		$apiParams = [
			'action' => 'parse',
			'text' => $wikitext,
			'title' => $title->getFullText(),
			'prop' => 'text|categorieshtml|displaytitle',
			'disablepp' => ''
		];

		$api = new ApiMain(
			new DerivativeRequest(
				$this->getRequest(),
				$apiParams,
				false // GET request
			)
		);
		$api->execute();

		$ret = $api->getResult()->getResultData( null, [
			'Strip' => 'all'
		] );

		$parsed = [];
		$parsed['text'] = $ret['parse']['text'];
		$parsed['categorieshtml'] = $ret['parse']['categorieshtml'];
		$parsed['displaytitle'] = $ret['parse']['displaytitle'];

		return $parsed;
	}

	public function getAllowedParams() {
		return [
			'mode' => [
				ApiBase::PARAM_DFLT => 'wikitext',
				ApiBase::PARAM_TYPE => [ 'wikitext', 'parsed' ]
			],
			'title' => null,
			'pageid' => [
				ApiBase::PARAM_TYPE => 'integer'
			],
			'section' => null
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=query&prop=moderationpreload&mptitle=Cat'
				=> 'apihelp-query+moderationpreload-wikitext-example',
			'action=query&prop=moderationpreload&mptitle=Dog&mpmode=parsed'
				=> 'apihelp-query+moderationpreload-parsed-example',
			'action=query&prop=moderationpreload&mptitle=Cat&mpsection=2'
				=> 'apihelp-query+moderationpreload-section-example',
		];
	}
}
