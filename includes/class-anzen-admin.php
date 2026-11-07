<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Settings > Anzen Security after the setup: status of the installation and the switch of the
 * token format from AES-256-CBC to AES-256-GCM.
 */
class Anzen_Admin {

    private $config_file;

    public function __construct( $config_file ) {
        $this->config_file = (string) $config_file;
    }

    public function init() {
        add_action( 'admin_menu', array( $this, 'add_page' ) );
        add_action( 'admin_post_anzen_switch_gcm', array( $this, 'handle_switch' ) );
    }

    public function add_page() {
        add_options_page( 'Anzen Security', 'Anzen Security', 'manage_options', 'anzen-setup', array( $this, 'render' ) );
    }

    private function page_url( $msg = '' ) {
        $url = admin_url( 'options-general.php?page=anzen-setup' );
        return $msg !== '' ? add_query_arg( 'anzen_msg', $msg, $url ) : $url;
    }

    public function handle_switch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.', 403 );
        }
        check_admin_referer( 'anzen_switch_gcm' );

        if ( anzen_cipher() === 'gcm' ) {
            wp_safe_redirect( $this->page_url( 'already' ) );
            exit;
        }

        $file    = $this->config_file;
        $content = ( $file !== '' && is_readable( $file ) ) ? (string) file_get_contents( $file ) : '';
        if ( $content === '' || ! str_contains( $content, 'ANZEN_KEY' ) ) {
            wp_safe_redirect( $this->page_url( 'failed' ) );
            exit;
        }

        $line = "if ( ! defined( 'ANZEN_CIPHER' ) ) define( 'ANZEN_CIPHER', 'gcm' );";
        if ( preg_match( '/^.*[\x27\x22]ANZEN_CIPHER[\x27\x22].*$/m', $content ) ) {
            // An existing ANZEN_CIPHER line (e.g. set to cbc by hand) is replaced
            $content = preg_replace( '/^.*[\x27\x22]ANZEN_CIPHER[\x27\x22].*$/m', $line, $content, 1 );
        } else {
            $content = rtrim( $content ) . "\n" . $line . "\n";
        }

        if ( file_put_contents( $file, $content, LOCK_EX ) === false ) {
            wp_safe_redirect( $this->page_url( 'failed' ) );
            exit;
        }
        if ( function_exists( 'opcache_invalidate' ) ) {
            @opcache_invalidate( $file, true );
        }
        wp_safe_redirect( $this->page_url( 'switched' ) );
        exit;
    }

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $msg    = isset( $_GET['anzen_msg'] ) ? sanitize_key( wp_unslash( $_GET['anzen_msg'] ) ) : '';
        $cipher = anzen_cipher();
        $method = ( defined( 'ANZEN_METHOD' ) && ANZEN_METHOD === 'nginx' ) ? 'nginx (X-Accel-Redirect)' : 'PHP (readfile)';

        // Example token length for an average path
        $sample     = gmdate( 'Y/m' ) . '/example-image-1024x576.webp';
        $len_cbc    = strlen( anzen_make_token( $sample, 'cbc' ) );
        $len_gcm    = strlen( anzen_make_token( $sample, 'gcm' ) );
        ?>
        <div class="wrap">
            <h1>Anzen Security</h1>

            <?php if ( $msg === 'switched' ) : ?>
                <div class="notice notice-success"><p><strong>Switched to AES-256-GCM.</strong> New URLs use the GCM format. Existing CBC links keep working. Clear your page cache so pages get the new URLs.</p></div>
            <?php elseif ( $msg === 'failed' ) : ?>
                <div class="notice notice-error"><p><strong>The configuration file could not be changed.</strong> Check that PHP may write <code><?php echo esc_html( $this->config_file ); ?></code>, or add this line to it by hand: <code>if ( ! defined( 'ANZEN_CIPHER' ) ) define( 'ANZEN_CIPHER', 'gcm' );</code></p></div>
            <?php elseif ( $msg === 'already' ) : ?>
                <div class="notice notice-info"><p>AES-256-GCM is already active.</p></div>
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr><th scope="row">Status</th><td>Active</td></tr>
                <tr><th scope="row">Configuration file</th><td><code><?php echo esc_html( $this->config_file ); ?></code></td></tr>
                <tr><th scope="row">URL prefix</th><td><code>/<?php echo esc_html( ANZEN_MASK_PREFIX ); ?>/</code></td></tr>
                <tr><th scope="row">Delivery</th><td><?php echo esc_html( $method ); ?></td></tr>
                <tr><th scope="row">Token format</th><td>
                    <?php if ( $cipher === 'gcm' ) : ?>
                        <strong>AES-256-GCM</strong> (about <?php echo (int) $len_gcm; ?> characters). Older CBC links are still accepted.
                    <?php else : ?>
                        <strong>AES-256-CBC + HMAC-SHA256</strong> (about <?php echo (int) $len_cbc; ?> characters)
                    <?php endif; ?>
                </td></tr>
            </table>

            <?php if ( $cipher !== 'gcm' ) : ?>
                <h2>Switch to AES-256-GCM</h2>
                <p>GCM encrypts and authenticates in one step and makes the URLs much shorter (about <?php echo (int) $len_gcm; ?> instead of <?php echo (int) $len_cbc; ?> characters).</p>
                <ul style="list-style:disc;padding-left:20px">
                    <li>The key stays the same.</li>
                    <li>All existing links (CBC) keep working: cached pages, shared links and search engine entries are not affected.</li>
                    <li>From then on, pages show the new, shorter URLs. Clear your page cache afterwards so cached pages get them too.</li>
                    <li>The switch writes one line into the configuration file. To go back, remove that line.</li>
                </ul>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Switch new URLs to AES-256-GCM? Existing links keep working.');">
                    <input type="hidden" name="action" value="anzen_switch_gcm">
                    <?php wp_nonce_field( 'anzen_switch_gcm' ); ?>
                    <?php submit_button( 'Switch to AES-256-GCM', 'primary', 'submit', false ); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}
