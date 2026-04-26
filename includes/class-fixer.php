<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CWPA_Fixer {

    public function apply_fix( $fix_id ) {
        $fixes = [
            'clear_expired_transients'  => [ $this, 'clear_expired_transients' ],
            'delete_post_revisions'     => [ $this, 'delete_post_revisions' ],
            'delete_spam_comments'      => [ $this, 'delete_spam_comments' ],
            'delete_trashed_posts'      => [ $this, 'delete_trashed_posts' ],
            'disable_file_editor'       => [ $this, 'disable_file_editor' ],
            'enable_debug_log'          => [ $this, 'enable_debug_log' ],
            'disable_debug_display'     => [ $this, 'disable_debug_display' ],
            'create_robots_txt'         => [ $this, 'create_robots_txt' ],
        ];

        if ( ! isset( $fixes[ $fix_id ] ) ) {
            return [ 'success' => false, 'message' => 'Correction inconnue: ' . $fix_id ];
        }

        try {
            $result = call_user_func( $fixes[ $fix_id ] );
            return $result;
        } catch ( Exception $e ) {
            return [ 'success' => false, 'message' => $e->getMessage() ];
        }
    }

    private function clear_expired_transients() {
        global $wpdb;
        $deleted = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' AND option_name NOT LIKE '_transient_timeout_%' AND option_name NOT IN (SELECT REPLACE(option_name,'_transient_timeout_','_transient_') FROM (SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%') AS t)");
        return [ 'success' => true, 'message' => "$deleted transients expirés supprimés." ];
    }

    private function delete_post_revisions() {
        global $wpdb;
        $revisions = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'");
        $count = 0;
        foreach ( $revisions as $id ) {
            if ( wp_delete_post_revision( $id ) ) $count++;
        }
        return [ 'success' => true, 'message' => "$count révisions supprimées." ];
    }

    private function delete_spam_comments() {
        global $wpdb;
        $deleted = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
        return [ 'success' => true, 'message' => "$deleted commentaires spam supprimés." ];
    }

    private function delete_trashed_posts() {
        $trashed = get_posts(['post_status' => 'trash', 'numberposts' => -1, 'post_type' => 'any']);
        $count = 0;
        foreach ( $trashed as $post ) {
            if ( wp_delete_post( $post->ID, true ) ) $count++;
        }
        return [ 'success' => true, 'message' => "$count éléments supprimés de la corbeille." ];
    }

    private function disable_file_editor() {
        $wp_config = ABSPATH . 'wp-config.php';
        if ( ! is_writable($wp_config) ) {
            return [ 'success' => false, 'message' => 'wp-config.php non accessible en écriture.' ];
        }
        $content = file_get_contents($wp_config);
        if ( strpos($content, 'DISALLOW_FILE_EDIT') !== false ) {
            return [ 'success' => true, 'message' => 'DISALLOW_FILE_EDIT déjà défini.' ];
        }
        $insert = "define('DISALLOW_FILE_EDIT', true);\n";
        $content = str_replace("<?php\n", "<?php\n" . $insert, $content);
        file_put_contents($wp_config, $content);
        return [ 'success' => true, 'message' => 'Éditeur de fichiers désactivé dans wp-config.php.' ];
    }

    private function enable_debug_log() {
        $wp_config = ABSPATH . 'wp-config.php';
        if ( ! is_writable($wp_config) ) {
            return [ 'success' => false, 'message' => 'wp-config.php non accessible en écriture.' ];
        }
        $content = file_get_contents($wp_config);
        if ( strpos($content, 'WP_DEBUG_LOG') !== false ) {
            return [ 'success' => true, 'message' => 'WP_DEBUG_LOG déjà configuré.' ];
        }
        $insert = "define('WP_DEBUG', true);\ndefine('WP_DEBUG_LOG', true);\ndefine('WP_DEBUG_DISPLAY', false);\n";
        $content = str_replace("<?php\n", "<?php\n" . $insert, $content);
        file_put_contents($wp_config, $content);
        return [ 'success' => true, 'message' => 'Debug log activé (erreurs enregistrées dans wp-content/debug.log).' ];
    }

    private function disable_debug_display() {
        $wp_config = ABSPATH . 'wp-config.php';
        if ( ! is_writable($wp_config) ) {
            return [ 'success' => false, 'message' => 'wp-config.php non accessible en écriture.' ];
        }
        $content = file_get_contents($wp_config);
        $content = preg_replace("/define\s*\(\s*'WP_DEBUG_DISPLAY'\s*,\s*true\s*\)/", "define('WP_DEBUG_DISPLAY', false)", $content);
        file_put_contents($wp_config, $content);
        return [ 'success' => true, 'message' => 'Affichage des erreurs désactivé en frontend.' ];
    }

    private function create_robots_txt() {
        $path = ABSPATH . 'robots.txt';
        if ( file_exists($path) ) {
            return [ 'success' => true, 'message' => 'robots.txt existe déjà.' ];
        }
        $site_url = get_site_url();
        $content = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: {$site_url}/sitemap.xml\n";
        if ( file_put_contents($path, $content) ) {
            return [ 'success' => true, 'message' => 'robots.txt créé avec succès.' ];
        }
        return [ 'success' => false, 'message' => 'Impossible de créer robots.txt. Vérifiez les permissions.' ];
    }
}
