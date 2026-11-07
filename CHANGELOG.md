# Changelog

## [1.4.1]
- **Fixed:** AVIF and JPEG XL files (`.avif`, `.jxl`) were not masked. Image converters like Henkan add them as `<source>` in `<picture>` tags, and browsers load AVIF first, so the real upload path stayed visible. Both types are now masked and delivered.
- **Fixed:** `serve.php` sets the content type by file extension. `mime_content_type()` does not know AVIF and JPEG XL on older systems and returned `application/octet-stream`.
- **Added:** New token format AES-256-GCM: encryption and authentication in one step, URLs about half as long. New installations use it from the start.
- **Added:** Existing installations keep AES-256-CBC, so all links stay the same. **Settings → Anzen Security** has a button to switch new URLs to GCM; existing CBC links keep working after the switch, because `serve.php` accepts both formats.
- **Added:** Status page under **Settings → Anzen Security** after the setup (configuration file, prefix, delivery method, token format).

## [1.4]
- **Fixed:** Every plugin update deleted `config.php` and with it the secret key, so the setup had to run again and a new key broke all masked links. WordPress removes the whole plugin folder on updates. The configuration is now stored outside the plugin folder, one level above the WordPress directory: `secrets/anzen-<site folder>.php` (directory `0700`, file `0600`). Another location can be set with `ANZEN_CONFIG_FILE` in `wp-config.php`.
- **Added:** An existing `config.php` in the plugin folder is copied to the new location automatically (same key, all links keep working). If the location is not writable (e.g. `open_basedir`), the old file keeps working and the admin shows a warning.
- **Added:** The setup wizard shows where the configuration was saved.

## [1.3.2]
- **Fixed:** Resized images (thumbnails, medium, site icon sizes …) got broken URLs like `/media/cropped-favicon-32x32.webp`. WordPress builds the URL of a resized image from the full-size URL by replacing the file name; that URL was already masked, so the file name ended up after the prefix. The full-size URL now stays unmasked inside this step and the finished URL of the resized image is masked as usual.

## [1.3.1]
- **Fixed:** The prefix chosen in the setup (e.g. `example`) was ignored and URLs always used `/media/`. The default was defined before `config.php` was loaded. Existing tokens stay valid; only the folder in the URL changes.
- **Fixed:** The GitHub updater was only loaded in the admin. Scheduled update checks and automatic background updates (WP-Cron) did not work; it is now loaded on every request.
- **Fixed:** Wrong text domain in the plugin header (`hydro-shortlinks` → `anzen`).
- **Changed:** Requires WordPress 7.1 and PHP 8.4.

## [1.3]
- **Added:** Hexadecimal quote characters (`\x22`, `\x27`) in all regular expressions, so they survive editing in console editors.
- **Added:** Already masked URLs are never masked twice.
- **Fixed:** `serve.php` reads the token from the request URI, so it also works when proxies or FastCGI setups drop query parameters.
- **Fixed:** Content filters only touch the supported media file types; CSS and JavaScript are never rewritten.
- **Fixed:** Configuration constants are only defined once (no PHP warnings).
- **Fixed:** Strict separation of path and signature when reading tokens.
- **Changed:** nginx delivery via `X-Accel-Redirect`.

## [1.2]
- Setup wizard with server detection
- Delivery method switch (nginx ↔ PHP)
- Admin bypass and non-persistent cache group
- `wp_parse_url()` everywhere

## [1.0]
- Initial release with token-based masking
