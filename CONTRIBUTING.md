# Contributing to WooCommerce Ticket QR

Thanks for your interest in contributing!

## Reporting Bugs

Please open an issue with:
- WordPress version
- WooCommerce version
- PHP version
- Steps to reproduce
- Expected vs actual behaviour
- Any error logs from `wp-content/debug.log`

## Feature Requests

Open an issue with the `enhancement` label. Describe the use case and why it would be valuable.

## Pull Requests

1. Fork the repo and create a branch from `main`
2. Follow WordPress coding standards
3. Test with WP_DEBUG enabled
4. Ensure backward compatibility with PHP 7.4
5. Update the changelog in `readme.txt` and `README.md`

## Code Style

- Follow [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- Prefix all functions, classes, and options with `wctqr_` or `WCTQR_`
- Escape all output (`esc_html`, `esc_url`, `esc_attr`)
- Sanitize all input (`sanitize_text_field`, `absint`, etc.)
- Use `$wpdb->prepare()` for all database queries
