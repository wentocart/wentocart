# Installation

Install WentoCart on your own computer or server. Choose **one** method.

WentoCart core is licensed under **OSL-3.0**. Marketplace extensions may use separate terms. See [LICENSING.md](LICENSING.md). After the shop is running, upgrades are in [UPGRADE.md](UPGRADE.md).

| Method | Best for |
| --- | --- |
| [Browser wizard](#1-browser-wizard-recommended) | Most merchants — unzip, start the helper, follow the steps |
| [Command line](#2-command-line) | Developers, SSH hosting, and automation |

## Requirements

| Requirement | Notes |
| --- | --- |
| **PHP** | **8.3 or newer**, with `pdo`, `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `curl`, `gd`, and `zip` |
| **Database** | **MySQL 5.7+** or **MariaDB 10.4+**. Use an empty database. SQLite is available from the CLI only. |
| **Disk** | Enough space for the shop files and later uploads |
| **Node.js** | **Not required** for the merchant zip. Required only when you install from a git clone. |

Check the PHP you will use:

```bash
php -v
php -m
```

If `php -v` shows 8.1 or older, install or select PHP 8.3+ (or use Docker).

## 1. Browser wizard (recommended)

Use the official customer zip, `wentocart.zip`. One zip works on Windows, Linux, and macOS.

### Step 1 — Extract the zip

Extract it to any folder (Desktop, `htdocs`, `~/www`, or a USB drive). The path does not matter.

You should see:

- `Install.vbs` (Windows) or `install.sh` (Linux and macOS)
- `shop.zip` — the inner shop archive, not the same file as the `wentocart.zip` you downloaded

### Step 2 — Start PHP for this folder

**Windows**

1. Double-click **`Install.vbs`**
2. A browser opens at `http://127.0.0.1:8099/`

PHP is found in this order: a `php.path` file (optional) → `php` on PATH → common XAMPP, Laragon, or WAMP folders.

**Linux**

```bash
chmod +x install.sh
./install.sh
```

Then open `http://127.0.0.1:8099/`.

**macOS**

```bash
chmod +x install.sh
./install.sh
```

**Already using Apache, nginx, XAMPP, WAMP, or Laragon**

Point the site document root at this folder and open that URL (for example `http://localhost/myshop/`). The helper script is not required.

Keep the helper window open while you use the shop on port 8099.

### Step 3 — Complete the wizard

1. **System check** — confirms this PHP meets the requirements
2. **Packages** — already prepared in the merchant zip
3. **Store information** — name, email, and currency. The shop address is taken from the page you opened and cannot be typed. On the helper, you can change **Port**; that value is written to `APP_URL`. A folder install under Apache shows the full address, including the folder.
4. **Database** — host, port, **empty** database name, user, password, and table prefix
5. **Admin account** — your login for `/admin`
6. **Installation** — wait for Database → Configuration → Design → Demo → Admin → Complete

When it finishes, open the **storefront** or **Admin Panel**.

After install, set a public website in **Admin → Store** when you have a real domain.

## 2. Command line

Use this after the inner shop is unpacked (the helper did it, or you extracted `shop.zip` into a folder that contains `artisan`).

### Step 1 — Check requirements

```bash
cd /path/to/shop
php artisan wento:doctor
```

Fix every FAIL line, then run doctor again.

### Step 2 — Install

Interactive:

```bash
php artisan wento:install
```

Non-interactive example:

```bash
php artisan wento:install --no-interaction --demo \
  --store-name="My Store" \
  --url=http://127.0.0.1:8000 \
  --db-database=myshop \
  --db-username=root \
  --db-password= \
  --prefix=WENTO \
  --admin-email=you@example.com \
  --admin-password='YourSecurePass1'
```

| Option | Meaning |
| --- | --- |
| `--demo` / `--no-demo` | Include or skip sample products |
| `--db-connection=sqlite` | Use SQLite instead of MySQL |
| `--db-host` / `--db-port` | MySQL host and port |
| `--prefix=` | Table prefix: start with a letter, then letters, numbers, or `_` |

### Step 3 — Serve the shop

```bash
php artisan serve
```

To pick a host and port:

```bash
php artisan serve --host=127.0.0.1 --port=8099
```

| Surface | Default URL |
| --- | --- |
| Store | `http://127.0.0.1:8000` |
| Admin | `http://127.0.0.1:8000/admin/login` |

You can also point Apache, nginx, or IIS at this folder.

## After install

1. Sign in to **Admin** with the account you created.
2. Configure **URLs**, **Email (SMTP)**, and **payments** before going live.
3. Use HTTPS in production (`APP_URL=https://…`).
4. Do not delete `storage/app/installed.lock` unless you intend to reinstall.

**Several shops on one machine:** one folder = one shop. Use a different empty database (or SQLite file) and a different URL or port for each copy.

## Common problems

### PHP version too old

| Symptom | What to do |
| --- | --- |
| Doctor or wizard says PHP must be 8.3+ | Install PHP 8.3+, or call it explicitly: `php8.3 artisan wento:install`, `C:\xampp\php\php.exe artisan wento:install` |
| Wizard still uses the wrong PHP | Create `php.path` next to `Install.vbs` / `install.sh` with one line: the full path to `php` or `php.exe` |

### Missing PHP extensions

| Symptom | What to do |
| --- | --- |
| `could not find driver` / PDO MySQL fail | Enable `pdo_mysql` in **this** PHP’s `php.ini`, then restart PHP or Apache |
| `Class DOMDocument not found` | Install or enable `xml` (for example `php8.3-xml` on Ubuntu) |
| Zip, GD, or curl errors | Enable `zip`, `gd`, and `curl` for the same PHP binary |

Linux example:

```bash
sudo apt install php8.3-cli php8.3-mysql php8.3-xml php8.3-mbstring \
  php8.3-curl php8.3-gd php8.3-zip
```

### Database errors

| Symptom | What to do |
| --- | --- |
| Connection refused | Start MySQL or MariaDB. Check host `127.0.0.1` and port `3306` |
| Access denied | Check the username and password. Grant that user rights on the database |
| Database already used or not empty | Create a **new empty** database, or drop all tables in the old one |
| Wrong database selected | Confirm the name in the wizard or `--db-database` matches MySQL |

Create an empty database:

```bash
mysql -u root -e "CREATE DATABASE myshop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### Wizard and helper

| Symptom | What to do |
| --- | --- |
| Installer opened as a local file | Start `Install.vbs` or `install.sh`, or point the web server at this folder |
| Port 8099 already in use | The helper picks the next free port (8099–8199). You can also type that port in the wizard |
| Install stuck on “Creating store tables” | Wait — a large schema can take several minutes. Keep the window open and check that MySQL is running |
| Redirects straight to “installed” | The shop is already installed. Use admin login. For a deliberate reinstall only: remove `storage/app/installed.lock`, set `WENTO_INSTALLED=false` in `.env`, and use an empty database |

### After install

| Symptom | What to do |
| --- | --- |
| Blank admin or missing CSS/JS | The merchant zip should include `public/build`. On a git clone: `npm install && npm run build` |
| Missing footer or theme images after git pull | Keep files under `themes/classic` (not `public/themes`). Then run `php artisan wento:theme-publish` |
| 404 on `/admin/login` | Use the same base URL as install. Then `php artisan cache:clear` |
| Images missing | Run `php artisan storage:link` |
| Port 8000 in use | `php artisan serve --port=8001` and set `APP_URL` to match |

### Already installed

```text
Already installed. Remove storage/app/installed.lock only if you intend to reinstall.
```

Only remove the lock if you mean to reinstall. Always use an empty database for a clean install.

## Developer checkout (git)

Composer and Node are required when you build from this repository.

```bash
git clone https://github.com/wentocart/wentocart.git
cd wentocart
composer install
npm install
npm run build
php artisan wento:theme-publish
php artisan wento:doctor
php artisan wento:install
```

## Quick reference

```bash
php artisan wento:doctor    # check this PHP
php artisan wento:install   # install the shop
php artisan serve           # local HTTP server
```

Zip build notes for releases are in `package/README.md`.
