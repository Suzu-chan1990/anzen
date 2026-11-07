<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anzen_URL_Masker {

    private $target_dir;
    private $upload_base_url;
    private $mask_prefix;
    // Set while image_downsize() runs: it builds the URL of a resized image from wp_get_attachment_url()
    // by replacing the file name, so that URL must stay unmasked (the result is masked afterwards)
    private $skip_next_url = false;

    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->upload_base_url = $upload_dir['baseurl'];
        $this->mask_prefix = defined( 'ANZEN_MASK_PREFIX' ) ? ANZEN_MASK_PREFIX : 'media';
        $this->target_dir = ANZEN_TARGET_DIR;
    }

    public function init() {
        if ( function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
            wp_cache_add_non_persistent_groups( 'anzen' );
        }
        if ( is_admin() ) return;

        add_filter( 'image_downsize', array( $this, 'before_downsize' ), PHP_INT_MAX, 3 );
        add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 999, 2 );
        add_filter( 'get_the_post_thumbnail_url', array( $this, 'mask_attachment_url' ), 999, 2 );
        add_filter( 'wp_calculate_image_srcset', array( $this, 'mask_srcset' ), 999, 5 );
        add_filter( 'post_thumbnail_html', array( $this, 'mask_thumbnail_html' ), 999, 5 );
        add_filter( 'the_content', array( $this, 'mask_content_urls' ), 999 );
        add_filter( 'wp_get_attachment_image_src', array( $this, 'mask_attachment_image_src' ), 999, 4 );
        add_filter( 'wp_get_attachment_image', array( $this, 'mask_attachment_image' ), 999, 5 );
    }

    // Runs last on image_downsize. Only when no other filter short-circuits, image_downsize() calls
    // wp_get_attachment_url() next; that one call stays unmasked.
    public function before_downsize( $out, $id, $size ) {
        if ( $out === false ) {
            $this->skip_next_url = true;
        }
        return $out;
    }

    public function filter_attachment_url( $url, $post_id ) {
        if ( $this->skip_next_url ) {
            $this->skip_next_url = false;
            return $url;
        }
        return $this->mask_attachment_url( $url, $post_id );
    }

    public function mask_attachment_url( $url, $post_id ) {
        if ( empty( $url ) || strpos( $url, $this->upload_base_url ) === false ) return $url;
        $file_name = $this->get_relative_upload_path( $url );
        return $file_name ? $this->get_masked_url( $file_name ) : $url;
    }

    public function mask_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
        if ( ! is_array( $sources ) ) return $sources;
        foreach ( $sources as $width => $source ) {
            if ( isset( $source['url'] ) ) $sources[ $width ]['url'] = $this->mask_attachment_url( $source['url'], $attachment_id );
        }
        return $sources;
    }

    public function mask_thumbnail_html( $html, $post_id = 0, $post_thumbnail_id = 0, $size = '', $attr = '' ) {
        // \x22 and \x27 avoid escaping quotes
        return preg_replace_callback('/(src|srcset)=([\x22\x27])(https?:\/\/[^\x22\x27]+?)\2/i', array( $this, 'replace_html_attribute_callback' ), $html);
    }

    private function replace_html_attribute_callback( $matches ) {
        $attr_name = $matches[1];
        $quote     = $matches[2];
        $url       = $matches[3];
        $file_name = $this->get_relative_upload_path( $url );
        $final_url = $file_name ? $this->get_masked_url( $file_name ) : $url;
        return $attr_name . '=' . $quote . $final_url . $quote;
    }

    public function mask_content_urls( $content ) {
        if ( empty( $content ) ) return $content;
        $base_url_no_protocol = preg_replace( '/^https?:\/\//i', '', $this->upload_base_url );
        // Hex codes for quotes, so the pattern survives editing in console editors
        $pattern = '/(https?:\/\/' . preg_quote( $base_url_no_protocol . '/', '/' ) . ')([^\x22\x27\s><]+\.(?:' . anzen_media_extensions() . ')(?:\?[^\x22\x27\s><]*)?)/i';
        return preg_replace_callback( $pattern, array( $this, 'replace_single_url_callback' ), $content );
    }

    private function replace_single_url_callback( $matches ) {
        $full_url = $matches[0];
        $file_name = $this->get_relative_upload_path( $full_url );
        return $file_name ? $this->get_masked_url( $file_name ) : $full_url;
    }

    private function get_masked_url( $file_name ) {
        $cipher    = anzen_cipher();
        $cache_key = 'anzen_mask_' . $cipher . '_' . md5( $file_name );
        if ( $cached = wp_cache_get( $cache_key, 'anzen' ) ) return $cached;

        // Format of new URLs: CBC (unchanged for existing installations) or GCM, see includes/crypto.php
        $token = anzen_make_token( $file_name, $cipher );
        if ( $token === '' ) {
            return get_site_url() . '/' . $this->mask_prefix . '/';
        }

        $masked_url = get_site_url() . '/' . $this->mask_prefix . '/' . $token;
        wp_cache_set( $cache_key, $masked_url, 'anzen', 86400 );
        return $masked_url;
    }

    private function get_relative_upload_path( $full_url ) {
        $path = wp_parse_url( $full_url, PHP_URL_PATH );
        if ( ! $path ) return false;

        $upload_path = wp_parse_url( $this->upload_base_url, PHP_URL_PATH );
        
        if ( strpos( $path, $upload_path ) !== 0 ) {
            return false;
        }

        if ( ! preg_match( '/\.(' . anzen_media_extensions() . ')$/i', $path ) ) {
            return false;
        }

        $relative = substr( $path, strlen( $upload_path ) );
        $relative = ltrim( $relative, '/' );

        // Rejects traversal segments (also URL-encoded like %2e%2e) and null bytes, but keeps every
        // regular path unchanged, so tokens stay identical
        $decoded_relative = rawurldecode( $relative );
        if (
            str_contains( $relative, "\0" ) ||
            str_contains( $decoded_relative, "\0" ) ||
            preg_match( '#(?:^|/|\\\\)\.\.?(?:/|\\\\|$)#', $decoded_relative )
        ) {
            return false;
        }

        if ( empty( $relative ) ) return false;
        return $relative;
    }

    public function mask_attachment_image_src( $image, $attachment_id, $size, $icon ) {
        if ( ! is_array( $image ) || empty( $image[0] ) ) return $image;
        $image[0] = $this->mask_attachment_url( $image[0], $attachment_id );
        return $image;
    }

    public function mask_attachment_image( $html, $attachment_id, $size, $icon, $attr ) {
        return $this->mask_thumbnail_html( $html, 0, $attachment_id, $size, $attr );
    }
}
