<?php
/**
 * Base classes for database dumpers
 *
 * Copyright © 2005 Brion Vibber <brion@pobox.com>
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
 * @ingroup Dump Maintenance
 */

/**
 * @ingroup Dump Maintenance
 */
class BackupDumper {
	public $reporting = true;
	public $pages = null; // all pages
	public $startId = 0;
	public $endId = 0;
	public $revStartId = 0;
	public $revEndId = 0;
	public $format = 'xml';

	protected $reportingInterval = 100;
	protected $pageCount = 0;
	protected $revCount = 0;
	protected $server = null; // use default
	protected $lastTime = 0;
	protected $pageCountLast = 0;
	protected $revCountLast = 0;

	protected $outputFilename = NULL;

	protected $ID = 0;

	/**
	 * The dependency-injected database to use.
	 *
	 * @var DatabaseBase|null
	 *
	 * @see self::setDb
	 */
	protected $forcedDb = null;

	/** @var LoadBalancer */
	protected $lb;

	function __construct( $args ) {
		$this->stderr = fopen( "php://stderr", "wt" );
		$this->processArgs( $args );
	}

	/**
	 * @param array $args
	 * @return array
	 */
	function processArgs( $args ) {
		foreach ( $args as $arg ) {
			$matches = array();
			if ( preg_match( '/^--(.+?)(?:=(.+?)(?::(.+?))?)?$/', $arg, $matches ) ) {
				MediaWiki\suppressWarnings();
				list( /* $full */, $opt, $val, $param ) = $matches;
				MediaWiki\restoreWarnings();

				switch ( $opt ) {
					case "outfile":
						$this->outputFilename = $val;
						break;
					case "report":
						$this->reportingInterval = intval( $val );
						break;
					case "server":
						$this->server = $val;
						break;
					case "force-normal":
						if ( !function_exists( 'utf8_normalize' ) ) {
							$this->fatalError( "UTF-8 normalization extension not loaded. " .
								"Install or remove --force-normal parameter to use slower code." );
						}
						break;
					default:
						$this->processOption( $opt, $val, $param );
				}
			}
		}
	}

	function processOption( $opt, $val, $param ) {
		// extension point for subclasses to add options
	}

	function dump( $history, $text = WikiExporter::TEXT ) {
		# Notice messages will foul up your XML output even if they're
		# relatively harmless.
		if ( ini_get( 'display_errors' ) ) {
			ini_set( 'display_errors', 'stderr' );
		}

		$this->initProgress( $history );

		$db = $this->backupDb();
		$exporter = new WikiExporter(
			$db, $history, WikiExporter::STREAM, $text,
			false, $this->format
		);

		$wrapper = new ExportProgressFilter( $exporter->writer, $this );
		$exporter->setDumpWriter( $wrapper );

		$exporter->openStream();
		# Log item dumps: all or by range
		if ( $history & WikiExporter::LOGS ) {
			if ( $this->startId || $this->endId ) {
				$exporter->logsByRange( $this->startId, $this->endId );
			} else {
				$exporter->allLogs();
			}
		} elseif ( is_null( $this->pages ) ) {
			# Page dumps: all or by page ID range
			if ( $this->startId || $this->endId ) {
				$exporter->pagesByRange( $this->startId, $this->endId );
			} elseif ( $this->revStartId || $this->revEndId ) {
				$exporter->revsByRange( $this->revStartId, $this->revEndId );
			} else {
				$exporter->allPages();
			}
		} else {
			# Dump of specific pages
			$exporter->pagesByName( $this->pages );
		}

		$exporter->closeStream();
		if ( !$exporter->getArchive( $fn, $mime, $ext ) ) {
			$this->fatalError( "Cannot get dump archive" );
		}

		if ( $this->outputFilename ) {
			rename( $fn, $this->outputFilename );
		} else {
			readfile( $fn );
		}

		$this->report( true );
	}

	/**
	 * Initialise starting time and maximum revision count.
	 * We'll make ETA calculations based an progress, assuming relatively
	 * constant per-revision rate.
	 * @param int $history WikiExporter::CURRENT or WikiExporter::FULL
	 */
	function initProgress( $history = WikiExporter::FULL ) {
		$table = ( $history == WikiExporter::CURRENT ) ? 'page' : 'revision';
		$field = ( $history == WikiExporter::CURRENT ) ? 'page_id' : 'rev_id';

		$dbr = $this->forcedDb;
		if ( $this->forcedDb === null ) {
			$dbr = wfGetDB( DB_SLAVE );
		}
		$this->maxCount = $dbr->selectField( $table, "MAX($field)", '', __METHOD__ );
		$this->startTime = microtime( true );
		$this->lastTime = $this->startTime;
		$this->ID = getmypid();
	}

	/**
	 * @todo Fixme: the --server parameter is currently not respected, as it
	 * doesn't seem terribly easy to ask the load balancer for a particular
	 * connection by name.
	 * @return DatabaseBase
	 */
	function backupDb() {
		if ( $this->forcedDb !== null ) {
			return $this->forcedDb;
		}

		$this->lb = wfGetLBFactory()->newMainLB();
		$db = $this->lb->getConnection( DB_SLAVE, 'dump' );

		// Discourage the server from disconnecting us if it takes a long time
		// to read out the big ol' batch query.
		$db->setSessionOptions( array( 'connTimeout' => 3600 * 24 ) );

		return $db;
	}

	/**
	 * Force the dump to use the provided database connection for database
	 * operations, wherever possible.
	 *
	 * @param DatabaseBase|null $db (Optional) the database connection to use. If null, resort to
	 *   use the globally provided ways to get database connections.
	 */
	function setDb( DatabaseBase $db = null ) {
		$this->forcedDb = $db;
	}

	function __destruct() {
		if ( isset( $this->lb ) ) {
			$this->lb->closeAll();
		}
	}

	function backupServer() {
		global $wgDBserver;

		return $this->server
			? $this->server
			: $wgDBserver;
	}

	function reportPage() {
		$this->pageCount++;
	}

	function revCount() {
		$this->revCount++;
		$this->report();
	}

	function report( $final = false ) {
		if ( $final xor ( $this->revCount % $this->reportingInterval == 0 ) ) {
			$this->showReport();
		}
	}

	function showReport() {
		if ( $this->reporting ) {
			$now = wfTimestamp( TS_DB );
			$nowts = microtime( true );
			$deltaAll = $nowts - $this->startTime;
			$deltaPart = $nowts - $this->lastTime;
			$this->pageCountPart = $this->pageCount - $this->pageCountLast;
			$this->revCountPart = $this->revCount - $this->revCountLast;

			if ( $deltaAll ) {
				$portion = $this->revCount / $this->maxCount;
				$eta = $this->startTime + $deltaAll / $portion;
				$etats = wfTimestamp( TS_DB, intval( $eta ) );
				$pageRate = $this->pageCount / $deltaAll;
				$revRate = $this->revCount / $deltaAll;
			} else {
				$pageRate = '-';
				$revRate = '-';
				$etats = '-';
			}
			if ( $deltaPart ) {
				$pageRatePart = $this->pageCountPart / $deltaPart;
				$revRatePart = $this->revCountPart / $deltaPart;
			} else {
				$pageRatePart = '-';
				$revRatePart = '-';
			}
			$this->progress( sprintf(
				"%s: %s (ID %d) %d pages (%0.1f|%0.1f/sec all|curr), "
					. "%d revs (%0.1f|%0.1f/sec all|curr), ETA %s [max %d]",
				$now, wfWikiID(), $this->ID, $this->pageCount, $pageRate,
				$pageRatePart, $this->revCount, $revRate, $revRatePart, $etats,
				$this->maxCount
			) );
			$this->lastTime = $nowts;
			$this->revCountLast = $this->revCount;
		}
	}

	function progress( $string ) {
		fwrite( $this->stderr, $string . "\n" );
	}

	function fatalError( $msg ) {
		$this->progress( "$msg\n" );
		die( 1 );
	}
}

class ExportProgressFilter extends DumpFilter {

	function __construct( DumpWriter $sink, $progress ) {
		parent::__construct( $sink );
		$this->progress = $progress;
	}

	function closePage() {
		$this->progress->reportPage();
		return parent::closePage();
	}

	function writeRevision( $row ) {
		$this->progress->revCount();
		return parent::writeRevision( $row );
	}

}
