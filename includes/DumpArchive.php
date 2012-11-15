<?php

/**
 * Copyright (C) 2011 Vitaliy Filippov <vitalif@mail.ru>
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
 */

/**
 * Stub dump archive class, does not support binary parts,
 * just passes through the XML one.
 */
class StubDumpArchive {

	var $fp = NULL, $buffer = '', $files = array(), $tempdir = '';
	var $mimetype = '', $extension = '', $mainFile = false;
	const BUFSIZE = 0x10000;

	/**
	 * Open an existing archive for importing
	 * Returns the constructed reader, or false in a case of failure
	 */
	function open( $archive, $config = NULL ) {
		global $wgExportFormats;
		$this->mainFile = $archive;
		foreach ( $wgExportFormats as $format ) {
			$class = $format['reader'];
			return new $class( $this, $config );
		}
		return false;
	}

	/**
	 * Get temporary filename for the main part
	 */
	function getMainPart() {
		return $this->mainFile;
	}

	/**
	 * Get temporary file name for a binary part
	 */
	function getBinary( $url ) {
		return false;
	}

	/**
	 * Allow DumpArchive to delete temporary file for a binary part
	 */
	function releaseBinary( $url ) {
		return false;
	}

	/**
	 * Create archive for writing, main file extension is $mainExt
	 */
	function create( $mainMimetype, $mainExtension ) {
		$this->mimetype = $mainMimetype;
		$this->extension = $mainExtension;
		$f = tempnam( wfTempDir(), 'exs' );
		$this->fp = fopen( $f, 'wb' );
		$this->files[ $f ] = true;
	}

	/**
	 * Write part of main ("index") stream (buffered)
	 */
	function write( $string ) {
		if ( !$this->fp ) {
			return;
		}
		if ( strlen( $this->buffer ) + strlen( $string ) < self::BUFSIZE ) {
			$this->buffer .= $string;
		} else {
			fwrite( $this->fp, $this->buffer );
			fwrite( $this->fp, $string );
			$this->buffer = '';
		}
	}

	/**
	 * Get the pseudo-URL for embedded file (object $file)
	 */
	function binUrl( File $file ) {
		return $file->getFullUrl();
	}

	/**
	 * Write binary file (object $file)
	 */
	function writeBinary( File $file ) {
		return false;
	}

	/**
	 * Finish writing
	 */
	function close() {
		if ( $this->fp ) {
			if ( $this->buffer !== '' ) {
				fwrite( $this->fp, $this->buffer );
			}
			fclose( $this->fp );
			$this->fp = NULL;
		}
	}

	/**
	 * Pack all files into an archive,
	 * return its name in $outFilename and MIME type in $outMimetype
	 */
	function getArchive( &$outFilename, &$outMimetype, &$outExtension ) {
		$f = array_keys( $this->files );
		$outFilename = $f[0];
		$outMimetype = $this->mimetype;
		$outExtension = $this->extension;
		return true;
	}

	/**
	 * Destructor
	 */
	function __destruct() {
		$this->cleanup();
	}

	/**
	 * Remove all temporary files and directory
	 */
	function cleanup() {
		if ( $this->files ) {
			foreach ( $this->files as $file => $true ) {
				if ( file_exists( $file ) ) {
					unlink( $file );
				}
			}
			$this->files = array();
		}
		if ( $this->tempdir ) {
			rmdir( $this->tempdir );
			$this->tempdir = '';
		}
	}
}

/**
 * Base class for dump "archiver", which takes the main stream (usually XML)
 * and the embedded binaries, and archives them to a single file.
 * This was multipart/related in old Mediawiki4Intranet versions,
 * will be ZIP in new ones, and can possibly be any other type of archive.
 */
class DumpArchive extends StubDumpArchive {

	var $mimetype = '', $extension = '';
	var $mainMimetype = '', $mainExtension = '';
	const BUFSIZE = 0x10000;

	/**
	 * Static method - constructs the importer with appropriate DumpArchive from file
	 */
	static function newFromFile( $file, $name = NULL, $config = NULL ) {
		global $wgDumpArchiveByExt;
		$ext = '';
		if ( !$name ) {
			$name = $file;
		}
		if ( ( $p = strrpos( $name, '.' ) ) !== false ) {
			$ext = strtolower( substr( $name, $p + 1 ) );
		}
		if ( !isset( $wgDumpArchiveByExt[ $ext ] ) ) {
			$ext = '';
		}
		foreach ( $wgDumpArchiveByExt[ $ext ] as $class ) {
			$archive = new $class();
			$importer = $archive->open( $file, $config );
			if ( $importer ) {
				return $importer;
			}
		}
		return NULL;
	}

	/**
	 * Constructor, generates an empty temporary directory
	 */
	function __construct() {
		$this->tempdir = tempnam( wfTempDir(), 'exp' );
		unlink( $this->tempdir );
		mkdir( $this->tempdir );
	}

	/**
	 * Open an existing archive (for importing)
	 * Returns a proper import reader, or false in a case of failure
	 */
	function open( $archive, $config = NULL ) {
		global $wgExportFormats;
		if ( !$this->tryUnpack( $archive ) ) {
			return false;
		}
		foreach ( $wgExportFormats as $format ) {
			$f = $this->getBinary( 'Revisions.' . $format['extension'] );
			if ( $f ) {
				$this->mainFile = $f;
				$class = $format['reader'];
				return new $class( $this, $config );
			}
		}
		return false;
	}

	/**
	 * Unpack the archive
	 */
	function tryUnpack( $archive ) {
		return false;
	}

	/**
	 * Get temporary file name for a binary part
	 */
	function getBinary( $url ) {
		// Strip out archive:// prefix
		if ( substr( $url, 0, 10 ) != 'archive://' ) {
			return false;
		}
		$name = substr( $url, 10 );
		if ( isset( $this->files[ $this->tempdir . '/' . $name ] ) ) {
			return $this->tempdir . '/' . $name;
		}
		return false;
	}

	/**
	 * Create archive for writing, main file extension is $mainExt
	 */
	function create( $mainMimetype, $mainExtension ) {
		$this->mainMimetype = $mainMimetype;
		$this->mainExtension = $mainExtension;
		$this->mainFile = $this->tempdir . '/Revisions.' . $this->mainExtension;
		$this->fp = fopen( $this->mainFile, 'wb' );
		$this->files[$this->mainFile] = true;
	}

	/**
	 * Generate a name for embedded file (object $file)
	 * By default $SHA1.bin
	 * Other archivers may desire to preserve original filenames
	 */
	function binName( File $file ) {
		return $file->getSha1() . '.bin';
	}

	/**
	 * Get the pseudo-URL for embedded file (object $file)
	 * By default, it's archive://$binName
	 */
	function binUrl( File $file ) {
		return 'archive://' . $this->binName( $file );
	}

	/**
	 * Pack all files into an archive,
	 * return its name in $outFilename and MIME type in $outMimetype
	 */
	function getArchive( &$outFilename, &$outMimetype, &$outExtension ) {
		if ( !$this->archive( $outFilename ) ) {
			return false;
		}
		$this->files[$outFilename] = true;
		$outMimetype = $this->mimetype;
		$outExtension = $this->extension;
		return true;
	}

	/**
	 * Pack all files into an archive file with name $arcfn
	 */
	protected function archive( &$arcfn ) {
		die();
	}
}

/**
 * Support for "multipart" dump files, used in Mediawiki4Intranet in 2009-2011
 */
class OldMultipartDumpArchive extends DumpArchive {

	var $mimetype = 'multipart/related', $extension = 'multipart';
	var $parts = array(), $writeParts = array();
	var $fp = NULL;
	const BUFSIZE = 0x80000;

	function __destruct() {
		if ( $this->fp ) {
			fclose( $this->fp );
		}
		parent::__destruct();
	}

	function getMainPart() {
		return $this->getBinary( 'multipart://Revisions' );
	}

	/**
	 * Get temporary file name for a binary part
	 */
	function getBinary( $url ) {
		if ( $url == 'Revisions.xml' ) {
			$url = 'multipart://Revisions';
		}
		// Strip out multipart:// prefix
		if ( substr( $url, 0, 12 ) != 'multipart://' ) {
			return false;
		}
		$url = substr( $url, 12 );
		if ( isset( $this->parts[$url] ) ) {
			if ( is_string( $this->parts[$url] ) ) {
				return $this->parts[$url];
			}
			if ( isset( $this->parts[$url]['file'] ) ) {
				return $this->parts[$url]['file'];
			}
			$filename = $url === 'Revisions' ? $this->tempdir.'/Revisions.xml' : tempnam( $this->tempdir, 'part' );
			$this->parts[$url]['file'] = $filename;
			$this->files[$filename] = true;
			$done = 0;
			$buf = true;
			$tempfp = fopen( $filename, "wb" );
			fseek( $this->fp, $this->parts[$url][0], 0 );
			while( $done < $this->parts[$url][1] && $buf ) {
				$buf = fread( $this->fp, min( self::BUFSIZE, $this->parts[$url][1] - $done ) );
				fwrite( $tempfp, $buf );
				$done += strlen( $buf );
			}
			fclose($tempfp);
			return $this->parts[$url]['file'];
		}
		return false;
	}

	function releaseBinary( $url ) {
		if ( substr( $url, 0, 12 ) != 'multipart://' ) {
			return;
		}
		$url = substr( $url, 12 );
		if ( isset( $this->parts[$url] ) && isset( $this->parts[$url]['file'] ) ) {
			$filename = $this->parts[$url]['file'];
			if ( file_exists( $filename ) ) {
				@unlink( $filename );
			}
			unset( $this->parts[$url]['file'] );
		}
	}

	/**
	 * Write binary file (object $file)
	 */
	function writeBinary( File $file ) {
		$name = tempnam( $this->tempdir, 'part' );
		$this->writeParts[ $this->binName( $file ) ] = $file;
		return false;
	}

	/**
	 * Generate a name for embedded file (object $file)
	 */
	function binName( File $file ) {
		return $file->isOld ? $file->getArchiveName() : $file->getName();
	}

	/**
	 * Get the pseudo-URL for embedded file (object $file)
	 * Here it's multipart://$binName
	 */
	function binUrl( File $file ) {
		return 'multipart://' . $this->binName( $file );
	}

	/**
	 * Unpack the archive
	 */
	function tryUnpack( $archive ) {
		$this->fp = fopen( $archive, "rb" );
		if ( !$this->fp ) {
			return false;
		}
		$s = fgets( $this->fp );
		if ( preg_match( "/Content-Type:\s*multipart\/related; boundary=(\S+)\s*\n/s", $s, $m ) ) {
			$boundary = $m[1];
		} else {
			fclose( $this->fp );
			$this->fp = NULL;
			return false;
		}
		// Loop over parts
		while ( !feof( $this->fp ) ) {
			$s = trim( fgets( $this->fp ) );
			if ( $s != $boundary ) {
				break;
			}
			$part = array();
			// Read headers
			while ( $s != "\n" && $s != "\r\n" ) {
				$s = fgets( $this->fp );
				if ( preg_match( '/([a-z0-9\-\_]+):\s*(.*?)\s*$/is', $s, $m ) ) {
					$part[ str_replace( '-', '_', strtolower( $m[1] ) ) ] = $m[2];
				}
			}
			// Record offsets
			if ( !isset( $part['content_length'] ) ) {
				// Main part was archived without Content-Length in old dumps :(
				$begin = ftell( $this->fp );
				$buf = fread( $this->fp, self::BUFSIZE );
				while ( $buf !== '' ) {
					if ( ( $p = strpos( $buf, "\n$boundary" ) ) !== false ) {
						fseek( $this->fp, $p + 1 - strlen( $buf ), 1 );
						$buf = '';
					} elseif ( strlen( $buf ) == self::BUFSIZE ) {
						$buf = substr( $buf, -1 -strlen( $boundary ) ) . fread( $this->fp, self::BUFSIZE - 1 - strlen( $boundary ) );
					} else {
						$buf = '';
					}
				}
				$this->parts[ $part['content_id'] ] = [ $begin, ftell( $this->fp ) - $begin ];
			} else {
				if ( !empty( $part['content_id'] ) ) {
					// Skip parts without Content-ID header
					$this->parts[ $part['content_id'] ] = [ ftell( $this->fp ), $part['content_length'] ];
				}
				fseek( $this->fp, ftell( $this->fp ) + $part['content_length'], 0 );
			}
		}
		return true;
	}

	/**
	 * Pack all files into an archive file with name $arcfn
	 */
	protected function archive( &$arcfn ) {
		$arcfn = $this->tempdir . '/archive.' . $this->extension;
		$fp = fopen( $arcfn, "wb" );
		if ( !$fp ) {
			return false;
		}
		$boundary = "--" . time();
		fwrite( $fp, "Content-Type: multipart/related; boundary=$boundary\n$boundary\n" );
		fwrite( $fp, "Content-Type: text/xml\nContent-ID: Revisions\n" .
			"Content-Length: " . filesize( $this->tempdir . '/Revisions' ) . "\n\n" );
		$tempfp = fopen( $this->tempdir . '/Revisions', "rb" );
		while ( ( $buf = fread( $tempfp, self::BUFSIZE ) ) !== '' ) {
			fwrite( $fp, $buf );
		}
		fclose( $tempfp );
		foreach ( $this->writeParts as $name => $fileObj ) {
			$file = $fileObj->getLocalRefPath();
			fwrite( $fp, "$boundary\nContent-ID: $name\nContent-Length: " . filesize( $file ) . "\n\n" );
			$tempfp = fopen( $file, "rb" );
			while ( ( $buf = fread( $tempfp, self::BUFSIZE ) ) !== '' ) {
				fwrite( $fp, $buf );
			}
			fclose( $tempfp );
		}
		fclose( $fp );
		return true;
	}
}

/**
 * ZIPped dump archive (does not preserve uploaded file names)
 */
class ZipDumpArchive extends DumpArchive {

	var $mimetype = 'application/zip', $extension = 'zip';

	function create( $mainMimetype, $mainExtension ) {
		parent::create( $mainMimetype, $mainExtension );
		$this->zip = new ZipArchive();
		$this->zip->open( $this->tempdir . '/archive.' . $this->extension, ZipArchive::CREATE );
	}

	function writeBinary( File $file ) {
		return $this->zip->addFile( $file->getLocalRefPath(), $this->binName( $file ) );
	}

	function archive( &$arcfn ) {
		$arcfn = $this->tempdir . '/archive.' . $this->extension;
		$this->zip->addFile( $this->mainFile, basename( $this->mainFile ) );
		$this->zip->close();
		$this->zip = NULL;
		return true;
	}

	function tryUnpack( $archive ) {
		$this->zip = new ZipArchive();
		return $this->zip->open( $archive );
	}

	function getBinary( $url ) {
		if ( substr( $url, 0, 9 ) !== 'Revisions' ) {
			// Strip out archive:// prefix
			if ( substr( $url, 0, 10 ) != 'archive://' ) {
				return false;
			}
			$url = substr( $url, 10 );
		}
		// FIXME mangle names
		if ( isset( $this->files[ $this->tempdir . '/' . $url ] ) ) {
			return $this->tempdir . '/' . $url;
		}
		if ( $this->zip->extractTo( $this->tempdir, array( $url ) ) ) {
			$this->files[ $this->tempdir . '/' . $url ] = true;
			return $this->tempdir . '/' . $url;
		}
		return false;
	}

	function releaseBinary( $url ) {
		if ( substr( $url, 0, 10 ) != 'archive://' ) {
			return;
		}
		$url = substr( $url, 10 );
		if ( isset( $this->files[ $this->tempdir . '/' . $url ] ) ) {
			$filename = $this->tempdir . '/' . $url;
			if ( file_exists( $filename ) ) {
				@unlink( $filename );
			}
			unset( $this->files[$filename] );
		}
	}

}
