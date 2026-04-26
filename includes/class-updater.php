<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Auto-update depuis GitHub Releases.
 *
 * Pour publier une mise à jour :
 *   1. Bumper CWPA_VERSION + le header "Version:" dans claude-wp-assistant.php
 *   2. git tag vX.Y.Z && git push origin vX.Y.Z
 *   3. gh release create vX.Y.Z --title "..." --notes "..."
 *      (joindre claude-wp-assistant.zip en asset si dispo, sinon le zipball GitHub est utilisé)
 *   4. WordPress détecte la nouvelle version au prochain check automatique
 *      ou via le bouton "Vérifier les mises à jour" dans le plugin.
 */
class CWPA_Updater {

    private $repo;
    private $plugin_file;
    private $current_version;
    private $api_url;
    private $cache_key;
    private $slug;

    public function __construct( $repo, $plugin_file, $current_version ) {
        $this->repo            = $repo;
        $this->plugin_file     = $plugin_file;
        $this->current_version = $current_version;
        $this->api_url         = "https://api.github.com/repos/{$repo}/releases/latest";
        $this->cache_key       = 'cwpa_gh_release_' . md5( $repo );
        $this->slug            = dirname( $plugin_file );

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
        add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 10, 3 );
        add_action( 'wp_ajax_cwpa_force_update_check',       [ $this, 'ajax_force_check' ] );
        add_action( 'admin_notices',                         [ $this, 'update_notice' ] );
    }

    // ── Injecte dans le transient WordPress ──────────────────────────────────
    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $release = $this->get_latest_release();
        if ( ! $release ) return $transient;

        $latest = ltrim( $release['tag_name'], 'v' );

        if ( version_compare( $latest, $this->current_version, '>' ) ) {
            $url = $this->get_download_url( $release );
            $transient->response[ $this->plugin_file ] = (object) [
                'slug'        => $this->slug,
                'plugin'      => $this->plugin_file,
                'new_version' => $latest,
                'url'         => "https://github.com/{$this->repo}",
                'package'     => $url,
                'icons'       => [],
                'banners'     => [],
                'tested'      => get_bloginfo( 'version' ),
                'requires'    => '5.8',
                'requires_php'=> '7.4',
            ];
        } else {
            // Confirm no update needed (avoid stale "no update" entries)
            unset( $transient->response[ $this->plugin_file ] );
            $transient->no_update[ $this->plugin_file ] = (object) [
                'slug'    => $this->slug,
                'plugin'  => $this->plugin_file,
                'version' => $this->current_version,
            ];
        }

        return $transient;
    }

    // ── Infos plugin dans la popup WordPress ─────────────────────────────────
    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) return $result;

        $release = $this->get_latest_release();
        if ( ! $release ) return $result;

        $latest = ltrim( $release['tag_name'], 'v' );
        $body   = $release['body'] ?? '';

        return (object) [
            'name'          => 'Claude WP Assistant',
            'slug'          => $this->slug,
            'version'       => $latest,
            'author'        => '<a href="https://biristools.com">Biristools</a>',
            'homepage'      => "https://github.com/{$this->repo}",
            'download_link' => $this->get_download_url( $release ),
            'requires'      => '5.8',
            'requires_php'  => '7.4',
            'tested'        => get_bloginfo( 'version' ),
            'last_updated'  => $release['published_at'] ?? '',
            'sections'      => [
                'description' => '<p>Plugin WordPress propulsé par <strong>Claude AI (Anthropic)</strong>. Analyse et corrige automatiquement les problèmes de votre site WordPress : performance, sécurité, SEO, PHP, plugins. Inclut PageSpeed, WebP, cache et optimisations serveur.</p>',
                'changelog'   => $body
                    ? '<pre style="white-space:pre-wrap">' . esc_html( $body ) . '</pre>'
                    : '<p><a href="https://github.com/' . esc_attr( $this->repo ) . '/releases" target="_blank">Voir les releases GitHub →</a></p>',
            ],
        ];
    }


    // ── Notice admin quand une màj est disponible ─────────────────────────────
    public function update_notice() {
        $screen = get_current_screen();
        // Only show on our plugin page
        if ( ! $screen || $screen->id !== 'toplevel_page_claude-wp-assistant' ) return;

        $release = $this->get_latest_release( false ); // no force, read cache only
        if ( ! $release ) return;

        $latest = ltrim( $release['tag_name'], 'v' );
        if ( ! version_compare( $latest, $this->current_version, '>' ) ) return;

        echo '<div class="notice notice-warning" style="margin:12px 0 0;padding:10px 16px;display:flex;align-items:center;gap:12px;">';
        echo '<strong>Claude WP Assistant v' . esc_html( $latest ) . ' disponible !</strong>';
        echo ' <a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">Mettre à jour via le tableau de bord</a>';
        echo ' &nbsp;·&nbsp; <a href="https://github.com/' . esc_attr( $this->repo ) . '/releases/latest" target="_blank">Voir les nouveautés →</a>';
        echo '</div>';
    }

    // ── AJAX : vide le cache et force un nouveau check ────────────────────────
    public function ajax_force_check() {
        check_ajax_referer( 'cwpa_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Non autorisé' );

        // Vide notre cache GitHub
        delete_transient( $this->cache_key );

        // Force WordPress à refaire son check
        delete_site_transient( 'update_plugins' );

        // Interroge GitHub maintenant
        $release = $this->get_latest_release();
        if ( ! $release ) {
            wp_send_json_error( 'Impossible de contacter GitHub. Vérifiez la connexion du serveur.' );
        }

        $latest = ltrim( $release['tag_name'], 'v' );
        $has_update = version_compare( $latest, $this->current_version, '>' );

        wp_send_json_success( [
            'current'    => $this->current_version,
            'latest'     => $latest,
            'has_update' => $has_update,
            'update_url' => $has_update ? admin_url( 'update-core.php' ) : null,
            'release_url'=> "https://github.com/{$this->repo}/releases/tag/v{$latest}",
        ] );
    }

    // ── Appel GitHub API avec cache ───────────────────────────────────────────
    private function get_latest_release( $use_cache = true ) {
        if ( $use_cache ) {
            $cached = get_transient( $this->cache_key );
            if ( $cached !== false ) return $cached;
        }

        $response = wp_remote_get( $this->api_url, [
            'timeout'   => 15,
            'sslverify' => true,
            'headers'   => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_site_url(),
            ],
        ] );

        if ( is_wp_error( $response ) ) return false;
        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) return false;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['tag_name'] ) ) return false;

        set_transient( $this->cache_key, $data, 6 * HOUR_IN_SECONDS );
        return $data;
    }

    // ── URL de téléchargement (asset ZIP ou zipball GitHub) ───────────────────
    private function get_download_url( $release ) {
        // Préfère un asset .zip attaché à la release
        if ( ! empty( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                $name = strtolower( $asset['name'] );
                // Compatible PHP 7.4+ (pas de str_ends_with)
                if ( substr( $name, -4 ) === '.zip' ) {
                    return $asset['browser_download_url'];
                }
            }
        }
        // Fallback : ZIP source généré automatiquement par GitHub
        return $release['zipball_url'] ?? '';
    }

    // ── Statut actuel (pour l'affichage dans l'admin) ─────────────────────────
    public function get_update_status() {
        $release = $this->get_latest_release();
        if ( ! $release ) {
            return [ 'status' => 'unknown', 'current' => $this->current_version ];
        }
        $latest     = ltrim( $release['tag_name'], 'v' );
        $has_update = version_compare( $latest, $this->current_version, '>' );
        return [
            'status'     => $has_update ? 'update_available' : 'up_to_date',
            'current'    => $this->current_version,
            'latest'     => $latest,
            'has_update' => $has_update,
            'update_url' => $has_update ? admin_url( 'update-core.php' ) : null,
            'release_url'=> "https://github.com/{$this->repo}/releases/tag/v{$latest}",
        ];
    }
}
