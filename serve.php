<?php
// NO WORDPRESS BOOT! This file runs in milliseconds.
define( 'ANZEN_SERVING', true );

// Configuration outside the plugin folder (../secrets/anzen-<site folder>.php, ANZEN_CONFIG_FILE) or the old config.php
require_once __DIR__ . '/includes/config-path.php';
require_once __DIR__ . '/includes/crypto.php';
list( $config_path ) = anzen_locate_config( '' );
if ( $config_path === '' ) {
    http_response_code( 404 );
    exit;
}

require_once $config_path;

if (
    ! defined( 'ANZEN_KEY' ) || ! is_string( ANZEN_KEY ) || strlen( ANZEN_KEY ) === 0 ||
    ! defined( 'ANZEN_TARGET_DIR' ) || ! is_string( ANZEN_TARGET_DIR ) || ANZEN_TARGET_DIR === '' ||
    ! defined( 'ANZEN_MASK_PREFIX' )
) {
    http_response_code( 500 );
    exit;
}

// Token is read from the URI directly (works when nginx/FastCGI drop the query string)
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url( $request_uri, PHP_URL_PATH );
$token = $path ? basename( $path ) : '';

if ( empty( $token ) || $token === ANZEN_MASK_PREFIX || $token === 'serve.php' ) {
    http_response_code( 404 );
    exit;
}

// Both token formats (CBC and GCM) are accepted, see includes/crypto.php
$file_name = anzen_read_token( $token );
if ( $file_name === '' ) {
    http_response_code( 404 );
    exit;
}

// Strict protection against directory traversal and null bytes
$file_name = rawurldecode( $file_name );
if (
    str_contains( $file_name, "\0" ) ||
    preg_match( '#(?:^|/|\\\\)\.\.?(?:/|\\\\|$)#', $file_name ) ||
    ! preg_match( '/\.(' . anzen_media_extensions() . ')$/i', $file_name )
) {
    http_response_code( 404 );
    exit;
}

$base_dir = realpath( ANZEN_TARGET_DIR );
$real_path = $base_dir ? realpath( $base_dir . '/' . ltrim( $file_name, '/' ) ) : false;

if (
    ! $base_dir ||
    ! $real_path ||
    ! str_starts_with( $real_path, $base_dir . DIRECTORY_SEPARATOR ) ||
    ! is_file( $real_path )
) {
    http_response_code( 404 );
    exit;
}

// 1. Headers (content type by extension; mime_content_type() does not know AVIF/JXL everywhere)
$mime = anzen_mime_type( $real_path );
header( 'Content-Type: ' . $mime );
header( 'X-Content-Type-Options: nosniff' );
if ( $mime === 'image/svg+xml' ) {
    header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox" );
}
header( 'Cache-Control: public, max-age=31536000, immutable' );

// 2. Delivery (no debug output)
if ( defined( 'ANZEN_METHOD' ) && ANZEN_METHOD === 'php' ) {
    header( 'Content-Length: ' . filesize( $real_path ) );
    readfile( $real_path );
    exit;
} else {
    // nginx fast lane (X-Accel-Redirect) with the validated relative path
    $clean_rel_path = str_replace( '\\', '/', substr( $real_path, strlen( $base_dir ) + 1 ) );
    $nginx_uri = defined( 'ANZEN_NGINX_URI' ) ? ANZEN_NGINX_URI : '/wp-content/uploads';
    $redirect_path = rtrim( $nginx_uri, '/' ) . '/' . ltrim( $clean_rel_path, '/' );
    header( 'X-Accel-Redirect: ' . $redirect_path );
    exit;
}
