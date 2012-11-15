<?php
/**
 * Implements Special:Import
 *
 * Copyright © 2003,2005 Brion Vibber <brion@pobox.com>
 * http://www.mediawiki.org/
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup SpecialPage
 */

class ImportSource {
	/**
	 * @param $fieldname string
	 * @return Status
	 */
	static function newFromUpload( $fieldname = "xmlimport" ) {
		$upload =& $_FILES[$fieldname];

		if( !isset( $upload ) || !$upload['name'] ) {
			return Status::newFatal( 'importnofile' );
		}
		if( !empty( $upload['error'] ) ) {
			switch($upload['error']){
				case 1: # The uploaded file exceeds the upload_max_filesize directive in php.ini.
					return Status::newFatal( 'importuploaderrorsize' );
				case 2: # The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.
					return Status::newFatal( 'importuploaderrorsize' );
				case 3: # The uploaded file was only partially uploaded
					return Status::newFatal( 'importuploaderrorpartial' );
				case 6: #Missing a temporary folder.
					return Status::newFatal( 'importuploaderrortemp' );
				# case else: # Currently impossible
			}

		}
		$fname = $upload['tmp_name'];
		if( is_uploaded_file( $fname ) ) {
			return Status::newGood( DumpArchive::newFromFile( $fname, $upload['name'] ) );
		} else {
			return Status::newFatal( 'importnofile' );
		}
	}

	/**
	 * @param $url
	 * @param $method string
	 * @return Status
	 */
	static function newFromURL( $url, $method = 'GET' ) {
		wfDebug( __METHOD__ . ": opening $url\n" );
		# Use the standard HTTP fetch function; it times out
		# quicker and sorts out user-agent problems which might
		# otherwise prevent importing from large sites, such
		# as the Wikimedia cluster, etc.
		$data = Http::request( $method, $url, array( 'followRedirects' => true ) );
		if( $data !== false ) {
			$file = tmpfile();
			fwrite( $file, $data );
			fflush( $file );
			fseek( $file, 0 );
			return Status::newGood( DumpArchive::newFromFile( $file ) );
		} else {
			return Status::newFatal( 'importcantopen' );
		}
	}

	/**
	 * @param $interwiki
	 * @param $page
	 * @param $history bool
	 * @param $templates bool
	 * @param $pageLinkDepth int
	 * @return Status
	 */
	static function newFromInterwiki( $interwiki, $page, $history = false, $templates = false, $pageLinkDepth = 0 ) {
		if( $page == '' ) {
			return Status::newFatal( 'import-noarticle' );
		}
		$link = Title::newFromText( "$interwiki:Special:Export/$page" );
		if( is_null( $link ) || $link->getInterwiki() == '' ) {
			return Status::newFatal( 'importbadinterwiki' );
		} else {
			$params = array();
			if ( $history ) $params['history'] = 1;
			if ( $templates ) $params['templates'] = 1;
			if ( $pageLinkDepth ) $params['pagelink-depth'] = $pageLinkDepth;
			$url = $link->getFullUrl( $params );
			# For interwikis, use POST to avoid redirects.
			return ImportStreamSource::newFromURL( $url, "POST" );
		}
	}

}

/**
 * MediaWiki page data importer
 *
 * @ingroup SpecialPage
 */
class SpecialImport extends SpecialPage {

	private $interwiki = false;
	private $namespace;
	private $frompage = '';
	private $logcomment= false;
	private $history = true;
	private $includeTemplates = false;
	private $pageLinkDepth;

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'Import', 'import' );
		global $wgImportTargetNamespace;
		$this->namespace = $wgImportTargetNamespace;
	}

	/**
	 * Execute
	 */
	function execute( $par ) {
		global $wgRequest, $wgUser, $wgOut;

		$this->setHeaders();
		$this->outputHeader();

		if ( wfReadOnly() ) {
			$wgOut->readOnlyPage();
			return;
		}

		if( !$wgUser->isAllowedAny( 'import', 'importupload' ) ) {
			return $wgOut->permissionRequired( 'import' );
		}

		# @todo Allow Title::getUserPermissionsErrors() to take an array
		# @todo FIXME: Title::checkSpecialsAndNSPermissions() has a very wierd expectation of what
		# getUserPermissionsErrors() might actually be used for, hence the 'ns-specialprotected'
		$errors = wfMergeErrorArrays(
			$this->getTitle()->getUserPermissionsErrors(
				'import', $wgUser, true,
				array( 'ns-specialprotected', 'badaccess-group0', 'badaccess-groups' )
			),
			$this->getTitle()->getUserPermissionsErrors(
				'importupload', $wgUser, true,
				array( 'ns-specialprotected', 'badaccess-group0', 'badaccess-groups' )
			)
		);

		if( $errors ){
			$wgOut->showPermissionsErrorPage( $errors );
			return;
		}

		if ( $wgRequest->wasPosted() && $wgRequest->getVal( 'action' ) == 'submit' ) {
			$this->doImport();
		}
		$this->showForm();
	}

	/**
	 * Do the actual import
	 */
	private function doImport() {
		global $wgOut, $wgRequest, $wgUser, $wgImportSources, $wgExportMaxLinkDepth;
		$isUpload = false;
		$this->namespace = $wgRequest->getIntOrNull( 'namespace' );
		$sourceName = $wgRequest->getVal( "source" );

		$this->logcomment = $wgRequest->getText( 'log-comment' );
		$this->pageLinkDepth = $wgExportMaxLinkDepth == 0 ? 0 : $wgRequest->getIntOrNull( 'pagelink-depth' );

		if ( !$wgUser->matchEditToken( $wgRequest->getVal( 'editToken' ) ) ) {
			$importer = Status::newFatal( 'import-token-mismatch' );
		} elseif ( $sourceName == 'upload' ) {
			$isUpload = true;
			if( $wgUser->isAllowed( 'importupload' ) ) {
				$importer = ImportSource::newFromUpload( "xmlimport" );
			} else {
				return $wgOut->permissionRequired( 'importupload' );
			}
		} elseif ( $sourceName == "interwiki" ) {
			if( !$wgUser->isAllowed( 'import' ) ){
				return $wgOut->permissionRequired( 'import' );
			}
			$this->interwiki = $wgRequest->getVal( 'interwiki' );
			if ( !in_array( $this->interwiki, $wgImportSources ) ) {
				$importer = Status::newFatal( "import-invalid-interwiki" );
			} else {
				$this->history = $wgRequest->getCheck( 'interwikiHistory' );
				$this->frompage = $wgRequest->getText( "frompage" );
				$this->includeTemplates = $wgRequest->getCheck( 'interwikiTemplates' );
				$importer = ImportSource::newFromInterwiki(
					$this->interwiki,
					$this->frompage,
					$this->history,
					$this->includeTemplates,
					$this->pageLinkDepth );
			}
		} else {
			$importer = Status::newFatal( "importunknownsource" );
		}
		if( !$importer ) {
			$importer = Status::newFatal( "importunknownformat" );
		}

		if( !$importer->isGood() ) {
			$wgOut->wrapWikiMsg( "<p class=\"error\">\n$1\n</p>", array( 'importfailed', $importer->getWikiText() ) );
		} else {
			$wgOut->addWikiMsg( "importstart" );

			$importer = $importer->value;
			if( !is_null( $this->namespace ) ) {
				$importer->setTargetNamespace( $this->namespace );
			}
			$reporter = new ImportReporter( $importer, $isUpload, $this->interwiki , $this->logcomment);
			$exception = false;

			$reporter->open();
			try {
				$importer->doImport();
			} catch ( MWException $e ) {
				$exception = $e;
			}
			$result = $reporter->close();

			if ( $exception ) {
				# No source or XML parse error
				$wgOut->wrapWikiMsg( "<p class=\"error\">\n$1\n</p>", array( 'importfailed', $exception->getMessage() ) );
			} elseif( !$result->isGood() ) {
				# Zero revisions
				$wgOut->wrapWikiMsg( "<p class=\"error\">\n$1\n</p>", array( 'importfailed', $result->getWikiText() ) );
			} else {
				# Success!
				$wgOut->addWikiMsg( 'importsuccess' );
			}
			$wgOut->addHTML( '<hr />' );
		}
	}

	private function showForm() {
		global $wgUser, $wgOut, $wgImportSources, $wgExportMaxLinkDepth;

		$action = $this->getTitle()->getLocalUrl( array( 'action' => 'submit' ) );

		if( $wgUser->isAllowed( 'importupload' ) ) {
			$wgOut->addWikiMsg( "importtext" );
			$wgOut->addHTML(
				Xml::fieldset( wfMsg( 'import-upload' ) ).
				Xml::openElement( 'form', array( 'enctype' => 'multipart/form-data', 'method' => 'post',
					'action' => $action, 'id' => 'mw-import-upload-form' ) ) .
				Html::hidden( 'action', 'submit' ) .
				Html::hidden( 'source', 'upload' ) .
				Xml::openElement( 'table', array( 'id' => 'mw-import-table' ) ) .

				"<tr>
					<td class='mw-label'>" .
						Xml::label( wfMsg( 'import-upload-filename' ), 'xmlimport' ) .
					"</td>
					<td class='mw-input'>" .
						Xml::input( 'xmlimport', 50, '', array( 'type' => 'file' ) ) . ' ' .
					"</td>
				</tr>
				<tr>
					<td class='mw-label'>" .
						Xml::label( wfMsg( 'import-comment' ), 'mw-import-comment' ) .
					"</td>
					<td class='mw-input'>" .
						Xml::input( 'log-comment', 50, '',
							array( 'id' => 'mw-import-comment', 'type' => 'text' ) ) . ' ' .
					"</td>
				</tr>
				<tr>
					<td></td>
					<td class='mw-submit'>" .
						Xml::submitButton( wfMsg( 'uploadbtn' ) ) .
					"</td>
				</tr>" .
				Xml::closeElement( 'table' ).
				Html::hidden( 'editToken', $wgUser->editToken() ) .
				Xml::closeElement( 'form' ) .
				Xml::closeElement( 'fieldset' )
			);
		} else {
			if( empty( $wgImportSources ) ) {
				$wgOut->addWikiMsg( 'importnosources' );
			}
		}

		if( $wgUser->isAllowed( 'import' ) && !empty( $wgImportSources ) ) {
			# Show input field for import depth only if $wgExportMaxLinkDepth > 0
			$importDepth = '';
			if( $wgExportMaxLinkDepth > 0 ) {
				$importDepth = "<tr>
							<td class='mw-label'>" .
								wfMsgExt( 'export-pagelinks', 'parseinline' ) .
							"</td>
							<td class='mw-input'>" .
								Xml::input( 'pagelink-depth', 3, 0 ) .
							"</td>
						</tr>";
			}

			$wgOut->addHTML(
				Xml::fieldset(  wfMsg( 'importinterwiki' ) ) .
				Xml::openElement( 'form', array( 'method' => 'post', 'action' => $action, 'id' => 'mw-import-interwiki-form' ) ) .
				wfMsgExt( 'import-interwiki-text', array( 'parse' ) ) .
				Html::hidden( 'action', 'submit' ) .
				Html::hidden( 'source', 'interwiki' ) .
				Html::hidden( 'editToken', $wgUser->editToken() ) .
				Xml::openElement( 'table', array( 'id' => 'mw-import-table' ) ) .
				"<tr>
					<td class='mw-label'>" .
						Xml::label( wfMsg( 'import-interwiki-source' ), 'interwiki' ) .
					"</td>
					<td class='mw-input'>" .
						Xml::openElement( 'select', array( 'name' => 'interwiki' ) )
			);
			foreach( $wgImportSources as $prefix ) {
				$selected = ( $this->interwiki === $prefix ) ? ' selected="selected"' : '';
				$wgOut->addHTML( Xml::option( $prefix, $prefix, $selected ) );
			}

			$wgOut->addHTML(
						Xml::closeElement( 'select' ) .
						Xml::input( 'frompage', 50, $this->frompage ) .
					"</td>
				</tr>
				<tr>
					<td>
					</td>
					<td class='mw-input'>" .
						Xml::checkLabel( wfMsg( 'import-interwiki-history' ), 'interwikiHistory', 'interwikiHistory', $this->history ) .
					"</td>
				</tr>
				<tr>
					<td>
					</td>
					<td class='mw-input'>" .
						Xml::checkLabel( wfMsg( 'import-interwiki-templates' ), 'interwikiTemplates', 'interwikiTemplates', $this->includeTemplates ) .
					"</td>
				</tr>
				$importDepth
				<tr>
					<td class='mw-label'>" .
						Xml::label( wfMsg( 'import-interwiki-namespace' ), 'namespace' ) .
					"</td>
					<td class='mw-input'>" .
						Xml::namespaceSelector( $this->namespace, '' ) .
					"</td>
				</tr>
				<tr>
					<td class='mw-label'>" .
						Xml::label( wfMsg( 'import-comment' ), 'mw-interwiki-comment' ) .
					"</td>
					<td class='mw-input'>" .
						Xml::input( 'log-comment', 50, '',
							array( 'id' => 'mw-interwiki-comment', 'type' => 'text' ) ) . ' ' .
					"</td>
				</tr>
				<tr>
					<td>
					</td>
					<td class='mw-submit'>" .
						Xml::submitButton( wfMsg( 'import-interwiki-submit' ), Linker::tooltipAndAccesskeyAttribs( 'import' ) ) .
					"</td>
				</tr>" .
				Xml::closeElement( 'table' ).
				Xml::closeElement( 'form' ) .
				Xml::closeElement( 'fieldset' )
			);
		}
	}
}

/**
 * Reporting callback
 * @ingroup SpecialPage
 */
class ImportReporter {
	private $reason=false;
	private $mOriginalLogCallback = null;
	private $mOriginalPageOutCallback = null;
	private $mLogItemCount = 0;

	function __construct( $importer, $upload, $interwiki , $reason=false ) {
		$this->mOriginalPageOutCallback =
				$importer->setPageOutCallback( array( $this, 'reportPage' ) );
		$this->mOriginalLogCallback =
			$importer->setLogItemCallback( array( $this, 'reportLogItem' ) );
		$this->mPageCount = 0;
		$this->mIsUpload = $upload;
		$this->mInterwiki = $interwiki;
		$this->reason = $reason;
	}

	function open() {
		global $wgOut;
		$wgOut->addHTML( "<ul>\n" );
	}

	function reportLogItem( /* ... */ ) {
		$this->mLogItemCount++;
		if ( is_callable( $this->mOriginalLogCallback ) ) {
			call_user_func_array( $this->mOriginalLogCallback, func_get_args() );
		}
	}

	/**
	 * @param Title $title
	 * @param Title $origTitle
	 * @param int $revisionCount
	 * @param  $successCount
	 * @param  $pageInfo
	 * @return void
	 */
	function reportPage( $title, $origTitle, $revisionCount, $successCount, $pageInfo ) {
		global $wgOut, $wgUser, $wgLang, $wgContLang;

		$args = func_get_args();
		call_user_func_array( $this->mOriginalPageOutCallback, $args );

		$skin = $wgUser->getSkin();

		$this->mPageCount++;

		$localCount = $wgLang->formatNum( $successCount );
		$contentCount = $wgContLang->formatNum( $successCount );
		$lastRevision = $pageInfo['lastRevision'];
		$lastExistingRevision = $pageInfo['lastExistingRevision'];
		$lastLocalRevision = $pageInfo['lastLocalRevision'];

		/* No revisions in import */
		if ( !$lastExistingRevision && $successCount == 0 ) {
			$msg = wfMsgHtml( 'import-norevisions' );
		} elseif ( !$lastLocalRevision && $successCount > 0 ) {
			// New page imported
			$msg = wfMsgExt( 'import-revision-count-newpage', array( 'parsemag', 'escape' ), $localCount );
		} else {
			$newer = !$lastExistingRevision ||
				$lastLocalRevision->getTimestamp() > $lastExistingRevision->getTimestamp();
			if ( $successCount > 0 ) {
				if ( $newer ) {
					// "Conflict"
					$linktext = wfMsgExt( 'import-conflict-difflink',
						array( 'parsemag', 'escape' ),
						$lastRevision->getId(),
						$lastLocalRevision->getId() );
					$link = $skin->makeKnownLinkObj(
						$title, $linktext,
						'diff=' . $lastRevision->getId() .
						"&oldid=" . $lastLocalRevision->getId() );
					$msg = wfMsgExt( 'import-conflict',
						array( 'parsemag' ),
						$localCount,
						$link );
				} else {
					// Page history continued with new revisions
					$msg = wfMsgExt( 'import-revision-count', array( 'parsemag', 'escape' ), $localCount );
				}
			} else {
				if ( $newer ) {
					// Local revision is newer
					$msg = wfMsgHtml( 'import-nonewrevisions-localnewer' );
				} else {
					// No changes nowhere
					$msg = wfMsgHtml( 'import-nonewrevisions' );
				}
			}
		}
		if ( isset( $pageInfo[ 'fileRevisionsUploaded' ] ) ) {
			$msg .= wfMsgExt( 'import-file-revisions', array( 'parsemag', 'escape' ), $pageInfo[ 'fileRevisionsUploaded' ] );
		}

		$msg = $skin->makeKnownLinkObj( $title ) . ': ' . $msg;

		$wgOut->addHtml( "<li>$msg</li>" );

		if( $successCount > 0 ) {
			$log = new LogPage( 'import' );
			if( $this->mIsUpload ) {
				$detail = wfMsgExt( 'import-logentry-upload-detail', array( 'content', 'parsemag' ),
					$contentCount );
				if ( $this->reason ) {
					$detail .=  wfMsgForContent( 'colon-separator' ) . $this->reason;
				}
				$log->addEntry( 'upload', $title, $detail );
			} else {
				$interwiki = '[[:' . $this->mInterwiki . ':' .
					$origTitle->getPrefixedText() . ']]';
				$detail = wfMsgExt( 'import-logentry-interwiki-detail', array( 'content', 'parsemag' ),
					$contentCount, $interwiki );
				if ( $this->reason ) {
					$detail .=  wfMsgForContent( 'colon-separator' ) . $this->reason;
				}
				$log->addEntry( 'interwiki', $title, $detail );
			}
			// [MediaWiki4Intranet] do not insert any empty revisions because it leads
			// to fancy bugs (infinitely multiplicated revisions) in the case of cross
			// (2-way) import-export.
		}
	}

	function close() {
		global $wgOut, $wgLang;

		if ( $this->mLogItemCount > 0 ) {
			$msg = wfMsgExt( 'imported-log-entries', 'parseinline',
						$wgLang->formatNum( $this->mLogItemCount ) );
			$wgOut->addHTML( Xml::tags( 'li', null, $msg ) );
		} elseif( $this->mPageCount == 0 && $this->mLogItemCount == 0 ) {
			$wgOut->addHTML( "</ul>\n" );
			return Status::newFatal( 'importnopages' );
		}
		$wgOut->addHTML( "</ul>\n" );

		return Status::newGood( $this->mPageCount );
	}
}
