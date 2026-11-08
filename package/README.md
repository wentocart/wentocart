# Installer / merchant zip helpers

Used when building or verifying the shop install package. A running shop does not load these files.

## Build the customer zip

Output is `dist/wentocart.zip` unless you override the path. The same script works from any folder.

The build needs the tools below. It does not use the `zip` command or Python. `npm ci` installs the committed `package-lock.json`, so frontend packages resolve the same way on every machine.

| Tool | Required |
| --- | --- |
| PHP | 8.3+ as `php` on PATH, with `ctype`, `curl`, `dom`, `fileinfo`, `mbstring`, `openssl`, `tokenizer`, `xml`, `zip` |
| Composer | 2.x |
| Node.js | 20.19+ or 22.12+ (Vite 8). Ubuntu’s apt `nodejs` package is too old. |
| npm | comes with Node |
| Disk | about 1.5 GB free |
| Network | for `composer` / `npm ci` when dependencies are not cached |

The zip builders **always** run `npm run build` so merchant packages cannot ship a stale `public/build`. `npm ci` runs only when `node_modules` is missing or older than `package-lock.json`. Set `WENTO_SKIP_FRONTEND_BUILD=1` only when you intentionally reuse an already-verified local build. After the frontend build, `package/verify-admin-build.php` checks that Host, ExtensionTabbedSettings, and ExtensionPrimaryNav are present in the Vite manifest.

The customer download is `wentocart.zip`. Inside it the shop archive is `shop.zip`, so extracting the download into the same folder does not overwrite the file being read.

Ubuntu 24.04 packages (Node still comes from nodejs.org or nvm, not apt):

```bash
sudo apt install -y php8.3-cli php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-mysql composer
```

Ubuntu 22.04 does not ship PHP 8.3 in the default apt repos. Install a PHP 8.3 build first and check `php -v`.

```bash
# Linux / macOS
./package/build-install-zip.sh
# → dist/wentocart.zip
```

```powershell
# Windows
powershell -File package/build-test-zip.ps1
# → dist\wentocart.zip
# optional: -OutDir C:\releases
# optional: $env:WENTO_ZIP_OUT = 'C:\releases\wentocart.zip'
```

Ship **only** `dist/wentocart.zip` (one zip for Windows, Linux, and macOS).

That zip is a production package. It includes the setup wizard, `vendor/` without dev packages, and `public/build`. It does not include React source, tests, docs, or `node_modules`. Translation keys are written to `resources/i18n/ui-source.json` so the admin can still edit translations after the source is left out. The wizard removes `setup/`, the schema dump, and leftover seeders after a successful install. Advanced → Install demo data uses `App\Domain\Installer\DemoCatalogInstaller`, which stays in the shop.

Customer install steps: **[docs/INSTALL.md](../docs/INSTALL.md)**.

The zip resets Apache `RewriteBase` to `/` so it is not tied to the folder where the zip was built.

## Local test loop

Keep one source tree for code. Rebuild and extract a clean install folder each time you test the wizard:

```bash
./package/rebuild-test-install.sh
# default extract: ~/www/wentocart-install
# or: WENTO_TEST_DIR=/path/to/test ./package/rebuild-test-install.sh
```

Then:

```bash
cd ~/www/wentocart-install
./install.sh
# http://127.0.0.1:8099/
```

Use an empty MySQL database each install.

| File | Role |
| --- | --- |
| `build-install-zip.sh` | Linux/macOS merchant zip builder → `dist/` |
| `rebuild-test-install.sh` | Build zip + fresh extract for local wizard checks |
| `build-test-zip.ps1` | Windows merchant zip builder → `dist/` |
| `verify-admin-build.php` | Fail the zip if extension admin nav bundles are stale |
| `Install.vbs` / `install.sh` | Start the helper PHP server |
| `server.php` / `index.php` / `.htaccess` / `checks.php` / `helper.php` | Installer entry, router, and PHP/port helper |
| `dump-install-schema.php` / `verify-install-schema.php` | Keep `database/schema/mysql-schema.sql` honest |

Root `INSTALL.bat` / `INSTALL.sh` / `server.php` are for a source checkout. The merchant zip excludes those and uses the helpers above.
