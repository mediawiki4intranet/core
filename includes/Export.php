<?php
/**
 * Classes for dumps and export
 *
 * Copyright © 2003, 2005, 2006 Brion Vibber <brion@pobox.com>
 * Copyright © 2012+ Vitaliy Filippov <vitalif@mail.ru>
 * https://www.mediawiki.org/
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
 */

/**
 * @defgroup Dump Dump
 */

/**
 * @ingroup SpecialPage Dump
 */
class WikiExporter {
	var $listAuthors = false; # Return distinct author list (when not returning full history)
	var $exportFormat = 'xml'; # 'xml', 'xml-base64', 'multipart-zip'

	const FULL = 1;
	const CURRENT = 2;
	const STABLE = 4; // extension defined
	const LOGS = 8;
	const RANGE = 16;

	const BUFFER = 0;
	const STREAM = 1;

	const TEXT = 0;
	const STUB = 1;

	var $db, $history, $buffer, $text, $open;

	/**
	 * @var DumpWriter
	 */
	var $writer;

	/**
	 * @var DumpArchive
	 */
	var $sink;

	/**
	 * Returns the export schema version.
	 * @return string
	 */
	public static function schemaVersion() {
		return "0.10";
	}

	/**
	 * If using WikiExporter::STREAM to stream a large amount of data,
	 * provide a database connection which is not managed by
	 * LoadBalancer to read from: some history blob types will
	 * make additional queries to pull source data while the
	 * main query is still running.
	 *
	 * @param DatabaseBase $db
	 * @param int|array $history One of WikiExporter::FULL, WikiExporter::CURRENT,
	 *   WikiExporter::RANGE or WikiExporter::STABLE, or an associative array:
	 *   - offset: non-inclusive offset at which to start the query
	 *   - limit: maximum number of rows to return
	 *   - dir: "asc" or "desc" timestamp order
	 * @param int $buffer One of WikiExporter::BUFFER or WikiExporter::STREAM
	 * @param int $text One of WikiExporter::TEXT or WikiExporter::STUB
	 */
	function __construct( $db, $history = WikiExporter::CURRENT,
			$buffer = WikiExporter::BUFFER, $text = WikiExporter::TEXT,
			$listAuthors = false, $format = 'xml' ) {
		$this->db = $db;
		$this->history = $history;
		$this->buffer = $buffer;
		$this->text = $text;
		$this->open = true;
		$this->listAuthors = $listAuthors;
		$this->setDumpWriter( new XmlDumpWriter() );
		$this->exportFormat = $format === 'multipart-zip' || $format === 'xml-base64' ? $format : 'xml';
		$archiveClass = $format == 'multipart-zip' ? 'ZipDumpArchive' : 'StubDumpArchive';
		$this->sink = new $archiveClass();
		$this->sink->create( $this->writer->mimetype, $this->writer->extension );
	}

	public function setDumpWriter( DumpWriter $writer ) {
		$this->writer = $writer;
	}

	public function openStream() {
		$this->sink->write( $this->writer->openStream() );
	}

	public function closeStream() {
		$this->sink->write( $this->writer->closeStream() );
		$this->sink->close();
		$this->open = false;
	}

	/**
	 * Get the filename, MIME type and extension of resulting dump archive
	 * @param &$outFilename
	 * @param &$outMimetype
	 * @param &$outExtension
	 * @return boolean True on success, false on failure
	 */
	public function getArchive( &$outFilename, &$outMimetype, &$outExtension ) {
		if ( $this->open ) {
			return false;
		}
		return $this->sink->getArchive( $outFilename, $outMimetype, $outExtension );
	}

	/**
	 * Write information about uploaded files for page $row into output stream
	 *
	 * @param $row DB row for page
	 * @param int $limit Maximum recent versions to dump
	 */
	protected function writeUploads( $row, $limit = null ) {
		if( $row->page_namespace == NS_FILE ) {
			$img = wfFindFile( $row->page_title );
			if( $img ) {
				if ( !$limit || $limit > 1 ) {
					foreach( $img->getHistory( $limit ? $limit-1 : NULL ) as $ver ) {
						$this->sink->write( $this->writer->writeUpload(
							$ver, $this->sink->binUrl( $ver ), $this->exportFormat == 'xml-base64' ) );
						$this->sink->writeBinary( $ver );
					}
				}
				$this->sink->write( $this->writer->writeUpload(
					$img, $this->sink->binUrl( $img ), $this->exportFormat == 'xml-base64' ) );
				$this->sink->writeBinary( $img );
			}
		}
	}

	/**
	 * Dumps a series of page and revision records for all pages
	 * in the database, either including complete history or only
	 * the most recent version.
	 */
	public function allPages() {
		$this->dumpFrom( '' );
	}

	/**
	 * Dumps a series of page and revision records for those pages
	 * in the database falling within the page_id range given.
	 * @param int $start Inclusive lower limit (this id is included)
	 * @param int $end Exclusive upper limit (this id is not included)
	 *   If 0, no upper limit.
	 */
	public function pagesByRange( $start, $end ) {
		$condition = 'page_id >= ' . intval( $start );
		if ( $end ) {
			$condition .= ' AND page_id < ' . intval( $end );
		}
		$this->dumpFrom( $condition );
	}

	/**
	 * Dumps a series of page and revision records for those pages
	 * in the database with revisions falling within the rev_id range given.
	 * @param int $start Inclusive lower limit (this id is included)
	 * @param int $end Exclusive upper limit (this id is not included)
	 *   If 0, no upper limit.
	 */
	public function revsByRange( $start, $end ) {
		$condition = 'rev_id >= ' . intval( $start );
		if ( $end ) {
			$condition .= ' AND rev_id < ' . intval( $end );
		}
		$this->dumpFrom( $condition );
	}

	/**
	 * @param Title $title
	 */
	public function pageByTitle( $title ) {
		$this->dumpFrom(
			'page_namespace=' . $title->getNamespace() .
			' AND page_title=' . $this->db->addQuotes( $title->getDBkey() ) );
	}

	/**
	 * @param $titles array(string|Title)
	 */
	public function pagesByTitles( $titles ) {
		$ids = array();
		foreach ( $titles as $title ) {
			if ( !is_object( $title ) ) {
				$title = Title::newFromText( $title );
			}
			$ids[ intval( $title->getArticleId() ) ] = true;
		}
		unset( $ids[0] );
		$this->dumpFrom( 'page_id IN ('.implode( ',', array_keys( $ids ) ).')' );
	}

	public function pagesByName( $names ) {
		$this->pagesByTitles( $names );
	}

	/**
	 * @param $name string
	 * @throws MWException
	 */
	public function pageByName( $name ) {
		$title = Title::newFromText( $name );
		if ( is_null( $title ) ) {
			throw new MWException( "Can't export invalid title" );
		} else {
			$this->pageByTitle( $title );
		}
	}

	public function allLogs() {
		$this->dumpFrom( '' );
	}

	/**
	 * @param int $start
	 * @param int $end
	 */
	public function logsByRange( $start, $end ) {
		$condition = 'log_id >= ' . intval( $start );
		if ( $end ) {
			$condition .= ' AND log_id < ' . intval( $end );
		}
		$this->dumpFrom( $condition );
	}

	/**
	 * Generates the distinct list of authors of an article
	 * Not called by default (depends on $this->listAuthors)
	 * Can be set by Special:Export when not exporting whole history
	 *
	 * @param array $cond
	 */
	protected function doListAuthors( $cond ) {
		// rev_deleted

		$res = $this->db->select(
			array( 'page', 'revision' ),
			array( 'DISTINCT rev_user_text', 'rev_user' ),
			array(
				$this->db->bitAnd( 'rev_deleted', Revision::DELETED_USER ) . ' = 0',
				$cond,
				'page_id = rev_id',
			),
			__METHOD__
		);

		$code = $this->writer->beginContributors();
		foreach ( $res as $row ) {
			$code .= $this->writer->writeContributor( $row->rev_user, $row->rev_user_text );
		}
		$code .= $this->writer->endContributors();
		return $code;
	}

	/**
	 * @param string $cond
	 * @throws MWException
	 * @throws Exception
	 */
	protected function dumpFrom( $cond = '' ) {
		# For logging dumps...
		if ( !is_array( $this->history ) && ( $this->history & self::LOGS ) ) {
			$where = array( 'user_id = log_user' );
			# Hide private logs
			$hideLogs = LogEventsList::getExcludeClause( $this->db );
			if ( $hideLogs ) {
				$where[] = $hideLogs;
			}
			# Add on any caller specified conditions
			if ( $cond ) {
				$where[] = $cond;
			}
			# Get logging table name for logging.* clause
			$logging = $this->db->tableName( 'logging' );

			if ( $this->buffer == WikiExporter::STREAM ) {
				$prev = $this->db->bufferResults( false );
			}
			$result = null; // Assuring $result is not undefined, if exception occurs early
			try {
				$result = $this->db->select( array( 'logging', 'user' ),
					array( "{$logging}.*", 'user_name' ), // grab the user name
					$where,
					__METHOD__,
					array( 'ORDER BY' => 'log_id', 'USE INDEX' => array( 'logging' => 'PRIMARY' ) )
				);
				$this->outputLogStream( $result );
				if ( $this->buffer == WikiExporter::STREAM ) {
					$this->db->bufferResults( $prev );
				}
			} catch ( Exception $e ) {
				// Throwing the exception does not reliably free the resultset, and
				// would also leave the connection in unbuffered mode.

				// Freeing result
				try {
					if ( $result ) {
						$result->free();
					}
				} catch ( Exception $e2 ) {
					// Already in panic mode -> ignoring $e2 as $e has
					// higher priority
				}

				// Putting database back in previous buffer mode
				try {
					if ( $this->buffer == WikiExporter::STREAM ) {
						$this->db->bufferResults( $prev );
					}
				} catch ( Exception $e2 ) {
					// Already in panic mode -> ignoring $e2 as $e has
					// higher priority
				}

				// Inform caller about problem
				throw $e;
			}
		# For page dumps...
		} else {
			$tables = array( 'page', 'revision' );
			$opts = array( 'ORDER BY' => 'page_id ASC' );
			$opts['USE INDEX'] = array();
			$join = array();
			if ( is_array( $this->history ) ) {
				# Time offset/limit for all pages/history...
				$revJoin = 'page_id=rev_page';
				# Set time order
				if ( $this->history['dir'] == 'asc' ) {
					$op = '>';
					$opts['ORDER BY'] = 'page_id ASC, rev_timestamp ASC';
				} else {
					$op = '<';
					$opts['ORDER BY'] = 'page_id ASC, rev_timestamp DESC';
				}
				# Set offset
				if ( !empty( $this->history['offset'] ) ) {
					$revJoin .= " AND rev_timestamp $op " .
						$this->db->addQuotes( $this->db->timestamp( $this->history['offset'] ) );
				}
				$join['revision'] = array( 'INNER JOIN', $revJoin );
				# Set query limit
				if ( !empty( $this->history['limit'] ) ) {
					$opts['LIMIT'] = intval( $this->history['limit'] );
				}
			} elseif ( $this->history & WikiExporter::FULL ) {
				# Full history dumps...
				$join['revision'] = array( 'INNER JOIN', 'page_id=rev_page' );
			} elseif ( $this->history & WikiExporter::CURRENT ) {
				# Latest revision dumps...
				if ( $this->listAuthors && $cond != '' )  { // List authors, if so desired
					$authors = $this->doListAuthors( $cond );
				}
				$join['revision'] = array( 'INNER JOIN', 'page_id=rev_page AND page_latest=rev_id' );
			} elseif ( $this->history & WikiExporter::STABLE ) {
				# "Stable" revision dumps...
				# Default JOIN, to be overridden...
				$join['revision'] = array( 'INNER JOIN', 'page_id=rev_page AND page_latest=rev_id' );
				# One, and only one hook should set this, and return false
				if ( Hooks::run( 'WikiExporter::dumpStableQuery', array( &$tables, &$opts, &$join ) ) ) {
					throw new MWException( __METHOD__ . " given invalid history dump type." );
				}
			} elseif ( $this->history & WikiExporter::RANGE ) {
				# Dump of revisions within a specified range
				$join['revision'] = array( 'INNER JOIN', 'page_id=rev_page' );
				$opts['ORDER BY'] = array( 'rev_page ASC', 'rev_id ASC' );
			} else {
				# Unknown history specification parameter?
				throw new MWException( __METHOD__ . " given invalid history dump type." );
			}
			# Query optimization hacks
			if ( $cond == '' ) {
				$opts[] = 'STRAIGHT_JOIN';
				$opts['USE INDEX']['page'] = 'PRIMARY';
			}
			# Build text join options
			if ( $this->text != WikiExporter::STUB ) { // 1-pass
				$tables[] = 'text';
				$join['text'] = array( 'INNER JOIN', 'rev_text_id=old_id' );
			}

			if ( $this->buffer == WikiExporter::STREAM ) {
				$prev = $this->db->bufferResults( false );
			}

			$result = null; // Assuring $result is not undefined, if exception occurs early
			try {
				Hooks::run( 'ModifyExportQuery',
						array( $this->db, &$tables, &$cond, &$opts, &$join ) );

				# Do the query!
				$result = $this->db->select( $tables, '*', $cond, __METHOD__, $opts, $join );
				# Output dump results
				$this->outputPageStream( $result, $this->listAuthors ? $authors : NULL );

				if ( $this->buffer == WikiExporter::STREAM ) {
					$this->db->bufferResults( $prev );
				}
			} catch ( Exception $e ) {
				// Throwing the exception does not reliably free the resultset, and
				// would also leave the connection in unbuffered mode.

				// Freeing result
				try {
					if ( $result ) {
						$result->free();
					}
				} catch ( Exception $e2 ) {
					// Already in panic mode -> ignoring $e2 as $e has
					// higher priority
				}

				// Putting database back in previous buffer mode
				try {
					if ( $this->buffer == WikiExporter::STREAM ) {
						$this->db->bufferResults( $prev );
					}
				} catch ( Exception $e2 ) {
					// Already in panic mode -> ignoring $e2 as $e has
					// higher priority
				}

				// Inform caller about problem
				throw $e;
			}
		}
	}

	/**
	 * Runs through a query result set dumping page and revision records.
	 * The result set should be sorted/grouped by page to avoid duplicate
	 * page records in the output.
	 *
	 * Should be safe for
	 * streaming (non-buffered) queries, as long as it was made on a
	 * separate database connection not managed by LoadBalancer; some
	 * blob storage types will make queries to pull source data.
	 *
	 * @param ResultWrapper $resultset
	 */
	protected function outputPageStream( $resultset, $authors = '' ) {
		$last = null;
		foreach ( $resultset as $row ) {
			// Run text filter
			wfRunHooks( 'ExportFilterText', array( &$row->old_text ) );
			if ( $last === null ||
				$last->page_namespace != $row->page_namespace ||
				$last->page_title != $row->page_title ) {
				if ( isset( $last ) ) {
					$this->writeUploads( $last, $this->history == WikiExporter::CURRENT ? 1 : null );
					$this->sink->write( $this->writer->closePage() );
				}
				$this->sink->write( $this->writer->openPage( $row ) );
				$last = $row;
			}
			$this->sink->write( $this->writer->writeRevision( $row ) );
		}
		if ( $last !== null ) {
			$this->writeUploads( $last, $this->history == WikiExporter::CURRENT ? 1 : null );
			$this->sink->write( $authors );
			$this->sink->write( $this->writer->closePage() );
		}
	}

	/**
	 * @param ResultWrapper $resultset
	 */
	protected function outputLogStream( $resultset ) {
		foreach ( $resultset as $row ) {
			$this->sink->write( $this->writer->writeLogItem( $row ) );
		}
	}
}

interface DumpWriter {

	public function openStream();
	public function closeStream();
	public function openPage( $row );
	public function closePage();
	public function writeRevision( $row );
	public function writeLogItem( $row );
	public function beginContributors();
	public function writeContributor( $id, $text, $indent = "      " );
	public function endContributors();
	public function writeUpload( File $file, $url, $dumpContents = false );

}

/**
 * WARNING: DO NOT use it to implement selection of pages to export!
 *
 * Page selection must be done OUTSIDE any dumper classes - it's MUCH faster.
 * DumpFilter is used, for example, to report export progress in the backup script.
 */
class DumpFilter implements DumpWriter {

	var $sink;

	public function __construct( DumpWriter $sink ) {
		$this->sink = $sink;
	}

	public function openStream() {
		return $this->sink->openStream();
	}

	public function closeStream() {
		return $this->sink->closeStream();
	}

	public function openPage( $row ) {
		return $this->sink->openPage( $row );
	}

	public function closePage() {
		return $this->sink->closePage();
	}

	public function writeRevision( $row ) {
		return $this->sink->writeRevision( $row );
	}

	public function writeLogItem( $row ) {
		return $this->sink->writeLogItem( $row );
	}

	public function beginContributors() {
		return $this->sink->beginContributors();
	}

	public function writeContributor( $id, $text, $indent = "      " ) {
		return $this->sink->writeContributor( $id, $text, $indent );
	}

	public function endContributors() {
		return $this->sink->endContributors();
	}

	public function writeUpload( File $file, $url, $dumpContents = false ) {
		return $this->sink->writeUpload( $file, $url, $dumpContents );
	}

}

/**
 * @ingroup Dump
 */
class XmlDumpWriter implements DumpWriter {

	var $mimetype = 'application/xml; charset=utf-8';
	var $extension = 'xml';

	/**
	 * Opens the XML output stream's root "<mediawiki>" element.
	 * This does not include an xml directive, so is safe to include
	 * as a subelement in a larger XML stream. Namespace and XML Schema
	 * references are included.
	 *
	 * Output will be encoded in UTF-8.
	 *
	 * @return string
	 */
	public function openStream() {
		global $wgLanguageCode;
		$ver = WikiExporter::schemaVersion();
		return "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n" . Xml::element( 'mediawiki', array(
			'xmlns'              => "http://www.mediawiki.org/xml/export-$ver/",
			'xmlns:xsi'          => "http://www.w3.org/2001/XMLSchema-instance",
			/*
			 * When a new version of the schema is created, it needs staging on mediawiki.org.
			 * This requires a change in the operations/mediawiki-config git repo.
			 *
			 * Create a changeset like https://gerrit.wikimedia.org/r/#/c/149643/ in which
			 * you copy in the new xsd file.
			 *
			 * After it is reviewed, merged and deployed (sync-docroot), the index.html needs purging.
			 * echo "http://www.mediawiki.org/xml/index.html" | mwscript purgeList.php --wiki=aawiki
			 */
			'xsi:schemaLocation' => "http://www.mediawiki.org/xml/export-$ver/ " .
				"http://www.mediawiki.org/xml/export-$ver.xsd",
			'version'            => $ver,
			'xml:lang'           => $wgLanguageCode ),
			null ) .
			"\n" .
			$this->siteInfo();
	}

	/**
	 * @return string
	 */
	protected function siteInfo() {
		$info = array(
			$this->sitename(),
			$this->dbname(),
			$this->homelink(),
			$this->generator(),
			$this->caseSetting(),
			$this->namespaces() );
		return "  <siteinfo>\n    " .
			implode( "\n    ", $info ) .
			"\n  </siteinfo>\n";
	}

	/**
	 * @return string
	 */
	protected function sitename() {
		global $wgSitename;
		return Xml::element( 'sitename', array(), $wgSitename );
	}

	/**
	 * @return string
	 */
	protected function dbname() {
		global $wgDBname;
		return Xml::element( 'dbname', array(), $wgDBname );
	}

	/**
	 * @return string
	 */
	protected function generator() {
		global $wgVersion;
		return Xml::element( 'generator', array(), "MediaWiki $wgVersion" );
	}

	/**
	 * @return string
	 */
	protected function homelink() {
		return Xml::element( 'base', array(), Title::newMainPage()->getCanonicalURL() );
	}

	/**
	 * @return string
	 */
	protected function caseSetting() {
		global $wgCapitalLinks;
		// "case-insensitive" option is reserved for future
		$sensitivity = $wgCapitalLinks ? 'first-letter' : 'case-sensitive';
		return Xml::element( 'case', array(), $sensitivity );
	}

	/**
	 * @return string
	 */
	protected function namespaces() {
		global $wgContLang;
		$spaces = "<namespaces>\n";
		foreach ( $wgContLang->getFormattedNamespaces() as $ns => $title ) {
			$spaces .= '      ' .
				Xml::element( 'namespace',
					array(
						'key' => $ns,
						'case' => MWNamespace::isCapitalized( $ns ) ? 'first-letter' : 'case-sensitive',
					), $title ) . "\n";
		}
		$spaces .= "    </namespaces>";
		return $spaces;
	}

	/**
	 * Closes the output stream with the closing root element.
	 * Call when finished dumping things.
	 *
	 * @return string
	 */
	public function closeStream() {
		return "</mediawiki>\n";
	}

	/**
	 * Opens a "<page>" section on the output stream, with data
	 * from the given database row.
	 *
	 * @param object $row
	 * @return string
	 */
	public function openPage( $row ) {
		global $wgContLang;
		$out = "  <page>\n";
		$title = Title::makeTitle( $row->page_namespace, $row->page_title );
		$out .= '    ' . Xml::elementClean( 'title', array(), self::canonicalTitle( $title ) ) . "\n";
		$out .= '    ' . Xml::element( 'ns', array(), strval( $row->page_namespace ) ) . "\n";
		$out .= '    ' . Xml::element( 'id', array(), strval( $row->page_id ) ) . "\n";
		if ( $row->page_is_redirect ) {
			$page = WikiPage::factory( $title );
			$redirect = $page->getRedirectTarget();
			if ( $redirect instanceof Title && $redirect->isValidRedirectTarget() ) {
				$out .= '    ';
				$out .= Xml::element( 'redirect', array( 'title' => self::canonicalTitle( $redirect ) ) );
				$out .= "\n";
			}
		}

		if ( $row->page_restrictions != '' ) {
			$out .= '    ' . Xml::element( 'restrictions', array(),
				strval( $row->page_restrictions ) ) . "\n";
		}

		Hooks::run( 'XmlDumpWriterOpenPage', array( $this, &$out, $row, $title ) );

		return $out;
	}

	/**
	 * Closes a "<page>" section on the output stream.
	 *
	 * @access private
	 * @return string
	 */
	public function closePage() {
		return "  </page>\n";
	}

	/**
	 * Dumps a "<revision>" section on the output stream, with
	 * data filled in from the given database row.
	 *
	 * @param object $row
	 * @return string
	 * @access private
	 */
	public function writeRevision( $row ) {

		$out = "    <revision>\n";
		$out .= "      " . Xml::element( 'id', null, strval( $row->rev_id ) ) . "\n";
		if ( isset( $row->rev_parent_id ) && $row->rev_parent_id ) {
			$out .= "      " . Xml::element( 'parentid', null, strval( $row->rev_parent_id ) ) . "\n";
		}

		$out .= $this->writeTimestamp( $row->rev_timestamp );

		if ( isset( $row->rev_deleted ) && ( $row->rev_deleted & Revision::DELETED_USER ) ) {
			$out .= "      " . Xml::element( 'contributor', array( 'deleted' => 'deleted' ) ) . "\n";
		} else {
			$out .= $this->writeContributor( $row->rev_user, $row->rev_user_text );
		}

		if ( isset( $row->rev_minor_edit ) && $row->rev_minor_edit ) {
			$out .= "      <minor/>\n";
		}
		if ( isset( $row->rev_deleted ) && ( $row->rev_deleted & Revision::DELETED_COMMENT ) ) {
			$out .= "      " . Xml::element( 'comment', array( 'deleted' => 'deleted' ) ) . "\n";
		} elseif ( $row->rev_comment != '' ) {
			$out .= "      " . Xml::elementClean( 'comment', array(), strval( $row->rev_comment ) ) . "\n";
		}

		if ( isset( $row->rev_content_model ) && !is_null( $row->rev_content_model ) ) {
			$content_model = strval( $row->rev_content_model );
		} else {
			// probably using $wgContentHandlerUseDB = false;
			$title = Title::makeTitle( $row->page_namespace, $row->page_title );
			$content_model = ContentHandler::getDefaultModelFor( $title );
		}

		$content_handler = ContentHandler::getForModelID( $content_model );

		if ( isset( $row->rev_content_format ) && !is_null( $row->rev_content_format ) ) {
			$content_format = strval( $row->rev_content_format );
		} else {
			// probably using $wgContentHandlerUseDB = false;
			$content_format = $content_handler->getDefaultFormat();
		}

		$out .= "      " . Xml::element( 'model', null, strval( $content_model ) ) . "\n";
		$out .= "      " . Xml::element( 'format', null, strval( $content_format ) ) . "\n";

		$text = '';
		if ( isset( $row->rev_deleted ) && ( $row->rev_deleted & Revision::DELETED_TEXT ) ) {
			$out .= "      " . Xml::element( 'text', array( 'deleted' => 'deleted' ) ) . "\n";
		} elseif ( isset( $row->old_text ) ) {
			// Raw text from the database may have invalid chars
			$text = strval( Revision::getRevisionText( $row ) );
			$text = $content_handler->exportTransform( $text, $content_format );
			$out .= "      " . Xml::elementClean( 'text',
				array( 'xml:space' => 'preserve', 'bytes' => intval( $row->rev_len ) ),
				strval( $text ) ) . "\n";
		} else {
			// Stub output
			$out .= "      " . Xml::element( 'text',
				array( 'id' => $row->rev_text_id, 'bytes' => intval( $row->rev_len ) ),
				"" ) . "\n";
		}

		if ( isset( $row->rev_sha1 )
			&& $row->rev_sha1
			&& !( $row->rev_deleted & Revision::DELETED_TEXT )
		) {
			$out .= "      " . Xml::element( 'sha1', null, strval( $row->rev_sha1 ) ) . "\n";
		} else {
			$out .= "      <sha1/>\n";
		}

		Hooks::run( 'XmlDumpWriterWriteRevision', array( &$this, &$out, $row, $text ) );

		$out .= "    </revision>\n";

		return $out;
	}

	/**
	 * Dumps a "<logitem>" section on the output stream, with
	 * data filled in from the given database row.
	 *
	 * @param object $row
	 * @return string
	 * @access private
	 */
	public function writeLogItem( $row ) {

		$out = "  <logitem>\n";
		$out .= "    " . Xml::element( 'id', null, strval( $row->log_id ) ) . "\n";

		$out .= $this->writeTimestamp( $row->log_timestamp, "    " );

		if ( $row->log_deleted & LogPage::DELETED_USER ) {
			$out .= "    " . Xml::element( 'contributor', array( 'deleted' => 'deleted' ) ) . "\n";
		} else {
			$out .= $this->writeContributor( $row->log_user, $row->user_name, "    " );
		}

		if ( $row->log_deleted & LogPage::DELETED_COMMENT ) {
			$out .= "    " . Xml::element( 'comment', array( 'deleted' => 'deleted' ) ) . "\n";
		} elseif ( $row->log_comment != '' ) {
			$out .= "    " . Xml::elementClean( 'comment', null, strval( $row->log_comment ) ) . "\n";
		}

		$out .= "    " . Xml::element( 'type', null, strval( $row->log_type ) ) . "\n";
		$out .= "    " . Xml::element( 'action', null, strval( $row->log_action ) ) . "\n";

		if ( $row->log_deleted & LogPage::DELETED_ACTION ) {
			$out .= "    " . Xml::element( 'text', array( 'deleted' => 'deleted' ) ) . "\n";
		} else {
			$title = Title::makeTitle( $row->log_namespace, $row->log_title );
			$out .= "    " . Xml::elementClean( 'logtitle', null, self::canonicalTitle( $title ) ) . "\n";
			$out .= "    " . Xml::elementClean( 'params',
				array( 'xml:space' => 'preserve' ),
				strval( $row->log_params ) ) . "\n";
		}

		$out .= "  </logitem>\n";

		return $out;
	}

	/**
	 * @param string $timestamp
	 * @param string $indent Default to six spaces
	 * @return string
	 */
	protected function writeTimestamp( $timestamp, $indent = "      " ) {
		$ts = wfTimestamp( TS_ISO_8601, $timestamp );
		return $indent . Xml::element( 'timestamp', null, $ts ) . "\n";
	}

	public function beginContributors() {
		return "    <contributors>\n";
	}

	public function endContributors() {
		return "    </contributors>\n";
	}

	/**
	 * @param int $id
	 * @param string $text
	 * @param string $indent Default to six spaces
	 * @return string
	 */
	public function writeContributor( $id, $text, $indent = "      " ) {
		$out = $indent . "<contributor>\n";
		if ( $id || !IP::isValid( $text ) ) {
			$out .= $indent . "  " . Xml::elementClean( 'username', null, strval( $text ) ) . "\n";
			$out .= $indent . "  " . Xml::element( 'id', null, strval( $id ) ) . "\n";
		} else {
			$out .= $indent . "  " . Xml::elementClean( 'ip', null, strval( $text ) ) . "\n";
		}
		$out .= $indent . "</contributor>\n";
		return $out;
	}

	/**
	 * @param $file File
	 * @param $dumpContents bool
	 * @return string
	 */
	public function writeUpload( File $file, $url, $dumpContents = false ) {
		if ( !$file->exists() ) {
			return "";
		}
		if ( $file->isOld() ) {
			$archiveName = "      " .
				Xml::element( 'archivename', null, $file->getArchiveName() ) . "\n";
		} else {
			$archiveName = '';
		}
		if ( $dumpContents ) {
			$be = $file->getRepo()->getBackend();
			# Dump file as base64
			# Uses only XML-safe characters, so does not need escaping
			# @todo Too bad this loads the contents into memory (script might swap)
			$contents = '      <contents encoding="base64">' .
				chunk_split( base64_encode(
					$be->getFileContents( array( 'src' => $file->getPath() ) ) ) ) .
				"      </contents>\n";
		} else {
			$contents = '';
		}
		if ( $file->isDeleted( File::DELETED_COMMENT ) ) {
			$comment = Xml::element( 'comment', array( 'deleted' => 'deleted' ) );
		} else {
			$comment = Xml::elementClean( 'comment', null, $file->getDescription() );
		}
		return "    <upload>\n" .
			$this->writeTimestamp( $file->getTimestamp() ) .
			$this->writeContributor( $file->getUser( 'id' ), $file->getUser( 'text' ) ) .
			"      " . $comment . "\n" .
			"      " . Xml::element( 'filename', null, $file->getName() ) . "\n" .
			$archiveName .
			"      " . Xml::element( 'src', null, $url ) . "\n" .
			"      " . Xml::element( 'size', null, $file->getSize() ) . "\n" .
			"      " . Xml::element( 'sha1base36', null, $file->getSha1() ) . "\n" .
			"      " . Xml::element( 'rel', null, $file->getRel() ) . "\n" .
			$contents .
			"    </upload>\n";
	}

	/**
	 * Return prefixed text form of title, but using the content language's
	 * canonical namespace. This skips any special-casing such as gendered
	 * user namespaces -- which while useful, are not yet listed in the
	 * XML "<siteinfo>" data so are unsafe in export.
	 *
	 * @param Title $title
	 * @return string
	 * @since 1.18
	 */
	protected static function canonicalTitle( Title $title ) {
		if ( $title->isExternal() ) {
			return $title->getPrefixedText();
		}

		static $canonicalNames;
		if ( !$canonicalNames ) {
			global $wgCanonicalNamespaceNames, $wgNamespaceAliases;
			$lang = Language::factory( 'en' );
			$canonicalNames = $wgCanonicalNamespaceNames + $lang->getNamespaces();
			foreach ( ( $lang->getNamespaceAliases() + $wgNamespaceAliases ) as $text => $ns ) {
				if ( !isset( $canonicalNames[$ns] ) ||
					!preg_match( '/^[a-z_ ]+$/is', $canonicalNames[$ns] ) && preg_match( '/^[a-z_ ]+$/is', $text ) ) {
					$canonicalNames[$ns] = $text;
				}
			}
		}
		if ( !$title->getNamespace() ) {
			$prefix = '';
		} else {
			$prefix = str_replace( '_', ' ', $canonicalNames[ $title->getNamespace() ] );
			if ( $prefix !== '' ) {
				$prefix .= ':';
			}
		}

		return $prefix . $title->getText();
	}
}
