<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anzen_Setup {

    public function init() {
        add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        add_action( 'admin_notices', array( $this, 'admin_notice' ) );
    }

    public function add_plugin_page() {
        add_options_page(
            'Anzen Setup', 
            'Anzen Security', 
            'manage_options', 
            'anzen-setup', 
            array( $this, 'create_admin_page' )
        );
    }

    public function admin_notice() {
        $screen = get_current_screen();
        // Show notice only if the configuration is missing/unconfigured and user is not on the setup page
        if ( $screen && $screen->id !== 'settings_page_anzen-setup' ) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Anzen Security:</strong> The plugin is almost ready! <a href="' . esc_url( admin_url( 'options-general.php?page=anzen-setup' ) ) . '">Please click here to run the setup wizard</a>.</p></div>';
        }
    }

    public function create_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $upload_dir = wp_upload_dir();
        
        // Absolute filesystem path (required for readfile and file checks)
        $absolute_basedir = $upload_dir['basedir']; 
        
        // Relative web path (required for Nginx X-Accel-Redirect)
        $parsed_url = wp_parse_url( $upload_dir['baseurl'] );
        $relative_baseurl = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '/wp-content/uploads';

        // Dynamically determine the relative URI to serve.php (supports custom plugin dirs)
        $plugin_url = plugin_dir_url( dirname( __FILE__ ) );
        $serve_uri = rtrim( (string) wp_parse_url( $plugin_url, PHP_URL_PATH ), '/' ) . '/serve.php';

        // Handle form submission
        if ( isset( $_POST['anzen_setup_submit'] ) && current_user_can( 'manage_options' ) ) {
            check_admin_referer( 'anzen_setup_action', 'anzen_setup_nonce' );
            
            // Strict allow-list against code injection into the configuration file
            $raw_method = isset( $_POST['anzen_method'] ) ? sanitize_text_field( wp_unslash( $_POST['anzen_method'] ) ) : 'php';
            $method = ( $raw_method === 'nginx' ) ? 'nginx' : 'php';
            
            // Strict regex clean-up of the prefix
            $raw_prefix = isset( $_POST['anzen_prefix'] ) ? wp_unslash( $_POST['anzen_prefix'] ) : 'media';
            $prefix = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $raw_prefix );
            if ( empty( $prefix ) ) {
                $prefix = 'media'; // Fallback just in case
            }
            
            // If a valid ANZEN_KEY already exists, NEVER replace it: existing links must keep working
            // Token format: an existing key keeps its format (its links must stay valid);
            // a new installation starts with AES-256-GCM
            if ( defined( 'ANZEN_KEY' ) && is_string( ANZEN_KEY ) && strlen( ANZEN_KEY ) >= 16 ) {
                $key    = bin2hex( ANZEN_KEY );
                $cipher = anzen_cipher();
            } else {
                $key    = bin2hex( random_bytes( 32 ) );
                $cipher = 'gcm';
            }
            
            // Build config file content safely using var_export
            $config_content  = "<?php\n";
            $config_content .= "if ( ! defined( 'ABSPATH' ) && ! defined( 'ANZEN_SERVING' ) && ! str_contains( \$_SERVER['SCRIPT_NAME'] ?? '', 'serve.php' ) ) exit;\n";
            $config_content .= "// Auto-generated configuration. Do not modify manually!\n";
            $config_content .= "if ( ! defined( 'ANZEN_KEY' ) ) define( 'ANZEN_KEY', hex2bin( " . var_export( $key, true ) . " ) );\n";
            $config_content .= "if ( ! defined( 'ANZEN_TARGET_DIR' ) ) define( 'ANZEN_TARGET_DIR', " . var_export( $absolute_basedir, true ) . " );\n";
            $config_content .= "if ( ! defined( 'ANZEN_NGINX_URI' ) ) define( 'ANZEN_NGINX_URI', " . var_export( $relative_baseurl, true ) . " );\n";
            $config_content .= "if ( ! defined( 'ANZEN_METHOD' ) ) define( 'ANZEN_METHOD', " . var_export( $method, true ) . " );\n";
            $config_content .= "if ( ! defined( 'ANZEN_MASK_PREFIX' ) ) define( 'ANZEN_MASK_PREFIX', " . var_export( $prefix, true ) . " );\n";
            $config_content .= "if ( ! defined( 'ANZEN_CIPHER' ) ) define( 'ANZEN_CIPHER', " . var_export( $cipher, true ) . " );\n";
            
            // Outside the plugin folder (survives updates); the plugin folder is only the last fallback
            $wp_root     = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
            $config_file = anzen_write_config( $wp_root, $config_content );
            $in_plugin   = false;
            if ( $config_file === '' ) {
                $config_file = anzen_legacy_config_file();
                $in_plugin   = file_put_contents( $config_file, $config_content, LOCK_EX ) !== false;
                if ( $in_plugin ) {
                    @chmod( $config_file, 0640 );
                } else {
                    $config_file = '';
                }
            }

            if ( $config_file !== '' ) {
                echo '<div class="notice notice-success"><p><strong>✅ Setup successful!</strong> The configuration has been saved to <code>' . esc_html( $in_plugin ? realpath( $config_file ) : $config_file ) . '</code>.</p></div>';
                if ( $in_plugin ) {
                    echo '<div class="notice notice-warning"><p><strong>Warning:</strong> <code>' . esc_html( anzen_target_config_file( $wp_root ) ) . '</code> is not writable, so the configuration was saved in the plugin folder. The next plugin update deletes it. Make that folder writable for PHP (check <code>open_basedir</code>) or set another path in wp-config.php: <code>define( \'ANZEN_CONFIG_FILE\', \'/path/anzen-config.php\' );</code></p></div>';
                }
                
                echo '<h2>Final Step: Server Configuration</h2>';
                if ( $method === 'php' ) {
                    echo '<p>Add this code at the top of your <strong>.htaccess</strong> file (in your root directory):</p>';
                    echo '<pre style="background:#111; color:#0f0; padding:15px; border-radius:5px;">&lt;IfModule mod_rewrite.c&gt;' . "\n" . 'RewriteEngine On' . "\n" . 'RewriteRule ^' . esc_html( $prefix ) . '/([a-zA-Z0-9_-]+)$ ' . esc_html( ltrim( $serve_uri, '/' ) ) . '?t=$1 [L]' . "\n" . '&lt;/IfModule&gt;</pre>';
                } else {
                    echo '<p>Add this block to your <strong>Nginx vhost configuration</strong> (before the standard WordPress block):</p>';
                    echo '<pre style="background:#111; color:#0f0; padding:15px; border-radius:5px;">location ~ ^/' . esc_html( $prefix ) . '/([a-zA-Z0-9_-]+)$ {' . "\n" . '    rewrite ^/' . esc_html( $prefix ) . '/([a-zA-Z0-9_-]+)$ ' . esc_html( $serve_uri ) . '?t=$1 last;' . "\n" . '}</pre>';
                }
                echo '<p><i>Reload this page to exit the setup and activate the plugin.</i></p>';
                return; // Stop rendering the form
            } else {
                echo '<div class="notice notice-error"><p><strong>❌ Error:</strong> Could not write the configuration file. Please check the folder permissions.</p></div>';
            }
        }

        // Try to guess the server software for pre-selection
        $server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( $_SERVER['SERVER_SOFTWARE'] ) : '';
        $is_nginx = ( strpos( $server_software, 'nginx' ) !== false );
        ?>
        <div class="wrap">
            <h1>Anzen Security Setup</h1>
            <p>Configure how your image URLs should be masked and delivered.</p>
            
            <form method="post" action="">
                <?php wp_nonce_field( 'anzen_setup_action', 'anzen_setup_nonce' ); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Virtual Folder (Prefix)</th>
                        <td>
                            <code>/</code> <input type="text" name="anzen_prefix" value="media" class="regular-text" style="width: 150px;" pattern="[a-zA-Z0-9_-]+" title="Only letters, numbers, hyphens, and underscores allowed"> <code>/</code>
                            <p class="description">Choose the disguise name for your image URLs (e.g., <i>media</i>, <i>assets</i>, <i>files</i>).</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Delivery Method</th>
                        <td>
                            <label>
                                <input type="radio" name="anzen_method" value="php" <?php checked( ! $is_nginx ); ?>> 
                                <strong>Apache / LiteSpeed / PHP readfile() (Recommended)</strong><br>
                                <span class="description">Works out-of-the-box on almost any server. Images are delivered directly via PHP.</span>
                            </label><br><br>
                            <label>
                                <input type="radio" name="anzen_method" value="nginx" <?php checked( $is_nginx ); ?>> 
                                <strong>Nginx Fast-Lane (X-Accel-Redirect)</strong><br>
                                <span class="description">For Nginx servers only. Bypasses PHP during delivery for maximum performance.</span>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Upload Path (Auto-detected)</th>
                        <td>
                            <code><?php echo esc_html( $absolute_basedir ); ?></code>
                        </td>
                    </tr>
                </table>
                <p class="submit"><input type="submit" name="anzen_setup_submit" id="submit" class="button button-primary" value="Complete Setup & Generate Key"></p>
            </form>
        </div>
        <?php
    }
}
