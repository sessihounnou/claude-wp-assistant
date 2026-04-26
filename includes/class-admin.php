<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CWPA_Admin {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_cwpa_scan',     [ $this, 'ajax_scan' ] );
        add_action( 'wp_ajax_cwpa_fix',      [ $this, 'ajax_fix' ] );
        add_action( 'wp_ajax_cwpa_chat',     [ $this, 'ajax_chat' ] );
        add_action( 'wp_ajax_cwpa_save_key', [ $this, 'ajax_save_key' ] );
    }

    public function register_menu() {
        add_menu_page(
            'Claude WP Assistant',
            'Claude AI',
            'manage_options',
            'claude-wp-assistant',
            [ $this, 'render_page' ],
            'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" fill="#D4A853"/><path d="M8 12c0-2.2 1.8-4 4-4s4 1.8 4 4-1.8 4-4 4-4-1.8-4-4z" fill="white"/></svg>'),
            30
        );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'toplevel_page_claude-wp-assistant' ) return;
        wp_enqueue_style( 'cwpa-style', CWPA_URL . 'assets/css/admin.css', [], CWPA_VERSION );
        wp_enqueue_script( 'cwpa-script', CWPA_URL . 'assets/js/admin.js', [ 'jquery' ], CWPA_VERSION, true );
        wp_localize_script( 'cwpa-script', 'CWPA', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('cwpa_nonce'),
            'api_set'  => ! empty( get_option('cwpa_api_key') ),
        ]);
    }

    public function render_page() {
        require_once CWPA_PATH . 'templates/admin-page.php';
    }

    public function ajax_save_key() {
        check_ajax_referer('cwpa_nonce', 'nonce');
        if ( ! current_user_can('manage_options') ) wp_send_json_error('Non autorisé');
        $key = sanitize_text_field( $_POST['api_key'] ?? '' );
        update_option( 'cwpa_api_key', $key );
        wp_send_json_success( [ 'message' => 'Clé API sauvegardée.' ] );
    }

    public function ajax_scan() {
        check_ajax_referer('cwpa_nonce', 'nonce');
        if ( ! current_user_can('manage_options') ) wp_send_json_error('Non autorisé');

        $type = sanitize_key( $_POST['scan_type'] ?? '' );
        $analyzer = new CWPA_Analyzer();
        $api = new CWPA_API();

        $context = [];
        switch ($type) {
            case 'php_errors':  $context = $analyzer->collect_php_errors(); break;
            case 'performance': $context = $analyzer->collect_performance(); break;
            case 'plugins':     $context = $analyzer->collect_plugins(); break;
            case 'security':    $context = $analyzer->collect_security(); break;
            case 'seo':         $context = $analyzer->collect_seo(); break;
            default: wp_send_json_error('Type de scan invalide');
        }

        $result = $api->analyze($context, $type);

        if ( is_wp_error($result) ) {
            wp_send_json_error($result->get_error_message());
        }

        $parsed = json_decode($result, true);
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            wp_send_json_error('Réponse API invalide: ' . $result);
        }

        // Save to log
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'cwpa_logs', [
            'scan_type' => $type,
            'result'    => $result,
            'severity'  => $this->get_max_severity($parsed),
        ]);

        wp_send_json_success([
            'data'       => $parsed,
            'raw_context'=> $context,
        ]);
    }

    public function ajax_fix() {
        check_ajax_referer('cwpa_nonce', 'nonce');
        if ( ! current_user_can('manage_options') ) wp_send_json_error('Non autorisé');

        $fix_id = sanitize_key( $_POST['fix_id'] ?? '' );
        $fixer = new CWPA_Fixer();
        $result = $fixer->apply_fix($fix_id);
        wp_send_json_success($result);
    }

    public function ajax_chat() {
        check_ajax_referer('cwpa_nonce', 'nonce');
        if ( ! current_user_can('manage_options') ) wp_send_json_error('Non autorisé');

        $message = sanitize_textarea_field( $_POST['message'] ?? '' );
        $history = isset($_POST['history']) ? (array)$_POST['history'] : [];

        $api = new CWPA_API();
        $response = $api->chat($message, $history);

        if ( is_wp_error($response) ) {
            wp_send_json_error($response->get_error_message());
        }

        wp_send_json_success(['reply' => $response]);
    }

    private function get_max_severity($data) {
        if ( empty($data['issues']) ) return 'info';
        $sevs = array_column($data['issues'], 'severity');
        if ( in_array('critical', $sevs) ) return 'critical';
        if ( in_array('warning', $sevs) ) return 'warning';
        return 'info';
    }
}
