<?php
/**
 * Location of the Anzen configuration.
 *
 * The configuration (with the secret key) must not live in the plugin folder: WordPress deletes
 * that folder on every update. Default location is one level above the WordPress directory:
 * ../secrets/anzen-<site folder>.php (next to the Tegatai secrets). Another path can be set
 * in wp-config.php with ANZEN_CONFIG_FILE.
 *
 * No WordPress functions here: serve.php uses this file without loading WordPress.
 */
if ( ! defined( 'ABSPATH' ) && ! defined( 'ANZEN_SERVING' ) ) {
    exit;
}

// Old location inside the plugin folder (Anzen 1.3 and earlier)
function anzen_legacy_config_file() {
    return __DIR__ . '/../config.php';
}

// WordPress directory: the first parent folder of $start that contains wp-load.php
function anzen_find_wp_root( $start ) {
    $dir = rtrim( str_replace( '\\', '/', (string) $start ), '/' );
    for ( $i = 0; $i < 6 && $dir !== '' && $dir !== '.'; $i++ ) {
        if ( @is_file( $dir . '/wp-load.php' ) ) {
            return $dir;
        }
        $parent = dirname( $dir );
        if ( $parent === $dir ) {
            break;
        }
        $dir = $parent;
    }
    return '';
}

// One file per site: several WordPress sites can share the same parent folder (and secrets folder),
// but every site has its own uploads path. The folder name of the site is unique within that parent.
function anzen_default_config_file( $wp_root ) {
    $root = rtrim( str_replace( '\\', '/', (string) $wp_root ), '/' );
    $site = preg_replace( '/[^A-Za-z0-9._-]/', '_', basename( $root ) );
    return dirname( $root ) . '/secrets/anzen-' . ( $site !== '' ? $site : 'site' ) . '.php';
}

// ANZEN_CONFIG_FILE from wp-config.php, read without loading WordPress (serve.php)
function anzen_config_file_from_wp_config( $wp_root ) {
    foreach ( [ $wp_root . '/wp-config.php', dirname( $wp_root ) . '/wp-config.php' ] as $wpc ) {
        if ( ! @is_file( $wpc ) ) {
            continue;
        }
        $src = (string) @file_get_contents( $wpc );
        if ( preg_match( '/define\s*\(\s*[\x27\x22]ANZEN_CONFIG_FILE[\x27\x22]\s*,\s*[\x27\x22]([^\x27\x22]+)[\x27\x22]\s*\)/', $src, $m ) ) {
            return $m[1];
        }
        return '';
    }
    return '';
}

/**
 * Configuration file to use: [path, is_legacy]. path is '' when none exists.
 * $wp_root is ABSPATH inside WordPress; serve.php passes '' and it is searched.
 */
function anzen_locate_config( $wp_root = '' ) {
    if ( $wp_root === '' ) {
        $wp_root = anzen_find_wp_root( __DIR__ );
    }
    $candidates = [];
    if ( defined( 'ANZEN_CONFIG_FILE' ) && is_string( ANZEN_CONFIG_FILE ) && ANZEN_CONFIG_FILE !== '' ) {
        $candidates[] = ANZEN_CONFIG_FILE;
    }
    if ( $wp_root !== '' ) {
        $candidates[] = anzen_default_config_file( $wp_root );
    }
    foreach ( $candidates as $file ) {
        if ( @is_file( $file ) && @is_readable( $file ) ) {
            return [ $file, false ];
        }
    }
    if ( ! defined( 'ABSPATH' ) && $wp_root !== '' ) {
        // serve.php: a custom path from wp-config.php
        $custom = anzen_config_file_from_wp_config( $wp_root );
        if ( $custom !== '' && @is_file( $custom ) && @is_readable( $custom ) ) {
            return [ $custom, false ];
        }
    }
    $legacy = anzen_legacy_config_file();
    if ( @is_file( $legacy ) ) {
        return [ $legacy, true ];
    }
    return [ '', false ];
}

// Where new configurations are written
function anzen_target_config_file( $wp_root ) {
    if ( defined( 'ANZEN_CONFIG_FILE' ) && is_string( ANZEN_CONFIG_FILE ) && ANZEN_CONFIG_FILE !== '' ) {
        return ANZEN_CONFIG_FILE;
    }
    return anzen_default_config_file( $wp_root );
}

/**
 * Writes the configuration outside the plugin folder (directory 0700, file 0600).
 * Returns the path or '' when the location is not writable (e.g. open_basedir).
 */
function anzen_write_config( $wp_root, $content ) {
    $file = anzen_target_config_file( $wp_root );
    $dir = dirname( $file );
    if ( ! @is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) ) {
        return '';
    }
    if ( @file_put_contents( $file, $content, LOCK_EX ) === false ) {
        return '';
    }
    @chmod( $file, 0600 );
    return $file;
}
