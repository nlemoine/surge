<?php
/**
 * Cache Content
 *
 * This file is loaded when there's a chance the request content should be
 * saved to cache.
 *
 * @package Surge
 */

namespace Surge;

include_once( __DIR__ . '/common.php' );

/**
 * The main output buffer callback.
 *
 * @param string $contents The buffer contents.
 *
 * @return string Contents.
 */
$ob_callback = function( $contents ) {
	$ttl = config( 'ttl' );

	if ( $ttl < 1 ) {
		return $contents;
	}

	$skip = false;
	$headers = [];

	foreach ( headers_list() as $header ) {
		list( $name, $value ) = array_map( 'trim', explode( ':', $header, 2 ) );

		// Do not store or vary on these headers.
		if ( in_array( strtolower( $name ), ['x-cache', 'x-powered-by'] ) ) {
			continue;
		}

		$headers[ $name ][] = $value;

		if ( strtolower( $name ) == 'set-cookie' ) {
			$skip = true;
			break;
		}

		if ( strtolower( $name ) == 'cache-control' ) {
			if ( stripos( $value, 'no-cache' ) !== false || stripos( $value, 'max-age=0' ) !== false ) {
				$skip = true;
				break;
			}
		}
	}

	if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
		$skip = true;
	}

	if ( ! in_array( strtoupper( $_SERVER['REQUEST_METHOD'] ), [ 'GET', 'HEAD' ] ) ) {
		$skip = true;
	}

	if ( ! in_array( http_response_code(), [ 200, 301, 302, 404 ] ) ) {
		$skip = true;
	}

	if ( $skip ) {
		return $contents;
	}

	$key = key();

    $cached_contents = $contents;

    // Encoding
    switch ( $key['encoding'] ) {
        case 'br':
            $headers['Content-Encoding'][] = 'br';
            $cached_contents = brotli_compress( $contents );
            break;
        case 'gz':
            $headers['Content-Encoding'][] = 'gzip';
            $cached_contents = gzencode( $contents, 9 );
            break;
    }

    // Encoding failed
    if ( false === $cached_contents && in_array($key['encoding'], ['br', 'gzip'], true) ) {
        return $contents;
    }

	$meta = [
		'code' => http_response_code(),
		'headers' => $headers,
		'created' => time(),
		'expires' => time() + $ttl,
		'flags' => array_unique( flag() ),
		'path' => $key['path'],
	];

	$meta = json_encode( $meta );
	$cache_key = md5( json_encode( $key ) );
	$level = substr( $cache_key, -2 );

	if ( ! wp_mkdir_p( CACHE_DIR . "/{$level}/" ) ) {
		return $contents;
	}

	// Open a new cache file.
	$hash = wp_generate_password( 6, false );
	$f = fopen( CACHE_DIR . "/{$level}/{$cache_key}.{$hash}.php", 'xb' );

	// Could not create file.
	if ( false === $f ) {
		return $contents;
	}

	fwrite( $f, '<?php exit; ?>' );
	fwrite( $f, pack( 'L', strlen( $meta ) ) );
	fwrite( $f, $meta );
	fwrite( $f, $cached_contents );

	// Close the file.
	fclose( $f );

	// Atomic (hopefully) rename.
	if ( ! rename( CACHE_DIR . "/{$level}/{$cache_key}.{$hash}.php",
		CACHE_DIR . "/{$level}/{$cache_key}.php" )
	) {
		unlink( CACHE_DIR . "/{$level}/{$cache_key}.{$hash}.php" );
	}

	return $contents;
};

// Attach to main output buffer.
ob_start( $ob_callback );
