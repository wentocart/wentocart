# Contributing

Thank you for helping improve WentoCart.

This repository is the product source. The merchant install zip on [wentocart.com/download](https://wentocart.com/download/) is a separate package.

## Before you start

- Read [LICENSE](LICENSE) (OSL-3.0) and [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).
- Install a local shop from [docs/INSTALL.md](docs/INSTALL.md).
- Do not open a public issue for a security vulnerability. See [SECURITY.md](SECURITY.md).

## How to help

1. Reproduce the problem on a clean install.
2. Open an issue with steps, expected result, and actual result.
3. Send a pull request that stays on one topic.
4. Keep secrets, `.env` files, machine-specific paths, and live shop data out of the commit.

Useful non-code help includes installer reports, documentation fixes, and interface translations.

## Local check

PHP 8.3+ and MySQL 5.7+ or MariaDB 10.4+ are required.

```bash
php artisan wento:doctor
php artisan wento:install
```

## Pull request checklist

- [ ] The change belongs in this product
- [ ] Tests or a manual smoke path are included
- [ ] `.env`, credentials, and local server files are not in the commit
- [ ] License and copyright notices stay intact
