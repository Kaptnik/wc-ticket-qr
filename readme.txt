=== WooCommerce Ticket QR ===
Contributors: karthikumashankar
Tags: woocommerce, tickets, qr code, events, pdf, event tickets
Requires at least: 6.0
Tested up to: 6.5
Stable tag: 1.2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 7.0
WC tested up to: 8.9

Turn any WooCommerce product into an event ticket with unique, single-use QR codes.

== Description ==

WooCommerce Ticket QR transforms any WooCommerce product into a fully featured event ticketing system — no third-party ticketing platform needed.

**How it works:**

1. Check "This product is a ticket" on any WooCommerce product
2. When a customer purchases, they receive an email with a unique QR code
3. At the door, staff scan QR codes using the built-in browser scanner
4. Each code can only be scanned once — duplicates and fakes are rejected

**Key Features:**

* **Unique QR codes** — one cryptographically signed token per unit purchased
* **Single-use enforcement** — atomic database validation prevents double-entry
* **PDF ticket attachments** — professional ticket PDF attached to confirmation emails
* **Inline QR in email** — QR image embedded directly, works in Gmail/Apple Mail/Outlook
* **Variable product support** — works with ticket type variations (Adult/Child/VIP)
* **Browser-based door scanner** — any smartphone, no app required
* **Expandable scan log** — view full attendee details for the last 10 scans
* **Bulk email sending** — send tickets for multiple orders at once, with live progress
* **Refund invalidation** — refunds automatically void and notify about cancelled tickets
* **Configurable settings** — customise email content, QR size, batch size, and more
* **REST API** — integrate with custom scanning apps

== Installation ==

1. Upload the plugin to `/wp-content/plugins/wc-ticket-qr/`
2. Activate through the Plugins menu
3. (Optional) Install FPDF for PDF tickets — see FAQ
4. Edit a product and check **"This product is a ticket"** in Product Data → General

== Frequently Asked Questions ==

= Do I need FPDF for the plugin to work? =
No. Without FPDF, tickets are delivered with an inline QR code in the email body. FPDF is only needed for the PDF attachment. Download it free from fpdf.org and place `fpdf.php` in `wp-content/plugins/wc-ticket-qr/lib/fpdf/fpdf.php`.

= How do I scan tickets at the door? =
A Ticket Scanner page is created automatically at `yoursite.com/ticket-scanner/`. Staff need to be logged in with Shop Manager or Administrator role. Open the page on any smartphone.

= Does it work with variable products? =
Yes — ticket variations (e.g. Adult/Child) are fully supported. The variation details appear in the scanner result.

= What happens when a ticket is refunded? =
The ticket is voided in the database, its QR image is deleted, and the customer receives a cancellation email. Attempting to scan a voided ticket returns an error.

= Can I send tickets for old orders? =
Yes — open any order in WooCommerce admin, find the "Ticket QR Codes" meta box, and click "Generate & Resend Ticket Email". You can also use the bulk action to process multiple orders at once.

= Is there a REST API for custom scanning apps? =
Yes. `POST /wp-json/wctqr/v1/validate/{token}` — requires WordPress authentication. Returns full attendee details on success.

= How do I set a custom secret key? =
Add `define('WCTQR_SECRET', 'your-random-string');` to `wp-config.php`, or set it in WooCommerce → Ticket QR: Settings.

== Screenshots ==

1. Ticket email with inline QR code
2. Browser-based door scanner with expandable scan log
3. WooCommerce admin ticket management page
4. Product settings — ticket checkbox, event date, and venue
5. Bulk action sending tickets for multiple orders
6. Settings panel

== Changelog ==

= 1.2.0 =
* Added per-order QR mode: one QR code for the entire order admitting all attendees with a single scan
* Per-order scan result shows itemized ticket breakdown (e.g. "3 Adult - Vegetarian, 2 Child - Kids Meal")
* Scanner result now appears in a modal popup requiring explicit acknowledgement before next scan
* Modal displays admits count prominently, all variation attributes individually, and full order breakdown
* Added {admits} placeholder for ticket card title (resolves to number of people this ticket admits)
* Added {ticket_number} and {total_tickets} placeholders for ticket card title
* QR mode setting added to settings panel with clear per-ticket vs per-order descriptions
* DB schema updated: added quantity and order_item_summary columns
* REST API response now includes itemized items array for per-order tickets
* Expandable scan log shows per-order item breakdown in collapsed summary and expanded view
* Updated plugin URI to GitHub repository
* Simplified retro/bulk classes to delegate token generation to WCTQR_Generator

= 1.1.0 =
* Added configurable settings panel (email content, QR size, batch size, and more)
* Added bulk order action with batched processing and live progress screen
* Expandable scan log with full attendee details (tap to expand)
* Settings-driven email subject, heading, body, and footer text
* Configurable maximum scan log entries

= 1.0.0 =
* Initial release
* Unique HMAC-SHA256 QR token generation per ticket unit
* PDF ticket generation with FPDF
* REST API validation endpoint with atomic scan locking
* Retroactive QR generation and email resend from order edit screen
* Full and partial refund invalidation with customer notification
* Browser-based door scanner using ZXing library
* Admin ticket management page with filtering and pagination
* HPOS (High-Performance Order Storage) compatible

== Upgrade Notice ==

= 1.2.0 =
Database migration required: two new columns (quantity, order_item_summary) are added automatically on activation. Deactivate and reactivate the plugin after updating to apply. Regenerate tokens for existing orders using the "Generate & Resend" button.

= 1.1.0 =
Adds settings panel, bulk actions, and expandable scan log. No database changes required.

