<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CWPA_Analyzer {

    public function collect_php_errors() {
        $data = [
            'php_version'   => PHP_VERSION,
            'wp_version'    => get_bloginfo('version'),
            'memory_limit'  => ini_get('memory_limit'),
            'max_execution' => ini_get('max_execution_time'),
            'error_log_path'=> ini_get('error_log'),
            'display_errors'=> ini_get('display_errors'),
            'recent_errors' => $this->get_recent_php_errors(),
            'deprecated_hooks' => $this->check_deprecated_hooks(),
        ];
        return $data;
    }

    public function collect_performance() {
        global $wpdb;

        $autoload_size = $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload='yes'");
        $autoload_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload='yes'");
        $total_options = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");

        $transients = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
        $expired_transients = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()");

        $post_revisions = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'");
        $spam_comments = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
        $trashed_posts = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'");

        $large_tables = $wpdb->get_results("SELECT table_name, ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb FROM information_schema.TABLES WHERE table_schema = DATABASE() ORDER BY size_mb DESC LIMIT 5");

        $active_plugins = get_option('active_plugins', []);

        return [
            'autoload_size_kb'    => round( (int)$autoload_size / 1024, 2 ),
            'autoload_count'      => (int)$autoload_count,
            'total_options'       => (int)$total_options,
            'transients_count'    => (int)$transients,
            'expired_transients'  => (int)$expired_transients,
            'post_revisions'      => (int)$post_revisions,
            'spam_comments'       => (int)$spam_comments,
            'trashed_posts'       => (int)$trashed_posts,
            'active_plugins_count'=> count($active_plugins),
            'large_tables'        => $large_tables,
            'wp_cron_disabled'    => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'object_cache_enabled'=> wp_using_ext_object_cache(),
            'https_enabled'       => is_ssl(),
        ];
    }

    public function collect_plugins() {
        if ( ! function_exists('get_plugins') ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = get_option('active_plugins', []);
        $updates        = get_site_transient('update_plugins');

        $plugins_data = [];
        foreach ( $all_plugins as $slug => $plugin ) {
            $is_active   = in_array( $slug, $active_plugins );
            $has_update  = isset( $updates->response[$slug] );
            $plugins_data[] = [
                'name'       => $plugin['Name'],
                'version'    => $plugin['Version'],
                'author'     => $plugin['Author'],
                'active'     => $is_active,
                'has_update' => $has_update,
                'new_version'=> $has_update ? $updates->response[$slug]->new_version : null,
            ];
        }

        return [
            'total_plugins'    => count($all_plugins),
            'active_plugins'   => count($active_plugins),
            'inactive_plugins' => count($all_plugins) - count($active_plugins),
            'plugins_with_updates' => count( array_filter($plugins_data, fn($p) => $p['has_update']) ),
            'plugins'          => $plugins_data,
        ];
    }

    public function collect_security() {
        $users = get_users(['role' => 'administrator']);
        $admin_users = [];
        foreach ( $users as $user ) {
            $admin_users[] = [
                'login'       => $user->user_login,
                'email'       => substr($user->user_email, 0, 3) . '***',
                'last_login'  => get_user_meta($user->ID, 'last_login', true) ?: 'unknown',
                'uses_admin'  => ($user->user_login === 'admin'),
            ];
        }

        return [
            'wp_version'          => get_bloginfo('version'),
            'php_version'         => PHP_VERSION,
            'https_enabled'       => is_ssl(),
            'debug_mode'          => defined('WP_DEBUG') && WP_DEBUG,
            'debug_log'           => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
            'file_editor_disabled'=> defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,
            'admin_users'         => $admin_users,
            'admin_count'         => count($users),
            'wp_login_exposed'    => file_exists(ABSPATH . 'wp-login.php'),
            'xmlrpc_enabled'      => ! ( defined('XMLRPC_REQUEST') && ! XMLRPC_REQUEST ),
            'security_keys_set'   => defined('AUTH_KEY') && AUTH_KEY !== 'put your unique phrase here',
            'db_prefix_default'   => $GLOBALS['wpdb']->prefix === 'wp_',
            'uploads_php_writeable'=> $this->check_uploads_php(),
        ];
    }

    public function collect_seo() {
        $front_page_id = get_option('page_on_front');
        $homepage = $front_page_id ? get_post($front_page_id) : null;

        $posts_without_meta = 0;
        $posts_without_desc = 0;

        $recent_posts = get_posts(['numberposts' => 20, 'post_status' => 'publish']);
        foreach ($recent_posts as $post) {
            if ( empty(get_post_meta($post->ID, '_yoast_wpseo_title', true)) && empty(get_post_meta($post->ID, '_aioseo_title', true)) ) {
                $posts_without_meta++;
            }
        }

        $seo_plugins = [];
        $seo_plugin_list = ['wordpress-seo/wp-seo.php', 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'seo-by-rank-math/rank-math.php'];
        $active = get_option('active_plugins', []);
        foreach ($seo_plugin_list as $sp) {
            if (in_array($sp, $active)) $seo_plugins[] = $sp;
        }

        return [
            'seo_plugin_active'    => ! empty($seo_plugins),
            'seo_plugins'          => $seo_plugins,
            'permalink_structure'  => get_option('permalink_structure'),
            'blog_public'          => get_option('blog_public'),
            'posts_without_meta'   => $posts_without_meta,
            'total_posts'          => wp_count_posts()->publish,
            'total_pages'          => wp_count_posts('page')->publish,
            'sitemap_exists'       => file_exists(ABSPATH . 'sitemap.xml') || file_exists(ABSPATH . 'sitemap_index.xml'),
            'robots_txt_exists'    => file_exists(ABSPATH . 'robots.txt'),
            'https_enabled'        => is_ssl(),
            'homepage_title'       => $homepage ? get_the_title($homepage) : get_bloginfo('name'),
            'tagline'              => get_bloginfo('description'),
        ];
    }

    private function get_recent_php_errors() {
        $log_file = ini_get('error_log');
        if ( empty($log_file) || ! file_exists($log_file) || ! is_readable($log_file) ) {
            $log_file = WP_CONTENT_DIR . '/debug.log';
        }
        if ( ! file_exists($log_file) || ! is_readable($log_file) ) {
            return ['status' => 'Log file not accessible'];
        }
        $size = filesize($log_file);
        $lines = [];
        $fp = fopen($log_file, 'r');
        if ($fp) {
            fseek($fp, max(0, $size - 8000));
            $content = fread($fp, 8000);
            fclose($fp);
            $lines = array_slice(explode("\n", $content), -30);
        }
        return array_filter($lines);
    }

    private function check_deprecated_hooks() {
        return [];
    }

    private function check_uploads_php() {
        $uploads = wp_upload_dir();
        $test_file = $uploads['basedir'] . '/test-cwpa.php';
        $result = @file_put_contents($test_file, '<?php // test ?>');
        if ($result !== false) {
            @unlink($test_file);
            return true;
        }
        return false;
    }
}
