<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CWPA_SSH {

    private $conn   = null; // phpseclib SSH2 instance or native ssh2 connection
    private $driver = null; // 'phpseclib' | 'native'

    // ── Actions prédéfinies (whitelist) ──────────────────────────────────────
    private static $actions = [
        'server_info'   => [ 'label' => 'Infos serveur',                 'cmd' => 'uname -a && echo "---" && free -h && echo "---" && df -h /' ],
        'php_status'    => [ 'label' => 'Statut PHP & OPcache',          'cmd' => 'php -v && echo "---" && php -r "if(function_exists(\'opcache_get_status\')){$s=opcache_get_status(false);echo \'OPcache : \'.($s[\'opcache_enabled\']?\'ON\':\'OFF\').\' | RAM : \'.round($s[\'memory_usage\'][\'used_memory\']/1048576,1).\'MB / \'.round(($s[\'memory_usage\'][\'used_memory\']+$s[\'memory_usage\'][\'free_memory\'])/1048576,1).\'MB | Scripts : \'.$s[\'opcache_statistics\'][\'num_cached_scripts\'];}else{echo \'OPcache non disponible\';}"' ],
        'opcache_clear' => [ 'label' => 'Vider OPcache',                 'cmd' => 'php -r "if(function_exists(\'opcache_reset\')){opcache_reset();echo \'OPcache vidé.\';}else{echo \'OPcache non disponible.\';}"' ],
        'nginx_test'    => [ 'label' => 'Tester config Nginx',           'cmd' => 'nginx -t 2>&1' ],
        'nginx_reload'  => [ 'label' => 'Recharger Nginx',               'cmd' => 'nginx -s reload 2>&1 && echo "Nginx rechargé." || sudo nginx -s reload 2>&1' ],
        'nginx_status'  => [ 'label' => 'Statut Nginx',                  'cmd' => 'systemctl is-active nginx 2>/dev/null || service nginx status 2>/dev/null | head -10' ],
        'apache_status' => [ 'label' => 'Statut Apache',                 'cmd' => 'systemctl is-active apache2 2>/dev/null || systemctl is-active httpd 2>/dev/null || service apache2 status 2>/dev/null | head -10' ],
        'apache_reload' => [ 'label' => 'Recharger Apache',              'cmd' => 'service apache2 graceful 2>&1 || apachectl graceful 2>&1' ],
        'php_errors'    => [ 'label' => 'Logs PHP (50 dernières lignes)','cmd' => 'tail -50 /var/log/php-fpm/error.log 2>/dev/null || tail -50 /var/log/php/error.log 2>/dev/null || tail -50 /var/log/php_errors.log 2>/dev/null || echo "Log PHP non trouvé"' ],
        'nginx_logs'    => [ 'label' => 'Logs Nginx erreurs',            'cmd' => 'tail -30 /var/log/nginx/error.log 2>/dev/null || echo "Log Nginx non trouvé"' ],
        'mysql_status'  => [ 'label' => 'Statut MySQL',                  'cmd' => 'mysqladmin status 2>/dev/null || mysql -e "SHOW GLOBAL STATUS LIKE \'Uptime\';" 2>/dev/null || echo "MySQL : identifiants requis"' ],
        'processes'     => [ 'label' => 'Processus PHP/MySQL',           'cmd' => 'ps aux | grep -E "php|mysql|nginx|apache" | grep -v grep | head -20' ],
        'nginx_gzip'    => [ 'label' => 'Ajouter gzip à Nginx',          'cmd' => null, 'write' => true ],
        'nginx_cache'   => [ 'label' => 'Ajouter cache headers Nginx',   'cmd' => null, 'write' => true ],
    ];

    public static function get_actions() { return self::$actions; }

    // ── Disponibilité des drivers ─────────────────────────────────────────────
    public static function has_phpseclib() {
        return file_exists( CWPA_PATH . 'vendor/autoload.php' );
    }

    public static function has_native_ssh2() {
        return function_exists( 'ssh2_connect' );
    }

    public static function is_available() {
        return self::has_phpseclib() || self::has_native_ssh2();
    }

    // ── Connexion ─────────────────────────────────────────────────────────────
    public function connect() {
        $cfg = self::get_settings();
        if ( ! $cfg || empty( $cfg['host'] ) || empty( $cfg['user'] ) ) {
            return new WP_Error( 'ssh_config', 'SSH non configuré (hôte ou utilisateur manquant).' );
        }
        if ( ! self::is_available() ) {
            return new WP_Error( 'ssh_missing', 'Aucun driver SSH disponible. Le plugin inclut phpseclib — vérifiez que le dossier vendor/ est bien présent.' );
        }

        // Priorité : phpseclib (pur PHP, universel)
        if ( self::has_phpseclib() ) {
            return $this->connect_phpseclib( $cfg );
        }
        return $this->connect_native( $cfg );
    }

    private function connect_phpseclib( $cfg ) {
        if ( ! class_exists( '\\phpseclib3\\Net\\SSH2' ) ) {
            require_once CWPA_PATH . 'vendor/autoload.php';
        }

        try {
            $ssh = new \phpseclib3\Net\SSH2( $cfg['host'], (int) ( $cfg['port'] ?: 22 ), 30 );

            if ( $cfg['auth'] === 'key' && ! empty( $cfg['privkey'] ) ) {
                $key = \phpseclib3\Crypt\PublicKeyLoader::load( $cfg['privkey'] );
                $ok  = $ssh->login( $cfg['user'], $key );
            } else {
                $ok = $ssh->login( $cfg['user'], $cfg['password'] ?? '' );
            }

            if ( ! $ok ) {
                return new WP_Error( 'ssh_auth', 'Authentification SSH échouée pour ' . $cfg['user'] . '@' . $cfg['host'] . '. Vérifiez l\'identifiant et le mot de passe/clé.' );
            }

            $this->conn   = $ssh;
            $this->driver = 'phpseclib';
            return true;

        } catch ( \Exception $e ) {
            return new WP_Error( 'ssh_connect', 'Connexion SSH échouée : ' . $e->getMessage() );
        }
    }

    private function connect_native( $cfg ) {
        $conn = @ssh2_connect( $cfg['host'], (int) ( $cfg['port'] ?: 22 ) );
        if ( ! $conn ) {
            return new WP_Error( 'ssh_connect', 'Connexion SSH échouée vers ' . $cfg['host'] . ':' . ( $cfg['port'] ?: 22 ) );
        }

        if ( $cfg['auth'] === 'key' && ! empty( $cfg['privkey'] ) ) {
            $privkey_file = $this->write_temp_key( $cfg['privkey'] );
            $ok = @ssh2_auth_pubkey_file( $conn, $cfg['user'], $privkey_file . '.pub', $privkey_file );
            @unlink( $privkey_file );
            @unlink( $privkey_file . '.pub' );
        } else {
            $ok = @ssh2_auth_password( $conn, $cfg['user'], $cfg['password'] ?? '' );
        }

        if ( ! $ok ) {
            return new WP_Error( 'ssh_auth', 'Authentification SSH échouée pour ' . $cfg['user'] . '.' );
        }

        $this->conn   = $conn;
        $this->driver = 'native';
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

    // ── Exécute une commande ──────────────────────────────────────────────────
    private function exec( $cmd ) {
        if ( $this->driver === 'phpseclib' ) {
            $out = $this->conn->exec( $cmd );
            return $out !== false ? trim( $out ) : new WP_Error( 'ssh_exec', 'La commande n\'a retourné aucune sortie.' );
        }

        // native ssh2
        $stream = @ssh2_exec( $this->conn, $cmd );
        if ( ! $stream ) return new WP_Error( 'ssh_exec', 'Impossible d\'exécuter la commande.' );
        $stderr = ssh2_fetch_stream( $stream, SSH2_STREAM_STDERR );
        stream_set_blocking( $stream, true );
        stream_set_blocking( $stderr, true );
        $out = trim( stream_get_contents( $stream ) . "\n" . stream_get_contents( $stderr ) );
        fclose( $stream );
        fclose( $stderr );
        return $out;
    }

    // ── Actions write (modifient des fichiers serveur) ────────────────────────
    private function apply_write_action( $action_id ) {
        if ( ! $this->conn ) {
            $connect = $this->connect();
            if ( is_wp_error( $connect ) ) return $connect;
        }

        if ( $action_id === 'nginx_gzip' ) {
            $target = '/etc/nginx/conf.d/cwpa-gzip.conf';
            $check  = $this->exec( 'cat ' . $target . ' 2>/dev/null | head -1' );
            if ( ! is_wp_error( $check ) && strpos( $check, 'Claude WP' ) !== false ) {
                return 'Gzip Nginx déjà configuré dans ' . $target;
            }
            $block = "# Claude WP Assistant — GZIP\ngzip on;\ngzip_comp_level 6;\ngzip_min_length 256;\ngzip_vary on;\ngzip_proxied any;\ngzip_types text/plain text/css application/javascript application/json image/svg+xml application/xml font/truetype font/opentype;\n";
            return $this->exec( 'echo ' . escapeshellarg( $block ) . ' | sudo tee ' . $target . ' > /dev/null && sudo nginx -s reload 2>&1 && echo "✓ Gzip Nginx activé et rechargé."' );
        }

        if ( $action_id === 'nginx_cache' ) {
            $target = '/etc/nginx/conf.d/cwpa-cache-headers.conf';
            $check  = $this->exec( 'cat ' . $target . ' 2>/dev/null | head -1' );
            if ( ! is_wp_error( $check ) && strpos( $check, 'Claude WP' ) !== false ) {
                return 'Cache headers Nginx déjà configurés dans ' . $target;
            }
            $block = "# Claude WP Assistant — Cache Headers\nlocation ~* \\.(jpg|jpeg|png|gif|ico|svg|webp|woff2?|ttf|otf) { expires 1y; add_header Cache-Control \"public, max-age=31536000, immutable\"; }\nlocation ~* \\.(css|js) { expires 30d; add_header Cache-Control \"public, max-age=2592000\"; }\n";
            return $this->exec( 'echo ' . escapeshellarg( $block ) . ' | sudo tee ' . $target . ' > /dev/null && sudo nginx -s reload 2>&1 && echo "✓ Cache headers Nginx activés et rechargés."' );
        }

        return new WP_Error( 'unknown_write', 'Action write inconnue.' );
    }

    // ── Paramètres (chiffrés AES-256) ────────────────────────────────────────
    public static function save_settings( $settings ) {
        update_option( 'cwpa_ssh_settings', [
            'host'     => sanitize_text_field( $settings['host'] ?? '' ),
            'port'     => (int) ( $settings['port'] ?? 22 ),
            'user'     => sanitize_text_field( $settings['user'] ?? '' ),
            'auth'     => in_array( $settings['auth'] ?? 'password', [ 'password', 'key' ], true ) ? $settings['auth'] : 'password',
            'password' => self::encrypt( $settings['password'] ?? '' ),
            'privkey'  => self::encrypt( $settings['privkey'] ?? '' ),
        ] );
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
        ];
    }

    public static function is_configured() {
        $s = self::get_settings();
        return $s && ! empty( $s['host'] ) && ! empty( $s['user'] );
    }

    // ── Chiffrement ───────────────────────────────────────────────────────────
    private static function get_key() {
        return substr( hash( 'sha256', defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt() ), 0, 32 );
    }

    private static function encrypt( $value ) {
        if ( ! $value || ! function_exists( 'openssl_encrypt' ) ) return $value;
        $iv  = openssl_random_pseudo_bytes( 16 );
        $enc = openssl_encrypt( $value, 'AES-256-CBC', self::get_key(), 0, $iv );
        return base64_encode( $iv . '::' . $enc );
    }

    private static function decrypt( $value ) {
        if ( ! $value || ! function_exists( 'openssl_decrypt' ) ) return $value;
        $decoded = base64_decode( $value );
        if ( strpos( $decoded, '::' ) === false ) return $value;
        [ $iv, $enc ] = explode( '::', $decoded, 2 );
        return openssl_decrypt( $enc, 'AES-256-CBC', self::get_key(), 0, $iv ) ?: $value;
    }

    private function write_temp_key( $key_content ) {
        $path = sys_get_temp_dir() . '/cwpa_key_' . wp_generate_password( 12, false );
        file_put_contents( $path, $key_content );
        chmod( $path, 0600 );
        return $path;
    }
}
