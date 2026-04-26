<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CWPA_WebP {

    public static function register_hooks() {
        if ( get_option( 'cwpa_webp_auto' ) ) {
            add_filter( 'wp_generate_attachment_metadata', [ __CLASS__, 'auto_convert_on_upload' ], 10, 2 );
        }
    }

    // ── Driver detection ─────────────────────────────────────────────────────
    public static function get_driver() {
        if ( extension_loaded( 'imagick' ) ) {
            try {
                $im = new Imagick();
                if ( in_array( 'WEBP', $im->queryFormats(), true ) ) return 'imagick';
            } catch ( Exception $e ) {}
        }
        if ( function_exists( 'imagewebp' ) ) return 'gd';
        return false;
    }

    // ── Stats ─────────────────────────────────────────────────────────────────
    public static function get_stats() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT p.ID, pm.meta_value AS file
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type IN ('image/jpeg','image/png','image/gif')
               AND pm.meta_key = '_wp_attached_file'"
        );

        $upload_dir   = wp_upload_dir();
        $base         = trailingslashit( $upload_dir['basedir'] );
        $total        = count( $rows );
        $converted    = 0;
        $size_orig    = 0;
        $size_webp    = 0;

        foreach ( $rows as $row ) {
            $path      = $base . $row->file;
            $webp_path = self::webp_path( $path );
            if ( file_exists( $path ) ) $size_orig += filesize( $path );
            if ( file_exists( $webp_path ) ) {
                $converted++;
                $size_webp += filesize( $webp_path );
            }
        }

        $saved_kb = $size_orig > 0 ? round( ( $size_orig - $size_webp ) / 1024 ) : 0;

        return [
            'driver'      => self::get_driver(),
            'total'       => $total,
            'converted'   => $converted,
            'pending'     => $total - $converted,
            'saved_kb'    => max( 0, $saved_kb ),
            'percent'     => $total > 0 ? round( ( $converted / $total ) * 100 ) : 0,
        ];
    }

    // ── Batch conversion ─────────────────────────────────────────────────────
    public static function convert_batch( $offset = 0, $limit = 5 ) {
        global $wpdb;

        $driver = self::get_driver();
        if ( ! $driver ) {
            return [ 'success' => false, 'message' => 'GD WebP ou Imagick requis. Contactez votre hébergeur pour activer l\'extension.' ];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, pm.meta_value AS file
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type IN ('image/jpeg','image/png','image/gif')
               AND pm.meta_key = '_wp_attached_file'
             LIMIT %d OFFSET %d",
            $limit, $offset
        ) );

        $upload_dir = wp_upload_dir();
        $base       = trailingslashit( $upload_dir['basedir'] );
        $converted  = 0;
        $skipped    = 0;
        $errors     = [];

        foreach ( $rows as $row ) {
            $path      = $base . $row->file;
            $webp_path = self::webp_path( $path );

            if ( ! file_exists( $path ) )     { $skipped++; continue; }
            if ( file_exists( $webp_path ) )  { $skipped++; continue; }

            $res = self::convert_file( $path, $webp_path, $driver );
            if ( $res === true ) {
                $converted++;
            } else {
                $errors[] = basename( $path ) . ': ' . $res;
            }
        }

        // Count total for progress
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type IN ('image/jpeg','image/png','image/gif')
               AND pm.meta_key = '_wp_attached_file'"
        );

        return [
            'success'      => true,
            'converted'    => $converted,
            'skipped'      => $skipped,
            'errors'       => array_slice( $errors, 0, 5 ),
            'has_more'     => count( $rows ) === $limit,
            'next_offset'  => $offset + $limit,
            'total'        => $total,
            'done_so_far'  => $offset + count( $rows ),
        ];
    }

    // ── Single file conversion ────────────────────────────────────────────────
    private static function convert_file( $source, $dest, $driver ) {
        $ext = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );

        try {
            if ( $driver === 'imagick' ) {
                $im = new Imagick( $source );
                $im->setImageFormat( 'webp' );
                $im->setOption( 'webp:lossless', 'false' );
                $im->setImageCompressionQuality( 82 );
                $im->writeImage( $dest );
                $im->clear();
                return true;
            }

            if ( $driver === 'gd' ) {
                if ( in_array( $ext, [ 'jpg', 'jpeg' ], true ) ) {
                    $img = imagecreatefromjpeg( $source );
                } elseif ( $ext === 'png' ) {
                    $img = imagecreatefrompng( $source );
                    imagealphablending( $img, true );
                    imagesavealpha( $img, true );
                } elseif ( $ext === 'gif' ) {
                    $img = imagecreatefromgif( $source );
                } else {
                    return 'Format non supporté.';
                }

                if ( ! $img ) return 'Impossible de lire l\'image.';

                imagewebp( $img, $dest, 82 );
                imagedestroy( $img );
                return true;
            }
        } catch ( Exception $e ) {
            return $e->getMessage();
        }

        return 'Driver inconnu.';
    }

    // ── Auto-convert on upload ───────────────────────────────────────────────
    public static function auto_convert_on_upload( $metadata, $attachment_id ) {
        $driver = self::get_driver();
        if ( ! $driver ) return $metadata;

        $file = get_attached_file( $attachment_id );
        if ( ! $file ) return $metadata;

        $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, [ 'jpg', 'jpeg', 'png', 'gif' ], true ) ) return $metadata;

        // Convert main file
        self::convert_file( $file, self::webp_path( $file ), $driver );

        // Convert generated thumbnails
        if ( ! empty( $metadata['sizes'] ) ) {
            $dir = trailingslashit( dirname( $file ) );
            foreach ( $metadata['sizes'] as $size_data ) {
                $thumb = $dir . $size_data['file'];
                if ( file_exists( $thumb ) ) {
                    self::convert_file( $thumb, self::webp_path( $thumb ), $driver );
                }
            }
        }

        return $metadata;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────
    private static function webp_path( $path ) {
        return preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $path );
    }
}
