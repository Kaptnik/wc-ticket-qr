FPDF Library — Required for PDF Ticket Generation
==================================================

The PDF generation feature requires the FPDF library (fpdf.php).

FPDF is free, requires no Composer, and works with any PHP 7+ installation.

HOW TO INSTALL
--------------
1. Go to: http://www.fpdf.org/
2. Click "Download" and save fpdf182.zip (or latest version)
3. Unzip the archive
4. Copy fpdf.php into this folder:
   wp-content/plugins/wc-ticket-qr/lib/fpdf/fpdf.php

That's it. No other files from the zip are required.

VERIFY INSTALLATION
-------------------
After copying fpdf.php, this folder should contain:
  lib/fpdf/fpdf.php        ← the library (you add this)
  lib/fpdf/README.txt      ← this file

If fpdf.php is missing, ticket PDFs will not be generated and
a warning will be written to the PHP error log. The rest of the
plugin (QR codes in email, validation, refunds) will continue to work.

LICENSE
-------
FPDF is released under a permissive free license:
http://www.fpdf.org/en/dl.php?v=182&f=zip
