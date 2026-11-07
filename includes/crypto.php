<?php
/**
 * Token format and file types, shared by the masker (WordPress) and serve.php (no WordPress).
 *
 * Two token formats:
 *  - CBC (Anzen 1.0 - 1.4): base64url( IV | AES-256-CBC( path "|" HMAC-SHA256(path) ) ).
 *    The IV is the first 16 hex characters of sha256(path . key), so these tokens always start
 *    with M, N, O, Y or Z (base64 of an ASCII hex digit). Kept unchanged: existing links stay valid.
 *  - GCM (since 1.4.1): "g" + base64url( nonce | AES-256-GCM( path ) | tag ).
 *    The lower-case "g" can never start a CBC token, so serve.php tells the formats apart by the
 *    first character. The nonce is derived from the path with its own sub-key (HMAC), so the same
 *    file always gets the same token (caches keep working) and a nonce is only ever reused for the
 *    same plaintext. Encryption key and nonce key are derived from ANZEN_KEY with HKDF.
 *
 * Both formats are always accepted when serving, whatever ANZEN_CIPHER says. ANZEN_CIPHER only
 * decides which format new URLs get.
 */
if ( ! defined( 'ABSPATH' ) && ! defined( 'ANZEN_SERVING' ) ) {
    exit;
}

// File types that are masked and delivered
function anzen_media_extensions() {
    return 'jpg|jpeg|png|gif|webp|avif|jxl|svg|mp4|webm|mp3|wav|ogg';
}

// Content types by extension (mime_content_type() does not know AVIF/JXL on older systems)
function anzen_mime_type( $file ) {
    static $map = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'jxl'  => 'image/jxl',
        'svg'  => 'image/svg+xml',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'ogg'  => 'audio/ogg',
    ];
    $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
    return $map[ $ext ] ?? 'application/octet-stream';
}

// Format for new URLs: 'gcm' or 'cbc' (default for installations without ANZEN_CIPHER)
function anzen_cipher() {
    return ( defined( 'ANZEN_CIPHER' ) && ANZEN_CIPHER === 'gcm' ) ? 'gcm' : 'cbc';
}

function anzen_b64url_encode( $raw ) {
    return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
}

function anzen_b64url_decode( $str ) {
    return base64_decode( strtr( $str, '-_', '+/' ), true );
}

function anzen_gcm_keys() {
    static $keys = null;
    if ( $keys === null ) {
        $keys = [
            hash_hkdf( 'sha256', ANZEN_KEY, 32, 'anzen-gcm-enc' ),
            hash_hkdf( 'sha256', ANZEN_KEY, 32, 'anzen-gcm-nonce' ),
        ];
    }
    return $keys;
}

const ANZEN_GCM_AAD = 'anzen-token-v2';

// Token for a path relative to the uploads folder, in the given format
function anzen_make_token( $path, $cipher = null ) {
    $cipher = $cipher ?? anzen_cipher();

    if ( $cipher === 'gcm' ) {
        list( $enc_key, $nonce_key ) = anzen_gcm_keys();
        $nonce = substr( hash_hmac( 'sha256', $path, $nonce_key, true ), 0, 12 );
        $tag   = '';
        $ct    = openssl_encrypt( $path, 'aes-256-gcm', $enc_key, OPENSSL_RAW_DATA, $nonce, $tag, ANZEN_GCM_AAD, 16 );
        if ( $ct === false ) {
            return '';
        }
        return 'g' . anzen_b64url_encode( $nonce . $ct . $tag );
    }

    // CBC: unchanged from Anzen 1.0 - 1.4, so every existing token stays identical
    $signature = hash_hmac( 'sha256', $path, ANZEN_KEY );
    $iv        = substr( hash( 'sha256', $path . ANZEN_KEY ), 0, 16 );
    $ct        = openssl_encrypt( $path . '|' . $signature, 'aes-256-cbc', ANZEN_KEY, OPENSSL_RAW_DATA, $iv );
    return $ct === false ? '' : anzen_b64url_encode( $iv . $ct );
}

// Path from a token (either format) or '' when the token is invalid
function anzen_read_token( $token ) {
    if ( ! is_string( $token ) || $token === '' || ! preg_match( '/^[A-Za-z0-9_-]+$/', $token ) ) {
        return '';
    }

    if ( $token[0] === 'g' ) {
        $raw = anzen_b64url_decode( substr( $token, 1 ) );
        if ( $raw === false || strlen( $raw ) <= 28 ) {
            return '';
        }
        list( $enc_key, $nonce_key ) = anzen_gcm_keys();
        $nonce = substr( $raw, 0, 12 );
        $tag   = substr( $raw, -16 );
        $ct    = substr( $raw, 12, -16 );
        $path  = openssl_decrypt( $ct, 'aes-256-gcm', $enc_key, OPENSSL_RAW_DATA, $nonce, $tag, ANZEN_GCM_AAD );
        if ( $path === false || $path === '' ) {
            return '';
        }
        // The nonce must be the one derived from this path (one token per file)
        $expected = substr( hash_hmac( 'sha256', $path, $nonce_key, true ), 0, 12 );
        return hash_equals( $expected, $nonce ) ? $path : '';
    }

    // CBC
    $raw = anzen_b64url_decode( $token );
    if ( $raw === false || strlen( $raw ) <= 16 ) {
        return '';
    }
    $iv      = substr( $raw, 0, 16 );
    $payload = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', ANZEN_KEY, OPENSSL_RAW_DATA, $iv );
    if ( ! $payload ) {
        // Same work as a valid token (timing side channel of the padding check)
        hash_hmac( 'sha256', $token, ANZEN_KEY );
        return '';
    }
    $parts = explode( '|', $payload );
    if ( count( $parts ) < 2 ) {
        return '';
    }
    $signature = array_pop( $parts );
    $path      = implode( '|', $parts );
    if ( ! hash_equals( hash_hmac( 'sha256', $path, ANZEN_KEY ), $signature ) ) {
        return '';
    }
    // The IV must be the one derived from this path
    if ( ! hash_equals( substr( hash( 'sha256', $path . ANZEN_KEY ), 0, 16 ), $iv ) ) {
        return '';
    }
    return $path;
}
