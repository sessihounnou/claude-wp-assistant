<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CWPA_Optimizer {

    public static function init() {
        if ( get_option( 'cwpa_disable_emojis' ) )       self::setup_disable_emojis();
        if ( get_option( 'cwpa_disable_embeds' ) )       self::setup_disable_embeds();
        if ( get_option( 'cwpa_heartbeat_control' ) )    self::setup_heartbeat();
        if ( get_option( 'cwpa_defer_js' ) )             add_filter( 'script_loader_tag', [ __CLASS__, 'defer_js' ], 10, 3 );
        if ( get_option( 'cwpa_lazy_load' ) )            add_filter( 'the_content', [ __CLASS__, 'add_lazy_load' ] );
        if ( get_option( 'cwpa_html_minify' ) && ! is_admin() ) add_action( 'template_redirect', [ __CLASS__, 'start_html_minify' ], 999 );
        if ( get_option( 'cwpa_remove_query_strings' ) ) {
            add_filter( 'script_loader_src', [ __CLASS__, 'remove_query_strings' ], 15 );
            add_filter( 'style_loader_src',  [ __CLASS__, 'remove_query_strings' ], 15 );
        }
        if ( get_option( 'cwpa_dns_prefetch' ) ) add_action( 'wp_head', [ __CLASS__, 'output_dns_prefetch' ], 1 );
    }

    // ── Emojis ───────────────────────────────────────────────────────────────
    private static function setup_disable_emojis() {
        remove_action( 'wp_head',             'print_emoji_detection_script', 7 );
        remove_action( 'wp_print_styles',     'print_emoji_styles' );
        remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
        remove_action( 'admin_print_styles',  'print_emoji_styles' );
        remove_filter( 'the_content_feed',    'wp_staticize_emoji' );
        remove_filter( 'comment_text_rss',    'wp_staticize_emoji' );
        remove_filter( 'wp_mail',             'wp_staticize_emoji_for_email' );
        add_filter( 'tiny_mce_plugins', function( $plugins ) {
            return array_diff( $plugins, [ 'wpemoji' ] );
        } );
        add_filter( 'wp_resource_hints', function( $urls, $type ) {
            if ( $type === 'dns-prefetch' ) {
                $urls = array_filter( $urls, fn( $url ) => strpos( $url, 'fonts.googleapis.com' ) === false );
            }
            return $urls;
        }, 10, 2 );
    }

    // ── Embeds ───────────────────────────────────────────────────────────────
    private static function setup_disable_embeds() {
        remove_action( 'wp_head',       'wp_oembed_add_discovery_links' );
        remove_action( 'wp_head',       'wp_oembed_add_host_js' );
        remove_action( 'rest_api_init', 'wp_oembed_register_route' );
        add_filter( 'embed_oembed_discover', '__return_false' );
        add_filter( 'rewrite_rules_array', function( $rules ) {
            foreach ( $rules as $rule => $rewrite ) {
                if ( strpos( $rewrite, 'embed=true' ) !== false ) unset( $rules[ $rule ] );
            }
            return $rules;
        } );
        wp_deregister_script( 'wp-embed' );
    }

    // ── Heartbeat ────────────────────────────────────────────────────────────
    private static function setup_heartbeat() {
        add_filter( 'heartbeat_settings', function( $s ) {
            $s['interval'] = 60;
            return $s;
        } );
        add_action( 'admin_enqueue_scripts', function() {
            wp_deregister_script( 'heartbeat' );
        }, 1 );
    }

    // ── Defer JS ─────────────────────────────────────────────────────────────
    public static function defer_js( $tag, $handle, $src ) {
        if ( is_admin() ) return $tag;
        $skip = [ 'jquery', 'jquery-core', 'jquery-migrate' ];
        if ( in_array( $handle, $skip, true ) ) return $tag;
        if ( strpos( $tag, 'defer' ) !== false ) return $tag;
        return str_replace( ' src=', ' defer="defer" src=', $tag );
    }

    // ── Lazy Load ────────────────────────────────────────────────────────────
    public static function add_lazy_load( $content ) {
        if ( is_admin() ) return $content;
        return preg_replace( '/<img(?![^>]*loading=)([^>]*)>/i', '<img loading="lazy"$1>', $content );
    }

    // ── HTML Minify ──────────────────────────────────────────────────────────
    public static function start_html_minify() {
        ob_start( [ __CLASS__, 'minify_html' ] );
    }

    public static function minify_html( $buffer ) {
        if ( strlen( $buffer ) < 255 ) return $buffer;
        // Strip HTML comments (keep IE conditionals)
        $buffer = preg_replace( '/<!--(?!\[if\s)(?!.*?\[endif\]).*?-->/s', '', $buffer );
        // Collapse whitespace between tags
        $buffer = preg_replace( '/>\s{2,}</', '> <', $buffer );
        return trim( $buffer );
    }

    // ── Query Strings ────────────────────────────────────────────────────────
    public static function remove_query_strings( $src ) {
        if ( ! $src ) return $src;
        $parts = explode( '?ver=', $src );
        return $parts[0] ?? $src;
    }

    // ── DNS Prefetch ─────────────────────────────────────────────────────────
    public static function output_dns_prefetch() {
        $domains = get_option( 'cwpa_dns_prefetch_domains', [] );
        if ( empty( $domains ) ) {
            $domains = [ 'fonts.googleapis.com', 'fonts.gstatic.com', 'cdnjs.cloudflare.com' ];
        }
        foreach ( $domains as $d ) {
            echo '<link rel="dns-prefetch" href="//' . esc_attr( ltrim( trim( $d ), '/' ) ) . '">' . "\n";
        }
    }

    // ── Status ───────────────────────────────────────────────────────────────
    public static function get_status() {
        return [
            'page_cache'           => (bool) get_option( 'cwpa_page_cache' ),
            'gzip'                 => CWPA_Htaccess::has_section( 'GZIP' ),
            'browser_cache'        => CWPA_Htaccess::has_section( 'BROWSER_CACHE' ),
            'disable_emojis'       => (bool) get_option( 'cwpa_disable_emojis' ),
            'disable_embeds'       => (bool) get_option( 'cwpa_disable_embeds' ),
            'heartbeat_control'    => (bool) get_option( 'cwpa_heartbeat_control' ),
            'defer_js'             => (bool) get_option( 'cwpa_defer_js' ),
            'lazy_load'            => (bool) get_option( 'cwpa_lazy_load' ),
            'html_minify'          => (bool) get_option( 'cwpa_html_minify' ),
            'remove_query_strings' => (bool) get_option( 'cwpa_remove_query_strings' ),
            'dns_prefetch'         => (bool) get_option( 'cwpa_dns_prefetch' ),
            'webp_serving'         => CWPA_Htaccess::has_section( 'WEBP' ),
            'webp_auto'            => (bool) get_option( 'cwpa_webp_auto' ),
        ];
    }
}
