<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CWPA_API {

    private $api_key;
    private $endpoint = 'https://api.anthropic.com/v1/messages';
    private $model    = 'claude-sonnet-4-6';

    public function __construct() {
        $this->api_key = get_option( 'cwpa_api_key', '' );
    }

    public function is_configured() {
        return ! empty( $this->api_key );
    }

    public function analyze( $context, $type ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'no_api_key', 'Clé API manquante.' );
        }

        $system_prompt = $this->get_system_prompt( $type );
        $user_message  = $this->build_user_message( $context, $type );

        $body = wp_json_encode( [
            'model'      => $this->model,
            'max_tokens' => 2048,
            'system'     => $system_prompt,
            'messages'   => [
                [ 'role' => 'user', 'content' => $user_message ]
            ],
        ] );

        $response = wp_remote_post( $this->endpoint, [
            'timeout' => 60,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $this->api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => $body,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Erreur API inconnue.';
            return new WP_Error( 'api_error', $msg );
        }

        $text = $data['content'][0]['text'] ?? 'Aucune réponse.';
        return $this->strip_markdown_json( $text );
    }

    private function strip_markdown_json( $text ) {
        $text = trim( $text );
        if ( preg_match( '/^```(?:json)?\s*([\s\S]+?)\s*```$/i', $text, $m ) ) {
            return trim( $m[1] );
        }
        return $text;
    }

    private function get_system_prompt( $type ) {
        $fix_ids = implode( ', ', [
            'clear_expired_transients',
            'delete_post_revisions',
            'delete_spam_comments',
            'delete_trashed_posts',
            'disable_file_editor',
            'enable_debug_log',
            'disable_debug_display',
            'create_robots_txt',
        ] );

        $base = "Tu es un expert WordPress senior. Tu analyses des données techniques d'un site WordPress et tu fournis des diagnostics précis en français. "
              . "Tes réponses sont structurées en JSON avec les champs suivants:\n"
              . "- \"summary\": résumé court de l'analyse\n"
              . "- \"score\": note de 0 à 100\n"
              . "- \"priority_action\": action la plus urgente (string)\n"
              . "- \"issues\": tableau de problèmes, chaque problème ayant:\n"
              . "  - \"title\": titre court\n"
              . "  - \"severity\": \"critical\", \"warning\" ou \"info\"\n"
              . "  - \"description\": explication du problème\n"
              . "  - \"fix_suggestion\": suggestion de correction manuelle\n"
              . "  - \"auto_fixable\": true si une correction automatique est disponible, sinon false\n"
              . "  - \"fix_id\": si auto_fixable est true, indique l'identifiant parmi: $fix_ids. Laisse null si aucun ne correspond.\n"
              . "Réponds UNIQUEMENT en JSON valide, sans markdown, sans backticks, sans commentaires.";

        $types = [
            'php_errors'   => $base . "\nFocus: erreurs PHP, warnings, notices dans les logs, version PHP, configuration error_log.",
            'performance'  => $base . "\nFocus: options autoload excessives, transients expirés (fix_id: clear_expired_transients), révisions de posts (fix_id: delete_post_revisions), commentaires spam (fix_id: delete_spam_comments), corbeille (fix_id: delete_trashed_posts), tables volumineuses.",
            'plugins'      => $base . "\nFocus: plugins inactifs, plugins avec mises à jour manquantes, conflits potentiels.",
            'security'     => $base . "\nFocus: éditeur de fichiers actif (fix_id: disable_file_editor), debug_display activé (fix_id: disable_debug_display), debug_log absent (fix_id: enable_debug_log), préfixe DB par défaut, clés de sécurité, HTTPS, utilisateur admin.",
            'seo'          => $base . "\nFocus: robots.txt absent (fix_id: create_robots_txt), sitemap manquant, plugin SEO absent, permalink, blog public, balises manquantes.",
        ];

        return $types[ $type ] ?? $base;
    }

    private function build_user_message( $context, $type ) {
        return "Voici les données de diagnostic de ce site WordPress. Analyse et fournis ton rapport JSON:\n\n" . wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
    }

    public function chat( $message, $history = [] ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'no_api_key', 'Clé API manquante.' );
        }

        $messages = [];
        foreach ( $history as $h ) {
            $messages[] = [ 'role' => $h['role'], 'content' => $h['content'] ];
        }
        $messages[] = [ 'role' => 'user', 'content' => $message ];

        $body = wp_json_encode( [
            'model'      => $this->model,
            'max_tokens' => 1024,
            'system'     => "Tu es un assistant WordPress expert. Tu aides à diagnostiquer et résoudre des problèmes WordPress. Réponds en français, de manière concise et pratique.",
            'messages'   => $messages,
        ] );

        $response = wp_remote_post( $this->endpoint, [
            'timeout' => 45,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $this->api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => $body,
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['content'][0]['text'] ?? 'Aucune réponse.';
    }
}
