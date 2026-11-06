# Changelog

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
