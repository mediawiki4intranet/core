<?php
/**
 * Handler for SVG images.
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
 * @ingroup Media
 */

class SvgThumbnailImage extends ThumbnailImage {

	function __construct( $file, $url, $svgurl, $width, $height, $path = false, $page = false, $later = false ) {
		$this->svgurl = $svgurl;
		$this->later = $later;
		parent::__construct( $file, $url, $width, $height, $path, $page );
	}

	static function scaleParam( $name, $value, $sw, $sh ) {
		// $name could be width, height or viewBox
		$i = ( $name == 'height' ? 1 : 0 );
		$mul = array( $sw, $sh );
		preg_match_all( '/\d+(?:\.\d+)?/', $value, $nums, PREG_PATTERN_ORDER|PREG_OFFSET_CAPTURE );
		$r = '';
		$p = 0;
		foreach( $nums[0] as $num ) {
			$r .= substr( $value, $p, $num[1]-$p );
			$r .= $num[0] * $mul[($i++) & 1];
			$p = $num[1] + strlen( $num[0] );
		}
		$r .= substr( $value, $p );
		return $name.'="'.$r.'"';
	}

	function toHtml( $options = array() ) {
		if ( count( func_get_args() ) == 2 ) {
			throw new MWException( __METHOD__ .' called in the old style' );
		}

		$alt = empty( $options['alt'] ) ? '' : $options['alt'];
		$query = empty( $options['desc-query'] )  ? '' : $options['desc-query'];

		if ( !empty( $options['custom-url-link'] ) ) {
			$linkAttribs = array( 'href' => $options['custom-url-link'] );
			if ( !empty( $options['title'] ) ) {
				$linkAttribs['title'] = $options['title'];
			}
		} elseif ( !empty( $options['custom-title-link'] ) ) {
			$title = $options['custom-title-link'];
			$linkAttribs = array(
				'href' => $title->getLinkUrl(),
				'title' => empty( $options['title'] ) ? $title->getFullText() : $options['title']
			);
		} elseif ( !empty( $options['desc-link'] ) ) {
			$linkAttribs = $this->getDescLinkAttribs( empty( $options['title'] ) ? null : $options['title'], $query );
		} elseif ( !empty( $options['file-link'] ) ) {
			$linkAttribs = array( 'href' => $this->file->getURL() );
		} else {
			$linkAttribs = array( 'href' => '' );
		}

		$attribs = array(
			'alt' => $alt,
			'src' => $this->url,
			'width' => $this->width,
			'height' => $this->height,
		);
		if ( !empty( $options['valign'] ) ) {
			$attribs['style'] = "vertical-align: {$options['valign']}";
		}
		if ( !empty( $options['img-class'] ) ) {
			$attribs['class'] = $options['img-class'];
		}

		$linkurl = $this->file->getUrl();

		if ( !empty( $linkAttribs['href'] ) ||
			$this->width != $this->file->getWidth() ||
			$this->height != $this->file->getHeight() ) {
			if ( empty( $linkAttribs['href'] ) ) {
				$linkAttribs['href'] = '';
			}
			if ( empty( $linkAttribs['title'] ) ) {
				$linkAttribs['title'] = '';
			}
			// :-( The only cross-browser way to link from SVG
			// is to add an <a xlink:href> into SVG image itself
			global $wgServer;
			$href = $linkAttribs['href'];
			if ( substr( $href, 0, 1 ) == '/' ) {
				$href = $wgServer . $href;
			}
			$method = method_exists( $this->file, 'getPhys' ) ? 'getPhys' : 'getName'; // 4intra.net
			$hash = '/' . $this->file->$method() . '-linked-' . crc32( $href . "\0" .
				$linkAttribs['title'] . "\0" . $this->width . "\0" . $this->height ) . '.svg';
			$linkfn = $this->file->getThumbPath() . $hash;
			$linkurl = $this->file->getThumbUrl() . $hash;

			// Cache changed SVGs only when TRANSFORM_LATER is on
			$mtime = false;
			if ( $this->later ) {
				$mtime = $this->file->repo->getFileTimestamp( $linkfn );
			}
			if ( !$mtime || $mtime < $this->file->getTimestamp() ) {
				// Load original SVG or SVGZ and extract opening element
				$readfn = $this->file->getLocalRefPath();
				if ( function_exists( 'gzopen' ) ) {
					$fp = gzopen( $readfn, 'rb' );
				} else {
					$fp = fopen( $readfn, 'rb' );
				}
				$skip = false;
				if ( $fp ) {
					$svg = stream_get_contents( $fp );
					fclose( $fp );
					if ( substr( $svg, 0, 3 ) == "\x1f\x8b\x08" ) {
						wfDebug( __CLASS__.": Zlib is not available, can't scale SVGZ image\n" );
						$skip = true;
					}
				}
				else {
					wfDebug( __CLASS__.": Cannot read file $readfn\n" );
					$skip = true;
				}
				if ( !$skip ) {
					// Find opening and closing tags
					preg_match( '#<svg[^<>]*>#is', $svg, $m, PREG_OFFSET_CAPTURE );
					$closepos = strrpos( $svg, '</svg' );
					if ( !$m || $closepos === false ) {
						wfDebug( __CLASS__.": Invalid SVG (opening or closing tag not found)\n" );
						$skip = true;
					}
				}
				if ( !$skip ) {
					$open = $m[0][0];
					$openpos = $m[0][1];
					$openlen = strlen( $m[0][0] );
					$sw = $this->width / $this->file->getWidth();
					$sh = $this->height / $this->file->getHeight();
					$close = '';
					// Scale width, height and viewBox
					$open = preg_replace_callback( '/(viewBox|width|height)=[\'\"]([^\'\"]+)[\'\"]/',
						create_function( '$m', "return SvgThumbnailImage::scaleParam( \$m[1], \$m[2], $sw, $sh );" ), $open );
					// Add xlink namespace, if not yet
					if ( !strpos( $open, 'xmlns:xlink' ) ) {
						$open = substr( $open, 0, -1 ) . ' xmlns:xlink="http://www.w3.org/1999/xlink">';
					}
					if ( $sw < 0.99 || $sw > 1.01 || $sh < 0.99 || $sh > 1.01 ) {
						// Wrap contents into a scaled layer
						$open .= "<g transform='scale($sw $sh)'>";
						$close = "</g>$close";
					}
					// Wrap contents into a hyperlink
					if ( $href ) {
						$open .= '<a xlink:href="'.htmlspecialchars( $href ).
							'" target="_parent" xlink:title="'.htmlspecialchars( $linkAttribs['title'] ).'">';
						$close = "</a>$close";
					}
					// Write modified SVG
					$svg = substr( $svg, 0, $openpos ) . $open .
						substr( $svg, $openpos+$openlen, $closepos-$openpos-$openlen ) . $close .
						ltrim( substr( $svg, $closepos ), ">\t\r\n" );
					$tmpFile = TempFSFile::factory( 'transform_svg' );
					$tmpThumbPath = $tmpFile->getPath(); // path of 0-byte temp file
					file_put_contents( $tmpThumbPath, $svg );
					$this->file->repo->quickImport( $tmpThumbPath, $linkfn );
				} else {
					// Skip SVG scaling
					$linkurl = $this->file->getUrl();
				}
			}
		}

		// Output PNG <img> wrapped into SVG <object>
		$html = $this->linkWrap( $linkAttribs, Xml::element( 'img', $attribs ) );
		$html = Xml::tags( 'object', array(
			'type' => 'image/svg+xml',
			'data' => $linkurl,
			'style' => 'overflow: hidden; vertical-align: middle',
			'width' => $this->width,
			'height' => $this->height,
		), $html );
		return $html;
	}
}

/**
 * Handler for SVG images.
 *
 * @ingroup Media
 */
class SvgHandler extends ImageHandler {
	const SVG_METADATA_VERSION = 2;

	function isEnabled() {
		global $wgSVGConverters, $wgSVGConverter;
		if ( !isset( $wgSVGConverters[$wgSVGConverter] ) ) {
			wfDebug( "\$wgSVGConverter is invalid, disabling SVG rendering.\n" );
			return false;
		} else {
			return true;
		}
	}

	function mustRender( $file ) {
		return true;
	}

	function isVectorized( $file ) {
		return true;
	}

	/**
	 * @param $file File
	 * @return bool
	 */
	function isAnimatedImage( $file ) {
		# TODO: detect animated SVGs
		$metadata = $file->getMetadata();
		if ( $metadata ) {
			$metadata = $this->unpackMetadata( $metadata );
			if( isset( $metadata['animated'] ) ) {
				return $metadata['animated'];
			}
		}
		return false;
	}

	/**
	 * We do not support making animated svg thumbnails
	 */
	function canAnimateThumb( $file ) {
		return false;
	}

	/**
	 * @param $image File
	 * @param  $params
	 * @return bool
	 */
	function normaliseParams( $image, &$params ) {
		global $wgSVGMaxSize;
		if ( !parent::normaliseParams( $image, $params ) ) {
			return false;
		}
		# Don't make an image bigger than wgMaxSVGSize on the smaller side
		if ( $params['physicalWidth'] <= $params['physicalHeight'] ) {
			if ( $params['physicalWidth'] > $wgSVGMaxSize ) {
				$srcWidth = $image->getWidth( $params['page'] );
				$srcHeight = $image->getHeight( $params['page'] );
				$params['physicalWidth'] = $wgSVGMaxSize;
				$params['physicalHeight'] = File::scaleHeight( $srcWidth, $srcHeight, $wgSVGMaxSize );
			}
		} else {
			if ( $params['physicalHeight'] > $wgSVGMaxSize ) {
				$srcWidth = $image->getWidth( $params['page'] );
				$srcHeight = $image->getHeight( $params['page'] );
				$params['physicalWidth'] = File::scaleHeight( $srcHeight, $srcWidth, $wgSVGMaxSize );
				$params['physicalHeight'] = $wgSVGMaxSize;
			}
		}
		return true;
	}

	/**
	 * @param $image File
	 * @param  $dstPath
	 * @param  $dstUrl
	 * @param  $params
	 * @param int $flags
	 * @return bool|MediaTransformError|ThumbnailImage|TransformParameterError
	 */
	function doTransform( $image, $dstPath, $dstUrl, $params, $flags = 0 ) {
		if ( !$this->normaliseParams( $image, $params ) ) {
			return new TransformParameterError( $params );
		}
		$clientWidth = $params['width'];
		$clientHeight = $params['height'];
		$physicalWidth = $params['physicalWidth'];
		$physicalHeight = $params['physicalHeight'];

		if ( $flags & self::TRANSFORM_LATER ) {
			return new SvgThumbnailImage( $image, $dstUrl, $image->getFullUrl(), $clientWidth, $clientHeight, $dstPath, false, true );
		}

		$metadata = $this->unpackMetadata( $image->getMetadata() );
		if ( isset( $metadata['error'] ) ) { // sanity check
			$err = wfMessage( 'svg-long-error', $metadata['error']['message'] )->text();
			return new MediaTransformError( 'thumbnail_error', $clientWidth, $clientHeight, $err );
		}

		if ( !wfMkdirParents( dirname( $dstPath ), null, __METHOD__ ) ) {
			return new MediaTransformError( 'thumbnail_error', $clientWidth, $clientHeight,
				wfMessage( 'thumbnail_dest_directory' )->text() );
		}

		$srcPath = $image->getLocalRefPath();
		$status = $this->rasterize( $srcPath, $dstPath, $physicalWidth, $physicalHeight );
		if( $status === true ) {
			return new SvgThumbnailImage( $image, $dstUrl, $image->getFullUrl(), $clientWidth, $clientHeight, $dstPath );
		} else {
			return $status; // MediaTransformError
		}
	}

	/**
	 * Transform an SVG file to PNG
	 * This function can be called outside of thumbnail contexts
	 * @param string $srcPath
	 * @param string $dstPath
	 * @param string $width
	 * @param string $height
	 * @throws MWException
	 * @return bool|MediaTransformError
	 */
	public function rasterize( $srcPath, $dstPath, $width, $height ) {
		global $wgSVGConverters, $wgSVGConverter, $wgSVGConverterPath;
		$err = false;
		$retval = '';
		if ( isset( $wgSVGConverters[$wgSVGConverter] ) ) {
			if ( is_array( $wgSVGConverters[$wgSVGConverter] ) ) {
				// This is a PHP callable
				$func = $wgSVGConverters[$wgSVGConverter][0];
				$args = array_merge( array( $srcPath, $dstPath, $width, $height ),
					array_slice( $wgSVGConverters[$wgSVGConverter], 1 ) );
				if ( !is_callable( $func ) ) {
					throw new MWException( "$func is not callable" );
				}
				$err = call_user_func_array( $func, $args );
				$retval = (bool)$err;
			} else {
				// External command
				$cmd = str_replace(
					array( '$path/', '$width', '$height', '$input', '$output' ),
					array( $wgSVGConverterPath ? wfEscapeShellArg( "$wgSVGConverterPath/" ) : "",
						intval( $width ),
						intval( $height ),
						wfEscapeShellArg( $srcPath ),
						wfEscapeShellArg( $dstPath ) ),
					$wgSVGConverters[$wgSVGConverter]
				) . " 2>&1";
				wfProfileIn( 'rsvg' );
				wfDebug( __METHOD__ . ": $cmd\n" );
				$err = wfShellExec( $cmd, $retval );
				wfProfileOut( 'rsvg' );
			}
		}
		$removed = $this->removeBadFile( $dstPath, $retval );
		if ( $retval != 0 || $removed ) {
			wfDebugLog( 'thumbnail', sprintf( 'thumbnail failed on %s: error %d "%s" from "%s"',
					wfHostname(), $retval, trim( $err ), $cmd ) );
			return new MediaTransformError( 'thumbnail_error', $width, $height, $err );
		}
		return true;
	}

	public static function rasterizeImagickExt( $srcPath, $dstPath, $width, $height ) {
		$im = new Imagick( $srcPath );
		$im->setImageFormat( 'png' );
		$im->setBackgroundColor( 'transparent' );
		$im->setImageDepth( 8 );

		if ( !$im->thumbnailImage( intval( $width ), intval( $height ), /* fit */ false ) ) {
			return 'Could not resize image';
		}
		if ( !$im->writeImage( $dstPath ) ) {
			return "Could not write to $dstPath";
		}
	}

	/**
	 * @param $file File
	 * @param  $path
	 * @param bool $metadata
	 * @return array
	 */
	function getImageSize( $file, $path, $metadata = false ) {
		if ( $metadata === false ) {
			$metadata = $file->getMetaData();
		}
		$metadata = $this->unpackMetaData( $metadata );

		if ( isset( $metadata['width'] ) && isset( $metadata['height'] ) ) {
			return array( $metadata['width'], $metadata['height'], 'SVG',
					"width=\"{$metadata['width']}\" height=\"{$metadata['height']}\"" );
		} else { // error
			return array( 0, 0, 'SVG', "width=\"0\" height=\"0\"" );
		}
	}

	function getThumbType( $ext, $mime, $params = null ) {
		return array( 'png', 'image/png' );
	}

	/**
	 * Subtitle for the image. Different from the base
	 * class so it can be denoted that SVG's have
	 * a "nominal" resolution, and not a fixed one,
	 * as well as so animation can be denoted.
	 *
	 * @param $file File
	 * @return string
	 */
	function getLongDesc( $file ) {
		global $wgLang;

		$metadata = $this->unpackMetadata( $file->getMetadata() );
		if ( isset( $metadata['error'] ) ) {
			return wfMessage( 'svg-long-error', $metadata['error']['message'] )->text();
		}

		$size = $wgLang->formatSize( $file->getSize() );

		if ( $this->isAnimatedImage( $file ) ) {
			$msg = wfMessage( 'svg-long-desc-animated' );
		} else {
			$msg = wfMessage( 'svg-long-desc' );
		}

		$msg->numParams( $file->getWidth(), $file->getHeight() )->params( $size );

		return $msg->parse();
	}

	function getMetadata( $file, $filename ) {
		$metadata = array( 'version' => self::SVG_METADATA_VERSION );
		try {
			$metadata += SVGMetadataExtractor::getMetadata( $filename );
		} catch( MWException $e ) { // @TODO: SVG specific exceptions
			// File not found, broken, etc.
			$metadata['error'] = array(
				'message' => $e->getMessage(),
				'code'    => $e->getCode()
			);
			wfDebug( __METHOD__ . ': ' . $e->getMessage() . "\n" );
		}
		return serialize( $metadata );
	}

	function unpackMetadata( $metadata ) {
		wfSuppressWarnings();
		$unser = unserialize( $metadata );
		wfRestoreWarnings();
		if ( isset( $unser['version'] ) && $unser['version'] == self::SVG_METADATA_VERSION ) {
			return $unser;
		} else {
			return false;
		}
	}

	function getMetadataType( $image ) {
		return 'parsed-svg';
	}

	function isMetadataValid( $image, $metadata ) {
		$meta = $this->unpackMetadata( $metadata );
		if ( $meta === false ) {
			return self::METADATA_BAD;
		}
		if ( !isset( $meta['originalWidth'] ) ) {
			// Old but compatible
			return self::METADATA_COMPATIBLE;
		}
		return self::METADATA_GOOD;
	}

	function visibleMetadataFields() {
		$fields = array( 'objectname', 'imagedescription' );
		return $fields;
	}

	/**
	 * @param $file File
	 * @return array|bool
	 */
	function formatMetadata( $file ) {
		$result = array(
			'visible' => array(),
			'collapsed' => array()
		);
		$metadata = $file->getMetadata();
		if ( !$metadata ) {
			return false;
		}
		$metadata = $this->unpackMetadata( $metadata );
		if ( !$metadata || isset( $metadata['error'] ) ) {
			return false;
		}

		/* TODO: add a formatter
		$format = new FormatSVG( $metadata );
		$formatted = $format->getFormattedData();
		*/

		// Sort fields into visible and collapsed
		$visibleFields = $this->visibleMetadataFields();

		// Rename fields to be compatible with exif, so that
		// the labels for these fields work and reuse existing messages.
		$conversion = array(
			'originalwidth' => 'imagewidth',
			'originalheight' => 'imagelength',
			'description' => 'imagedescription',
			'title' => 'objectname',
		);
		foreach ( $metadata as $name => $value ) {
			$tag = strtolower( $name );
			if ( isset( $conversion[$tag] ) ) {
				$tag = $conversion[$tag];
			} else {
				// Do not output other metadata not in list
				continue;
			}
			self::addMeta( $result,
				in_array( $tag, $visibleFields ) ? 'visible' : 'collapsed',
				'exif',
				$tag,
				$value
			);
		}
		return $result;
	}
}
