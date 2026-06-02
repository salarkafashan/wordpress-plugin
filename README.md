# Kanguru Support (WordPress Plugin)

Kanguru Support is a WordPress plugin that provides a multi-step support request form with:

- Website/service request flows
- WHMCS validation
- Captcha support (Cloudflare Turnstile / Google reCAPTCHA)
- Jira queue/ticket integration
- Admin settings and ticket management UI

---

## Requirements

- WordPress (latest stable recommended)
- PHP 7.4+ (8.x recommended)
- MySQL/MariaDB

---

## Installation

1. In WordPress Admin, go to **Plugins -> Add New -> Upload Plugin**.
2. Upload the plugin ZIP.
3. Activate **Kanguru Support**.
4. Go to **Kanguru Support** settings and configure:
   - WHMCS credentials
   - Jira credentials
   - Captcha provider/keys

The plugin creates and migrates its own database tables automatically on activation/update.

---

## Shortcode

Use this shortcode on any page:

```txt
[support_request_form]
```

---

## Versioning

Plugin version is defined in:

- Plugin header in `kanguru-support.php`
- `KGR_PLUGIN_VERSION` constant in `kanguru-support.php`

Keep both aligned.

---

## WordPress Updates via GitHub Releases

This plugin uses GitHub as the update source in WordPress Admin.

- Repository: `https://github.com/salarkafashan/wordpress-plugin`
- Slug: `kanguru-support`
- Update method: **GitHub release assets**

### Release asset format

Upload a ZIP asset to each GitHub release with this naming pattern:

- `kanguru-support-<version>.zip`
- Example: `kanguru-support-1.1.1.zip`

### Publish a new update

1. Bump version in `kanguru-support.php`.
2. Commit and push.
3. Create Git tag/release (example: `v1.1.2`).
4. Build the ZIP with `.\scripts\build-release.ps1 -Version X.Y.Z` and upload that release asset ZIP (example: `kanguru-support-1.1.2.zip`).
5. In WordPress Admin:
   - **Dashboard -> Updates -> Check Again**
   - **Plugins** page should show the update
   - Click **Update now**

### ZIP packaging rules

Important:

- The ZIP must unpack to a single top-level `kanguru-support/` folder.
- The main plugin file must remain `kanguru-support/kanguru-support.php`.
- Do not change the folder name or main file name between releases.
- Use the release build script on Windows. Some zip tools create invalid entry paths for Linux hosts, which causes files to unzip into the plugin root with names like `kanguru-support/assets/css/admin.css`.

Include:

- Plugin PHP files
- `assets/`
- `includes/`
- `templates/`
- `backend/` (needed runtime code)
- `vendor/` (Composer dependencies)
- `composer.json` and `composer.lock` (recommended)

Exclude:

- `.git/`
- local environment files/secrets
- temporary runtime logs/storage artifacts
- development-only files that are not required in production

---

## Database Migrations

The plugin uses two migration/version tracks:

1. **Schema migration version**
   - Option: `kgr_support_db_version`
   - Managed by `DatabaseManager` with idempotent table migrations.

2. **Plugin migration version**
   - Option: `kgr_support_plugin_migration_version`
   - Tracks plugin-version update migrations.

On plugin load, if installed plugin migration version is lower than `KGR_PLUGIN_VERSION`, migrations run and the option is updated.

---

## Data Persistence

- Plugin updates preserve settings and normal operational data.
- Data is **not** deleted on deactivate.
- Data is deleted on **uninstall** (full cleanup behavior is enabled).

---

## Uninstall Behavior

On uninstall, plugin cleanup removes:

- `kgr_support_*` database tables
- plugin options with `kgr_` prefix
- plugin runtime data directories/files under `backend/` (storage/logs/uploads/database/.env if present)

Use uninstall only when full data removal is intended.

