<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CWPA_PageSpeed {

    private $api_key;
    private $endpoint = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    // Map PageSpeed audit IDs to our fix_ids
    private static $audit_fix_map = [
        'render-blocking-resources' => 'defer_js',
        'uses-text-compression'     => 'enable_gzip',
        'uses-long-cache-ttl'       => 'enable_browser_cache',
        'offscreen-images'          => 'enable_lazy_load',
        'unused-javascript'         => 'disable_embeds',
        'uses-rel-preconnect'       => 'enable_dns_prefetch',
        'mainthread-work-breakdown' => 'heartbeat_control',
        'dom-size'                  => null,
        'uses-optimized-images'     => null,
        'uses-webp-images'          => 'convert_all_webp',
        'efficient-animated-content'=> null,
        'unused-css-rules'          => null,
    ];

    public function __construct() {
        $this->api_key = get_option( 'cwpa_pagespeed_key', '' );
    }

    public function analyze( $url, $strategy = 'mobile' ) {
        $categories = [ 'performance', 'accessibility', 'best-practices', 'seo' ];
        $query = add_query_arg( [
            'url'      => $url,
            'strategy' => $strategy,
        ], $this->endpoint );

        foreach ( $categories as $cat ) {
            $query .= '&category=' . rawurlencode( $cat );
        }
        if ( $this->api_key ) {
            $query .= '&key=' . rawurlencode( $this->api_key );
        }

        $response = wp_remote_get( $query, [
            'timeout'   => 90,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $data['error']['message'] ?? "Erreur API PageSpeed (HTTP {$code}).";
            return new WP_Error( 'pagespeed', $msg );
        }

        return $this->parse( $data, $strategy );
    }

    private function parse( $data, $strategy ) {
        $cats   = $data['lighthouseResult']['categories'] ?? [];
        $audits = $data['lighthouseResult']['audits'] ?? [];

        $scores = [
            'performance'    => (int) round( ( $cats['performance']['score']    ?? 0 ) * 100 ),
            'accessibility'  => (int) round( ( $cats['accessibility']['score']  ?? 0 ) * 100 ),
            'best_practices' => (int) round( ( $cats['best-practices']['score'] ?? 0 ) * 100 ),
            'seo'            => (int) round( ( $cats['seo']['score']            ?? 0 ) * 100 ),
        ];

        $metric_keys = [
            'lcp' => 'largest-contentful-paint',
            'tbt' => 'total-blocking-time',
            'cls' => 'cumulative-layout-shift',
            'fcp' => 'first-contentful-paint',
            'si'  => 'speed-index',
            'tti' => 'interactive',
        ];
        $metric_labels = [
            'lcp' => 'LCP',
            'tbt' => 'TBT',
            'cls' => 'CLS',
            'fcp' => 'FCP',
            'si'  => 'Speed Index',
            'tti' => 'TTI',
        ];

        $cwv = [];
        foreach ( $metric_keys as $key => $audit_id ) {
            $a = $audits[ $audit_id ] ?? [];
            $score = isset( $a['score'] ) ? (int) round( $a['score'] * 100 ) : null;
            $cwv[ $key ] = [
                'label' => $metric_labels[ $key ],
                'value' => $a['displayValue'] ?? 'N/A',
                'score' => $score,
                'status'=> $score === null ? 'na' : ( $score >= 90 ? 'good' : ( $score >= 50 ? 'medium' : 'bad' ) ),
            ];
        }

        $opportunities = [];
        foreach ( self::$audit_fix_map as $audit_id => $fix_id ) {
            $a = $audits[ $audit_id ] ?? null;
            if ( ! $a ) continue;
            $score = $a['score'] ?? 1;
            if ( $score >= 0.9 ) continue; // Already good

            $savings = '';
            if ( isset( $a['details']['overallSavingsMs'] ) && $a['details']['overallSavingsMs'] > 0 ) {
                $savings = round( $a['details']['overallSavingsMs'] ) . ' ms économisés';
            } elseif ( isset( $a['details']['overallSavingsBytes'] ) && $a['details']['overallSavingsBytes'] > 0 ) {
                $savings = round( $a['details']['overallSavingsBytes'] / 1024 ) . ' KB économisés';
            }

            $opportunities[] = [
                'audit_id'     => $audit_id,
                'title'        => $a['title'] ?? $audit_id,
                'description'  => $a['description'] ?? '',
                'score'        => (int) round( $score * 100 ),
                'savings'      => $savings,
                'severity'     => $score < 0.5 ? 'critical' : 'warning',
                'auto_fixable' => $fix_id !== null,
                'fix_id'       => $fix_id,
            ];
        }

        usort( $opportunities, fn( $a, $b ) => $a['score'] - $b['score'] );

        return [
            'url'           => $data['id'] ?? '',
            'strategy'      => $strategy,
            'scores'        => $scores,
            'cwv'           => $cwv,
            'opportunities' => $opportunities,
            'fetch_time'    => $data['analysisUTCTimestamp'] ?? '',
        ];
    }
}
