YOAST SEO CSV IMPORTER - HOW TO USE
===================================
Author: Thimira Perera
Requires: Yoast SEO plugin must be installed and active. This plugin
will refuse to activate without it, and will auto-deactivate if Yoast
is later switched off.

WHAT IT DOES
Reads a CSV and writes Yoast SEO fields (SEO title, meta description,
focus keyphrase, canonical, cornerstone, social + Twitter title/
description, breadcrumb title, schema page/article type, and the
WordPress excerpt) to each page or post, matched automatically by URL.
Works on any WordPress site - no company branding.

After activating, a "Yoast SEO Tools" menu appears in the WP admin
sidebar (its own page, not under Tools) with three sub-pages:
  * CSV Importer - the bulk import described above.
  * SEO Status - lists published pages/posts/etc split into
    "SEO Completed" and "Not Completed". Post types whose Yoast search
    appearance is switched OFF (noindex) are hidden.
  * Featured Images - lists every page/post with a "Set featured image"
    button. It opens the WordPress media library, you pick an
    already-uploaded image, and it is applied in ONE click to the
    post/page featured image, the social (Facebook/LinkedIn) image,
    and the X (Twitter) image.

FILES IN THIS FOLDER
  yoast-csv-importer.php   <- the plugin
  (optional) yoast-import.csv or any *.csv  <- bundled data, if you prefer

INSTALL & RUN (no SSH needed)
1. Zip this folder so you have: yoast-csv-importer.zip
   (Windows: right-click the folder > Send to > Compressed (zipped) folder)
2. WP Admin > Plugins > Add New > Upload Plugin > choose the zip > Install > Activate.
3. Go to: WP Admin > Tools > Yoast SEO CSV Importer
4. Click "Download sample CSV" to get the exact column layout.
5. Fill the sample with your pages, save as CSV (keep the header row).
6. Upload your CSV with the "Choose File" field.
7. Click "1. Preview (dry run)". Check the table:
     - Green/normal rows = matched, will be updated.
     - RED rows = "NOT FOUND". Fix that URL/slug in the CSV and re-upload,
       OR set that page by hand.
8. When the preview looks right, click "2. Apply changes".

CSV COLUMNS (in order)
  URL, Focus Keyphrase, SEO Title, Meta Description, Cornerstone,
  Breadcrumb Title, Canonical URL, Page Type, Article Type,
  Social Title, Social Description, Twitter Title, Twitter Description,
  Excerpt

NOTES
- Empty cells in the CSV are SKIPPED - it will never blank an existing
  value. Fill a cell only when you want to set it.
- Cornerstone: put "Yes" or "No" in the Cornerstone column.
- You can upload once and the file is remembered for 1 hour, so Preview
  then Apply works without re-uploading.
- No upload? It falls back to any .csv file placed in this plugin folder.
- Requires the Yoast SEO plugin to be active.
- Safe to run on a staging copy first if you have one.

UNINSTALL
Plugins > deactivate "Yoast SEO CSV Importer" > Delete. Your SEO data
stays in place (it was written into Yoast's own fields).
