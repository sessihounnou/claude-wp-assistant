<?php
/**
 * Plugin Name: Claude WP Assistant
 * Plugin URI:  https://biristools.com
 * Description: Connectez Claude AI à votre WordPress pour analyser et résoudre automatiquement les problèmes de performance, sécurité, SEO, erreurs PHP et conflits de plugins. Inclut PageSpeed, cache, WebP, GZIP et toutes les optimisations WP Rocket.
 * Version:     1.4.3
 * Author:      Biristools
 * Author URI:  https://biristools.com
 * License:     GPL-2.0+
 * Text Domain: claude-wp-assistant
 * GitHub Plugin URI: biristools/claude-wp-assistant
 * GitHub Branch: main
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CWPA_VERSION',     '1.4.3' );
define( 'CWPA_PATH',        plugin_dir_path( __FILE__ ) );
define( 'CWPA_URL',         plugin_dir_url( __FILE__ ) );
define( 'CWPA_GITHUB_REPO', 'sessihounnou/claude-wp-assistant' );
define( 'CWPA_PLUGIN_FILE', plugin_basename( __FILE__ ) );

require_once CWPA_PATH . 'includes/class-analyzer.php';
require_once CWPA_PATH . 'includes/class-api.php';
require_once CWPA_PATH . 'includes/class-htaccess.php';
require_once CWPA_PATH . 'includes/class-cache.php';
require_once CWPA_PATH . 'includes/class-optimizer.php';
require_once CWPA_PATH . 'includes/class-pagespeed.php';
require_once CWPA_PATH . 'includes/class-webp.php';
require_once CWPA_PATH . 'includes/class-fixer.php';
require_once CWPA_PATH . 'includes/class-lcp.php';
require_once CWPA_PATH . 'includes/class-ssh.php';
require_once CWPA_PATH . 'includes/class-updater.php';
require_once CWPA_PATH . 'includes/class-admin.php';

register_activation_hook( __FILE__, 'cwpa_activate' );
function cwpa_activate() {
    global $wpdb;
    $table   = $wpdb->prefix . 'cwpa_logs';
    $charset = $wpdb->get_charset_collate();
    $sql     = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        scan_type VARCHAR(50) NOT NULL,
        result LONGTEXT NOT NULL,
        severity VARCHAR(20) DEFAULT 'info',
        fixed TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    wp_mkdir_p( WP_CONTENT_DIR . '/cache/cwpa-pages/' );
}

register_deactivation_hook( __FILE__, 'cwpa_deactivate' );
function cwpa_deactivate() {
    CWPA_Htaccess::remove_section( 'GZIP' );
    CWPA_Htaccess::remove_section( 'BROWSER_CACHE' );
    CWPA_Htaccess::remove_section( 'WEBP' );
    CWPA_Cache::clear_all();
}

add_action( 'init', function() {
    CWPA_Optimizer::init();
    CWPA_Cache::init();
    CWPA_WebP::register_hooks();
    CWPA_LCP::register_hooks();
} );

add_action( 'plugins_loaded', function() {
    new CWPA_Updater( CWPA_GITHUB_REPO, CWPA_PLUGIN_FILE, CWPA_VERSION );
    new CWPA_Admin();
} );
