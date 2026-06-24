# Yoast SEO CSV Importer

A WordPress plugin to bulk-manage [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) metadata from a CSV file, plus an SEO status dashboard and a one-click featured-image picker.

Works on any WordPress site. Matches your content by URL — no manual copying field by field.

## Features

- **Bulk import from CSV** — set SEO title, meta description, focus keyphrase, canonical, cornerstone, breadcrumb title, schema page/article type, social (Open Graph) title & description, X (Twitter) title & description, and the WordPress excerpt.
- **Matched by URL** — works for pages, posts, and any public custom post type.
- **Dry-run preview with per-row exclude** — see exactly what will change before applying, then untick any pages you want to skip. Only the ticked rows are applied. Rows that can't be matched are flagged and can't be selected.
- **No re-saving each page** — after applying, Yoast's cached SEO data is refreshed automatically, so the new tags show on the front end without opening and re-saving every page.
- **Never blanks existing values** — empty CSV cells are skipped.
- **Sample CSV download** — grab the exact column layout to fill in (or to generate against).
- **SEO status dashboard** — lists published content split into *Completed* vs *Not Completed* (based on focus keyphrase, SEO title, meta description). Post types with Yoast search appearance switched off are hidden.
- **Featured-image picker** — opens the WordPress media library and applies a chosen image to the post/page featured image, the social (Facebook/LinkedIn) image, and the X image in one click. Set one item at a time, or tick several and **set them all in bulk** with a single chosen image.

## Requirements

- WordPress 5.5+
- [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) installed and active (the plugin will not activate without it).

## Installation

1. Download this repository as a ZIP (or zip the `yoast-csv-importer` folder).
2. In WP Admin: **Plugins → Add New → Upload Plugin**, choose the ZIP, **Install**, then **Activate**.
3. After activating, a **Yoast SEO Tools** menu appears in the admin sidebar with three pages: **CSV Importer**, **SEO Status**, and **Featured Images**.

## Usage

1. Go to **Tools → Yoast SEO CSV Importer**.
2. Click **Download sample CSV** to get the column layout.
3. Fill it with your pages, keeping the header row and column names exactly.
4. Upload the CSV, click **Preview (dry run)**, and review the table.
5. Untick any pages you want to exclude (the header checkbox toggles all).
6. Click **Apply changes to selected pages**. Yoast's cache is refreshed automatically — no need to open and re-save each page.

### CSV columns (in order)

```
URL, Focus Keyphrase, SEO Title, Meta Description, Cornerstone,
Breadcrumb Title, Canonical URL, Page Type, Article Type,
Social Title, Social Description, Twitter Title, Twitter Description,
Excerpt
```

- `Cornerstone`: `Yes` or `No`.
- Empty cells are skipped — fill a cell only when you want to set it.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
