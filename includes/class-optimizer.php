<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CWPA_Optimizer {

    // ── Plugins d'optimisation connus — évite les doubles traitements ────────
    private static $conflict_map = [
        'gzip'                    => [ 'wp-rocket/wp-rocket.php', 'litespeed-cache/litespeed-cache.php', 'w3-total-cache/w3-total-cache.php', 'wp-super-cache/wp-cache.php' ],
        'browser_cache'           => [ 'wp-rocket/wp-rocket.php', 'litespeed-cache/litespeed-cache.php', 'w3-total-cache/w3-total-cache.php' ],
        'defer_js'                => [ 'wp-rocket/wp-rocket.php', 'autoptimize/autoptimize.php', 'litespeed-cache/litespeed-cache.php', 'flying-scripts/flying-scripts.php' ],
        'lazy_load'               => [ 'wp-rocket/wp-rocket.php', 'autoptimize/autoptimize.php', 'litespeed-cache/litespeed-cache.php', 'a3-lazy-load/a3-lazy-load.php', 'lazy-loader/lazy-loader.php' ],
        'html_minify'             => [ 'wp-rocket/wp-rocket.php', 'autoptimize/autoptimize.php', 'litespeed-cache/litespeed-cache.php', 'fast-velocity-minify/fast-velocity-minify.php' ],
        'remove_query_strings'    => [ 'wp-rocket/wp-rocket.php', 'autoptimize/autoptimize.php', 'litespeed-cache/litespeed-cache.php' ],
        'page_cache'              => [ 'wp-rocket/wp-rocket.php', 'litespeed-cache/litespeed-cache.php', 'w3-total-cache/w3-total-cache.php', 'wp-super-cache/wp-cache.php', 'comet-cache/comet-cache.php' ],
        'disable_jquery_migrate'  => [ 'wp-rocket/wp-rocket.php', 'autoptimize/autoptimize.php' ],
        'font_display_swap'       => [ 'wp-rocket/wp-rocket.php', 'autoptimize/autoptimize.php' ],
        'css_minify'              => [ 'wp-rocket/wp-rocket.php', 'autoptimize/autoptimize.php', 'litespeed-cache/litespeed-cache.php' ],
        'async_css'               => [ 'wp-rocket/wp-rocket.php', 'autoptimize/autoptimize.php', 'litespeed-cache/litespeed-cache.php' ],
        'remove_unused_wp_css'    => [ 'wp-rocket/wp-rocket.php', 'autoptimize/autoptimize.php' ],
    ];

    public static function has_conflict( $feature ) {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach ( self::$conflict_map[ $feature ] ?? [] as $plugin ) {
            if ( is_plugin_active( $plugin ) ) return true;
        }
        return false;
    }

    public static function get_conflict_name( $feature ) {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach ( self::$conflict_map[ $feature ] ?? [] as $plugin ) {
            if ( is_plugin_active( $plugin ) ) {
                return basename( dirname( $plugin ) ); // e.g. "wp-rocket"
            }
        }
        return null;
    }

    public static function init() {
        if ( get_option( 'cwpa_disable_emojis' ) )    self::setup_disable_emojis();
        if ( get_option( 'cwpa_disable_embeds' ) )    self::setup_disable_embeds();
        if ( get_option( 'cwpa_heartbeat_control' ) ) self::setup_heartbeat();

        if ( get_option( 'cwpa_defer_js' ) && ! self::has_conflict( 'defer_js' ) ) {
            add_filter( 'script_loader_tag', [ __CLASS__, 'defer_js' ], 10, 3 );
        }
        if ( get_option( 'cwpa_lazy_load' ) && ! self::has_conflict( 'lazy_load' ) ) {
            add_filter( 'the_content', [ __CLASS__, 'add_lazy_load' ] );
        }
        if ( get_option( 'cwpa_html_minify' ) && ! is_admin() && ! self::has_conflict( 'html_minify' ) ) {
            add_action( 'template_redirect', [ __CLASS__, 'start_html_minify' ], 999 );
        }
        if ( get_option( 'cwpa_remove_query_strings' ) && ! self::has_conflict( 'remove_query_strings' ) ) {
            add_filter( 'script_loader_src', [ __CLASS__, 'remove_query_strings' ], 15 );
            add_filter( 'style_loader_src',  [ __CLASS__, 'remove_query_strings' ], 15 );
        }
        if ( get_option( 'cwpa_dns_prefetch' ) ) {
            add_action( 'wp_head', [ __CLASS__, 'output_dns_prefetch' ], 1 );
        }

        // ── 4G / mobile optimizations ────────────────────────────────────────
        if ( get_option( 'cwpa_font_display_swap' ) && ! self::has_conflict( 'font_display_swap' ) ) {
            add_filter( 'style_loader_src', [ __CLASS__, 'add_font_display_swap' ], 10, 2 );
            add_action( 'wp_head', [ __CLASS__, 'inject_font_display_css' ], 50 );
        }
        if ( get_option( 'cwpa_remove_wp_bloat' ) ) {
            self::setup_remove_wp_bloat();
        }
        if ( get_option( 'cwpa_disable_jquery_migrate' ) && ! self::has_conflict( 'disable_jquery_migrate' ) ) {
            add_action( 'wp_default_scripts', [ __CLASS__, 'disable_jquery_migrate' ] );
        }
        if ( get_option( 'cwpa_preload_key_assets' ) ) {
            add_action( 'wp_head', [ __CLASS__, 'output_preload_key_assets' ], 2 );
        }
        if ( get_option( 'cwpa_save_data' ) ) {
            add_action( 'wp_head', [ __CLASS__, 'handle_save_data_meta' ], 1 );
            add_filter( 'the_content', [ __CLASS__, 'save_data_images' ] );
        }

        // ── CSS minify (inline <style> blocks) ───────────────────────────────
        if ( get_option( 'cwpa_css_minify' ) && ! is_admin() && ! self::has_conflict( 'html_minify' ) ) {
            add_action( 'template_redirect', [ __CLASS__, 'start_css_minify' ], 998 );
        }

        // ── CSS critique inline (premier rendu instantané) ────────────────────
        if ( get_option( 'cwpa_critical_css' ) && get_option( 'cwpa_critical_css_content' ) ) {
            add_action( 'wp_head', [ __CLASS__, 'inline_critical_css' ], 1 );
        }

        // ── Async CSS (élimine render-blocking resources) ─────────────────────
        if ( get_option( 'cwpa_async_css' ) && ! is_admin() && ! self::has_conflict( 'async_css' ) ) {
            add_filter( 'style_loader_tag', [ __CLASS__, 'async_css_tag' ], 10, 4 );
        }

        // ── Supprimer CSS WordPress inutile ───────────────────────────────────
        if ( get_option( 'cwpa_remove_unused_wp_css' ) ) {
            self::setup_remove_unused_wp_css();
        }

        // ── Image upload optimizations ───────────────────────────────────────
        if ( get_option( 'cwpa_jpeg_quality' ) ) {
            add_filter( 'jpeg_quality',           [ __CLASS__, 'set_jpeg_quality' ] );
            add_filter( 'wp_editor_set_quality',  [ __CLASS__, 'set_jpeg_quality' ] );
        }
        if ( get_option( 'cwpa_strip_exif' ) ) {
            add_filter( 'wp_handle_upload', [ __CLASS__, 'strip_exif_on_upload' ] );
        }
        if ( get_option( 'cwpa_limit_img_size' ) ) {
            add_filter( 'wp_handle_upload', [ __CLASS__, 'limit_upload_dimensions' ] );
        }
        if ( get_option( 'cwpa_img_decode_async' ) ) {
            add_filter( 'the_content', [ __CLASS__, 'add_decode_async' ] );
        }

        // ── Fallbacks PHP quand .htaccess n'est pas accessible ──────────────
        if ( get_option( 'cwpa_gzip_mode' ) === 'php' && ! self::has_conflict( 'gzip' ) ) {
            self::setup_php_gzip();
        }
        if ( get_option( 'cwpa_browser_cache_mode' ) === 'php' && ! self::has_conflict( 'browser_cache' ) ) {
            add_action( 'send_headers', [ __CLASS__, 'php_browser_cache_headers' ] );
        }
        if ( get_option( 'cwpa_webp_serve_mode' ) === 'php' ) {
            CWPA_WebP::register_php_serving();
        }
    }

    // ── PHP GZIP via zlib ────────────────────────────────────────────────────
    private static function setup_php_gzip() {
        if ( headers_sent() || ! extension_loaded( 'zlib' ) ) return;
        if ( ! is_admin() ) {
            add_action( 'template_redirect', function() {
                if ( ! ob_get_level() ) {
                    @ini_set( 'zlib.output_compression', '1' );
                    @ini_set( 'zlib.output_compression_level', '6' );
                }
            }, 1 );
        }
    }

    // ── PHP Browser Cache via headers ────────────────────────────────────────
    public static function php_browser_cache_headers() {
        if ( is_admin() || is_user_logged_in() ) return;

        $uri = $_SERVER['REQUEST_URI'] ?? '';

        if ( preg_match( '/\.(jpe?g|png|gif|webp|svg|ico|mp4|woff2?)(\?.*)?$/i', $uri ) ) {
            header( 'Cache-Control: public, max-age=31536000, immutable' );
            header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + 31536000 ) . ' GMT' );
        } elseif ( preg_match( '/\.(css|js)(\?.*)?$/i', $uri ) ) {
            header( 'Cache-Control: public, max-age=2592000' );
            header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + 2592000 ) . ' GMT' );
        }
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
    // Exclut la 1ère image (candidate LCP) — la mettre en lazy load ferait baisser le score
    public static function add_lazy_load( $content ) {
        if ( is_admin() ) return $content;
        $count = 0;
        return preg_replace_callback( '/<img(?![^>]*loading=)([^>]*)>/i', function ( $m ) use ( &$count ) {
            $count++;
            if ( $count === 1 ) return $m[0]; // skip LCP candidate
            return '<img loading="lazy"' . $m[1] . '>';
        }, $content );
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

    // ── CSS critique inline ──────────────────────────────────────────────────
    public static function inline_critical_css() {
        $css = get_option( 'cwpa_critical_css_content', '' );
        if ( ! $css ) return;
        echo '<style id="cwpa-critical">' . wp_strip_all_tags( $css ) . '</style>' . "\n";
    }

    // ── Async CSS — élimine le render-blocking CSS ───────────────────────────
    // Transforme chaque <link rel="stylesheet"> en preload non-bloquant
    public static function async_css_tag( $tag, $handle, $href, $media ) {
        if ( is_admin() || is_feed() ) return $tag;

        // Handles à ne jamais async (CSS admin, login, notre propre CSS)
        static $excluded = [ 'login', 'dashicons', 'admin-bar', 'cwpa-style', 'wp-admin' ];
        if ( in_array( $handle, $excluded, true ) ) return $tag;

        // Déjà preload ou commentaire conditionnel IE
        if ( strpos( $tag, 'rel="preload"' ) !== false
          || strpos( $tag, "rel='preload'" ) !== false
          || strpos( $tag, '<!--[if' ) !== false ) {
            return $tag;
        }

        $href_esc = esc_url( $href );
        return '<link rel="preload" href="' . $href_esc . '" as="style" onload="this.onload=null;this.rel=\'stylesheet\'">' . "\n"
             . '<noscript>' . $tag . '</noscript>' . "\n";
    }

    // ── Supprimer CSS WordPress inutile ─────────────────────────────────────
    // Retire les feuilles de style Gutenberg/blocs que la plupart des thèmes classiques n'utilisent pas
    private static function setup_remove_unused_wp_css() {
        add_action( 'wp_enqueue_scripts', function() {
            if ( is_admin() ) return;
            $to_remove = [
                'wp-block-library',        // CSS des blocs Gutenberg (~40 KB)
                'wp-block-library-theme',  // Styles thème blocs
                'classic-theme-styles',    // Backcompat CSS pour thèmes classiques (WP 6.1+)
                'global-styles',           // CSS généré par theme.json (WP 5.9+)
                'core-block-supports',     // Support CSS blocs core
            ];
            foreach ( $to_remove as $handle ) {
                wp_dequeue_style( $handle );
            }
        }, 100 );
    }

    // ── CSS minify (inline <style> blocks via output buffer) ─────────────────
    public static function start_css_minify() {
        ob_start( [ __CLASS__, 'minify_css_in_html' ] );
    }

    public static function minify_css_in_html( $buffer ) {
        if ( strlen( $buffer ) < 255 ) return $buffer;
        return preg_replace_callback( '/<style([^>]*)>(.*?)<\/style>/si', function( $m ) {
            $css = $m[2];
            // Strip CSS comments (keep IE conditionals)
            $css = preg_replace( '/\/\*(?!!)[^*]*\*+([^\/][^*]*\*+)*\//', '', $css );
            // Collapse whitespace
            $css = preg_replace( '/\s+/', ' ', $css );
            // Remove spaces around CSS syntax chars
            $css = preg_replace( '/\s*([:;{},>+~])\s*/', '$1', $css );
            // Remove trailing semicolons before closing brace
            $css = str_replace( ';}', '}', $css );
            return '<style' . $m[1] . '>' . trim( $css ) . '</style>';
        }, $buffer );
    }

    // ── JPEG quality ─────────────────────────────────────────────────────────
    public static function set_jpeg_quality() {
        return (int) get_option( 'cwpa_jpeg_quality_value', 80 );
    }

    // ── Strip EXIF metadata on upload ────────────────────────────────────────
    public static function strip_exif_on_upload( $file ) {
        if ( empty( $file['type'] ) || strpos( $file['type'], 'image/' ) !== 0 ) return $file;
        $path = $file['file'];

        if ( class_exists( 'Imagick' ) ) {
            try {
                $img = new Imagick( $path );
                $img->stripImage();
                $img->writeImage( $path );
                $img->destroy();
            } catch ( Exception $e ) { /* silent */ }
        } elseif ( extension_loaded( 'gd' ) && $file['type'] === 'image/jpeg' ) {
            // GD re-save naturally strips EXIF
            $im = @imagecreatefromjpeg( $path );
            if ( $im ) {
                imagejpeg( $im, $path, get_option( 'cwpa_jpeg_quality_value', 80 ) );
                imagedestroy( $im );
            }
        }
        return $file;
    }

    // ── Limit upload max dimensions ──────────────────────────────────────────
    public static function limit_upload_dimensions( $file ) {
        if ( empty( $file['type'] ) || strpos( $file['type'], 'image/' ) !== 0 ) return $file;
        $max  = (int) get_option( 'cwpa_max_img_dimension', 2048 );
        $path = $file['file'];
        list( $w, $h ) = @getimagesize( $path ) ?: [ 0, 0 ];
        if ( $w > $max || $h > $max ) {
            $editor = wp_get_image_editor( $path );
            if ( ! is_wp_error( $editor ) ) {
                $editor->resize( $max, $max, false );
                $editor->save( $path );
            }
        }
        return $file;
    }

    // ── Async image decoding ─────────────────────────────────────────────────
    // Frees the main thread on slow CPUs by letting images decode off-thread
    public static function add_decode_async( $content ) {
        if ( is_admin() ) return $content;
        return preg_replace_callback( '/<img(?![^>]*decoding=)([^>]*)>/i', function( $m ) {
            return '<img decoding="async"' . $m[1] . '>';
        }, $content );
    }

    // ── Font-display: swap ───────────────────────────────────────────────────
    // Append display=swap to Google Fonts stylesheet URLs
    public static function add_font_display_swap( $src, $handle ) {
        if ( strpos( $src, 'fonts.googleapis.com' ) !== false ) {
            if ( strpos( $src, 'display=' ) === false ) {
                $src = add_query_arg( 'display', 'swap', $src );
            }
        }
        return $src;
    }

    // Inject font-display: swap for self-hosted fonts (catch-all via CSS)
    public static function inject_font_display_css() {
        if ( is_admin() ) return;
        echo '<style id="cwpa-font-display">@font-face{font-display:swap}</style>' . "\n";
    }

    // ── Remove WordPress head bloat ──────────────────────────────────────────
    // Removes ~5 KB of rarely-needed meta tags and links from the <head>
    private static function setup_remove_wp_bloat() {
        remove_action( 'wp_head', 'wp_generator' );
        remove_action( 'wp_head', 'rsd_link' );
        remove_action( 'wp_head', 'wlwmanifest_link' );
        remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
        remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
        remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
        remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
        remove_action( 'template_redirect', 'rest_output_link_header', 11 );
        // Suppress WordPress version from scripts/styles query args
        add_filter( 'the_generator', '__return_empty_string' );
    }

    // ── Disable jQuery Migrate ───────────────────────────────────────────────
    // jquery-migrate adds ~10 KB gzipped and is only needed for plugins using WP <3.5 APIs
    public static function disable_jquery_migrate( $scripts ) {
        if ( ! is_admin() && isset( $scripts->registered['jquery'] ) ) {
            $jquery = $scripts->registered['jquery'];
            // Remove jquery-migrate from jquery's deps
            if ( $jquery->deps ) {
                $jquery->deps = array_diff( $jquery->deps, [ 'jquery-migrate' ] );
            }
        }
    }

    // ── Preload key assets ───────────────────────────────────────────────────
    // Preloads the main theme stylesheet and the first woff2 font found in uploads
    public static function output_preload_key_assets() {
        if ( is_admin() ) return;

        // Preload main theme CSS
        $theme_css = get_stylesheet_uri();
        if ( $theme_css ) {
            echo '<link rel="preload" href="' . esc_url( $theme_css ) . '" as="style">' . "\n";
        }

        // Preload custom font if configured
        $font_url = get_option( 'cwpa_preload_font_url', '' );
        if ( $font_url ) {
            echo '<link rel="preload" href="' . esc_url( $font_url ) . '" as="font" type="font/woff2" crossorigin="anonymous">' . "\n";
        }
    }

    // ── Save-Data header support ─────────────────────────────────────────────
    // Adds a body class + meta tag so themes/JS can detect slow connections
    public static function handle_save_data_meta() {
        if ( is_admin() ) return;
        // Inject hint for JS/CSS to reduce payload
        echo '<meta name="save-data" content="' . ( self::is_save_data() ? '1' : '0' ) . '">' . "\n";
        if ( self::is_save_data() ) {
            // Dequeue non-essential scripts on Save-Data connections
            add_action( 'wp_enqueue_scripts', function() {
                wp_dequeue_script( 'wp-embed' );
                wp_dequeue_style( 'wp-block-library' );
            }, 99 );
        }
    }

    // Reduce image quality attribute hint in content for Save-Data connections
    public static function save_data_images( $content ) {
        if ( is_admin() || ! self::is_save_data() ) return $content;
        // Add decoding="async" to all images to hint async decoding on slow CPUs
        return preg_replace_callback( '/<img(?![^>]*decoding=)([^>]*)>/i', function( $m ) {
            return '<img decoding="async"' . $m[1] . '>';
        }, $content );
    }

    private static function is_save_data() {
        return ! empty( $_SERVER['HTTP_SAVE_DATA'] ) && strtolower( $_SERVER['HTTP_SAVE_DATA'] ) === 'on';
    }

    // ── Status ───────────────────────────────────────────────────────────────
    public static function get_status() {
        $features_with_conflict = [ 'gzip', 'browser_cache', 'defer_js', 'lazy_load', 'html_minify', 'remove_query_strings', 'page_cache', 'disable_jquery_migrate', 'font_display_swap', 'css_minify', 'async_css', 'remove_unused_wp_css' ];
        $conflicts = [];
        foreach ( $features_with_conflict as $f ) {
            $name = self::get_conflict_name( $f );
            if ( $name ) $conflicts[ $f ] = $name;
        }

        $gzip_mode    = get_option( 'cwpa_gzip_mode', '' );
        $bcache_mode  = get_option( 'cwpa_browser_cache_mode', '' );
        $webp_mode    = get_option( 'cwpa_webp_serve_mode', '' );

        return [
            'page_cache'              => (bool) get_option( 'cwpa_page_cache' ),
            'gzip'                    => CWPA_Htaccess::has_section( 'GZIP' ) || $gzip_mode === 'php',
            'gzip_mode'               => CWPA_Htaccess::has_section( 'GZIP' ) ? 'htaccess' : ( $gzip_mode ?: '' ),
            'browser_cache'           => CWPA_Htaccess::has_section( 'BROWSER_CACHE' ) || $bcache_mode === 'php',
            'browser_cache_mode'      => CWPA_Htaccess::has_section( 'BROWSER_CACHE' ) ? 'htaccess' : ( $bcache_mode ?: '' ),
            'disable_emojis'          => (bool) get_option( 'cwpa_disable_emojis' ),
            'disable_embeds'          => (bool) get_option( 'cwpa_disable_embeds' ),
            'heartbeat_control'       => (bool) get_option( 'cwpa_heartbeat_control' ),
            'defer_js'                => (bool) get_option( 'cwpa_defer_js' ),
            'lazy_load'               => (bool) get_option( 'cwpa_lazy_load' ),
            'html_minify'             => (bool) get_option( 'cwpa_html_minify' ),
            'remove_query_strings'    => (bool) get_option( 'cwpa_remove_query_strings' ),
            'dns_prefetch'            => (bool) get_option( 'cwpa_dns_prefetch' ),
            'webp_serving'            => CWPA_Htaccess::has_section( 'WEBP' ) || $webp_mode === 'php',
            'webp_serving_mode'       => CWPA_Htaccess::has_section( 'WEBP' ) ? 'htaccess' : ( $webp_mode ?: '' ),
            'webp_auto'               => (bool) get_option( 'cwpa_webp_auto' ),
            // 4G optimizations
            'font_display_swap'       => (bool) get_option( 'cwpa_font_display_swap' ),
            'remove_wp_bloat'         => (bool) get_option( 'cwpa_remove_wp_bloat' ),
            'disable_jquery_migrate'  => (bool) get_option( 'cwpa_disable_jquery_migrate' ),
            'preload_key_assets'      => (bool) get_option( 'cwpa_preload_key_assets' ),
            'save_data'               => (bool) get_option( 'cwpa_save_data' ),
            'css_minify'              => (bool) get_option( 'cwpa_css_minify' ),
            'async_css'               => (bool) get_option( 'cwpa_async_css' ),
            'remove_unused_wp_css'    => (bool) get_option( 'cwpa_remove_unused_wp_css' ),
            'critical_css'            => (bool) get_option( 'cwpa_critical_css' ),
            'critical_css_content'    => (bool) get_option( 'cwpa_critical_css_content' ),
            // Image optimizations
            'jpeg_quality'            => (bool) get_option( 'cwpa_jpeg_quality' ),
            'jpeg_quality_value'      => (int) get_option( 'cwpa_jpeg_quality_value', 80 ),
            'strip_exif'              => (bool) get_option( 'cwpa_strip_exif' ),
            'limit_img_size'          => (bool) get_option( 'cwpa_limit_img_size' ),
            'max_img_dimension'       => (int) get_option( 'cwpa_max_img_dimension', 2048 ),
            'img_decode_async'        => (bool) get_option( 'cwpa_img_decode_async' ),
            'conflicts'               => $conflicts,
        ];
    }
}
