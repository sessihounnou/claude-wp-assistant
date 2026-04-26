<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CWPA_SSH {

    private $conn = null;

    // ── Actions prédéfinies (whitelist de commandes) ──────────────────────────
    private static $actions = [
        'server_info'    => [ 'label' => 'Infos serveur',          'cmd' => 'uname -a && echo "---" && free -h && echo "---" && df -h /' ],
        'php_status'     => [ 'label' => 'Statut PHP & OPcache',   'cmd' => 'php -v && echo "---" && php -r "if(function_exists(\'opcache_get_status\')){$s=opcache_get_status(false);echo \'OPcache : \'.($s[\'opcache_enabled\']?\'ON\':\'OFF\').\' | Mémoire utilisée : \'.round($s[\'memory_usage\'][\'used_memory\']/1048576,1).\'MB / \'.round(($s[\'memory_usage\'][\'used_memory\']+$s[\'memory_usage\'][\'free_memory\'])/1048576,1).\'MB | Scripts cachés : \'.$s[\'opcache_statistics\'][\'num_cached_scripts\'];} else {echo \'OPcache non disponible\';}"' ],
        'opcache_clear'  => [ 'label' => 'Vider OPcache',          'cmd' => 'php -r "if(function_exists(\'opcache_reset\')){opcache_reset();echo \'OPcache vidé avec succès.\';}else{echo \'OPcache non disponible.\';}"' ],
        'nginx_test'     => [ 'label' => 'Tester config Nginx',    'cmd' => 'nginx -t 2>&1' ],
        'nginx_reload'   => [ 'label' => 'Recharger Nginx',        'cmd' => 'nginx -s reload 2>&1 && echo "Nginx rechargé."' ],
        'nginx_status'   => [ 'label' => 'Statut Nginx',           'cmd' => 'systemctl is-active nginx 2>/dev/null || service nginx status 2>/dev/null | head -10' ],
        'apache_status'  => [ 'label' => 'Statut Apache',          'cmd' => 'systemctl is-active apache2 2>/dev/null || systemctl is-active httpd 2>/dev/null || service apache2 status 2>/dev/null | head -10' ],
        'apache_reload'  => [ 'label' => 'Recharger Apache',       'cmd' => 'service apache2 reload 2>&1 || apachectl graceful 2>&1 && echo "Apache rechargé."' ],
        'php_errors'     => [ 'label' => 'Logs PHP (50 dernières lignes)', 'cmd' => 'tail -50 /var/log/php-fpm/error.log 2>/dev/null || tail -50 /var/log/php/error.log 2>/dev/null || tail -50 /var/log/php_errors.log 2>/dev/null || echo "Log PHP non trouvé — vérifiez error_log dans php.ini"' ],
        'nginx_logs'     => [ 'label' => 'Logs Nginx erreurs',     'cmd' => 'tail -30 /var/log/nginx/error.log 2>/dev/null || echo "Log Nginx non trouvé"' ],
        'mysql_status'   => [ 'label' => 'Statut MySQL',           'cmd' => 'mysqladmin -u root status 2>/dev/null || mysql -e "SHOW GLOBAL STATUS LIKE \'Uptime\';" 2>/dev/null || echo "MySQL : accès root requis"' ],
        'processes'      => [ 'label' => 'Processus PHP/MySQL',    'cmd' => 'ps aux | grep -E "php|mysql|nginx|apache" | grep -v grep | head -20' ],
        'nginx_gzip'     => [ 'label' => 'Ajouter gzip à Nginx',   'cmd' => null, 'write' => true ],
        'nginx_cache'    => [ 'label' => 'Ajouter cache headers Nginx', 'cmd' => null, 'write' => true ],
    ];

    public static function get_actions() { return self::$actions; }

    // ── Disponibilité de l'extension ssh2 ────────────────────────────────────
    public static function has_ssh2() {
        return function_exists( 'ssh2_connect' );
    }

    // ── Connexion ─────────────────────────────────────────────────────────────
    public function connect() {
        $cfg = self::get_settings();
        if ( ! $cfg || empty( $cfg['host'] ) ) {
            return new WP_Error( 'ssh_config', 'SSH non configuré.' );
        }
        if ( ! self::has_ssh2() ) {
            return new WP_Error( 'ssh2_missing', 'Extension PHP ssh2 non disponible sur ce serveur.' );
        }

        $conn = @ssh2_connect( $cfg['host'], (int) ( $cfg['port'] ?: 22 ) );
        if ( ! $conn ) {
            return new WP_Error( 'ssh_connect', 'Connexion SSH échouée vers ' . $cfg['host'] . ':' . $cfg['port'] . '. Vérifiez l\'hôte et le port.' );
        }

        if ( $cfg['auth'] === 'key' && ! empty( $cfg['privkey'] ) ) {
            // Authentification par clé
            $privkey_file = $this->write_temp_key( $cfg['privkey'] );
            $pubkey_file  = $privkey_file . '.pub';
            file_put_contents( $pubkey_file, $cfg['pubkey'] ?? '' );
            $ok = @ssh2_auth_pubkey_file( $conn, $cfg['user'], $pubkey_file, $privkey_file );
            @unlink( $privkey_file );
            @unlink( $pubkey_file );
        } else {
            // Authentification par mot de passe
            $ok = @ssh2_auth_password( $conn, $cfg['user'], $cfg['password'] ?? '' );
        }

        if ( ! $ok ) {
            return new WP_Error( 'ssh_auth', 'Authentification SSH échouée pour l\'utilisateur ' . $cfg['user'] . '.' );
        }

        $this->conn = $conn;
        return true;
    }

    // ── Exécute une action prédéfinie ─────────────────────────────────────────
    public function run_action( $action_id ) {
        if ( ! isset( self::$actions[ $action_id ] ) ) {
            return new WP_Error( 'unknown_action', 'Action inconnue : ' . $action_id );
        }

        $action = self::$actions[ $action_id ];

        if ( ! empty( $action['write'] ) ) {
            return $this->apply_write_action( $action_id );
        }

        if ( ! $this->conn ) {
            $connect = $this->connect();
            if ( is_wp_error( $connect ) ) return $connect;
        }

        return $this->exec( $action['cmd'] );
    }

    // ── Exécute une commande SSH ──────────────────────────────────────────────
    private function exec( $cmd ) {
        $stream = @ssh2_exec( $this->conn, $cmd );
        if ( ! $stream ) {
            return new WP_Error( 'ssh_exec', 'Impossible d\'exécuter la commande.' );
        }
        $stderr = ssh2_fetch_stream( $stream, SSH2_STREAM_STDERR );
        stream_set_blocking( $stream, true );
        stream_set_blocking( $stderr, true );

        $out = stream_get_contents( $stream );
        $err = stream_get_contents( $stderr );

        fclose( $stream );
        fclose( $stderr );

        return trim( $out . ( $err ? "\n[STDERR] " . $err : '' ) );
    }

    // ── Actions qui modifient des fichiers serveur ────────────────────────────
    private function apply_write_action( $action_id ) {
        if ( ! $this->conn ) {
            $connect = $this->connect();
            if ( is_wp_error( $connect ) ) return $connect;
        }

        if ( $action_id === 'nginx_gzip' ) {
            // Détecte le fichier de config nginx
            $conf_candidates = [
                '/etc/nginx/conf.d/gzip.conf',
                '/etc/nginx/snippets/gzip.conf',
            ];
            $target = $conf_candidates[0];
            $block  = "# Claude WP Assistant — GZIP\ngzip on;\ngzip_comp_level 6;\ngzip_min_length 256;\ngzip_vary on;\ngzip_proxied any;\ngzip_types text/plain text/css text/javascript application/javascript application/json image/svg+xml application/xml;\n";
            $check  = $this->exec( 'cat ' . $target . ' 2>/dev/null' );
            if ( strpos( $check, 'Claude WP Assistant' ) !== false ) {
                return 'Gzip Nginx déjà configuré (' . $target . ')';
            }
            $result = $this->exec( 'echo ' . escapeshellarg( $block ) . ' > ' . $target . ' && nginx -s reload 2>&1 && echo "Gzip Nginx activé et rechargé."' );
            return $result;
        }

        if ( $action_id === 'nginx_cache' ) {
            $target = '/etc/nginx/conf.d/cache-headers.conf';
            $block  = "# Claude WP Assistant — Cache Headers\nmap \$sent_http_content_type \$cache_control {\n  default                 \"no-cache\";\n  text/css                \"public, max-age=2592000\";\n  application/javascript  \"public, max-age=2592000\";\n  ~image/                 \"public, max-age=31536000, immutable\";\n  font/                   \"public, max-age=31536000, immutable\";\n}\nadd_header Cache-Control \$cache_control;\n";
            $check  = $this->exec( 'cat ' . $target . ' 2>/dev/null' );
            if ( strpos( $check, 'Claude WP Assistant' ) !== false ) {
                return 'Cache headers Nginx déjà configurés (' . $target . ')';
            }
            $result = $this->exec( 'echo ' . escapeshellarg( $block ) . ' > ' . $target . ' && nginx -s reload 2>&1 && echo "Cache headers Nginx activés et rechargés."' );
            return $result;
        }

        return new WP_Error( 'unknown_write', 'Action write inconnue.' );
    }

    // ── Sauvegarde les paramètres (chiffrés) ─────────────────────────────────
    public static function save_settings( $settings ) {
        $to_save = [
            'host'     => sanitize_text_field( $settings['host'] ?? '' ),
            'port'     => (int) ( $settings['port'] ?? 22 ),
            'user'     => sanitize_text_field( $settings['user'] ?? '' ),
            'auth'     => in_array( $settings['auth'] ?? 'password', [ 'password', 'key' ], true ) ? $settings['auth'] : 'password',
            'password' => self::encrypt( $settings['password'] ?? '' ),
            'privkey'  => self::encrypt( $settings['privkey'] ?? '' ),
            'pubkey'   => self::encrypt( $settings['pubkey'] ?? '' ),
        ];
        update_option( 'cwpa_ssh_settings', $to_save );
    }

    public static function get_settings() {
        $s = get_option( 'cwpa_ssh_settings', [] );
        if ( ! $s ) return null;
        return [
            'host'     => $s['host'] ?? '',
            'port'     => $s['port'] ?? 22,
            'user'     => $s['user'] ?? '',
            'auth'     => $s['auth'] ?? 'password',
            'password' => self::decrypt( $s['password'] ?? '' ),
            'privkey'  => self::decrypt( $s['privkey'] ?? '' ),
            'pubkey'   => self::decrypt( $s['pubkey'] ?? '' ),
        ];
    }

    public static function is_configured() {
        $s = self::get_settings();
        return $s && ! empty( $s['host'] ) && ! empty( $s['user'] );
    }

    // ── Chiffrement des credentials ──────────────────────────────────────────
    private static function get_key() {
        $base = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt();
        return substr( hash( 'sha256', $base ), 0, 32 );
    }

    private static function encrypt( $value ) {
        if ( ! $value || ! function_exists( 'openssl_encrypt' ) ) return $value;
        $key = self::get_key();
        $iv  = openssl_random_pseudo_bytes( 16 );
        $enc = openssl_encrypt( $value, 'AES-256-CBC', $key, 0, $iv );
        return base64_encode( $iv . '::' . $enc );
    }

    private static function decrypt( $value ) {
        if ( ! $value || ! function_exists( 'openssl_decrypt' ) ) return $value;
        $decoded = base64_decode( $value );
        if ( strpos( $decoded, '::' ) === false ) return $value; // not encrypted
        [ $iv, $enc ] = explode( '::', $decoded, 2 );
        $key = self::get_key();
        return openssl_decrypt( $enc, 'AES-256-CBC', $key, 0, $iv ) ?: $value;
    }

    private function write_temp_key( $key_content ) {
        $path = sys_get_temp_dir() . '/cwpa_ssh_key_' . wp_generate_password( 12, false );
        file_put_contents( $path, $key_content );
        chmod( $path, 0600 );
        return $path;
    }
}
