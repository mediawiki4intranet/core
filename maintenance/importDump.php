<?php
/**
 * Import XML/ZIP dump files into the current wiki.
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
 * @ingroup Maintenance
 */

declare(ticks = 1);

require_once __DIR__ . '/Maintenance.php';

/**
 * Maintenance script that imports XML/ZIP dump files into the current wiki.
 *
 * @ingroup Maintenance
 */
class BackupReader extends Maintenance {
	public $reportingInterval = 100;
	public $pageCount = 0;
	public $revCount = 0;
	public $uploadCount = 0;
	public $dryRun = false;
	public $noUploads = false;
	public $imageBasePath = false;
	public $nsFilter = false;

	function __construct() {
		parent::__construct();
		$gz = in_array( 'compress.zlib', stream_get_wrappers() )
			? 'ok'
			: '(disabled; requires PHP zlib module)';
		$bz2 = in_array( 'compress.bzip2', stream_get_wrappers() )
			? 'ok'
			: '(disabled; requires PHP bzip2 module)';

		$this->mDescription = <<<TEXT
This script reads pages from an XML or ZIP file as produced from Special:Export or
dumpBackup.php, and saves them into the current wiki.

Note that for very large data sets, importDump.php may be slow; there are
alternate methods which can be much faster for full site restoration:
<https://www.mediawiki.org/wiki/Manual:Importing_XML_dumps>
TEXT;
		$this->stderr = fopen( "php://stderr", "wt" );
		$this->addOption( 'report',
			'Report position and speed after every n pages processed', false, true );
		// FIXME: namespace filter does not work
		//$this->addOption( 'namespaces',
		//	'Import only the pages from namespaces belonging to the list of ' .
		//	'pipe-separated namespace names or namespace indexes', false, true );
		// FIXME: dry run does not work
		//$this->addOption( 'dry-run', 'Parse dump without actually importing pages' );
		$this->addOption( 'debug', 'Output extra verbose debug information' );
		$this->addOption( 'no-uploads', 'Do not process file upload data even if included' );
		$this->addOption(
			'no-updates',
			'Disable link table updates. Is faster but leaves the wiki in an inconsistent state'
		);
		$this->addOption( 'image-base-path', 'Import files from a specified path', false, true );
		$this->addArg( 'file', 'Dump file to import', false );
	}

	public function execute() {
		if ( function_exists( 'pcntl_signal' ) ) {
			// So DumpArchive removes temporary files on Ctrl-C
			pcntl_signal( SIGINT, function() { exit(); } );
		}

		if ( wfReadOnly() ) {
			$this->error( "Wiki is in read-only mode; you'll need to disable it for import to work.", true );
		}

		$this->reportingInterval = intval( $this->getOption( 'report', 100 ) );
		if ( !$this->reportingInterval ) {
			$this->reportingInterval = 100; // avoid division by zero
		}

		//$this->dryRun = $this->hasOption( 'dry-run' );
		$this->noUploads = $this->hasOption( 'no-uploads' );
		if ( $this->hasOption( 'image-base-path' ) ) {
			$this->imageBasePath = $this->getOption( 'image-base-path' );
		}
		if ( $this->hasOption( 'namespaces' ) ) {
			$this->setNsfilter( explode( '|', $this->getOption( 'namespaces' ) ) );
		}

		if ( !$this->getArg() ) {
			$this->error( "Please specify the input file" );
			return;
		}
		$this->importFromFile( $this->getArg() );

		$this->output( "Done!\n" );
		$this->output( "You might want to run rebuildrecentchanges.php to regenerate RecentChanges\n" );
	}

	function setNsfilter( array $namespaces ) {
		if ( count( $namespaces ) == 0 ) {
			$this->nsFilter = false;

			return;
		}
		$this->nsFilter = array_unique( array_map( array( $this, 'getNsIndex' ), $namespaces ) );
	}

	private function getNsIndex( $namespace ) {
		global $wgContLang;
		if ( ( $result = $wgContLang->getNsIndex( $namespace ) ) !== false ) {
			return $result;
		}
		$ns = intval( $namespace );
		if ( strval( $ns ) === $namespace && $wgContLang->getNsText( $ns ) !== false ) {
			return $ns;
		}
		$this->error( "Unknown namespace text / index specified: $namespace", true );
	}

	/**
	 * @param Title|Revision $obj
	 * @return bool
	 */
	private function skippedNamespace( $obj ) {
		if ( $obj instanceof Title ) {
			$ns = $obj->getNamespace();
		} elseif ( $obj instanceof Revision ) {
			$ns = $obj->getTitle()->getNamespace();
		} elseif ( $obj instanceof WikiRevision ) {
			$ns = $obj->title->getNamespace();
		} else {
			throw new MWException( "Cannot get namespace of object in " . __METHOD__ );
		}

		return is_array( $this->nsFilter ) && !in_array( $ns, $this->nsFilter );
	}

	function reportPage( $page ) {
		$this->pageCount++;
		$this->report();
		$args = func_get_args();
		return call_user_func_array( $this->pageOutCallback, $args );
	}

	/**
	 * @param Revision $rev
	 */
	function handleRevision( $rev ) {
		$this->revCount++;
		$args = func_get_args();
		return call_user_func_array( $this->revisionCallback, $args );
	}

	/**
	 * @param Revision $revision
	 * @return bool
	 */
	function handleUpload( $revision ) {
		$this->uploadCount++;
		$args = func_get_args();
		return call_user_func_array( $this->uploadCallback, $args );
	}

	function handleLogItem( $rev ) {
		$this->revCount++;
		$args = func_get_args();
		return call_user_func_array( $this->logItemCallback, $args );
	}

	function report( $final = false ) {
		if ( $final xor ( $this->pageCount % $this->reportingInterval == 0 ) ) {
			$this->showReport();
		}
	}

	function showReport() {
		if ( !$this->mQuiet ) {
			$delta = microtime( true ) - $this->startTime;
			if ( $delta ) {
				$rate = sprintf( "%.2f", $this->pageCount / $delta );
				$revrate = sprintf( "%.2f", $this->revCount / $delta );
			} else {
				$rate = '-';
				$revrate = '-';
			}
			// Log dumps don't have page tallies
			if ( $this->pageCount ) {
				$this->progress( "Imported $this->pageCount pages, $this->revCount revisions, $this->uploadCount uploads ($rate pages/sec $revrate revs/sec)" );
			} else {
				$this->progress( "Imported $this->revCount revisions ($revrate revs/sec)" );
			}
		}
		wfWaitForSlaves();
		// XXX: Don't let deferred jobs array get absurdly large (bug 24375)
		DeferredUpdates::doUpdates( 'commit' );
	}

	function progress( $string ) {
		fwrite( $this->stderr, $string . "\n" );
	}

	function importFromFile( $filename ) {
		$this->startTime = microtime( true );

		$importer = DumpArchive::newFromFile( $filename, NULL, $this->getConfig() );
		if ( !$importer ) {
			die( "Cannot read dump archive $filename\n" );
		}
		if ( $this->hasOption( 'debug' ) ) {
			$importer->setDebug( true );
		}
		if ( $this->hasOption( 'no-updates' ) ) {
			$importer->setNoUpdates( true );
		}
		$this->pageOutCallback = $importer->setPageOutCallback( array( &$this, 'reportPage' ) );
		$this->revisionCallback = $importer->setRevisionCallback( array( &$this, 'handleRevision' ) );
		$this->uploadCallback = $importer->setUploadCallback( array( &$this, 'handleUpload' ) );
		$this->logItemCallback = $importer->setLogItemCallback( array( &$this, 'handleLogItem' ) );
		if ( $this->noUploads ) {
			$importer->setImportUploads( false );
		}
		if ( $this->imageBasePath ) {
			$importer->setImageBasePath( $this->imageBasePath );
		}

		if ( $this->dryRun ) {
			$importer->setPageOutCallback( null );
		}

		return $importer->doImport();
	}
}

$maintClass = 'BackupReader';
require_once RUN_MAINTENANCE_IF_MAIN;
