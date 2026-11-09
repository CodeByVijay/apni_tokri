# Media Library — deployment notes (Nginx + uploads)

## Apache vs Nginx

`Upload::ensureUploadsRootSecured()` may create `uploads/.htaccess` (Apache `mod_authz_core` only). **Nginx does not read `.htaccess`.** You must block script execution in the server config.

## Nginx recommendation (not installed by the application)

Serve `/uploads/` as static files only; never pass unknown extensions to PHP-FPM:

```nginx
location ^~ /uploads/ {
    root /var/www/apni_tokri;   # adjust to your deployment root
    default_type application/octet-stream;
    types {
        image/jpeg  jpg jpeg;
        image/png   png;
        image/webp  webp;
        image/gif   gif;
        application/pdf pdf;
    }
    location ~* \.(php|phtml|php[0-9]*|phar|cgi|pl|py|sh|bash)$ {
        deny all;
        return 403;
    }
    try_files $uri =404;
}
```

If uploads are outside the web root (recommended long-term), expose them via a controlled `/media/` alias with the same extension deny list.

Verify with a test file upload attempt (e.g. `evil.php` renamed to `.jpg` should not execute).

## Disposable database tests

```bash
php scripts/run-in-disposable-db.php --migrate-media-library scripts/test-media-library.php
```

Requires: local MySQL CLI, `database/production/apni_tokri_production.sql`, and migration `1.37.0` applied to disposable DB when production SQL does not yet include `media_assets`.
