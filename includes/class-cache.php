<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CWPA_Cache {

    const TTL = 3600;

    private static function cache_dir() {
        return WP_CONTENT_DIR . '/cache/cwpa-pages/';
    }

    public static function init() {
        if ( ! get_option( 'cwpa_page_cache' ) ) return;

        add_action( 'template_redirect', [ __CLASS__, 'maybe_serve_cache' ], 1 );
        add_action( 'save_post',          [ __CLASS__, 'clear_all' ] );
        add_action( 'comment_post',       [ __CLASS__, 'clear_all' ] );
        add_action( 'wp_trash_post',      [ __CLASS__, 'clear_all' ] );
        add_action( 'switch_theme',       [ __CLASS__, 'clear_all' ] );
        add_action( 'upgrader_process_complete', [ __CLASS__, 'clear_all' ] );
    }

    public static function maybe_serve_cache() {
        // Don't cache logged-in users, POST requests, or pages with query args
        if ( is_user_logged_in() ) return;
        if ( $_SERVER['REQUEST_METHOD'] !== 'GET' ) return;
        if ( ! empty( $_GET ) ) return;
        if ( is_admin() ) return;
        if ( ! is_singular() && ! is_front_page() && ! is_home() ) return;

        $cache_file = self::get_cache_file();

        if ( file_exists( $cache_file ) && ( time() - filemtime( $cache_file ) ) < self::TTL ) {
            header( 'X-CWPA-Cache: HIT' );
            readfile( $cache_file );
            exit;
        }

        ob_start( [ __CLASS__, 'write_cache' ] );
    }

    public static function write_cache( $buffer ) {
        if ( strlen( $buffer ) < 255 ) return $buffer;
        if ( is_user_logged_in() ) return $buffer;

        $file = self::get_cache_file();
        $dir  = dirname( $file );

        if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
        file_put_contents( $file, $buffer );

        return $buffer;
    }

    private static function get_cache_file() {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $host = $_SERVER['HTTP_HOST'] ?? 'default';
        $hash = md5( $host . $uri );
        return self::cache_dir() . $hash . '.html';
    }

    public static function clear_all() {
        if ( ! is_dir( self::cache_dir() ) ) return 0;
        $files   = glob( self::cache_dir() . '*.html' ) ?: [];
        $deleted = 0;
        foreach ( $files as $f ) {
            if ( @unlink( $f ) ) $deleted++;
        }
        return $deleted;
    }

    public static function get_stats() {
        if ( ! is_dir( self::cache_dir() ) ) {
            return [ 'files' => 0, 'size_kb' => 0 ];
        }
        $files = glob( self::cache_dir() . '*.html' ) ?: [];
        $size  = array_sum( array_map( 'filesize', $files ) );
        return [
            'files'   => count( $files ),
            'size_kb' => round( $size / 1024, 1 ),
        ];
    }
}
