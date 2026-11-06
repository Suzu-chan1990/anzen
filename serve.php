<?php
// NO WORDPRESS BOOT! This file runs in milliseconds.
define( 'ANZEN_SERVING', true );

$config_path = __DIR__ . '/config.php';
if ( ! file_exists( $config_path ) ) {
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

// Bewiesener Nginx Bypass: Token direkt aus der URI lesen
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url( $request_uri, PHP_URL_PATH );
$token = $path ? basename( $path ) : '';

if ( empty( $token ) || $token === ANZEN_MASK_PREFIX || $token === 'serve.php' ) {
    http_response_code( 404 );
    exit;
}

$b64 = strtr( $token, '-_', '+/' );
$raw = base64_decode( $b64 );

if ( $raw === false || strlen( $raw ) <= 16 ) {
    http_response_code( 404 );
    exit;
}

$iv = substr( $raw, 0, 16 );
$ciphertext = substr( $raw, 16 );

$payload = openssl_decrypt( $ciphertext, 'aes-256-cbc', ANZEN_KEY, OPENSSL_RAW_DATA, $iv );
if ( ! $payload ) {
    // Timing-Ausgleich gegen Padding-Oracle-Seitenkanaele
    hash_hmac( 'sha256', $token, ANZEN_KEY );
    http_response_code( 404 );
    exit;
}

$parts = explode( '|', $payload );
if ( count( $parts ) < 2 ) {
    http_response_code( 404 );
    exit;
}

$signature = array_pop( $parts );
$file_name = implode( '|', $parts );

$expected_sig = hash_hmac( 'sha256', $file_name, ANZEN_KEY );
if ( ! hash_equals( $expected_sig, $signature ) ) {
    http_response_code( 404 );
    exit;
}

// Zusaetzliche Konsistenzpruefung des deterministischen IV (100% kompatibel mit allen generierten Links)
$expected_iv = substr( hash( 'sha256', $file_name . ANZEN_KEY ), 0, 16 );
if ( ! hash_equals( $expected_iv, $iv ) ) {
    http_response_code( 404 );
    exit;
}

// Strikter Schutz vor Directory Traversal & Null-Bytes
$file_name = rawurldecode( $file_name );
if (
    str_contains( $file_name, "\0" ) ||
    preg_match( '#(?:^|/|\\\\)\.\.?(?:/|\\\\|$)#', $file_name ) ||
    ! preg_match( '/\.(jpg|jpeg|png|gif|webp|svg|mp4|webm|mp3|wav|ogg)$/i', $file_name )
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

// 1. Header setzen fuer sauberes und sicheres Browser-Rendering
$mime = mime_content_type( $real_path );
header( 'Content-Type: ' . ( $mime ? $mime : 'application/octet-stream' ) );
header( 'X-Content-Type-Options: nosniff' );
if ( $mime === 'image/svg+xml' ) {
    header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox" );
}
header( 'Cache-Control: public, max-age=31536000, immutable' );

// 2. Auslieferung starten (ohne Debug-Texte)
if ( defined( 'ANZEN_METHOD' ) && ANZEN_METHOD === 'php' ) {
    header( 'Content-Length: ' . filesize( $real_path ) );
    readfile( $real_path );
    exit;
} else {
    // Nginx Fast-Lane (X-Accel-Redirect) mit validiertem Relativpfad
    $clean_rel_path = str_replace( '\\', '/', substr( $real_path, strlen( $base_dir ) + 1 ) );
    $nginx_uri = defined( 'ANZEN_NGINX_URI' ) ? ANZEN_NGINX_URI : '/wp-content/uploads';
    $redirect_path = rtrim( $nginx_uri, '/' ) . '/' . ltrim( $clean_rel_path, '/' );
    header( 'X-Accel-Redirect: ' . $redirect_path );
    exit;
}
