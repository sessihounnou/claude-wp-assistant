<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Vérifie les nouvelles versions sur GitHub Releases et les injecte dans
 * le système de mise à jour natif de WordPress.
 *
 * Pour publier une mise à jour :
 *   1. Bumper CWPA_VERSION dans le fichier principal du plugin.
 *   2. Créer un tag git correspondant (ex. v1.1.0).
 *   3. Publier une GitHub Release depuis ce tag — joindre le ZIP du plugin
 *      en tant qu'asset (nom : claude-wp-assistant.zip).
 *   4. WordPress détecte la nouvelle version au prochain check (toutes les 12h)
 *      ou via Tableau de bord > Mises à jour.
 */
class CWPA_Updater {

    private $repo;
    private $plugin_file;
    private $current_version;
    private $api_url;
    private $cache_key;

    public function __construct( $repo, $plugin_file, $current_version ) {
        $this->repo            = $repo;
        $this->plugin_file     = $plugin_file;
        $this->current_version = $current_version;
        $this->api_url         = "https://api.github.com/repos/{$repo}/releases/latest";
        $this->cache_key       = 'cwpa_github_release_' . md5( $repo );

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
        add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 10, 3 );
        add_filter( 'upgrader_post_install',                 [ $this, 'after_install' ], 10, 3 );
    }

    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $release = $this->get_latest_release();
        if ( ! $release ) return $transient;

        $latest = ltrim( $release['tag_name'], 'v' );

        if ( version_compare( $latest, $this->current_version, '>' ) ) {
            $download_url = $this->get_asset_url( $release );
            if ( $download_url ) {
                $transient->response[ $this->plugin_file ] = (object) [
                    'slug'        => dirname( $this->plugin_file ),
                    'plugin'      => $this->plugin_file,
                    'new_version' => $latest,
                    'url'         => "https://github.com/{$this->repo}",
                    'package'     => $download_url,
                    'icons'       => [],
                    'banners'     => [],
                    'tested'      => get_bloginfo( 'version' ),
                    'requires'    => '5.8',
                    'requires_php'=> '7.4',
                ];
            }
        }

        return $transient;
    }

    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->plugin_file ) ) return $result;

        $release = $this->get_latest_release();
        if ( ! $release ) return $result;

        $latest       = ltrim( $release['tag_name'], 'v' );
        $download_url = $this->get_asset_url( $release );
        $body         = $release['body'] ?? '';

        return (object) [
            'name'          => 'Claude WP Assistant',
            'slug'          => dirname( $this->plugin_file ),
            'version'       => $latest,
            'author'        => '<a href="https://biristools.com">Biristools</a>',
            'homepage'      => "https://github.com/{$this->repo}",
            'download_link' => $download_url,
            'requires'      => '5.8',
            'requires_php'  => '7.4',
            'tested'        => get_bloginfo( 'version' ),
            'last_updated'  => $release['published_at'] ?? '',
            'sections'      => [
                'description' => 'Plugin WordPress propulsé par Claude AI (Anthropic) pour analyser et corriger automatiquement les problèmes de votre site.',
                'changelog'   => nl2br( esc_html( $body ) ) ?: '<p>Voir les <a href="https://github.com/' . esc_attr( $this->repo ) . '/releases">releases GitHub</a>.</p>',
            ],
        ];
    }

    public function after_install( $response, $hook_extra, $result ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_file ) {
            return $response;
        }

        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $this->plugin_file );
        global $wp_filesystem;
        $wp_filesystem->move( $result['destination'], $plugin_dir );
        $result['destination'] = $plugin_dir;

        if ( is_plugin_active( $this->plugin_file ) ) {
            activate_plugin( $this->plugin_file );
        }

        return $result;
    }

    private function get_latest_release() {
        $cached = get_transient( $this->cache_key );
        if ( $cached !== false ) return $cached;

        $response = wp_remote_get( $this->api_url, [
            'timeout' => 15,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_site_url(),
            ],
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['tag_name'] ) ) return false;

        set_transient( $this->cache_key, $data, 6 * HOUR_IN_SECONDS );
        return $data;
    }

    private function get_asset_url( $release ) {
        if ( ! empty( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                if ( str_ends_with( strtolower( $asset['name'] ), '.zip' ) ) {
                    return $asset['browser_download_url'];
                }
            }
        }
        // Fallback: source ZIP généré automatiquement par GitHub
        return $release['zipball_url'] ?? null;
    }
}
