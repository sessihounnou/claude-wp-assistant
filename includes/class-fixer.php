<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CWPA_Fixer {

    private static $fixes;

    private function get_fixes() {
        return [
            // ── Database ──────────────────────────────────────────────────────
            'clear_expired_transients'  => [ $this, 'clear_expired_transients' ],
            'delete_post_revisions'     => [ $this, 'delete_post_revisions' ],
            'delete_spam_comments'      => [ $this, 'delete_spam_comments' ],
            'delete_trashed_posts'      => [ $this, 'delete_trashed_posts' ],

            // ── Security ──────────────────────────────────────────────────────
            'disable_file_editor'       => [ $this, 'disable_file_editor' ],
            'enable_debug_log'          => [ $this, 'enable_debug_log' ],
            'disable_debug_display'     => [ $this, 'disable_debug_display' ],

            // ── SEO ───────────────────────────────────────────────────────────
            'create_robots_txt'         => [ $this, 'create_robots_txt' ],

            // ── Server / .htaccess ────────────────────────────────────────────
            'enable_gzip'               => [ $this, 'enable_gzip' ],
            'disable_gzip'              => [ $this, 'disable_gzip' ],
            'enable_browser_cache'      => [ $this, 'enable_browser_cache' ],
            'disable_browser_cache'     => [ $this, 'disable_browser_cache' ],

            // ── Page Cache ────────────────────────────────────────────────────
            'enable_page_cache'         => [ $this, 'enable_page_cache' ],
            'disable_page_cache'        => [ $this, 'disable_page_cache' ],
            'clear_page_cache'          => [ $this, 'clear_page_cache' ],

            // ── Frontend optimizations ────────────────────────────────────────
            'disable_emojis'            => [ $this, 'toggle_option_on',  'cwpa_disable_emojis' ],
            'enable_emojis'             => [ $this, 'toggle_option_off', 'cwpa_disable_emojis' ],
            'disable_embeds'            => [ $this, 'toggle_option_on',  'cwpa_disable_embeds' ],
            'enable_embeds'             => [ $this, 'toggle_option_off', 'cwpa_disable_embeds' ],
            'heartbeat_control'         => [ $this, 'toggle_option_on',  'cwpa_heartbeat_control' ],
            'disable_heartbeat_control' => [ $this, 'toggle_option_off', 'cwpa_heartbeat_control' ],
            'defer_js'                  => [ $this, 'toggle_option_on',  'cwpa_defer_js' ],
            'disable_defer_js'          => [ $this, 'toggle_option_off', 'cwpa_defer_js' ],
            'enable_lazy_load'          => [ $this, 'toggle_option_on',  'cwpa_lazy_load' ],
            'disable_lazy_load'         => [ $this, 'toggle_option_off', 'cwpa_lazy_load' ],
            'enable_html_minify'        => [ $this, 'toggle_option_on',  'cwpa_html_minify' ],
            'disable_html_minify'       => [ $this, 'toggle_option_off', 'cwpa_html_minify' ],
            'remove_query_strings'      => [ $this, 'toggle_option_on',  'cwpa_remove_query_strings' ],
            'disable_query_strings'     => [ $this, 'toggle_option_off', 'cwpa_remove_query_strings' ],
            'enable_dns_prefetch'       => [ $this, 'toggle_option_on',  'cwpa_dns_prefetch' ],
            'disable_dns_prefetch'      => [ $this, 'toggle_option_off', 'cwpa_dns_prefetch' ],

            // ── WebP ──────────────────────────────────────────────────────────
            'enable_webp_serving'       => [ $this, 'enable_webp_serving' ],
            'disable_webp_serving'      => [ $this, 'disable_webp_serving' ],
            'enable_webp_auto'          => [ $this, 'toggle_option_on',  'cwpa_webp_auto' ],
            'disable_webp_auto'         => [ $this, 'toggle_option_off', 'cwpa_webp_auto' ],
            'convert_all_webp'          => [ $this, 'convert_all_webp' ],

            // ── LCP ───────────────────────────────────────────────────────────
            'enable_lcp'                => [ $this, 'toggle_option_on',  'cwpa_lcp_enabled' ],
            'disable_lcp'               => [ $this, 'toggle_option_off', 'cwpa_lcp_enabled' ],

            // ── 4G / Mobile optimizations ─────────────────────────────────────
            'enable_font_display_swap'      => [ $this, 'toggle_option_on',  'cwpa_font_display_swap' ],
            'disable_font_display_swap'     => [ $this, 'toggle_option_off', 'cwpa_font_display_swap' ],
            'enable_remove_wp_bloat'        => [ $this, 'toggle_option_on',  'cwpa_remove_wp_bloat' ],
            'disable_remove_wp_bloat'       => [ $this, 'toggle_option_off', 'cwpa_remove_wp_bloat' ],
            'enable_jquery_migrate'         => [ $this, 'toggle_option_off', 'cwpa_disable_jquery_migrate' ],
            'disable_jquery_migrate'        => [ $this, 'toggle_option_on',  'cwpa_disable_jquery_migrate' ],
            'enable_preload_key_assets'     => [ $this, 'toggle_option_on',  'cwpa_preload_key_assets' ],
            'disable_preload_key_assets'    => [ $this, 'toggle_option_off', 'cwpa_preload_key_assets' ],
            'enable_save_data'              => [ $this, 'toggle_option_on',  'cwpa_save_data' ],
            'disable_save_data'             => [ $this, 'toggle_option_off', 'cwpa_save_data' ],
        ];
    }

    public function apply_fix( $fix_id ) {
        $fixes = $this->get_fixes();

        if ( ! isset( $fixes[ $fix_id ] ) ) {
            return [ 'success' => false, 'message' => 'Correction inconnue: ' . $fix_id ];
        }

        try {
            $callback = $fixes[ $fix_id ];

            // toggle_option_on/off are stored as 3-element arrays
            if ( is_array( $callback ) && is_string( $callback[1] ) && in_array( $callback[1], [ 'toggle_option_on', 'toggle_option_off' ], true ) ) {
                return $this->{ $callback[1] }( $callback[2] );
            }

            return call_user_func( $callback );
        } catch ( Exception $e ) {
            return [ 'success' => false, 'message' => $e->getMessage() ];
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DATABASE
    // ══════════════════════════════════════════════════════════════════════════

    private function clear_expired_transients() {
        global $wpdb;
        $deleted = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()" );
        $wpdb->query( "DELETE o FROM {$wpdb->options} o LEFT JOIN {$wpdb->options} t ON t.option_name = REPLACE(o.option_name,'_transient_','_transient_timeout_') WHERE o.option_name LIKE '_transient_%' AND o.option_name NOT LIKE '_transient_timeout_%' AND t.option_name IS NULL" );
        return [ 'success' => true, 'message' => "{$deleted} transients expirés supprimés." ];
    }

    private function delete_post_revisions() {
        global $wpdb;
        $ids   = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'" );
        $count = 0;
        foreach ( $ids as $id ) {
            if ( wp_delete_post_revision( (int) $id ) ) $count++;
        }
        return [ 'success' => true, 'message' => "{$count} révisions supprimées." ];
    }

    private function delete_spam_comments() {
        global $wpdb;
        $n = $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
        return [ 'success' => true, 'message' => "{$n} commentaires spam supprimés." ];
    }

    private function delete_trashed_posts() {
        $posts = get_posts( [ 'post_status' => 'trash', 'numberposts' => -1, 'post_type' => 'any' ] );
        $count = 0;
        foreach ( $posts as $p ) {
            if ( wp_delete_post( $p->ID, true ) ) $count++;
        }
        return [ 'success' => true, 'message' => "{$count} éléments supprimés de la corbeille." ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SECURITY / WP-CONFIG
    // ══════════════════════════════════════════════════════════════════════════

    private function disable_file_editor() {
        return $this->wpconfig_define( 'DISALLOW_FILE_EDIT', 'true', 'Éditeur de fichiers désactivé dans wp-config.php.' );
    }

    private function enable_debug_log() {
        $wpc = ABSPATH . 'wp-config.php';
        if ( ! is_writable( $wpc ) ) return [ 'success' => false, 'message' => 'wp-config.php non accessible en écriture.' ];
        $c = file_get_contents( $wpc );
        if ( strpos( $c, 'WP_DEBUG_LOG' ) !== false ) return [ 'success' => true, 'message' => 'WP_DEBUG_LOG déjà configuré.' ];
        $insert = "define('WP_DEBUG', true);\ndefine('WP_DEBUG_LOG', true);\ndefine('WP_DEBUG_DISPLAY', false);\n";
        $c = preg_replace( '/(<\?php\s)/', "<?php\n" . $insert, $c, 1 );
        file_put_contents( $wpc, $c );
        return [ 'success' => true, 'message' => 'Debug log activé (erreurs dans wp-content/debug.log).' ];
    }

    private function disable_debug_display() {
        $wpc = ABSPATH . 'wp-config.php';
        if ( ! is_writable( $wpc ) ) return [ 'success' => false, 'message' => 'wp-config.php non accessible en écriture.' ];
        $c = file_get_contents( $wpc );
        $c = preg_replace( "/define\s*\(\s*'WP_DEBUG_DISPLAY'\s*,\s*true\s*\)/", "define('WP_DEBUG_DISPLAY', false)", $c );
        file_put_contents( $wpc, $c );
        return [ 'success' => true, 'message' => 'Affichage des erreurs désactivé en frontend.' ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SEO
    // ══════════════════════════════════════════════════════════════════════════

    private function create_robots_txt() {
        $path = ABSPATH . 'robots.txt';
        if ( file_exists( $path ) ) return [ 'success' => true, 'message' => 'robots.txt existe déjà.' ];
        $content = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: " . get_site_url() . "/sitemap.xml\n";
        file_put_contents( $path, $content );
        return [ 'success' => true, 'message' => 'robots.txt créé avec succès.' ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SERVER / .HTACCESS
    // ══════════════════════════════════════════════════════════════════════════

    private function enable_gzip() {
        // Tente .htaccess en premier
        $result = CWPA_Htaccess::write_section( 'GZIP', CWPA_Htaccess::gzip_rules() );
        if ( ! is_wp_error( $result ) ) {
            update_option( 'cwpa_gzip_mode', 'htaccess' );
            return [ 'success' => true, 'message' => 'Compression GZIP activée via .htaccess (Apache mod_deflate).' ];
        }

        // Fallback PHP via zlib
        if ( extension_loaded( 'zlib' ) ) {
            update_option( 'cwpa_gzip_mode', 'php' );
            return [ 'success' => true, 'message' => 'Compression GZIP activée via PHP (zlib) — .htaccess non accessible sur ce serveur, fallback automatique.' ];
        }

        return [ 'success' => false, 'message' => '.htaccess inaccessible (' . $result->get_error_message() . ') et l\'extension zlib PHP n\'est pas disponible.' ];
    }

    private function disable_gzip() {
        CWPA_Htaccess::remove_section( 'GZIP' );
        delete_option( 'cwpa_gzip_mode' );
        return [ 'success' => true, 'message' => 'Compression GZIP désactivée.' ];
    }

    private function enable_browser_cache() {
        // Tente .htaccess en premier
        $result = CWPA_Htaccess::write_section( 'BROWSER_CACHE', CWPA_Htaccess::browser_cache_rules() );
        if ( ! is_wp_error( $result ) ) {
            update_option( 'cwpa_browser_cache_mode', 'htaccess' );
            return [ 'success' => true, 'message' => 'Cache navigateur activé via .htaccess (mod_expires + mod_headers).' ];
        }

        // Fallback PHP via headers
        update_option( 'cwpa_browser_cache_mode', 'php' );
        return [ 'success' => true, 'message' => 'Cache navigateur activé via PHP (headers HTTP) — .htaccess non accessible sur ce serveur, fallback automatique.' ];
    }

    private function disable_browser_cache() {
        CWPA_Htaccess::remove_section( 'BROWSER_CACHE' );
        delete_option( 'cwpa_browser_cache_mode' );
        return [ 'success' => true, 'message' => 'Cache navigateur désactivé.' ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PAGE CACHE
    // ══════════════════════════════════════════════════════════════════════════

    private function enable_page_cache() {
        update_option( 'cwpa_page_cache', 1 );
        if ( ! is_dir( WP_CONTENT_DIR . '/cache/cwpa-pages/' ) ) {
            wp_mkdir_p( WP_CONTENT_DIR . '/cache/cwpa-pages/' );
        }
        return [ 'success' => true, 'message' => 'Cache de pages activé.' ];
    }

    private function disable_page_cache() {
        update_option( 'cwpa_page_cache', 0 );
        CWPA_Cache::clear_all();
        return [ 'success' => true, 'message' => 'Cache de pages désactivé et vidé.' ];
    }

    private function clear_page_cache() {
        $n = CWPA_Cache::clear_all();
        return [ 'success' => true, 'message' => "{$n} fichiers de cache supprimés." ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // WEBP
    // ══════════════════════════════════════════════════════════════════════════

    private function enable_webp_serving() {
        // Tente .htaccess en premier
        $result = CWPA_Htaccess::write_section( 'WEBP', CWPA_Htaccess::webp_serving_rules() );
        if ( ! is_wp_error( $result ) ) {
            update_option( 'cwpa_webp_serve_mode', 'htaccess' );
            return [ 'success' => true, 'message' => 'Serving WebP activé via .htaccess (mod_rewrite). Les navigateurs compatibles recevront automatiquement les images WebP.' ];
        }

        // Fallback PHP — middleware init qui sert les .webp si acceptés
        update_option( 'cwpa_webp_serve_mode', 'php' );
        return [ 'success' => true, 'message' => 'Serving WebP activé via PHP (middleware) — .htaccess non accessible sur ce serveur, fallback automatique.' ];
    }

    private function disable_webp_serving() {
        CWPA_Htaccess::remove_section( 'WEBP' );
        delete_option( 'cwpa_webp_serve_mode' );
        return [ 'success' => true, 'message' => 'Serving WebP désactivé.' ];
    }

    private function convert_all_webp() {
        $stats = CWPA_WebP::get_stats();
        if ( ! $stats['driver'] ) {
            return [ 'success' => false, 'message' => 'Aucun driver WebP disponible (GD ou Imagick requis).' ];
        }
        if ( $stats['pending'] === 0 ) {
            return [ 'success' => true, 'message' => 'Toutes les images sont déjà converties en WebP.' ];
        }
        return [ 'success' => true, 'message' => "Conversion initiée. {$stats['pending']} images à convertir. Utilisez le module WebP pour suivre la progression.", 'start_webp' => true ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    private function toggle_option_on( $option ) {
        update_option( $option, 1 );
        $labels = [
            'cwpa_disable_emojis'       => 'Emojis WordPress désactivés.',
            'cwpa_disable_embeds'       => 'Embeds WordPress désactivés.',
            'cwpa_heartbeat_control'    => 'Heartbeat réduit à 60 secondes.',
            'cwpa_defer_js'             => 'Chargement JS différé (defer) activé.',
            'cwpa_lazy_load'            => 'Lazy loading des images activé.',
            'cwpa_html_minify'          => 'Minification HTML activée.',
            'cwpa_remove_query_strings' => 'Query strings supprimées des ressources statiques.',
            'cwpa_dns_prefetch'         => 'DNS prefetch activé.',
            'cwpa_webp_auto'            => 'Conversion WebP automatique à l\'upload activée.',
            'cwpa_lcp_enabled'               => 'Optimisation LCP activée (preload image, fetchpriority, preconnect).',
            'cwpa_font_display_swap'         => 'Font-display swap activé — les polices Google Fonts ne bloquent plus le rendu.',
            'cwpa_remove_wp_bloat'           => 'Balises inutiles supprimées du <head> (generator, rsd_link, wlwmanifest…).',
            'cwpa_disable_jquery_migrate'    => 'jQuery Migrate désactivé (~10 Ko économisés).',
            'cwpa_preload_key_assets'        => 'Préchargement des ressources clés activé (feuille de style principale, police).',
            'cwpa_save_data'                 => 'Mode Save-Data activé — contenu allégé pour connexions lentes (4G/3G).',
        ];
        return [ 'success' => true, 'message' => $labels[ $option ] ?? "Option {$option} activée." ];
    }

    private function toggle_option_off( $option ) {
        update_option( $option, 0 );
        return [ 'success' => true, 'message' => "Option désactivée." ];
    }

    private function wpconfig_define( $constant, $value, $success_msg ) {
        $wpc = ABSPATH . 'wp-config.php';
        if ( ! is_writable( $wpc ) ) return [ 'success' => false, 'message' => 'wp-config.php non accessible en écriture.' ];
        $c = file_get_contents( $wpc );
        if ( strpos( $c, $constant ) !== false ) return [ 'success' => true, 'message' => $constant . ' déjà défini.' ];
        $insert = "define('{$constant}', {$value});\n";
        $c = preg_replace( '/(<\?php\s)/', "<?php\n" . $insert, $c, 1 );
        file_put_contents( $wpc, $c );
        return [ 'success' => true, 'message' => $success_msg ];
    }
}
