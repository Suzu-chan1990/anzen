<?php
/**
 * Plugin Name: Anzen
 * Description: Modular security plugin for URL masking. (v1.3 High-Performance)
 * Version: 1.4
 * Author: Saguya
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: anzen
 * Requires at least: 7.1
 * Requires PHP: 8.4
 * Update URI: https://github.com/Suzu-chan1990/anzen
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ANZEN_VERSION', '1.4' );
define( 'ANZEN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once ANZEN_PLUGIN_DIR . 'includes/config-path.php';

// The configuration lives outside the plugin folder (WordPress deletes that folder on updates)
list( $config_file, $anzen_config_legacy ) = anzen_locate_config( rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) );
$anzen_is_configured = false;

if ( $config_file !== '' ) {
    require_once $config_file;
    if (
        defined( 'ANZEN_KEY' ) && is_string( ANZEN_KEY ) && strlen( ANZEN_KEY ) > 0 &&
        defined( 'ANZEN_TARGET_DIR' ) && is_string( ANZEN_TARGET_DIR ) && ANZEN_TARGET_DIR !== ''
    ) {
        $anzen_is_configured = true;
    }
}

// Old config.php in the plugin folder: copy it to the new location (same key, links keep working).
// If that location cannot be written, the old file keeps working and the admin shows a warning.
if ( $anzen_is_configured && $anzen_config_legacy ) {
    $anzen_moved = anzen_write_config( rtrim( str_replace( '\\', '/', ABSPATH ), '/' ), (string) file_get_contents( $config_file ) );
    if ( $anzen_moved === '' ) {
        add_action( 'admin_notices', function () {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }
            $target = anzen_target_config_file( rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) );
            echo '<div class="notice notice-warning"><p><strong>Anzen:</strong> The configuration is still stored in the plugin folder and will be deleted by the next plugin update (a new key would break all masked links). It could not be copied to <code>' . esc_html( $target ) . '</code>. Make this folder writable for PHP (check <code>open_basedir</code>) or set another path in wp-config.php: <code>define( \'ANZEN_CONFIG_FILE\', \'/path/anzen-config.php\' );</code></p></div>';
        } );
    }
    unset( $anzen_moved );
}

// Default prefix only after config.php was loaded, otherwise the prefix chosen in the setup is never used
if ( ! defined( 'ANZEN_MASK_PREFIX' ) ) {
    define( 'ANZEN_MASK_PREFIX', 'media' );
}

// Switch: Load the masker if config exists and is valid, otherwise load the setup wizard
if ( $anzen_is_configured ) {
    require_once ANZEN_PLUGIN_DIR . 'includes/class-anzen-url-masker.php';
    
    function run_anzen() {
        $anzen_masker = new Anzen_URL_Masker();
        $anzen_masker->init();
    }
    run_anzen();

} else {
    require_once ANZEN_PLUGIN_DIR . 'includes/class-anzen-setup.php';
    
    function run_anzen_setup() {
        $anzen_setup = new Anzen_Setup();
        $anzen_setup->init();
    }
    run_anzen_setup();
}


// --- GitHub Updater (Plugin Update Checker) ---
// Built on every request (not only in the admin): the scheduled update checks and the automatic
// background updates run via WP-Cron and need the checker as well.
$anzen_puc = ANZEN_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $anzen_puc ) ) {
    require_once $anzen_puc;

    $anzen_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/Suzu-chan1990/anzen/',
        __FILE__,
        'anzen' // Plugin slug (must stay stable)
    );

    // Use the ZIP attached to the GitHub release (folder "anzen/" inside);
    // without one, the source archive of the release tag is used
    $anzen_update_checker->getVcsApi()->enableReleaseAssets();
}
unset( $anzen_puc );
