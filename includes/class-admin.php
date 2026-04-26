<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CWPA_Admin {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Original endpoints
        add_action( 'wp_ajax_cwpa_scan',     [ $this, 'ajax_scan' ] );
        add_action( 'wp_ajax_cwpa_fix',      [ $this, 'ajax_fix' ] );
        add_action( 'wp_ajax_cwpa_chat',     [ $this, 'ajax_chat' ] );
        add_action( 'wp_ajax_cwpa_save_key', [ $this, 'ajax_save_key' ] );

        // New endpoints
        add_action( 'wp_ajax_cwpa_pagespeed',         [ $this, 'ajax_pagespeed' ] );
        add_action( 'wp_ajax_cwpa_save_pagespeed_key', [ $this, 'ajax_save_pagespeed_key' ] );
        add_action( 'wp_ajax_cwpa_optimizer_status',  [ $this, 'ajax_optimizer_status' ] );
        add_action( 'wp_ajax_cwpa_webp_stats',        [ $this, 'ajax_webp_stats' ] );
        add_action( 'wp_ajax_cwpa_webp_convert',      [ $this, 'ajax_webp_convert' ] );
        add_action( 'wp_ajax_cwpa_cache_clear',       [ $this, 'ajax_cache_clear' ] );
        add_action( 'wp_ajax_cwpa_diagnostics',       [ $this, 'ajax_diagnostics' ] );
        add_action( 'wp_ajax_cwpa_pagespeed_ai',      [ $this, 'ajax_pagespeed_ai' ] );
        add_action( 'wp_ajax_cwpa_apply_all_fixes',   [ $this, 'ajax_apply_all_fixes' ] );
    }

    public function register_menu() {
        add_menu_page(
            'Claude WP Assistant',
            'Claude AI',
            'manage_options',
            'claude-wp-assistant',
            [ $this, 'render_page' ],
            'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="#D4A853"/><path d="M8 12c0-2.2 1.8-4 4-4s4 1.8 4 4-1.8 4-4 4-4-1.8-4-4z" fill="white"/></svg>' ),
            30
        );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'toplevel_page_claude-wp-assistant' ) return;

        // Isoler notre page — retire les assets d'autres plugins qui peuvent causer des conflits
        add_action( 'admin_print_styles',  [ $this, 'dequeue_conflicting_styles'  ], 9999 );
        add_action( 'admin_print_scripts', [ $this, 'dequeue_conflicting_scripts' ], 9999 );

        wp_enqueue_style( 'cwpa-style', CWPA_URL . 'assets/css/admin.css', [], CWPA_VERSION );
        wp_enqueue_script( 'cwpa-script', CWPA_URL . 'assets/js/admin.js', [ 'jquery' ], CWPA_VERSION, true );
        wp_localize_script( 'cwpa-script', 'CWPA', [
            'ajax_url'       => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'cwpa_nonce' ),
            'api_set'        => ! empty( get_option( 'cwpa_api_key' ) ),
            'site_url'       => get_site_url(),
            'pagespeed_key'  => get_option( 'cwpa_pagespeed_key', '' ),
            'version'        => CWPA_VERSION,
        ] );
    }

    // ── Retire les styles conflictuels d'autres plugins sur notre page ────────
    public function dequeue_conflicting_styles() {
        $known = [
            // Page builders & design plugins
            'elementor-admin-css', 'elementor-common', 'elementor-editor',
            'wp-block-editor',
            // WooCommerce
            'woocommerce_admin_styles', 'wc-admin-app',
            // SEO plugins
            'yoast-seo-adminbar', 'rank-math-common', 'aioseo-admin',
            // Performance/cache plugins
            'wp-rocket-admin', 'litespeed-admin',
        ];
        foreach ( $known as $handle ) {
            wp_dequeue_style( $handle );
        }
    }

    // ── Retire les scripts conflictuels d'autres plugins sur notre page ───────
    public function dequeue_conflicting_scripts() {
        // WP core scripts qu'on conserve
        $keep = [
            'jquery', 'jquery-core', 'jquery-migrate',
            'cwpa-script',
            // WP admin chrome essentials
            'common', 'admin-bar', 'svg-painter', 'iris', 'wp-color-picker',
            'shortcut', 'clipboard', 'jquery-ui-core',
        ];
        global $wp_scripts;
        foreach ( array_keys( $wp_scripts->registered ?? [] ) as $handle ) {
            if ( in_array( $handle, $keep, true ) ) continue;
            // Ne retire que les scripts qui sont effectivement en file
            if ( in_array( $handle, $wp_scripts->queue ?? [], true ) ) {
                // Garde les scripts WP dont le src est dans wp-admin ou wp-includes
                $src = $wp_scripts->registered[ $handle ]->src ?? '';
                if ( strpos( $src, '/wp-admin/' ) !== false || strpos( $src, '/wp-includes/' ) !== false ) continue;
                wp_dequeue_script( $handle );
            }
        }
    }

    public function render_page() {
        require_once CWPA_PATH . 'templates/admin-page.php';
    }

    // ── Save Claude API Key ───────────────────────────────────────────────────
    public function ajax_save_key() {
        check_ajax_referer( 'cwpa_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Non autorisé' );
        $key = sanitize_text_field( $_POST['api_key'] ?? '' );
        update_option( 'cwpa_api_key', $key );
        wp_send_json_success( [ 'message' => 'Clé API sauvegardée.' ] );
    }

    // ── Save PageSpeed API Key ────────────────────────────────────────────────
    public function ajax_save_pagespeed_key() {
        check_ajax_referer( 'cwpa_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Non autorisé' );
        $key = sanitize_text_field( $_POST['pagespeed_key'] ?? '' );
        update_option( 'cwpa_pagespeed_key', $key );
        wp_send_json_success( [ 'message' => 'Clé PageSpeed sauvegardée.' ] );
    }

    // ── Claude Scan (existing) ────────────────────────────────────────────────
    public function ajax_scan() {
        check_ajax_referer( 'cwpa_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Non autorisé' );

        $type     = sanitize_key( $_POST['scan_type'] ?? '' );
        $analyzer = new CWPA_Analyzer();
        $api      = new CWPA_API();

        $context = [];
        switch ( $type ) {
            case 'php_errors':  $context = $analyzer->collect_php_errors();  break;
            case 'performance': $context = $analyzer->collect_performance();  break;
            case 'plugins':     $context = $analyzer->collect_plugins();      break;
            case 'security':    $context = $analyzer->collect_security();     break;
            case 'seo':         $context = $analyzer->collect_seo();          break;
            default: wp_send_json_error( 'Type de scan invalide' );
        }

        $result = $api->analyze( $context, $type );

        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

        $parsed = json_decode( $result, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            wp_send_json_error( 'Réponse API invalide: ' . substr( $result, 0, 200 ) );
        }

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'cwpa_logs', [
            'scan_type' => $type,
            'result'    => $result,
            'severity'  => $this->get_max_severity( $parsed ),
        ] );

        wp_send_json_success( [ 'data' => $parsed ] );
    }

    // ── PageSpeed Scan ────────────────────────────────────────────────────────
    public function ajax_pagespeed() {
        check_ajax_referer( 'cwpa_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Non autorisé' );

        $url      = esc_url_raw( $_POST['url'] ?? get_site_url() );
        $strategy = in_array( $_POST['strategy'] ?? 'mobile', [ 'mobile', 'desktop' ], true )
                    ? $_POST['strategy'] : 'mobile';

        $ps     = new CWPA_PageSpeed();
        $result = $ps->analyze( $url, $strategy );

        if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

        wp_send_json_success( $result );
    }

    // ── Fix ───────────────────────────────────────────────────────────────────
    public function ajax_fix() {
        check_ajax_referer( 'cwpa_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Non autorisé' );
        $fix_id = sanitize_key( $_POST['fix_id'] ?? '' );
        $fixer  = new CWPA_Fixer();
        wp_send_json_success( $fixer->apply_fix( $fix_id ) );
    }

    // ── Optimizer Status ──────────────────────────────────────────────────────
    public function ajax_optimizer_status() {
        check_ajax_referer( 'cwpa_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Non autorisé' );

        $status = CWPA_Optimizer::get_status();
        $cache  = CWPA_Cache::get_stats();
        wp_send_json_success( [ 'status' => $status, 'cache' => $cache ] );
    }

    // ── WebP Stats ────────────────────────────────────────────────────────────
    public function ajax_webp_stats() {
        check_ajax_referer( 'cwpa_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Non autorisé' );
        wp_send_json_success( CWPA_WebP::get_stats() );
    }

    // ── WebP Batch Convert ────────────────────────────────────────────────────
    public function ajax_webp_convert() {
        check_ajax_referer( 'cwpa_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Non autorisé' );
        $offset = (int) ( $_POST['offset'] ?? 0 );
        $result = CWPA_WebP::convert_batch( $offset, 8 );
        wp_send_json_success( $result );
    }

    // ── Clear Cache ───────────────────────────────────────────────────────────
    public function ajax_cache_clear() {
        check_ajax_referer( 'cwpa_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Non autorisé' );
        $n = CWPA_Cache::clear_all();
        wp_send_json_success( [ 'message' => "{$n} fichiers de cache supprimés." ] );
    }

    // ── Chat ──────────────────────────────────────────────────────────────────
    public function ajax_chat() {
        check_ajax_referer( 'cwpa_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Non autorisé' );

        $message = sanitize_textarea_field( $_POST['message'] ?? '' );
        $history = isset( $_POST['history'] ) ? (array) $_POST['history'] : [];

        $api      = new CWPA_API();
        $response = $api->chat( $message, $history );

        if ( is_wp_error( $response ) ) wp_send_json_error( $response->get_error_message() );
        wp_send_json_success( [ 'reply' => $response ] );
    }

    // ── Diagnostics ───────────────────────────────────────────────────────────
    public function ajax_diagnostics() {
        check_ajax_referer( 'cwpa_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Non autorisé' );

        $checks = [];

        // ── PHP ──────────────────────────────────────────────────────────────
        $checks[] = $this->diag( 'PHP ' . PHP_VERSION, version_compare( PHP_VERSION, '7.4', '>=' ), 'Version PHP correcte', 'PHP 7.4+ requis' );
        $checks[] = $this->diag( 'Mémoire : ' . ini_get('memory_limit'), true, '', '', 'info' );
        $checks[] = $this->diag( 'max_execution_time : ' . ini_get('max_execution_time') . 's', true, '', '', 'info' );

        // ── Serveur ──────────────────────────────────────────────────────────
        $server_soft = $_SERVER['SERVER_SOFTWARE'] ?? 'inconnu';
        $is_apache   = stripos( $server_soft, 'apache' ) !== false || function_exists( 'apache_get_version' );
        $is_nginx    = stripos( $server_soft, 'nginx' ) !== false;
        $server_label = $is_apache ? 'Apache' : ( $is_nginx ? 'Nginx' : $server_soft );
        $checks[] = $this->diag(
            'Serveur : ' . $server_label,
            $is_apache,
            'Apache détecté — .htaccess compatible',
            $is_nginx ? 'Nginx détecté — GZIP/Cache navigateur et WebP via .htaccess ne fonctionneront pas (configurer dans nginx.conf)' : 'Serveur non reconnu — les règles .htaccess peuvent ne pas s\'appliquer',
            $is_apache ? 'ok' : 'warn'
        );

        // ── .htaccess ────────────────────────────────────────────────────────
        $htaccess = ABSPATH . '.htaccess';
        $ht_exists   = file_exists( $htaccess );
        $ht_writable = $ht_exists && is_writable( $htaccess );
        $checks[] = $this->diag( '.htaccess ' . ( $ht_exists ? 'présent' : 'absent' ), $ht_exists, '.htaccess trouvé', '.htaccess absent — créez-le à la racine WordPress' );
        $checks[] = $this->diag( '.htaccess ' . ( $ht_writable ? 'accessible en écriture' : 'lecture seule' ), $ht_writable, '.htaccess modifiable', 'Droits insuffisants sur .htaccess — chmod 644 ou contactez votre hébergeur' );

        // ── Cache dir ────────────────────────────────────────────────────────
        $cache_dir  = WP_CONTENT_DIR . '/cache/';
        $cwpa_cache = WP_CONTENT_DIR . '/cache/cwpa-pages/';
        $checks[] = $this->diag( 'wp-content/cache/ ' . ( is_writable( $cache_dir ) ? 'accessible' : 'inaccessible' ), is_writable( $cache_dir ), 'Répertoire cache accessible', 'wp-content/cache/ non accessible en écriture — le cache de pages ne fonctionnera pas' );

        // ── GD / WebP ────────────────────────────────────────────────────────
        $gd_loaded   = extension_loaded( 'gd' );
        $gd_webp     = $gd_loaded && function_exists( 'imagewebp' );
        $checks[] = $this->diag( 'Extension GD ' . ( $gd_loaded ? 'chargée' : 'absente' ), $gd_loaded, 'GD disponible', 'GD non disponible — demandez à votre hébergeur de l\'activer' );
        $checks[] = $this->diag( 'GD imagewebp() ' . ( $gd_webp ? 'disponible' : 'indisponible' ), $gd_webp, 'Conversion WebP via GD possible', 'imagewebp() absent — GD compilé sans support WebP (fréquent sur certains hébergeurs mutualisés)' );

        // ── Imagick ──────────────────────────────────────────────────────────
        $im_loaded  = extension_loaded( 'imagick' );
        $im_webp    = false;
        if ( $im_loaded ) {
            try {
                $im      = new Imagick();
                $im_webp = in_array( 'WEBP', $im->queryFormats(), true );
            } catch ( Exception $e ) {}
        }
        $checks[] = $this->diag( 'Imagick ' . ( $im_loaded ? 'chargé' : 'absent' ), $im_loaded, 'Imagick disponible', 'Imagick non chargé', $im_loaded ? 'ok' : 'info' );
        if ( $im_loaded ) {
            $checks[] = $this->diag( 'Imagick WebP ' . ( $im_webp ? 'supporté' : 'non supporté' ), $im_webp, 'Conversion WebP via Imagick possible', 'Imagick chargé mais WebP non compilé', $im_webp ? 'ok' : 'warn' );
        }

        // ── Résumé WebP ──────────────────────────────────────────────────────
        $webp_ok = $gd_webp || $im_webp;
        $checks[] = $this->diag(
            'Compression WebP : ' . ( $webp_ok ? 'opérationnelle' : 'impossible' ),
            $webp_ok,
            'Au moins un driver WebP disponible (' . ( $gd_webp ? 'GD' : 'Imagick' ) . ')',
            'Ni GD+imagewebp ni Imagick avec WebP — contactez votre hébergeur',
            $webp_ok ? 'ok' : 'critical'
        );

        // ── WordPress ────────────────────────────────────────────────────────
        $checks[] = $this->diag( 'WordPress ' . get_bloginfo('version'), version_compare( get_bloginfo('version'), '5.8', '>=' ), 'Version WordPress compatible', 'WordPress 5.8+ recommandé' );
        $checks[] = $this->diag( 'admin-ajax.php accessible', true, 'AJAX WordPress disponible (vous lisez cette réponse)', '', 'ok' );

        // ── Plugins conflictuels ─────────────────────────────────────────────
        $conflict_plugins = [
            'WP Rocket'       => 'wp-rocket/wp-rocket.php',
            'LiteSpeed Cache' => 'litespeed-cache/litespeed-cache.php',
            'W3 Total Cache'  => 'w3-total-cache/w3-total-cache.php',
            'WP Super Cache'  => 'wp-super-cache/wp-cache.php',
            'Autoptimize'     => 'autoptimize/autoptimize.php',
        ];
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active_conflicts = [];
        foreach ( $conflict_plugins as $name => $file ) {
            if ( is_plugin_active( $file ) ) $active_conflicts[] = $name;
        }
        if ( $active_conflicts ) {
            $checks[] = $this->diag(
                'Plugins d\'optimisation actifs : ' . implode( ', ', $active_conflicts ),
                true,
                'Détectés et gérés — les fonctionnalités en doublon sont désactivées automatiquement',
                '',
                'info'
            );
        }

        wp_send_json_success( $checks );
    }

    // ── PageSpeed AI — envoie les résultats PageSpeed à Claude ───────────────
    public function ajax_pagespeed_ai() {
        check_ajax_referer( 'cwpa_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Non autorisé' );

        $raw = isset( $_POST['pagespeed_data'] ) ? wp_unslash( $_POST['pagespeed_data'] ) : '';
        $pagespeed_data = json_decode( $raw, true );
        if ( ! $pagespeed_data ) {
            wp_send_json_error( 'Données PageSpeed manquantes ou invalides.' );
        }

        $optimizer_status = CWPA_Optimizer::get_status();

        $api    = new CWPA_API();
        $result = $api->analyze_pagespeed( $pagespeed_data, $optimizer_status );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $parsed = json_decode( $result, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            wp_send_json_error( 'Réponse IA invalide : ' . substr( $result, 0, 200 ) );
        }

        wp_send_json_success( $parsed );
    }

    // ── Applique une liste de fix_ids en une seule requête ────────────────────
    public function ajax_apply_all_fixes() {
        check_ajax_referer( 'cwpa_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Non autorisé' );

        $raw     = isset( $_POST['fix_ids'] ) ? wp_unslash( $_POST['fix_ids'] ) : '[]';
        $fix_ids = json_decode( $raw, true );
        if ( ! is_array( $fix_ids ) || empty( $fix_ids ) ) {
            wp_send_json_error( 'Aucun fix_id fourni.' );
        }

        $fixer   = new CWPA_Fixer();
        $results = [];
        foreach ( $fix_ids as $fix_id ) {
            $fix_id    = sanitize_key( $fix_id );
            $results[] = array_merge( [ 'fix_id' => $fix_id ], $fixer->apply_fix( $fix_id ) );
        }

        $applied = count( array_filter( $results, fn( $r ) => $r['success'] ) );
        wp_send_json_success( [ 'results' => $results, 'applied' => $applied, 'total' => count( $fix_ids ) ] );
    }

    private function diag( $label, $ok, $ok_msg = '', $fail_msg = '', $force_status = '' ) {
        $status = $force_status ?: ( $ok ? 'ok' : 'critical' );
        return [
            'label'   => $label,
            'status'  => $status,
            'message' => $ok ? $ok_msg : $fail_msg,
        ];
    }

    // ── Helper ────────────────────────────────────────────────────────────────
    private function get_max_severity( $data ) {
        if ( empty( $data['issues'] ) ) return 'info';
        $sevs = array_column( $data['issues'], 'severity' );
        if ( in_array( 'critical', $sevs, true ) ) return 'critical';
        if ( in_array( 'warning', $sevs, true ) ) return 'warning';
        return 'info';
    }
}
