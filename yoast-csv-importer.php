<?php
/**
 * Plugin Name: Yoast SEO CSV Importer
 * Description: Bulk-set Yoast SEO meta (title, meta description, focus keyphrase, canonical, cornerstone, social, breadcrumb, schema type, excerpt) for pages and posts from a CSV, matched by URL. The preview lets you untick individual pages to exclude them before applying, and Yoast's cached SEO data is refreshed automatically so you never have to open and re-save each page. Adds its own admin menu with a CSV Importer, an SEO status dashboard (completed vs not completed), and a Featured Images page that sets the post thumbnail plus Yoast social and X images at once - one at a time or in bulk to many selected items.
 * Version: 2.4.1
 * Author: Thimira Perera
 * Requires at least: 5.5
 * Requires Plugins: wordpress-seo
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Block activation if Yoast SEO is not active, so this plugin can never
 * run without its dependency. (Requires Plugins header covers WP 6.5+;
 * this guard covers older versions and direct activation.)
 */
register_activation_hook( __FILE__, function () {
	if ( ! is_plugin_active( 'wordpress-seo/wp-seo.php' ) && ! defined( 'WPSEO_VERSION' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'Yoast SEO CSV Importer requires the Yoast SEO plugin to be installed and active. Please activate Yoast SEO first, then activate this plugin.', 'yoast-csv-importer' ),
			esc_html__( 'Dependency missing', 'yoast-csv-importer' ),
			array( 'back_link' => true )
		);
	}
} );

/**
 * Safety net: if Yoast is deactivated later, auto-deactivate this plugin
 * and show an admin notice instead of running broken.
 */
add_action( 'admin_init', function () {
	if ( ! defined( 'WPSEO_VERSION' ) && ! is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) {
		if ( is_plugin_active( plugin_basename( __FILE__ ) ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p><strong>Yoast SEO CSV Importer</strong> was deactivated because the required <strong>Yoast SEO</strong> plugin is not active.</p></div>';
			} );
		}
	}
} );

/**
 * CSV column header  =>  meta key (or special handler).
 * Special: __cornerstone, __excerpt are handled in code, not as raw meta.
 */
function yscsv_map() {
	return array(
		'Focus Keyphrase'      => '_yoast_wpseo_focuskw',
		'SEO Title'            => '_yoast_wpseo_title',
		'Meta Description'     => '_yoast_wpseo_metadesc',
		'Cornerstone'          => '__cornerstone',
		'Breadcrumb Title'     => '_yoast_wpseo_bctitle',
		'Canonical URL'        => '_yoast_wpseo_canonical',
		'Page Type'            => '_yoast_wpseo_schema_page_type',
		'Article Type'         => '_yoast_wpseo_schema_article_type',
		'Social Title'         => '_yoast_wpseo_opengraph-title',
		'Social Description'   => '_yoast_wpseo_opengraph-description',
		'Twitter Title'        => '_yoast_wpseo_twitter-title',
		'Twitter Description'  => '_yoast_wpseo_twitter-description',
		'Excerpt'              => '__excerpt',
	);
}

/** Ordered list of CSV column headers (URL first, then the mapped columns). */
function yscsv_columns() {
	return array_merge( array( 'URL' ), array_keys( yscsv_map() ) );
}

/** Resolve a public URL to a post/page ID (works for pages and any public CPT). */
function yscsv_resolve_post_id( $url ) {
	$id = url_to_postid( $url );
	if ( $id ) { return $id; }

	// Fallback: match by the last path segment (slug).
	$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
	if ( $path === '' ) {
		// Home page.
		$front = (int) get_option( 'page_on_front' );
		return $front ? $front : 0;
	}
	$parts = explode( '/', $path );
	$slug  = end( $parts );

	// Try any public post type by slug.
	$types = get_post_types( array( 'public' => true ), 'names' );
	$q = new WP_Query( array(
		'name'           => $slug,
		'post_type'      => array_values( $types ),
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'no_found_rows'  => true,
	) );
	if ( $q->have_posts() ) { return (int) $q->posts[0]->ID; }
	return 0;
}

/** Guess the column delimiter from the header line (comma, semicolon, tab or pipe). */
function yscsv_detect_delimiter( $line ) {
	$counts = array(
		','  => substr_count( $line, ',' ),
		';'  => substr_count( $line, ';' ),
		"\t" => substr_count( $line, "\t" ),
		'|'  => substr_count( $line, '|' ),
	);
	arsort( $counts );
	$best = key( $counts );
	return ( $counts[ $best ] > 0 ) ? $best : ',';
}

/** Clean a header cell: strip a UTF-8 BOM and zero-width chars, then trim. */
function yscsv_clean_header( $s ) {
	$s = (string) $s;
	$s = preg_replace( '/^\xEF\xBB\xBF/', '', $s );          // UTF-8 BOM at the very start.
	$s = preg_replace( '/[\x{200B}\x{FEFF}]/u', '', $s );    // stray zero-width / BOM chars.
	return trim( $s );
}

/** Map a header to its canonical name (e.g. "url" or " URL " -> "URL"), case/space-insensitive. */
function yscsv_canonical_header( $col ) {
	$norm = strtolower( preg_replace( '/\s+/', ' ', trim( (string) $col ) ) );
	foreach ( yscsv_columns() as $known ) {
		if ( strtolower( $known ) === $norm ) { return $known; }
	}
	return $col; // Unknown column: leave as-is (it's ignored later anyway).
}

/**
 * Parse an open CSV file handle into an array of assoc rows.
 *
 * Robust to the two things that commonly break imports: a UTF-8 BOM on the
 * header (from "CSV UTF-8" / AI-generated files) and non-comma delimiters
 * (semicolon/tab, common in localised Excel exports). Header names are also
 * matched case- and whitespace-insensitively so "url" still maps to "URL".
 */
function yscsv_parse_handle( $h ) {
	$rows = array();

	// Peek at the first line to detect the delimiter, then rewind to parse properly.
	$first = fgets( $h );
	if ( $first === false ) { return $rows; }
	rewind( $h );
	$delim = yscsv_detect_delimiter( ltrim( $first, "\xEF\xBB\xBF" ) );

	$header = fgetcsv( $h, 0, $delim );
	if ( ! $header ) { return $rows; }
	$header = array_map( 'yscsv_clean_header', $header );
	$header = array_map( 'yscsv_canonical_header', $header );

	while ( ( $data = fgetcsv( $h, 0, $delim ) ) !== false ) {
		// Skip fully blank lines (null-safe: fgetcsv returns array(null) for an empty line).
		$has_value = false;
		foreach ( $data as $cell ) {
			if ( $cell !== null && $cell !== '' ) { $has_value = true; break; }
		}
		if ( ! $has_value ) { continue; }
		$row = array();
		foreach ( $header as $i => $col ) {
			$row[ $col ] = isset( $data[ $i ] ) ? $data[ $i ] : '';
		}
		$rows[] = $row;
	}
	return $rows;
}

/** Read a bundled CSV next to this plugin file, if present. Returns rows or WP_Error. */
function yscsv_read_bundled_csv() {
	// Accept either the canonical name or any single .csv dropped in the folder.
	$dir  = plugin_dir_path( __FILE__ );
	$file = $dir . 'yoast-import.csv';
	if ( ! file_exists( $file ) ) {
		$found = glob( $dir . '*.csv' );
		$file  = ( $found && isset( $found[0] ) ) ? $found[0] : '';
	}
	if ( ! $file || ! file_exists( $file ) ) {
		return new WP_Error( 'nofile', 'No CSV uploaded and no .csv file found in the plugin folder.' );
	}
	$h = fopen( $file, 'r' );
	if ( $h === false ) { return new WP_Error( 'noopen', 'Could not open the bundled CSV file.' ); }
	$rows = yscsv_parse_handle( $h );
	fclose( $h );
	return $rows;
}

/**
 * Refresh Yoast's cached "indexable" for a post.
 *
 * Yoast keeps a cached copy of each page's SEO data in its own indexables table, and the
 * front end reads from there. Writing post meta directly (as this importer does) leaves that
 * cache stale - which is why, normally, you'd have to open each page and click Update to see
 * the change. This rebuilds the cache for the post so the new values show up right away.
 */
function yscsv_refresh_indexable( $post_id ) {
	$post_id = (int) $post_id;
	if ( ! $post_id ) { return; }

	// Preferred: have Yoast rebuild the indexable from the fresh post meta.
	if ( function_exists( 'YoastSEO' ) ) {
		try {
			$builder = YoastSEO()->classes->get( \Yoast\WP\SEO\Builders\Indexable_Builder::class );
			if ( is_object( $builder ) && method_exists( $builder, 'build_for_id_and_type' ) ) {
				$builder->build_for_id_and_type( $post_id, 'post' );
				return;
			}
		} catch ( \Throwable $e ) {
			// Fall through to the delete-based refresh below.
		}
	}

	// Fallback: delete the cached row so Yoast rebuilds it on the next request.
	global $wpdb;
	$table = $wpdb->prefix . 'yoast_indexable';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
		$wpdb->delete( $table, array( 'object_id' => $post_id, 'object_type' => 'post' ), array( '%d', '%s' ) );
	}
}

/** Apply one row to a post. $dry = true means preview only. Returns status array. */
function yscsv_apply_row( $row, $dry ) {
	$url = isset( $row['URL'] ) ? trim( $row['URL'] ) : '';
	if ( $url === '' ) { return array( 'url' => '(blank)', 'id' => 0, 'status' => 'skipped: no URL' ); }

	$post_id = yscsv_resolve_post_id( $url );
	if ( ! $post_id ) {
		return array( 'url' => $url, 'id' => 0, 'status' => 'NOT FOUND - check the URL/slug' );
	}

	$changes = array();
	foreach ( yscsv_map() as $col => $meta_key ) {
		if ( ! array_key_exists( $col, $row ) ) { continue; }
		$value = trim( $row[ $col ] );

		if ( $meta_key === '__excerpt' ) {
			if ( $value !== '' ) {
				$changes[] = 'excerpt';
				if ( ! $dry ) { wp_update_post( array( 'ID' => $post_id, 'post_excerpt' => $value ) ); }
			}
			continue;
		}

		if ( $meta_key === '__cornerstone' ) {
			$is = ( strtolower( $value ) === 'yes' || $value === '1' );
			$changes[] = 'cornerstone=' . ( $is ? '1' : '0' );
			if ( ! $dry ) {
				if ( $is ) { update_post_meta( $post_id, '_yoast_wpseo_is_cornerstone', '1' ); }
				else { delete_post_meta( $post_id, '_yoast_wpseo_is_cornerstone' ); }
			}
			continue;
		}

		// Plain meta. Skip empty so we never blank an existing good value.
		if ( $value === '' ) { continue; }
		$changes[] = $col;
		if ( ! $dry ) { update_post_meta( $post_id, $meta_key, $value ); }
	}

	// Real run: refresh Yoast's cached SEO copy so the new values show on the front end
	// immediately, without having to open and re-save each page by hand.
	if ( ! $dry && $changes ) {
		yscsv_refresh_indexable( $post_id );
	}

	$status = ( $dry ? 'WILL UPDATE: ' : 'UPDATED: ' ) . implode( ', ', $changes );
	return array( 'url' => $url, 'id' => $post_id, 'status' => $status );
}

/** admin-post handler: stream a sample CSV download with the exact column pattern. */
function yscsv_download_sample() {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Insufficient permissions.' ); }
	check_admin_referer( 'yscsv_sample' );

	$cols = yscsv_columns();
	$samples = array(
		array(
			'URL'                 => 'https://example.com/',
			'Focus Keyphrase'     => 'your focus keyphrase',
			'SEO Title'           => 'Home | Example Company',
			'Meta Description'    => 'A concise 150-160 character meta description for your home page.',
			'Cornerstone'         => 'Yes',
			'Breadcrumb Title'    => 'Home',
			'Canonical URL'       => 'https://example.com/',
			'Page Type'           => 'WebPage',
			'Article Type'        => 'None',
			'Social Title'        => 'Home | Example Company',
			'Social Description'  => 'Open Graph description shown when shared on Facebook or LinkedIn.',
			'Twitter Title'       => 'Home | Example',
			'Twitter Description' => 'Twitter card description, kept short.',
			'Excerpt'             => 'Short excerpt summarising the home page.',
		),
		array(
			'URL'                 => 'https://example.com/about/',
			'Focus Keyphrase'     => 'about example company',
			'SEO Title'           => 'About | Example Company',
			'Meta Description'    => 'Learn about Example Company, what we do and who we serve.',
			'Cornerstone'         => 'No',
			'Breadcrumb Title'    => 'About Us',
			'Canonical URL'       => 'https://example.com/about/',
			'Page Type'           => 'AboutPage',
			'Article Type'        => 'None',
			'Social Title'        => 'About | Example Company',
			'Social Description'  => 'Social description for the about page.',
			'Twitter Title'       => 'About Example',
			'Twitter Description' => 'Twitter description for the about page.',
			'Excerpt'             => 'Short excerpt for the about page.',
		),
	);

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="yoast-import-sample.csv"' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, $cols );
	foreach ( $samples as $s ) {
		$line = array();
		foreach ( $cols as $c ) { $line[] = isset( $s[ $c ] ) ? $s[ $c ] : ''; }
		fputcsv( $out, $line );
	}
	fclose( $out );
	exit;
}
add_action( 'admin_post_yscsv_sample', 'yscsv_download_sample' );

/** Admin page: CSV importer with preview, per-row exclude, then apply. */
function yscsv_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }

	$mode          = '';      // '' | 'preview' | 'applied'
	$results       = array(); // rows shown in the table below
	$skipped_count = 0;       // CSV rows that were not applied (excluded or unmatched)

	// ---------- 2) APPLY the rows the user kept ticked ----------
	if ( isset( $_POST['yscsv_apply_selected'] ) && check_admin_referer( 'yscsv_apply' ) ) {
		$cached = get_transient( 'yscsv_rows' );
		if ( ! is_array( $cached ) || ! $cached ) {
			echo '<div class="notice notice-error"><p>Your preview has expired. Please upload your CSV and run Preview again.</p></div>';
		} else {
			$selected = ( isset( $_POST['apply_rows'] ) && is_array( $_POST['apply_rows'] ) )
				? array_flip( array_map( 'intval', $_POST['apply_rows'] ) )
				: array();
			foreach ( $cached as $i => $row ) {
				if ( ! isset( $selected[ $i ] ) ) { continue; } // unticked = excluded.
				$results[] = yscsv_apply_row( $row, false );
			}
			$skipped_count = count( $cached ) - count( $results );
			$mode          = 'applied';
		}
	}

	// ---------- 1) PREVIEW (dry run) ----------
	if ( $mode === '' && isset( $_POST['yscsv_preview'] ) && check_admin_referer( 'yscsv_run' ) ) {
		$rows = null;

		// Prefer a freshly uploaded file.
		if ( isset( $_FILES['yscsv_csv'] ) && ! empty( $_FILES['yscsv_csv']['tmp_name'] ) && is_uploaded_file( $_FILES['yscsv_csv']['tmp_name'] ) ) {
			$h = fopen( $_FILES['yscsv_csv']['tmp_name'], 'r' );
			if ( $h !== false ) {
				$rows = yscsv_parse_handle( $h );
				fclose( $h );
			} else {
				echo '<div class="notice notice-error"><p>Could not read the uploaded CSV.</p></div>';
			}
		}

		// Else fall back to a .csv bundled in the plugin folder.
		if ( $rows === null ) {
			$rows = yscsv_read_bundled_csv();
		}

		if ( is_wp_error( $rows ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $rows->get_error_message() ) . '</p></div>';
		} elseif ( ! $rows ) {
			echo '<div class="notice notice-error"><p>The CSV had no data rows.</p></div>';
		} else {
			// Re-index so row numbers stay stable between Preview and Apply, then cache.
			$rows = array_values( $rows );
			set_transient( 'yscsv_rows', $rows, HOUR_IN_SECONDS );
			foreach ( $rows as $row ) { $results[] = yscsv_apply_row( $row, true ); }
			$mode = 'preview';
		}
	}

	$sample_url = wp_nonce_url( admin_url( 'admin-post.php?action=yscsv_sample' ), 'yscsv_sample' );
	?>
	<div class="wrap">
		<h1>Yoast SEO CSV Importer</h1>
		<p>Reads a CSV and writes Yoast SEO meta for each matched page, matched automatically by URL.
		   <strong>Run Preview first</strong>, then untick any page you want to skip before applying.
		   Empty CSV cells are skipped (existing values are never blanked). Requires the Yoast SEO plugin to be active.</p>

		<p>
			<a class="button" href="<?php echo esc_url( $sample_url ); ?>">&#8681; Download sample CSV</a>
			<span class="description">&nbsp;Use this exact column layout when generating your data.</span>
		</p>

		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'yscsv_run' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="yscsv_csv">CSV file</label></th>
					<td>
						<input type="file" id="yscsv_csv" name="yscsv_csv" accept=".csv,text/csv">
						<p class="description">Optional. If left empty, a <code>.csv</code> in the plugin folder is used.</p>
					</td>
				</tr>
			</table>
			<p>
				<button type="submit" name="yscsv_preview" value="1" class="button button-primary">Preview (dry run)</button>
			</p>
		</form>

		<?php if ( $mode === 'preview' ) : ?>
			<form method="post">
				<?php wp_nonce_field( 'yscsv_apply' ); ?>
				<h2>Preview &mdash; <?php echo count( $results ); ?> rows</h2>
				<p>Untick any page you want to <strong>exclude</strong>, then apply.
				   Rows marked <em>NOT FOUND</em> can&rsquo;t be applied.</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" id="yscsv-toggle-all" checked title="Select all"></td>
							<th>URL</th><th>Post ID</th><th>Result</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $results as $i => $r ) :
						$can_apply = ( (int) $r['id'] > 0 ); ?>
						<tr<?php echo $can_apply ? '' : ' style="background:#fcebea;"'; ?>>
							<th scope="row" class="check-column">
								<?php if ( $can_apply ) : ?>
									<input type="checkbox" class="yscsv-row" name="apply_rows[]" value="<?php echo (int) $i; ?>" checked>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</th>
							<td><?php echo esc_html( $r['url'] ); ?></td>
							<td><?php echo (int) $r['id']; ?></td>
							<td><?php echo esc_html( $r['status'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<button type="submit" name="yscsv_apply_selected" value="1" class="button button-primary"
						onclick="return confirm('Apply changes to the ticked pages now?');">Apply changes to selected pages</button>
				</p>
			</form>
			<script>
			(function(){
				var all = document.getElementById('yscsv-toggle-all');
				if ( ! all ) { return; }
				all.addEventListener('change', function(){
					var boxes = document.querySelectorAll('.yscsv-row');
					for ( var i = 0; i < boxes.length; i++ ) { boxes[i].checked = all.checked; }
				});
			})();
			</script>

		<?php elseif ( $mode === 'applied' ) : ?>
			<h2>Applied &mdash; <?php echo count( $results ); ?> page(s) updated<?php
				if ( $skipped_count > 0 ) { echo ', ' . (int) $skipped_count . ' not applied'; } ?></h2>
			<table class="widefat striped">
				<thead><tr><th>URL</th><th>Post ID</th><th>Result</th></tr></thead>
				<tbody>
				<?php foreach ( $results as $r ) : ?>
					<tr<?php echo ( strpos( $r['status'], 'NOT FOUND' ) === 0 ) ? ' style="background:#fcebea;"' : ''; ?>>
						<td><?php echo esc_html( $r['url'] ); ?></td>
						<td><?php echo (int) $r['id']; ?></td>
						<td><?php echo esc_html( $r['status'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><strong>Done.</strong> Yoast&rsquo;s cached SEO data was refreshed automatically &mdash; you do
			   <em>not</em> need to open and re-save each page. If you use a caching/CDN plugin, clear its cache
			   so visitors see the new tags.</p>
		<?php endif; ?>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * SEO STATUS DASHBOARD  +  FEATURED IMAGE PICKER
 * ---------------------------------------------------------------------- */

/** Is Yoast SEO active? */
function yscsv_yoast_active() {
	return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
}

/** Is Yoast search appearance turned OFF (noindex) for this post type? */
function yscsv_posttype_seo_off( $post_type ) {
	$titles = get_option( 'wpseo_titles' );
	if ( ! is_array( $titles ) ) { return false; }
	return ! empty( $titles[ 'noindex-' . $post_type ] );
}

/** Post types we audit: public, has a UI, not attachments, and SEO not switched off. */
function yscsv_audited_post_types() {
	$types = get_post_types( array( 'public' => true ), 'objects' );
	$out   = array();
	foreach ( $types as $pt ) {
		if ( $pt->name === 'attachment' ) { continue; }
		if ( yscsv_posttype_seo_off( $pt->name ) ) { continue; } // SEO off in Yoast -> skip.
		$out[] = $pt;
	}
	return $out;
}

/** Decide if a post's core SEO is complete. Returns array( bool $done, array $missing ). */
function yscsv_seo_state( $post_id ) {
	$checks = array(
		'Focus Keyphrase'  => '_yoast_wpseo_focuskw',
		'SEO Title'        => '_yoast_wpseo_title',
		'Meta Description' => '_yoast_wpseo_metadesc',
	);
	$missing = array();
	foreach ( $checks as $label => $key ) {
		if ( trim( (string) get_post_meta( $post_id, $key, true ) ) === '' ) { $missing[] = $label; }
	}
	return array( 'done' => empty( $missing ), 'missing' => $missing );
}

/** Render a featured-image cell (current thumb + pick/change button). */
function yscsv_featured_cell( $post_id ) {
	$thumb = get_the_post_thumbnail_url( $post_id, 'thumbnail' );
	ob_start();
	if ( $thumb ) {
		echo '<img class="yscsv-thumb" src="' . esc_url( $thumb ) . '" style="max-width:60px;height:auto;display:block;margin-bottom:4px;">';
	}
	$label = $thumb ? 'Change featured image' : 'Set featured image';
	echo '<button type="button" class="button button-small yscsv-set-featured" data-post="' . (int) $post_id . '">' . esc_html( $label ) . '</button>';
	return ob_get_clean();
}

/** Apply one image to a post in all 3 featured-image places. */
function yscsv_apply_featured_image( $post_id, $att_id, $url ) {
	// 1) Website page/post featured image (the WordPress post thumbnail).
	set_post_thumbnail( $post_id, $att_id );
	// 2) Social media featured image (Yoast Open Graph - Facebook/LinkedIn).
	update_post_meta( $post_id, '_yoast_wpseo_opengraph-image', $url );
	update_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', $att_id );
	// 3) X (Twitter) featured image.
	update_post_meta( $post_id, '_yoast_wpseo_twitter-image', $url );
	update_post_meta( $post_id, '_yoast_wpseo_twitter-image-id', $att_id );
}

/** AJAX: set the featured image for ONE post. */
function yscsv_ajax_set_featured() {
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'No permission.' ); }
	check_ajax_referer( 'yscsv_featured', 'nonce' );

	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
	$att_id  = isset( $_POST['att_id'] ) ? (int) $_POST['att_id'] : 0;
	if ( ! $post_id || ! $att_id ) { wp_send_json_error( 'Missing post or image.' ); }

	$url = wp_get_attachment_url( $att_id );
	if ( ! $url ) { wp_send_json_error( 'Image not found.' ); }

	yscsv_apply_featured_image( $post_id, $att_id, $url );

	wp_send_json_success( array(
		'thumb' => wp_get_attachment_image_url( $att_id, 'thumbnail' ),
		'url'   => $url,
	) );
}
add_action( 'wp_ajax_yscsv_set_featured', 'yscsv_ajax_set_featured' );

/** AJAX: set the SAME featured image for MANY posts at once. */
function yscsv_ajax_set_featured_bulk() {
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'No permission.' ); }
	check_ajax_referer( 'yscsv_featured', 'nonce' );

	$att_id = isset( $_POST['att_id'] ) ? (int) $_POST['att_id'] : 0;
	$ids    = ( isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] ) ) ? array_map( 'intval', $_POST['post_ids'] ) : array();
	$ids    = array_values( array_filter( array_unique( $ids ) ) );
	if ( ! $att_id || ! $ids ) { wp_send_json_error( 'Select at least one item and an image.' ); }

	$url = wp_get_attachment_url( $att_id );
	if ( ! $url ) { wp_send_json_error( 'Image not found.' ); }

	$done = array();
	foreach ( $ids as $pid ) {
		yscsv_apply_featured_image( $pid, $att_id, $url );
		$done[] = $pid;
	}

	wp_send_json_success( array(
		'ids'   => $done,
		'thumb' => wp_get_attachment_image_url( $att_id, 'thumbnail' ),
		'url'   => $url,
	) );
}
add_action( 'wp_ajax_yscsv_set_featured_bulk', 'yscsv_ajax_set_featured_bulk' );

/** The SEO Status admin page. */
function yscsv_status_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	?>
	<div class="wrap">
		<h1>Yoast SEO Status</h1>
		<?php if ( ! yscsv_yoast_active() ) : ?>
			<div class="notice notice-warning"><p>Yoast SEO does not appear to be active. Activate it to see accurate status.</p></div>
		<?php endif; ?>
		<p>Lists published content split into <strong>SEO completed</strong> and <strong>not completed</strong>
		   (based on Focus Keyphrase, SEO Title and Meta Description). Post types with Yoast search appearance
		   switched <em>off</em> are not shown. To add featured images, use the <strong>Featured Images</strong> page.</p>

		<?php
		$types = yscsv_audited_post_types();
		if ( ! $types ) {
			echo '<p>No applicable post types found.</p></div>';
			return;
		}

		$completed     = array();
		$not_completed = array();

		foreach ( $types as $pt ) {
			$q = new WP_Query( array(
				'post_type'      => $pt->name,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			) );
			foreach ( $q->posts as $p ) {
				$state = yscsv_seo_state( $p->ID );
				$entry = array( 'post' => $p, 'label' => $pt->labels->singular_name, 'missing' => $state['missing'] );
				if ( $state['done'] ) { $completed[] = $entry; }
				else { $not_completed[] = $entry; }
			}
			wp_reset_postdata();
		}
		?>

		<h2 style="margin-top:24px;">&#10003; SEO Completed &mdash; <?php echo count( $completed ); ?></h2>
		<?php yscsv_status_table( $completed, false ); ?>

		<h2 style="margin-top:24px;">&#9888; Not Completed &mdash; <?php echo count( $not_completed ); ?></h2>
		<?php yscsv_status_table( $not_completed, true ); ?>
	</div>
	<?php
}

/** Render one status table. $show_missing adds a "Missing" column. */
function yscsv_status_table( $rows, $show_missing ) {
	if ( ! $rows ) { echo '<p><em>None.</em></p>'; return; }
	?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th>Type</th>
				<th>Title</th>
				<?php if ( $show_missing ) : ?><th>Missing</th><?php endif; ?>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $rows as $r ) :
			$p = $r['post'];
			$edit = get_edit_post_link( $p->ID );
			$view = get_permalink( $p->ID );
			?>
			<tr>
				<td><?php echo esc_html( $r['label'] ); ?></td>
				<td>
					<strong><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( get_the_title( $p ) ?: '(no title)' ); ?></a></strong>
					<div><a href="<?php echo esc_url( $view ); ?>" target="_blank" rel="noopener" style="font-size:12px;"><?php echo esc_html( $view ); ?></a></div>
				</td>
				<?php if ( $show_missing ) : ?>
					<td><?php echo esc_html( implode( ', ', $r['missing'] ) ); ?></td>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/* -------------------------------------------------------------------------
 * FEATURED IMAGES PAGE
 * ---------------------------------------------------------------------- */

/** Dedicated page: pick a featured image for any page/post via the media library. */
function yscsv_featured_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	?>
	<div class="wrap">
		<h1>Featured Images</h1>
		<p>Pick an <strong>already-uploaded</strong> image from the WordPress media library for any page or post.
		   One click sets it in all three places at once: the <strong>page/post featured image</strong>,
		   the <strong>social (Facebook/LinkedIn) image</strong>, and the <strong>X (Twitter) image</strong>.</p>
		<p><strong>Set many at once:</strong> tick the boxes for the items you want, then click
		   &ldquo;Set featured image for selected&rdquo; and choose one image &ndash; it is applied to every ticked item.</p>

		<p style="position:sticky;top:32px;z-index:10;background:#f0f0f1;padding:10px 0;">
			<button type="button" class="button button-primary yscsv-bulk-set" disabled>
				Set featured image for selected (<span class="yscsv-count">0</span>)
			</button>
		</p>

		<?php
		$types = yscsv_audited_post_types();
		if ( ! $types ) { echo '<p>No applicable post types found.</p></div>'; return; }

		foreach ( $types as $pt ) :
			$q = new WP_Query( array(
				'post_type'      => $pt->name,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			) );
			if ( ! $q->posts ) { wp_reset_postdata(); continue; }
			?>
			<h2 style="margin-top:24px;"><?php echo esc_html( $pt->labels->name ); ?> &mdash; <?php echo count( $q->posts ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<td class="check-column"><input type="checkbox" class="yscsv-check-all" title="Select all"></td>
					<th>Title</th>
					<th style="width:220px;">Featured image</th>
				</tr></thead>
				<tbody>
				<?php foreach ( $q->posts as $p ) : ?>
					<tr>
						<th scope="row" class="check-column"><input type="checkbox" class="yscsv-check" value="<?php echo (int) $p->ID; ?>"></th>
						<td>
							<strong><a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>"><?php echo esc_html( get_the_title( $p ) ?: '(no title)' ); ?></a></strong>
						</td>
						<td><?php echo yscsv_featured_cell( $p->ID ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			wp_reset_postdata();
		endforeach;
		?>
	</div>

	<script>
	jQuery(function($){
		var nonce = <?php echo wp_json_encode( wp_create_nonce( 'yscsv_featured' ) ); ?>;

		// Paint a row's featured-image cell with the chosen thumbnail.
		function paintRow(postId, src){
			var btn = $('.yscsv-set-featured[data-post="'+postId+'"]');
			var cell = btn.closest('td');
			cell.find('img.yscsv-thumb').remove();
			btn.before('<img class="yscsv-thumb" src="'+src+'" style="max-width:60px;height:auto;display:block;margin-bottom:4px;">');
			btn.text('Change featured image');
		}

		// ---- Single row ----
		$('.yscsv-set-featured').on('click', function(e){
			e.preventDefault();
			var btn = $(this), postId = btn.data('post');
			var frame = wp.media({ title:'Select a featured image', button:{text:'Use this image'}, library:{type:'image'}, multiple:false });
			frame.on('select', function(){
				var att = frame.state().get('selection').first().toJSON();
				btn.prop('disabled', true).text('Saving...');
				$.post(ajaxurl, { action:'yscsv_set_featured', nonce:nonce, post_id:postId, att_id:att.id }, function(resp){
					btn.prop('disabled', false);
					if (resp && resp.success) { paintRow(postId, resp.data.thumb || resp.data.url); }
					else { alert('Failed: ' + (resp && resp.data ? resp.data : 'unknown error')); btn.text('Set featured image'); }
				});
			});
			frame.open();
		});

		// ---- Selection helpers ----
		function selected(){ return $('.yscsv-check:checked').map(function(){ return $(this).val(); }).get(); }
		function refresh(){ var n = selected().length; $('.yscsv-count').text(n); $('.yscsv-bulk-set').prop('disabled', n === 0); }

		$('.yscsv-check-all').on('change', function(){
			$(this).closest('table').find('.yscsv-check').prop('checked', this.checked);
			refresh();
		});
		$(document).on('change', '.yscsv-check', refresh);

		// ---- Bulk: one image -> many posts ----
		$('.yscsv-bulk-set').on('click', function(e){
			e.preventDefault();
			var ids = selected();
			if (!ids.length) { return; }
			var btn = $(this), orig = btn.html();
			var frame = wp.media({ title:'Select a featured image for '+ids.length+' item(s)', button:{text:'Use this image'}, library:{type:'image'}, multiple:false });
			frame.on('select', function(){
				var att = frame.state().get('selection').first().toJSON();
				btn.prop('disabled', true).html('Saving '+ids.length+' item(s)...');
				$.post(ajaxurl, { action:'yscsv_set_featured_bulk', nonce:nonce, att_id:att.id, post_ids:ids }, function(resp){
					btn.html(orig);
					if (resp && resp.success) {
						var src = resp.data.thumb || resp.data.url;
						$.each(resp.data.ids, function(i, id){ paintRow(id, src); });
						$('.yscsv-check, .yscsv-check-all').prop('checked', false);
					} else {
						alert('Failed: ' + (resp && resp.data ? resp.data : 'unknown error'));
					}
					refresh();
				});
			});
			frame.open();
		});
	});
	</script>
	<?php
}

add_action( 'admin_menu', function () {
	$cap  = 'manage_options';
	$slug = 'yoast-csv-importer';

	// Top-level menu for the plugin (its own separate page in the admin sidebar).
	add_menu_page( 'Yoast SEO Tools', 'Yoast SEO Tools', $cap, $slug, 'yscsv_admin_page', 'dashicons-search', 81 );

	// Sub-pages.
	add_submenu_page( $slug, 'CSV Importer', 'CSV Importer', $cap, $slug, 'yscsv_admin_page' );
	add_submenu_page( $slug, 'SEO Status', 'SEO Status', $cap, 'yoast-csv-status', 'yscsv_status_page' );
	$GLOBALS['yscsv_featured_hook'] = add_submenu_page( $slug, 'Featured Images', 'Featured Images', $cap, 'yoast-csv-featured', 'yscsv_featured_page' );
} );

/** Load the WordPress media library scripts only on the Featured Images page. */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( isset( $GLOBALS['yscsv_featured_hook'] ) && $hook === $GLOBALS['yscsv_featured_hook'] ) {
		wp_enqueue_media();
	}
} );
