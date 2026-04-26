<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CWPA_Htaccess {

    public static function write_section( $marker, $rules ) {
        $htaccess = ABSPATH . '.htaccess';

        if ( ! file_exists( $htaccess ) ) {
            @touch( $htaccess );
        }

        if ( ! is_writable( $htaccess ) ) {
            return new WP_Error( 'htaccess', '.htaccess non accessible en écriture. Vérifiez les permissions (644).' );
        }

        $content = file_get_contents( $htaccess );
        $start   = "# BEGIN CWPA_{$marker}";
        $end     = "# END CWPA_{$marker}";

        $content = preg_replace( "/\n?" . preg_quote( $start, '/' ) . "\n.*?" . preg_quote( $end, '/' ) . "\n?/s", '', $content );
        $content = ltrim( $content );

        $block   = "{$start}\n{$rules}\n{$end}\n\n";
        $content = $block . $content;

        if ( file_put_contents( $htaccess, $content ) === false ) {
            return new WP_Error( 'htaccess', 'Impossible d\'écrire dans .htaccess.' );
        }

        return true;
    }

    public static function remove_section( $marker ) {
        $htaccess = ABSPATH . '.htaccess';
        if ( ! file_exists( $htaccess ) ) return true;
        if ( ! is_writable( $htaccess ) ) {
            return new WP_Error( 'htaccess', '.htaccess non accessible en écriture.' );
        }

        $start   = "# BEGIN CWPA_{$marker}";
        $end     = "# END CWPA_{$marker}";
        $content = file_get_contents( $htaccess );
        $content = preg_replace( "/\n?" . preg_quote( $start, '/' ) . "\n.*?" . preg_quote( $end, '/' ) . "\n?/s", '', $content );

        file_put_contents( $htaccess, ltrim( $content ) );
        return true;
    }

    public static function has_section( $marker ) {
        $htaccess = ABSPATH . '.htaccess';
        if ( ! file_exists( $htaccess ) ) return false;
        return strpos( file_get_contents( $htaccess ), "# BEGIN CWPA_{$marker}" ) !== false;
    }

    public static function gzip_rules() {
        return '<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css
  AddOutputFilterByType DEFLATE application/javascript application/json application/xml
  AddOutputFilterByType DEFLATE font/woff font/woff2 image/svg+xml
  <IfModule mod_setenvif.c>
    BrowserMatch ^Mozilla/4 gzip-only-text/html
    BrowserMatch ^Mozilla/4\.0[678] no-gzip
    BrowserMatch \bMSIE !no-gzip !gzip-only-text/html
  </IfModule>
</IfModule>';
    }

    public static function browser_cache_rules() {
        return '<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType image/jpeg            "access plus 1 year"
  ExpiresByType image/gif             "access plus 1 year"
  ExpiresByType image/png             "access plus 1 year"
  ExpiresByType image/webp            "access plus 1 year"
  ExpiresByType image/svg+xml         "access plus 1 year"
  ExpiresByType image/x-icon          "access plus 1 year"
  ExpiresByType video/mp4             "access plus 1 year"
  ExpiresByType font/woff             "access plus 1 year"
  ExpiresByType font/woff2            "access plus 1 year"
  ExpiresByType text/css              "access plus 1 month"
  ExpiresByType application/javascript "access plus 1 month"
  ExpiresByType text/javascript       "access plus 1 month"
  ExpiresByType application/pdf       "access plus 1 month"
</IfModule>
<IfModule mod_headers.c>
  <FilesMatch "\.(ico|jpe?g|png|gif|webp|svg|mp4|woff2?)$">
    Header set Cache-Control "max-age=31536000, public, immutable"
  </FilesMatch>
  <FilesMatch "\.(css|js)$">
    Header set Cache-Control "max-age=2592000, public"
  </FilesMatch>
</IfModule>';
    }

    public static function webp_serving_rules() {
        return '<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{HTTP_ACCEPT} image/webp
  RewriteCond %{REQUEST_FILENAME} \.(jpe?g|png)$
  RewriteCond %{REQUEST_FILENAME}\.webp -f
  RewriteRule ^ %{REQUEST_URI}.webp [T=image/webp,E=webp_redir:1,L]
</IfModule>
<IfModule mod_headers.c>
  Header append Vary Accept env=webp_redir
</IfModule>
AddType image/webp .webp';
    }
}
