<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CWPA_LCP {

    public static function register_hooks() {
        if ( ! get_option( 'cwpa_lcp_enabled' ) ) return;
        add_action( 'wp_head',    [ __CLASS__, 'output_preload' ],    1 );
        add_action( 'wp_head',    [ __CLASS__, 'output_preconnect' ], 2 );
        add_filter( 'the_content', [ __CLASS__, 'optimize_lcp_image' ], 5 );
    }

    // ── Détection URL image LCP ───────────────────────────────────────────────
    public static function get_lcp_url() {
        // Override manuel configuré par l'admin
        $manual = get_option( 'cwpa_lcp_manual_url', '' );
        if ( $manual ) return $manual;

        // Image mise en avant du post/page courant
        if ( is_singular() && has_post_thumbnail() ) {
            $img = wp_get_attachment_image_src( get_post_thumbnail_id(), 'large' );
            if ( $img ) return $img[0];
        }

        // Première image dans le contenu
        if ( is_singular() ) {
            $content = get_the_content();
            if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $m ) ) {
                return $m[1];
            }
        }

        // Logo du site comme fallback pour les pages sans image
        $logo_id = get_theme_mod( 'custom_logo' );
        if ( $logo_id ) {
            $logo = wp_get_attachment_image_src( $logo_id, 'full' );
            if ( $logo ) return $logo[0];
        }

        return '';
    }

    // ── <link rel="preload"> dans <head> ─────────────────────────────────────
    public static function output_preload() {
        if ( is_admin() ) return;
        $url = self::get_lcp_url();
        if ( ! $url ) return;

        $ext     = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
        $type_map = [ 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'avif' => 'image/avif' ];
        $type    = $type_map[ $ext ] ?? '';

        echo '<link rel="preload" as="image" href="' . esc_url( $url ) . '"'
           . ( $type ? ' type="' . esc_attr( $type ) . '"' : '' )
           . ' fetchpriority="high">' . "\n";
    }

    // ── <link rel="preconnect"> dans <head> ──────────────────────────────────
    public static function output_preconnect() {
        if ( is_admin() ) return;
        $domains  = (array) get_option( 'cwpa_preconnect_domains', [] );
        $defaults = [ 'fonts.googleapis.com', 'fonts.gstatic.com' ];
        $all      = array_unique( array_merge( $defaults, $domains ) );
        foreach ( $all as $d ) {
            $d = trim( $d );
            if ( ! $d ) continue;
            echo '<link rel="preconnect" href="//' . esc_attr( ltrim( $d, '/' ) ) . '" crossorigin>' . "\n";
        }
    }

    // ── Optimise la 1ère image du contenu (candidate LCP) ────────────────────
    // - Retire loading="lazy" et decoding="async" (bloquent le rendu LCP)
    // - Ajoute fetchpriority="high"
    public static function optimize_lcp_image( $content ) {
        if ( is_admin() ) return $content;
        $count = 0;
        return preg_replace_callback( '/<img([^>]*)>/i', function ( $match ) use ( &$count ) {
            $count++;
            if ( $count !== 1 ) return $match[0];

            $attrs = $match[1];
            $attrs = preg_replace( '/\s+loading=["\'][^"\']*["\']/i',   '', $attrs );
            $attrs = preg_replace( '/\s+decoding=["\'][^"\']*["\']/i',  '', $attrs );
            if ( strpos( $attrs, 'fetchpriority' ) === false ) {
                $attrs = ' fetchpriority="high"' . $attrs;
            }
            return '<img' . $attrs . '>';
        }, $content );
    }

    // ── Statut pour l'admin ──────────────────────────────────────────────────
    public static function get_status() {
        return [
            'enabled'    => (bool) get_option( 'cwpa_lcp_enabled' ),
            'manual_url' => get_option( 'cwpa_lcp_manual_url', '' ),
            'domains'    => get_option( 'cwpa_preconnect_domains', [] ),
        ];
    }
}
