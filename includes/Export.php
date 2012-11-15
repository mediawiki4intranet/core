<?php
/**
 * Base classes for dumps and export
 *
 * Copyright © 2003, 2005, 2006 Brion Vibber <brion@pobox.com>
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
 */

/**
 * @defgroup Dump Dump
 */

/**
 * @ingroup SpecialPage Dump
 */
// TODO Pages should be bulk-loaded - calling pageByTitle for each page is slower
class WikiExporter {
	var $listAuthors = false; # Return distinct author list (when not returning full history)
	var $dumpUploads = false; # Dump uploaded files into the export file
	var $selfContained = false; # Archive uploaded file contents into the export file (ZIP)

	const FULL = 1;
	const CURRENT = 2;
	const STABLE = 4; // extension defined
	const LOGS = 8;
	const RANGE = 16;

	const BUFFER = 0;
	const STREAM = 1;

	const TEXT = 0;
	const STUB = 1;

	/**
	 * If using WikiExporter::STREAM to stream a large amount of data,
	 * provide a database connection which is not managed by
	 * LoadBalancer to read from: some history blob types will
	 * make additional queries to pull source data while the
	 * main query is still running.
	 *
	 * @param $db Database
	 * @param $history Mixed: one of WikiExporter::FULL, WikiExporter::CURRENT,
	 *                 WikiExporter::RANGE or WikiExporter::STABLE,
	 *                 or an associative array:
	 *                   offset: non-inclusive offset at which to start the query
	 *                   limit: maximum number of rows to return
	 *                   dir: "asc" or "desc" timestamp order
	 * @param $buffer Int: one of WikiExporter::BUFFER or WikiExporter::STREAM
	 * @param $text Int: one of WikiExporter::TEXT or WikiExporter::STUB
	 */
	function __construct( $db, $history = WikiExporter::CURRENT,
			$buffer = WikiExporter::BUFFER, $text = WikiExporter::TEXT,
			$listAuthors = false, $dumpUploads = false, $selfContained = false ) {
		$this->db = $db;
		$this->history = $history;
		$this->buffer = $buffer;
		$this->text = $text;
		$this->open = true;
		$this->listAuthors = $listAuthors;
		$this->dumpUploads = $dumpUploads;
		$this->selfContained = $dumpUploads && $selfContained;

		$this->writer = new XmlDumpWriter();
		$class = $this->selfContained ? 'ZipDumpArchive' : 'StubDumpArchive';
		$this->sink = new $class();
		$this->sink->create( $this->writer->mimetype, $this->writer->extension );
	}

	public function openStream() {
		$this->sink->write( $this->writer->openStream() );
	}

	public function closeStream() {
		$this->sink->write( $this->writer->closeStream() );
		$this->sink->close();
		$this->open = false;
	}

	public function getArchive( &$outFilename, &$outMimetype, &$outExtension ) {
		if ( $this->open )
			return false;
		return $this->sink->getArchive( $outFilename, $outMimetype, $outExtension );
	}

	protected function writeUploads( $row, $limit = null ) {
		if( $row->page_namespace == NS_IMAGE ) {
			$img = wfFindFile( $row->page_title );
			if( $img ) {
				if ( !$limit || $limit > 1 ) {
					foreach( $img->getHistory( $limit ? $limit-1 : NULL ) as $ver ) {
						$this->sink->write( $this->writer->writeUpload(
							$ver, $this->sink->binUrl( $ver ) ) );
						$this->sink->writeBinary( $ver );
					}
				}
				$this->sink->write( $this->writer->writeUpload(
					$img, $this->sink->binUrl( $img ) ) );
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
		return $this->dumpFrom( '' );
	}

	/**
	 * Dumps a series of page and revision records for those pages
	 * in the database falling within the page_id range given.
	 * @param $start Int: inclusive lower limit (this id is included)
	 * @param $end   Int: Exclusive upper limit (this id is not included)
	 *                   If 0, no upper limit.
	 */
	public function pagesByRange( $start, $end ) {
		$condition = 'page_id >= ' . intval( $start );
		if ( $end ) {
			$condition .= ' AND page_id < ' . intval( $end );
		}
		return $this->dumpFrom( $condition );
	}

	/**
	 * Dumps a series of page and revision records for those pages
	 * in the database with revisions falling within the rev_id range given.
	 * @param $start Int: inclusive lower limit (this id is included)
	 * @param $end   Int: Exclusive upper limit (this id is not included)
	 *                   If 0, no upper limit.
	 */
	public function revsByRange( $start, $end ) {
		$condition = 'rev_id >= ' . intval( $start );
		if ( $end ) {
			$condition .= ' AND rev_id < ' . intval( $end );
		}
		return $this->dumpFrom( $condition );
	}

	/**
	 * @param $title Title
	 */
	public function pageByTitle( $title ) {
		return $this->dumpFrom(
			'page_namespace=' . $title->getNamespace() .
			' AND page_title=' . $this->db->addQuotes( $title->getDBkey() ) );
	}

	public function pageByName( $name ) {
		$title = Title::newFromText( $name );
		if ( is_null( $title ) ) {
			throw new MWException( "Can't export invalid title" );
		} else {
			return $this->pageByTitle( $title );
		}
	}

	public function pagesByName( $names ) {
		foreach ( $names as $name ) {
			$this->pageByName( $name );
		}
	}

	public function allLogs() {
		return $this->dumpFrom( '' );
	}

	public function logsByRange( $start, $end ) {
		$condition = 'log_id >= ' . intval( $start );
		if ( $end ) {
			$condition .= ' AND log_id < ' . intval( $end );
		}
		return $this->dumpFrom( $condition );
	}

	# Generates the distinct list of authors of an article
	# Not called by default (depends on $this->listAuthors)
	# Can be set by Special:Export when not exporting whole history
	protected function doListAuthors( $cond ) {
		wfProfileIn( __METHOD__ );
		$this->author_list = "<contributors>";
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
		foreach ( $res as $row )
			$code .= $this->writer->writeContributor( $row->rev_user, $row->rev_user_text );
		$code .= $this->writer->endContributors();

		wfProfileOut( __METHOD__ );
		return $code;
	}

	protected function dumpFrom( $cond = '' ) {
		wfProfileIn( __METHOD__ );
		# For logging dumps...
		if ( $this->history & self::LOGS ) {
			if ( $this->buffer == WikiExporter::STREAM ) {
				$prev = $this->db->bufferResults( false );
			}
			$where = array( 'user_id = log_user' );
			# Hide private logs
			$hideLogs = LogEventsList::getExcludeClause( $this->db );
			if ( $hideLogs ) $where[] = $hideLogs;
			# Add on any caller specified conditions
			if ( $cond ) $where[] = $cond;
			# Get logging table name for logging.* clause
			$logging = $this->db->tableName( 'logging' );
			$result = $this->db->select( array( 'logging', 'user' ),
				array( "{$logging}.*", 'user_name' ), // grab the user name
				$where,
				__METHOD__,
				array( 'ORDER BY' => 'log_id', 'USE INDEX' => array( 'logging' => 'PRIMARY' ) )
			);
			$wrapper = $this->db->resultObject( $result );
			$this->outputLogStream( $wrapper );
			if ( $this->buffer == WikiExporter::STREAM ) {
				$this->db->bufferResults( $prev );
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
					$opts['ORDER BY'] = 'rev_timestamp ASC';
				} else {
					$op = '<';
					$opts['ORDER BY'] = 'rev_timestamp DESC';
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
				if ( wfRunHooks( 'WikiExporter::dumpStableQuery', array( &$tables, &$opts, &$join ) ) ) {
					wfProfileOut( __METHOD__ );
					throw new MWException( __METHOD__ . " given invalid history dump type." );
				}
			} elseif ( $this->history & WikiExporter::RANGE ) {
				# Dump of revisions within a specified range
				$join['revision'] = array( 'INNER JOIN', 'page_id=rev_page' );
				$opts['ORDER BY'] = 'rev_page ASC, rev_id ASC';
			} else {
				# Uknown history specification parameter?
				wfProfileOut( __METHOD__ );
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

			wfRunHooks( 'ModifyExportQuery',
						array( $this->db, &$tables, &$cond, &$opts, &$join ) );

			# Do the query!
			$result = $this->db->select( $tables, '*', $cond, __METHOD__, $opts, $join );
			$wrapper = $this->db->resultObject( $result );
			# Output dump results
			$this->outputPageStream( $wrapper, $this->listAuthors ? $authors : NULL );

			if ( $this->buffer == WikiExporter::STREAM ) {
				$this->db->bufferResults( $prev );
			}
		}
		wfProfileOut( __METHOD__ );
	}

	/**
	 * Runs through a query result set dumping page and revision records.
	 * The result set should be sorted/grouped by page to avoid duplicate
	 * page records in the output.
	 *
	 * The result set will be freed once complete. Should be safe for
	 * streaming (non-buffered) queries, as long as it was made on a
	 * separate database connection not managed by LoadBalancer; some
	 * blob storage types will make queries to pull source data.
	 *
	 * @param $resultset ResultWrapper
	 */
	protected function outputPageStream( $resultset, $authors = '' ) {
		$last = null;
		foreach ( $resultset as $row ) {
			// Run text filter
			wfRunHooks( 'ExportFilterText', array( &$row->old_text ) );
			if ( is_null( $last ) ||
				$last->page_namespace != $row->page_namespace ||
				$last->page_title     != $row->page_title ) {
				if ( isset( $last ) ) {
					if ( $this->dumpUploads ) {
						$this->writeUploads( $last,
							$this->history == WikiExporter::CURRENT ? 1 : null );
					}
					$this->sink->write( $this->writer->closePage() );
				}
				$this->sink->write( $this->writer->openPage( $row ) );
				$last = $row;
			}
			$this->sink->write( $this->writer->writeRevision( $row ) );
		}
		if ( isset( $last ) ) {
			if ( $this->dumpUploads ) {
				$this->writeUploads( $last,
					$this->history == WikiExporter::CURRENT ? 1 : null );
			}
			$this->sink->write( $authors );
			$this->sink->write( $this->writer->closePage() );
		}
	}

	protected function outputLogStream( $resultset ) {
		foreach ( $resultset as $row ) {
			$this->sink->write( $this->writer->writeLogItem( $row ) );
		}
	}
}

/**
 * @ingroup Dump
 */
class XmlDumpWriter {

	var $mimetype = 'application/xml; charset=utf-8';
	var $extension = 'xml';

	/**
	 * Returns the export schema version.
	 * @return string
	 */
	protected function schemaVersion() {
		return "0.6";
	}

	/**
	 * Opens the XML output stream's root <mediawiki> element.
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
		$ver = $this->schemaVersion();
		return "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n" .
			Xml::element( 'mediawiki', array(
				'xmlns'              => "http://www.mediawiki.org/xml/export-$ver/",
				'xmlns:xsi'          => "http://www.w3.org/2001/XMLSchema-instance",
				'xsi:schemaLocation' => "http://www.mediawiki.org/xml/export-$ver/ " .
				                        "http://www.mediawiki.org/xml/export-$ver.xsd",
				'version'            => $ver,
				'xml:lang'           => $wgLanguageCode ),
				null ) .
			"\n" .
			$this->siteInfo();
	}

	protected function siteInfo() {
		$info = array(
			$this->sitename(),
			$this->homelink(),
			$this->generator(),
			$this->caseSetting(),
			$this->namespaces() );
		return "  <siteinfo>\n    " .
			implode( "\n    ", $info ) .
			"\n  </siteinfo>\n";
	}

	protected function sitename() {
		global $wgSitename;
		return Xml::element( 'sitename', array(), $wgSitename );
	}

	protected function generator() {
		global $wgVersion;
		return Xml::element( 'generator', array(), "MediaWiki $wgVersion" );
	}

	protected function homelink() {
		return Xml::element( 'base', array(), Title::newMainPage()->getCanonicalUrl() );
	}

	protected function caseSetting() {
		global $wgCapitalLinks;
		// "case-insensitive" option is reserved for future
		$sensitivity = $wgCapitalLinks ? 'first-letter' : 'case-sensitive';
		return Xml::element( 'case', array(), $sensitivity );
	}

	protected function namespaces() {
		global $wgContLang;
		$spaces = "<namespaces>\n";
		foreach ( $wgContLang->getFormattedNamespaces() as $ns => $title ) {
			$spaces .= '      ' .
				Xml::element( 'namespace',
					array(	'key' => $ns,
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
	 * Opens a <page> section on the output stream, with data
	 * from the given database row.
	 *
	 * @param $row object
	 * @return string
	 * @access private
	 */
	public function openPage( $row ) {
		global $wgContLang;
		$out = "  <page>\n";
		$title = Title::makeTitle( $row->page_namespace, $row->page_title );
		$out .= '    ' . Xml::elementClean( 'title', array(), self::canonicalTitle( $title ) ) . "\n";
		$out .= '    ' . Xml::element( 'id', array(), strval( $row->page_id ) ) . "\n";
		if ( $row->page_is_redirect ) {
			$out .= '    ' . Xml::element( 'redirect', array() ) . "\n";
		}
		if ( $row->page_restrictions != '' ) {
			$out .= '    ' . Xml::element( 'restrictions', array(),
				strval( $row->page_restrictions ) ) . "\n";
		}

		wfRunHooks( 'XmlDumpWriterOpenPage', array( $this, &$out, $row, $title ) );

		return $out;
	}

	/**
	 * Closes a <page> section on the output stream.
	 *
	 * @access private
	 */
	public function closePage() {
		return "  </page>\n";
	}

	/**
	 * Dumps a <revision> section on the output stream, with
	 * data filled in from the given database row.
	 *
	 * @param $row object
	 * @return string
	 * @access private
	 */
	public function writeRevision( $row ) {
		wfProfileIn( __METHOD__ );

		$out  = "    <revision>\n";
		$out .= "      " . Xml::element( 'id', null, strval( $row->rev_id ) ) . "\n";

		$out .= $this->writeTimestamp( $row->rev_timestamp );

		if ( $row->rev_deleted & Revision::DELETED_USER ) {
			$out .= "      " . Xml::element( 'contributor', array( 'deleted' => 'deleted' ) ) . "\n";
		} else {
			$out .= $this->writeContributor( $row->rev_user, $row->rev_user_text );
		}

		if ( $row->rev_minor_edit ) {
			$out .=  "      <minor/>\n";
		}
		if ( $row->rev_deleted & Revision::DELETED_COMMENT ) {
			$out .= "      " . Xml::element( 'comment', array( 'deleted' => 'deleted' ) ) . "\n";
		} elseif ( $row->rev_comment != '' ) {
			$out .= "      " . Xml::elementClean( 'comment', null, strval( $row->rev_comment ) ) . "\n";
		}

		$text = '';
		if ( $row->rev_deleted & Revision::DELETED_TEXT ) {
			$out .= "      " . Xml::element( 'text', array( 'deleted' => 'deleted' ) ) . "\n";
		} elseif ( isset( $row->old_text ) ) {
			// Raw text from the database may have invalid chars
			$text = strval( Revision::getRevisionText( $row ) );
			$out .= "      " . Xml::elementClean( 'text',
				array( 'xml:space' => 'preserve', 'bytes' => $row->rev_len ),
				strval( $text ) ) . "\n";
		} else {
			// Stub output
			$out .= "      " . Xml::element( 'text',
				array( 'id' => $row->rev_text_id, 'bytes' => $row->rev_len ),
				"" ) . "\n";
		}

		wfRunHooks( 'XmlDumpWriterWriteRevision', array( &$this, &$out, $row, $text ) );

		$out .= "    </revision>\n";

		wfProfileOut( __METHOD__ );
		return $out;
	}

	/**
	 * Dumps a <logitem> section on the output stream, with
	 * data filled in from the given database row.
	 *
	 * @param $row object
	 * @return string
	 * @access private
	 */
	public function writeLogItem( $row ) {
		wfProfileIn( __METHOD__ );

		$out  = "    <logitem>\n";
		$out .= "      " . Xml::element( 'id', null, strval( $row->log_id ) ) . "\n";

		$out .= $this->writeTimestamp( $row->log_timestamp );

		if ( $row->log_deleted & LogPage::DELETED_USER ) {
			$out .= "      " . Xml::element( 'contributor', array( 'deleted' => 'deleted' ) ) . "\n";
		} else {
			$out .= $this->writeContributor( $row->log_user, $row->user_name );
		}

		if ( $row->log_deleted & LogPage::DELETED_COMMENT ) {
			$out .= "      " . Xml::element( 'comment', array( 'deleted' => 'deleted' ) ) . "\n";
		} elseif ( $row->log_comment != '' ) {
			$out .= "      " . Xml::elementClean( 'comment', null, strval( $row->log_comment ) ) . "\n";
		}

		$out .= "      " . Xml::element( 'type', null, strval( $row->log_type ) ) . "\n";
		$out .= "      " . Xml::element( 'action', null, strval( $row->log_action ) ) . "\n";

		if ( $row->log_deleted & LogPage::DELETED_ACTION ) {
			$out .= "      " . Xml::element( 'text', array( 'deleted' => 'deleted' ) ) . "\n";
		} else {
			$title = Title::makeTitle( $row->log_namespace, $row->log_title );
			$out .= "      " . Xml::elementClean( 'logtitle', null, self::canonicalTitle( $title ) ) . "\n";
			$out .= "      " . Xml::elementClean( 'params',
				array( 'xml:space' => 'preserve' ),
				strval( $row->log_params ) ) . "\n";
		}

		$out .= "    </logitem>\n";

		wfProfileOut( __METHOD__ );
		return $out;
	}

	protected function writeTimestamp( $timestamp ) {
		$ts = wfTimestamp( TS_ISO_8601, $timestamp );
		return "      " . Xml::element( 'timestamp', null, $ts ) . "\n";
	}

	public function beginContributors() {
		return "    <contributors>\n";
	}

	public function endContributors() {
		return "    </contributors>\n";
	}

	public function writeContributor( $id, $text ) {
		$out = "      <contributor>\n";
		if ( $id ) {
			$out .= "        " . Xml::elementClean( 'username', null, strval( $text ) ) . "\n";
			$out .= "        " . Xml::element( 'id', null, strval( $id ) ) . "\n";
		} else {
			$out .= "        " . Xml::elementClean( 'ip', null, strval( $text ) ) . "\n";
		}
		$out .= "      </contributor>\n";
		return $out;
	}

	/**
	 * @param $file File
	 * @param $dumpContents bool
	 * @return string
	 */
	function writeUpload( $file, $url, $dumpContents = false ) {
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
			# Dump file as base64
			# Uses only XML-safe characters, so does not need escaping
			$contents = '      <contents encoding="base64">' .
				chunk_split( base64_encode( file_get_contents( $file->getPath() ) ) ) .
				"      </contents>\n";
		} else {
			$contents = '';
		}
		return "    <upload>\n" .
			$this->writeTimestamp( $file->getTimestamp() ) .
			$this->writeContributor( $file->getUser( 'id' ), $file->getUser( 'text' ) ) .
			"      " . Xml::elementClean( 'comment', null, $file->getDescription() ) . "\n" .
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
	 * Return prefixed text form of title, but using english namespace names.
	 * This skips any special-casing such as gendered
	 * user namespaces -- which while useful, are not yet listed in the
	 * XML <siteinfo> data so are unsafe in export.
	 *
	 * @param Title $title
	 * @return string
	 */
	public static function canonicalTitle( Title $title ) {
		if ( $title->getInterwiki() ) {
			return $title->getPrefixedText();
		}

		global $wgCanonicalNamespaceNames;
		if ( !$title->getNamespace() ) {
			$prefix = '';
		} else {
			$prefix = str_replace( '_', ' ', $wgCanonicalNamespaceNames[ $title->getNamespace() ] );
			if ( $prefix !== '' ) {
				$prefix .= ':';
			}
		}

		return $prefix . $title->getText();
	}
}

# -- Vitaliy Filippov 2011-10-13:
# Implementing additional "dump filter" layer is a very silly idea.
# Page selection must be done OUTSIDE any dumper classes. It's much faster.
