<p align="center"><img src="./assets/banner-772x250.webp" width="772" alt="Anzen – Media URL Masking for WordPress"></p>

<p align="center">
  <img src="https://img.shields.io/github/v/release/Suzu-chan1990/anzen?color=green&style=flat-square" alt="Release">
  <img src="https://img.shields.io/badge/WordPress-7.1%2B-blue?style=flat-square" alt="WordPress 7.1+">
  <img src="https://img.shields.io/badge/PHP-8.4%2B-777BB4?style=flat-square" alt="PHP 8.4+">
  <img src="https://img.shields.io/badge/license-GPLv2-blue?style=flat-square" alt="License GPLv2">
</p>

# 🔒 Anzen – Media URL Masking for WordPress

**Anzen** (安全, "safety") hides the real paths of your media files. Instead of

```
https://example.com/wp-content/uploads/2026/11/cover.webp
```

visitors only see a signed, encrypted token:

```
https://example.com/media/MmRkMzE2NmQ0MDk3NzQxOO0BH4Cl8Ons1KX2…
```

The token is resolved by a small standalone endpoint (`serve.php`) that does **not** load WordPress. On nginx the file itself is then delivered by nginx (`X-Accel-Redirect`); on other servers by PHP.

---

## ✨ Features

- **Encrypted, authenticated tokens** – AES-256-GCM (new installations) or AES-256-CBC + HMAC-SHA256 (installations before 1.4.1). Tokens cannot be guessed, changed or used for other files.
- **Switch to GCM** – one click under **Settings → Anzen Security**; existing links keep working.
- **Stable URLs** – the same file always gets the same token, so caches and CDNs work normally.
- **Fast delivery** – `serve.php` runs without WordPress and without database queries.
  - **nginx:** hand-off via `X-Accel-Redirect`, nginx sends the file.
  - **Apache / LiteSpeed / other servers:** PHP `readfile()`.
- **Your own URL prefix** – `/media/`, `/files/`, `/fairu/` … chosen in the setup.
- **Setup wizard** – generates a 256-bit key, saves the configuration outside the plugin folder (it survives updates) and shows the matching server rule.
- **Admin untouched** – the media library and the dashboard keep the real URLs; only the frontend is masked.
- **Hardened endpoint** – timing-safe comparisons, path traversal and null-byte protection, allow-list of file types, a plain 404 for every invalid request. SVG files are sent with a restrictive Content-Security-Policy.
- **Updates from GitHub** – built-in update checker for new releases.

---

## 🖼️ What gets masked

Media files in the uploads folder with these extensions:

`jpg` `jpeg` `png` `gif` `webp` `avif` `jxl` `svg` `mp4` `webm` `mp3` `wav` `ogg`

Anzen hooks into:

| Source | Filter |
|---|---|
| Attachment URLs | `wp_get_attachment_url`, `wp_get_attachment_image_src` |
| Image tags and srcset | `wp_get_attachment_image`, `wp_calculate_image_srcset` |
| Featured images | `get_the_post_thumbnail_url`, `post_thumbnail_html` |
| Post content | `the_content` |

CSS, JavaScript and other theme or plugin files are not touched.

---

## ⚙️ Requirements

| | |
|---|---|
| WordPress | 7.1 or newer |
| PHP | 8.4 or newer with the `openssl` extension |
| Server | nginx (recommended), Apache, LiteSpeed or any server with URL rewriting |

---

## 📦 Installation

1. Upload the `anzen` folder to your plugins directory and activate the plugin.
2. Open **Settings → Anzen Security**.
3. Choose the URL prefix and the delivery method, then click **Complete Setup & Generate Key**.
4. Add the server rule that the wizard shows (see below) and reload the web server.
5. Reload the setup page – Anzen is active.

The wizard saves the configuration outside the plugin folder (see [Configuration](#-configuration)), so plugin updates never delete the key.

---

## 🌐 Server rules

Replace `media` with your prefix and the path to `serve.php` with the one shown by the wizard (plugin folders can have other names).

### nginx

Add to the `server { … }` block of the site, before the general WordPress `location /`:

```nginx
location ~ ^/media/([a-zA-Z0-9_-]+)$ {
    rewrite ^/media/([a-zA-Z0-9_-]+)$ /wp-content/plugins/anzen/serve.php?t=$1 last;
}
```

Then check and reload the configuration:

```bash
nginx -t && systemctl reload nginx
```

### Apache / LiteSpeed (.htaccess)

At the top of the `.htaccess` in the WordPress directory:

```apache
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^media/([a-zA-Z0-9_-]+)$ wp-content/plugins/anzen/serve.php?t=$1 [L]
</IfModule>
```

---

## 🔧 Configuration

### Location

WordPress deletes the whole plugin folder on every update, so Anzen keeps its configuration (with the secret key) outside of it. By default it is stored one level above the WordPress directory:

```
/path/to/site/                    ← parent folder
├── secrets/
│   └── anzen-<site folder>.php   ← Anzen configuration (0600)
└── <site folder>/                ← WordPress (wp-load.php, wp-content …)
```

The file name contains the name of the WordPress folder, so several sites in the same parent folder never share one configuration. The folder is outside the web root and cannot be reached from the browser.

PHP must be allowed to write there. If your hosting panel uses `open_basedir`, the parent folder has to be included (the default on most panels, as the customer folder is the parent of the site). Otherwise choose another location in `wp-config.php`, above the line `/* That's all, stop editing! */`:

```php
define( 'ANZEN_CONFIG_FILE', '/path/outside/webroot/anzen-config.php' );
```

**Upgrading from 1.3.x:** the old `config.php` in the plugin folder is copied to the new location automatically on the first request after the update (same key, all links keep working). If that fails, a warning appears in the admin. Make sure this has happened before the next update.

### Constants


| Constant | Meaning |
|---|---|
| `ANZEN_KEY` | Secret key (32 bytes). **Never change or delete it** – all existing links depend on it. |
| `ANZEN_TARGET_DIR` | Absolute path of the uploads folder |
| `ANZEN_NGINX_URI` | URL path of the uploads folder (used for `X-Accel-Redirect`) |
| `ANZEN_METHOD` | `nginx` or `php` |
| `ANZEN_MASK_PREFIX` | URL prefix, e.g. `media` |
| `ANZEN_CIPHER` | Format of new URLs: `gcm` or `cbc`. Missing = `cbc` (installations before 1.4.1). Both formats are always accepted. |

### Changing the prefix later

Edit `ANZEN_MASK_PREFIX` in the configuration file and update the server rule. The tokens stay the same, only the folder in the URL changes. Keep the old rule for a while if old URLs are still cached.

> Do **not** delete the configuration file to run the wizard again: a new key would be generated and every existing link would stop working.

---

## 🛡️ How it works

1. **Masking** – on the frontend, Anzen replaces upload URLs with `/<prefix>/<token>`.
2. **Token** – two formats, a file always gets the same token:
   - **GCM** (default since 1.4.1): `token = "g" + base64url( nonce ‖ AES-256-GCM( path ) ‖ tag )`. Encryption key and nonce key are derived from `ANZEN_KEY` with HKDF; the nonce is an HMAC of the path.
   - **CBC** (installations before 1.4.1): `token = base64url( IV ‖ AES-256-CBC( path | HMAC-SHA256(path) ) )`. The IV is derived from the path and the key.

   `serve.php` recognizes the format by the first character and accepts both.
3. **Delivery** – the server rule sends `/<prefix>/<token>` to `serve.php`. It decrypts the token, checks the signature, the IV and the path, and delivers the file – or answers 404.

---

## 🔄 Updates

Anzen updates itself from the [GitHub releases](https://github.com/Suzu-chan1990/anzen/releases) of this repository. A release is offered as an update when its tag matches a higher `Version:` in `anzen.php` (e.g. tag `v1.3.1` for version `1.3.1`). If a ZIP is attached to the release, that ZIP is installed; it has to contain the folder `anzen/`. The configuration is stored outside the plugin folder and is not touched by updates.

---

## 📜 Changelog

**v1.4.1**
- **Fixed:** AVIF and JPEG XL files (`.avif`, `.jxl`) were not masked. Image converters like Henkan add them as `<source>` in `<picture>` tags, and browsers load AVIF first, so the real upload path stayed visible. Both types are now masked and delivered.
- **Fixed:** `serve.php` sets the content type by file extension. `mime_content_type()` does not know AVIF and JPEG XL on older systems and returned `application/octet-stream`.
- **Added:** New token format AES-256-GCM: encryption and authentication in one step, URLs about half as long. New installations use it from the start.
- **Added:** Existing installations keep AES-256-CBC, so all links stay the same. **Settings → Anzen Security** has a button to switch new URLs to GCM; existing CBC links keep working after the switch, because `serve.php` accepts both formats.
- **Added:** Status page under **Settings → Anzen Security** after the setup (configuration file, prefix, delivery method, token format).

**v1.4**
- **Fixed:** Every plugin update deleted `config.php` and with it the secret key, so the setup had to run again and a new key broke all masked links. WordPress removes the whole plugin folder on updates. The configuration is now stored outside the plugin folder, one level above the WordPress directory: `secrets/anzen-<site folder>.php` (directory `0700`, file `0600`). Another location can be set with `ANZEN_CONFIG_FILE` in `wp-config.php`.
- **Added:** An existing `config.php` in the plugin folder is copied to the new location automatically (same key, all links keep working). If the location is not writable (e.g. `open_basedir`), the old file keeps working and the admin shows a warning.
- **Added:** The setup wizard shows where the configuration was saved.

**v1.3.2**
- **Fixed:** Resized images (thumbnails, medium, site icon sizes …) got broken URLs like `/media/cropped-favicon-32x32.webp`. WordPress builds the URL of a resized image from the full-size URL by replacing the file name; that URL was already masked, so the file name ended up after the prefix. The full-size URL now stays unmasked inside this step and the finished URL of the resized image is masked as usual.

**v1.3.1**
- **Fixed:** The prefix chosen in the setup (e.g. `example`) was ignored and URLs always used `/media/`. The default was defined before `config.php` was loaded. Existing tokens stay valid; only the folder in the URL changes.
- **Fixed:** The GitHub updater was only loaded in the admin. Scheduled update checks and automatic background updates (WP-Cron) did not work; it is now loaded on every request.
- **Fixed:** Wrong text domain in the plugin header (`hydro-shortlinks` → `anzen`).
- **Changed:** Requires WordPress 7.1 and PHP 8.4.

**v1.3**
- **Added:** Hexadecimal quote characters (`\x22`, `\x27`) in all regular expressions, so they survive editing in console editors.
- **Added:** Already masked URLs are never masked twice.
- **Fixed:** `serve.php` reads the token from the request URI, so it also works when proxies or FastCGI setups drop query parameters.
- **Fixed:** Content filters only touch the supported media file types; CSS and JavaScript are never rewritten.
- **Fixed:** Configuration constants are only defined once (no PHP warnings).
- **Fixed:** Strict separation of path and signature when reading tokens.
- **Changed:** nginx delivery via `X-Accel-Redirect`.

**v1.2**
- Setup wizard with server detection
- Delivery method switch (nginx ↔ PHP)
- Admin bypass and non-persistent cache group
- `wp_parse_url()` everywhere

**v1.0**
- Initial release with token-based masking

---

## 🛡️ Security

Please report security issues privately – see [SECURITY.md](SECURITY.md).

## 📄 License

GPLv2 or later.

## 👤 Author

**Saguya** – [GitHub @Suzu-chan1990](https://github.com/Suzu-chan1990)

## 💬 Support & Feedback

- [Report a bug](https://github.com/Suzu-chan1990/anzen/issues)
- [Request a feature](https://github.com/Suzu-chan1990/anzen/issues)
- ⭐ Star the repository if Anzen is useful to you.
