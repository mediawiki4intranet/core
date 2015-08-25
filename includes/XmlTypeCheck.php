<?php
/**
 * XML syntax and type checker.
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

class XmlTypeCheck {
	/**
	 * Will be set to true or false to indicate whether the file is
	 * well-formed XML. Note that this doesn't check schema validity.
	 */
	public $wellFormed = false;

	/**
	 * Will be set to true if the optional element filter returned
	 * a match at some point.
	 */
	public $filterMatch = false;

	/**
	 * Name of the document's root element, including any namespace
	 * as an expanded URL.
	 */
	public $rootElement = '';

	/**
	 * Additional parsing options
	 */
	private $parserOptions = array(
		'processing_instruction_handler' => '',
	);

	/**
	 * @param string $file filename
	 * Name of file compression type (can be only 'gzip' by now),
	 * or FALSE if the file is uncompressed.
	 */
	public $compressed = false;

	/**
	 * @param $file string filename
	 * @param $filterCallback callable (optional)
	 *        Function to call to do additional custom validity checks from the
	 *        SAX element handler event. This gives you access to the element
	 *        namespace, name, and attributes, but not to text contents.
	 *        Filter should return 'true' to toggle on $this->filterMatch
	 * @param array $options list of additional parsing options:
	 *        processing_instruction_handler: Callback for xml_set_processing_instruction_handler
	 */
	function __construct( $file, $filterCallback=null, $options=array() ) {
		$this->filterCallback = $filterCallback;
		$this->parserOptions = array_merge( $this->parserOptions, $options );
		$this->run( $file );
	}

	/**
	 * Get the root element. Simple accessor to $rootElement
	 *
	 * @return string
	 */
	public function getRootElement() {
		return $this->rootElement;
	}

	/**
	 * @param $fname
	 */
	private function run( $fname ) {
		$parser = xml_parser_create_ns( 'UTF-8' );

		// case folding violates XML standard, turn it off
		xml_parser_set_option( $parser, XML_OPTION_CASE_FOLDING, false );

		xml_set_element_handler( $parser, array( $this, 'rootElementOpen' ), false );

		if ( $this->parserOptions['processing_instruction_handler'] ) {
			xml_set_processing_instruction_handler(
				$parser,
				array( $this, 'processingInstructionHandler' )
			);
		}

		if ( file_exists( $fname ) ) {
			$file = fopen( $fname, "rb" );
			if ( $file ) {
				$gz = fread( $file, 2 );
				if ( $gz == "\x1F\x8B" ) {
					if ( function_exists( 'gzopen' ) ) {
						fclose( $file );
						$this->compressed = 'gzip';
						$file = gzopen( $fname, "rb" );
					} else {
						return;
					}
				} else {
					fseek( $file, 0, SEEK_SET );
				}

				do {
					$chunk = fread( $file, 32768 );
					$ret = xml_parse( $parser, $chunk, feof( $file ) );
					if( $ret == 0 ) {
						// XML isn't well-formed!
						fclose( $file );
						xml_parser_free( $parser );
						return;
					}
				} while( !feof( $file ) );

				fclose( $file );
			}
		}

		$this->wellFormed = true;

		xml_parser_free( $parser );
	}

	/**
	 * @param $parser
	 * @param $name
	 * @param $attribs
	 */
	private function rootElementOpen( $parser, $name, $attribs ) {
		$this->rootElement = $name;

		if( is_callable( $this->filterCallback ) ) {
			xml_set_element_handler( $parser, array( $this, 'elementOpen' ), false );
			$this->elementOpen( $parser, $name, $attribs );
		} else {
			// We only need the first open element
			xml_set_element_handler( $parser, false, false );
		}
	}

	/**
	 * @param $parser
	 * @param $name
	 * @param $attribs
	 */
	private function elementOpen( $parser, $name, $attribs ) {
		if( call_user_func( $this->filterCallback, $name, $attribs ) ) {
			// Filter hit!
			$this->filterMatch = true;
		}
	}

	/**
	 * @param $parser
	 * @param $target
	 * @param $data
	 */
	private function processingInstructionHandler( $parser, $target, $data ) {
		if ( call_user_func( $this->parserOptions['processing_instruction_handler'], $target, $data ) ) {
			// Filter hit!
			$this->filterMatch = true;
		}
	}
}
