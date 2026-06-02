# Kanguru Support Release Instructions

Use this checklist for every plugin release.

## 1) Update plugin version in code

Open `kanguru-support.php` and update both version values to the new release version `X.Y.Z`:

- Plugin header:
  - `Version: X.Y.Z`
- Constant:
  - `KGR_PLUGIN_VERSION` to `X.Y.Z`

## 2) Commit your changes

```bash
git add .
git commit -m "Release X.Y.Z"
```

## 3) Push your branch

```bash
git push origin <your-branch>
```

## 4) Merge to release branch

If you use a separate release branch, merge your changes into the release branch (usually `main`).

## 5) Create and push Git tag

```bash
git tag vX.Y.Z
git push origin vX.Y.Z
```

## 6) Build release ZIP

Create the release ZIP with this exact filename format:

- `kanguru-support-X.Y.Z.zip`

Example:

- `kanguru-support-1.1.1.zip`

### One-command build (PowerShell)

From plugin root, run:

```powershell
.\scripts\build-release.ps1 -Version X.Y.Z
```

Example:

```powershell
.\scripts\build-release.ps1 -Version 1.1.1
```

Do not create the release ZIP manually from Windows Explorer or other ad-hoc zip tools. Use the script so the archive entries are written with standard ZIP paths. Some Linux hosting unzip tools will otherwise extract files with names like `kanguru-support/assets/css/admin.css` instead of real folders.

Output ZIP will be created in:

- `dist/kanguru-support-X.Y.Z.zip`

### Required ZIP structure

The ZIP must unpack to a single top-level folder named `kanguru-support/`.
Inside that folder, the main plugin file must still be:

- `kanguru-support/kanguru-support.php`

Do not rename the folder or the main plugin file between releases. WordPress stores the active plugin path using that basename, and a changed path can produce `Plugin file does not exist` after update.

## 7) Validate ZIP contents

The ZIP must contain production-ready plugin files, including `vendor/`, and must exclude development/runtime artifacts.

Include:
- plugin PHP files
- `assets/`
- `includes/`
- `templates/`
- `backend/` runtime code
- `vendor/`

Exclude:
- `.git/`
- local secrets/env files
- temporary logs/storage artifacts
- other local/dev-only files not required in production

## 8) Publish GitHub release

1. Go to your GitHub repository.
2. Open **Releases -> Draft a new release**.
3. Choose tag: `vX.Y.Z`.
4. Set release title (example): `Kanguru Support X.Y.Z`.
5. Upload release asset: `kanguru-support-X.Y.Z.zip`.
6. Publish the release.
