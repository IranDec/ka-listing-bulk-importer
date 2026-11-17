<?php
/**
 * CSV import steps and the shared preview / results screens.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ka_step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'upload'; // phpcs:ignore WordPress.Security.NonceVerification
$ka_why  = array(
	'google'  => __( 'same Google place', 'ka-listing-bulk-importer' ),
	'title'   => __( 'same name', 'ka-listing-bulk-importer' ),
	'phone'   => __( 'same phone', 'ka-listing-bulk-importer' ),
	'website' => __( 'same website', 'ka-listing-bulk-importer' ),
);
$ka_steps = array(
	'upload'  => __( 'Upload', 'ka-listing-bulk-importer' ),
	'map'     => __( 'Columns', 'ka-listing-bulk-importer' ),
	'preview' => __( 'Review', 'ka-listing-bulk-importer' ),
	'done'    => __( 'Done', 'ka-listing-bulk-importer' ),
);
$ka_batch = KA_LBI_Batch::get();
$ka_is_csv = ! $ka_batch || 'csv' === $ka_batch['meta']['source'];
?>
<?php if ( 'preview' !== $ka_step || $ka_is_csv ) : ?>
	<ol class="ka-lbi-stepper">
		<?php
		$ka_passed = true;
		foreach ( $ka_steps as $ka_k => $ka_label ) {
			$ka_cls = $ka_k === $ka_step ? 'is-current' : ( $ka_passed ? 'is-done' : '' );
			if ( $ka_k === $ka_step ) {
				$ka_passed = false;
			}
			echo '<li class="' . esc_attr( $ka_cls ) . '"><span>' . esc_html( $ka_label ) . '</span></li>';
		}
		?>
	</ol>
<?php endif; ?>

<?php
/* ------------------------------------------------------------------ upload */
if ( 'upload' === $ka_step ) :
	?>
	<div class="ka-lbi-grid">
		<div class="ka-lbi-card">
			<div class="ka-lbi-card-h"><h2><span class="dashicons dashicons-upload"></span><?php esc_html_e( 'Upload a CSV file', 'ka-listing-bulk-importer' ); ?></h2></div>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ka-lbi-form">
				<?php wp_nonce_field( KA_LBI_Plugin::NONCE ); ?>
				<input type="hidden" name="action" value="ka_lbi_upload">
				<label class="ka-lbi-drop">
					<input type="file" name="ka_lbi_file" accept=".csv,text/csv" required>
					<span class="dashicons dashicons-media-spreadsheet"></span>
					<strong><?php esc_html_e( 'Choose a CSV file or drop it here', 'ka-listing-bulk-importer' ); ?></strong>
					<em><?php esc_html_e( 'UTF-8, comma or semicolon separated, up to 2,000 rows / 5 MB', 'ka-listing-bulk-importer' ); ?></em>
				</label>
				<div class="ka-lbi-cols">
					<div>
						<label class="ka-lbi-label" for="ka-lbi-st"><?php esc_html_e( 'Status of new listings', 'ka-listing-bulk-importer' ); ?></label>
						<select name="ka_lbi_status" id="ka-lbi-st">
							<option value="pending"><?php esc_html_e( 'Pending review (recommended)', 'ka-listing-bulk-importer' ); ?></option>
							<option value="draft"><?php esc_html_e( 'Draft', 'ka-listing-bulk-importer' ); ?></option>
							<?php if ( current_user_can( 'publish_posts' ) ) : ?><option value="publish"><?php esc_html_e( 'Published', 'ka-listing-bulk-importer' ); ?></option><?php endif; ?>
						</select>
					</div>
					<?php if ( count( KA_LBI_Lang::languages() ) > 1 ) : ?>
						<div>
							<label class="ka-lbi-label" for="ka-lbi-ka_lbi_lang"><?php esc_html_e( 'Language of the file', 'ka-listing-bulk-importer' ); ?></label>
							<?php KA_LBI_Admin::language_select( 'ka_lbi_lang', KA_LBI_Lang::import_language() ); ?>
						</div>
					<?php endif; ?>
				</div>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Upload and continue', 'ka-listing-bulk-importer' ); ?></button></p>
			</form>
		</div>
		<div class="ka-lbi-card">
			<div class="ka-lbi-card-h"><h2><span class="dashicons dashicons-lightbulb"></span><?php esc_html_e( 'Good to know', 'ka-listing-bulk-importer' ); ?></h2></div>
			<ul class="ka-lbi-list">
				<li><?php esc_html_e( 'Nothing is written before the review step - you see every row, possible duplicates and problems first.', 'ka-listing-bulk-importer' ); ?></li>
				<li><?php esc_html_e( 'Only a title is required. Columns are matched automatically by name (English, German or Persian headers).', 'ka-listing-bulk-importer' ); ?></li>
				<li><?php esc_html_e( 'Categories and cities can be names or slugs in any language; they are mapped to the import language.', 'ka-listing-bulk-importer' ); ?></li>
				<li>
					<?php
					/* translators: %s: folder path */
					echo wp_kses( sprintf( __( 'Photos: a web address, or a file name of an image you uploaded to %s.', 'ka-listing-bulk-importer' ), '<code>' . esc_html( KA_LBI_Media::photo_dir() ) . '</code>' ), array( 'code' => array() ) );
					?>
				</li>
			</ul>
			<p><a class="button" href="<?php echo esc_url( KA_LBI_Plugin::action_url( 'ka_lbi_template' ) ); ?>"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Download the CSV template', 'ka-listing-bulk-importer' ); ?></a></p>
		</div>
	</div>
	<?php
/* ------------------------------------------------------------------ map */
elseif ( 'map' === $ka_step ) :
	$ka_csv = KA_LBI_Csv::uploaded();
	if ( ! $ka_csv ) {
		echo '<div class="ka-lbi-card"><p>' . esc_html__( 'The uploaded file has expired. Please upload it again.', 'ka-listing-bulk-importer' ) . '</p><p><a class="button" href="' . esc_url( KA_LBI_Plugin::url( 'ka-lbi-import' ) ) . '">' . esc_html__( 'Back', 'ka-listing-bulk-importer' ) . '</a></p></div>';
		return;
	}
	$ka_fields = KA_LBI_Store::importable_fields();
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ka-lbi-card">
		<?php wp_nonce_field( KA_LBI_Plugin::NONCE ); ?>
		<input type="hidden" name="action" value="ka_lbi_map">
		<div class="ka-lbi-card-h">
			<h2><span class="dashicons dashicons-randomize"></span><?php echo esc_html( $ka_csv['name'] ); ?></h2>
			<span class="ka-lbi-muted"><?php
				/* translators: 1: rows, 2: columns */
				echo esc_html( sprintf( __( '%1$d rows, %2$d columns', 'ka-listing-bulk-importer' ), count( $ka_csv['rows'] ), count( $ka_csv['headers'] ) ) );
			?></span>
		</div>
		<table class="ka-lbi-table ka-lbi-map">
			<thead><tr><th><?php esc_html_e( 'Column in the file', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Examples', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Becomes', 'ka-listing-bulk-importer' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $ka_csv['headers'] as $ka_h ) :
				$ka_guess   = KA_LBI_Csv::guess( $ka_h );
				$ka_samples = array_filter( array_map( function ( $r ) use ( $ka_h ) {
					return isset( $r[ $ka_h ] ) ? $r[ $ka_h ] : '';
				}, array_slice( $ka_csv['rows'], 0, 3 ) ) );
				?>
				<tr class="<?php echo $ka_guess ? 'is-mapped' : ''; ?>">
					<td><strong><?php echo esc_html( $ka_h ); ?></strong></td>
					<td class="ka-lbi-samples"><?php foreach ( $ka_samples as $ka_s ) { echo '<span>' . esc_html( mb_strimwidth( $ka_s, 0, 48, '…' ) ) . '</span>'; } ?></td>
					<td>
						<select name="ka_lbi_map[<?php echo esc_attr( $ka_h ); ?>]" data-ka-lbi-map>
							<option value=""><?php esc_html_e( '— ignore —', 'ka-listing-bulk-importer' ); ?></option>
							<?php foreach ( $ka_fields as $ka_key => $ka_def ) : ?>
								<option value="<?php echo esc_attr( $ka_key ); ?>" <?php selected( $ka_guess, $ka_key ); ?>><?php echo esc_html( $ka_def[0] . ( ! empty( $ka_def[2] ) ? ' *' : '' ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<div class="ka-lbi-cols">
			<div>
				<label class="ka-lbi-label" for="ka-lbi-dl"><?php esc_html_e( 'City for rows without one (optional)', 'ka-listing-bulk-importer' ); ?></label>
				<input type="text" id="ka-lbi-dl" name="ka_lbi_default_location" list="ka-lbi-locs" class="regular-text">
				<datalist id="ka-lbi-locs"><?php foreach ( KA_LBI_Lang::terms( KA_LBI_Plugin::TAX_LOCATION, $ka_csv['lang'] ) as $ka_t ) { echo '<option value="' . esc_attr( $ka_t->name ) . '">'; } ?></datalist>
			</div>
			<div>
				<label class="ka-lbi-label" for="ka-lbi-dc"><?php esc_html_e( 'Category for rows without one (optional)', 'ka-listing-bulk-importer' ); ?></label>
				<select id="ka-lbi-dc" name="ka_lbi_default_category"><option value=""></option><?php foreach ( KA_LBI_Lang::terms( KA_LBI_Plugin::TAX_CATEGORY, $ka_csv['lang'] ) as $ka_t ) { echo '<option value="' . esc_attr( $ka_t->slug ) . '">' . esc_html( html_entity_decode( $ka_t->name, ENT_QUOTES, 'UTF-8' ) ) . '</option>'; } ?></select>
			</div>
		</div>
		<p class="ka-lbi-muted ka-lbi-small"><?php esc_html_e( '* A title column is required.', 'ka-listing-bulk-importer' ); ?></p>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Continue to the review', 'ka-listing-bulk-importer' ); ?></button>
			<a class="button" href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-import' ) ); ?>"><?php esc_html_e( 'Start over', 'ka-listing-bulk-importer' ); ?></a></p>
	</form>
	<?php
/* ------------------------------------------------------------------ preview */
elseif ( 'preview' === $ka_step ) :
	if ( ! $ka_batch ) {
		echo '<div class="ka-lbi-card"><p>' . esc_html__( 'Nothing to review - the preview has expired or was imported already.', 'ka-listing-bulk-importer' ) . '</p></div>';
		return;
	}
	$ka_meta  = $ka_batch['meta'];
	$ka_count = array( 'new' => 0, 'dup' => 0, 'err' => 0 );
	foreach ( $ka_batch['records'] as $ka_r ) {
		$ka_count[ $ka_r['errors'] ? 'err' : ( $ka_r['duplicates'] ? 'dup' : 'new' ) ]++;
	}
	$ka_cats = KA_LBI_Lang::terms( KA_LBI_Plugin::TAX_CATEGORY, $ka_meta['lang'] );
	?>
	<?php if ( $ka_meta['note'] ) : ?>
		<div class="ka-lbi-notice is-<?php echo $ka_meta['warn'] ? 'warning' : 'info'; ?>"><span class="dashicons dashicons-<?php echo $ka_meta['warn'] ? 'warning' : 'info'; ?>"></span><p><?php echo esc_html( $ka_meta['note'] ); ?></p></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ka-lbi-card ka-lbi-preview" data-ka-lbi-preview>
		<?php wp_nonce_field( KA_LBI_Plugin::NONCE ); ?>
		<input type="hidden" name="action" value="ka_lbi_import">
		<div class="ka-lbi-card-h">
			<div class="ka-lbi-filters" role="tablist">
				<button type="button" class="is-on" data-filter="all"><?php esc_html_e( 'All', 'ka-listing-bulk-importer' ); ?> <em><?php echo (int) count( $ka_batch['records'] ); ?></em></button>
				<button type="button" data-filter="new"><?php esc_html_e( 'New', 'ka-listing-bulk-importer' ); ?> <em><?php echo (int) $ka_count['new']; ?></em></button>
				<button type="button" data-filter="dup"><?php esc_html_e( 'Possibly existing', 'ka-listing-bulk-importer' ); ?> <em><?php echo (int) $ka_count['dup']; ?></em></button>
				<button type="button" data-filter="err"><?php esc_html_e( 'Problems', 'ka-listing-bulk-importer' ); ?> <em><?php echo (int) $ka_count['err']; ?></em></button>
			</div>
			<div class="ka-lbi-bulkset">
				<button type="button" class="button button-small" data-set="create" data-scope="new"><?php esc_html_e( 'Import all new', 'ka-listing-bulk-importer' ); ?></button>
				<button type="button" class="button button-small" data-set="skip" data-scope="all"><?php esc_html_e( 'Skip all', 'ka-listing-bulk-importer' ); ?></button>
				<a class="button button-small" href="<?php echo esc_url( KA_LBI_Plugin::action_url( 'ka_lbi_export_preview' ) ); ?>"><?php esc_html_e( 'Export CSV', 'ka-listing-bulk-importer' ); ?></a>
			</div>
		</div>
		<datalist id="ka-lbi-loc-list"><?php foreach ( KA_LBI_Lang::terms( KA_LBI_Plugin::TAX_LOCATION, $ka_meta['lang'] ) as $ka_t ) { echo '<option value="' . esc_attr( $ka_t->name ) . '">'; } ?></datalist>
		<div class="ka-lbi-rows">
			<?php foreach ( $ka_batch['records'] as $ka_r ) :
				$ka_f    = $ka_r['fields'];
				$ka_row  = (int) $ka_r['row'];
				$ka_kind = $ka_r['errors'] ? 'err' : ( $ka_r['duplicates'] ? 'dup' : 'new' );
				$ka_def  = KA_LBI_Batch::default_action( $ka_r, $ka_meta['mode'] );
				$ka_name = 'ka_lbi_edit[' . $ka_row . ']';
				?>
				<div class="ka-lbi-row is-<?php echo esc_attr( $ka_kind ); ?>" data-kind="<?php echo esc_attr( $ka_kind ); ?>">
					<div class="ka-lbi-row-main">
						<input type="text" class="ka-lbi-row-title" name="<?php echo esc_attr( $ka_name ); ?>[post_title]" value="<?php echo esc_attr( isset( $ka_f['post_title'] ) ? $ka_f['post_title'] : '' ); ?>" aria-label="<?php esc_attr_e( 'Title', 'ka-listing-bulk-importer' ); ?>">
						<div class="ka-lbi-row-grid">
							<label><span class="dashicons dashicons-location" title="<?php esc_attr_e( 'Address', 'ka-listing-bulk-importer' ); ?>"></span><input type="text" name="<?php echo esc_attr( $ka_name ); ?>[gAddress]" value="<?php echo esc_attr( isset( $ka_f['gAddress'] ) ? $ka_f['gAddress'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Address', 'ka-listing-bulk-importer' ); ?>"></label>
							<label><span class="dashicons dashicons-phone" title="<?php esc_attr_e( 'Phone', 'ka-listing-bulk-importer' ); ?>"></span><input type="text" name="<?php echo esc_attr( $ka_name ); ?>[phone]" value="<?php echo esc_attr( isset( $ka_f['phone'] ) ? $ka_f['phone'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Phone', 'ka-listing-bulk-importer' ); ?>"></label>
							<label><span class="dashicons dashicons-admin-site-alt3" title="<?php esc_attr_e( 'Website', 'ka-listing-bulk-importer' ); ?>"></span><input type="text" name="<?php echo esc_attr( $ka_name ); ?>[website]" value="<?php echo esc_attr( isset( $ka_f['website'] ) ? $ka_f['website'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Website', 'ka-listing-bulk-importer' ); ?>"></label>
							<label><span class="dashicons dashicons-email" title="<?php esc_attr_e( 'E-mail', 'ka-listing-bulk-importer' ); ?>"></span><input type="text" name="<?php echo esc_attr( $ka_name ); ?>[email]" value="<?php echo esc_attr( isset( $ka_f['email'] ) ? $ka_f['email'] : '' ); ?>" placeholder="<?php esc_attr_e( 'E-mail', 'ka-listing-bulk-importer' ); ?>"></label>
							<label><span class="dashicons dashicons-category" title="<?php esc_attr_e( 'Category', 'ka-listing-bulk-importer' ); ?>"></span><select name="<?php echo esc_attr( $ka_name ); ?>[category]">
								<option value=""><?php esc_html_e( '— no category —', 'ka-listing-bulk-importer' ); ?></option>
								<?php
								$ka_cur = isset( $ka_f['category'] ) ? $ka_f['category'] : '';
								$ka_hit = $ka_cur ? KA_LBI_Lang::find_term( KA_LBI_Plugin::TAX_CATEGORY, explode( ',', $ka_cur )[0], $ka_meta['lang'] ) : null;
								foreach ( $ka_cats as $ka_t ) {
									echo '<option value="' . esc_attr( $ka_t->slug ) . '"' . selected( $ka_hit && $ka_hit->term_id === $ka_t->term_id, true, false ) . '>' . esc_html( ( $ka_t->parent ? '— ' : '' ) . html_entity_decode( $ka_t->name, ENT_QUOTES, 'UTF-8' ) ) . '</option>';
								}
								?>
							</select></label>
							<label><span class="dashicons dashicons-admin-multisite" title="<?php esc_attr_e( 'City', 'ka-listing-bulk-importer' ); ?>"></span><input type="text" list="ka-lbi-loc-list" name="<?php echo esc_attr( $ka_name ); ?>[location]" value="<?php echo esc_attr( isset( $ka_f['location'] ) ? $ka_f['location'] : '' ); ?>" placeholder="<?php esc_attr_e( 'City', 'ka-listing-bulk-importer' ); ?>"></label>
						</div>
						<div class="ka-lbi-row-tags">
							<?php
							if ( ! empty( $ka_f['featured_photo'] ) ) {
								echo KA_LBI_Admin::pill( __( 'photo', 'ka-listing-bulk-importer' ), 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
							if ( ! empty( $ka_f['business_hours'] ) ) {
								echo KA_LBI_Admin::pill( __( 'opening hours', 'ka-listing-bulk-importer' ), 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
							if ( isset( $ka_f['rating'] ) ) {
								echo KA_LBI_Admin::pill( '★ ' . number_format_i18n( (float) $ka_f['rating'], 1 ) . ' (' . (int) $ka_f['rating_count'] . ')', 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
							if ( ! empty( $ka_f['source_url'] ) ) {
								echo '<a class="ka-lbi-small" href="' . esc_url( $ka_f['source_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google Maps', 'ka-listing-bulk-importer' ) . ' ↗</a>';
							}
							foreach ( $ka_r['errors'] as $ka_e ) {
								echo KA_LBI_Admin::pill( $ka_e, 'bad' ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
							foreach ( $ka_r['warnings'] as $ka_w ) {
								echo KA_LBI_Admin::pill( $ka_w, 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
							?>
						</div>
					</div>
					<div class="ka-lbi-row-side">
						<?php if ( $ka_r['duplicates'] ) : ?>
							<div class="ka-lbi-dup">
								<strong><?php esc_html_e( 'Possibly existing:', 'ka-listing-bulk-importer' ); ?></strong>
								<?php foreach ( array_slice( $ka_r['duplicates'], 0, 2 ) as $ka_d ) : ?>
									<a href="<?php echo esc_url( $ka_d['edit'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $ka_d['title'] ); ?></a>
									<em><?php echo esc_html( implode( ', ', array_map( function ( $w ) use ( $ka_why ) {
										return isset( $ka_why[ $w ] ) ? $ka_why[ $w ] : $w;
									}, $ka_d['why'] ) ) ); ?></em>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						<label class="screen-reader-text" for="ka-lbi-act-<?php echo (int) $ka_row; ?>"><?php esc_html_e( 'Action', 'ka-listing-bulk-importer' ); ?></label>
						<select id="ka-lbi-act-<?php echo (int) $ka_row; ?>" name="ka_lbi_action[<?php echo (int) $ka_row; ?>]" class="ka-lbi-act">
							<option value="create" <?php selected( $ka_def, 'create' ); ?>><?php esc_html_e( 'Import as new', 'ka-listing-bulk-importer' ); ?></option>
							<option value="skip" <?php selected( $ka_def, 'skip' ); ?>><?php esc_html_e( 'Skip', 'ka-listing-bulk-importer' ); ?></option>
							<?php foreach ( array_slice( $ka_r['duplicates'], 0, 2 ) as $ka_d ) : ?>
								<option value="update:<?php echo (int) $ka_d['id']; ?>" <?php selected( $ka_def, 'update:' . $ka_d['id'] ); ?>><?php
									/* translators: %s: listing title */
									echo esc_html( sprintf( __( 'Update "%s"', 'ka-listing-bulk-importer' ), mb_strimwidth( $ka_d['title'], 0, 34, '…' ) ) );
								?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<div class="ka-lbi-runbar">
			<div class="ka-lbi-runbar-t">
				<strong data-k="chosen">0</strong> <span><?php esc_html_e( 'rows will be written', 'ka-listing-bulk-importer' ); ?></span>
				<label class="ka-lbi-inline"><?php esc_html_e( 'as', 'ka-listing-bulk-importer' ); ?>
					<select name="ka_lbi_status">
						<option value="pending" <?php selected( $ka_meta['status'], 'pending' ); ?>><?php esc_html_e( 'Pending review', 'ka-listing-bulk-importer' ); ?></option>
						<option value="draft" <?php selected( $ka_meta['status'], 'draft' ); ?>><?php esc_html_e( 'Draft', 'ka-listing-bulk-importer' ); ?></option>
						<?php if ( current_user_can( 'publish_posts' ) ) : ?><option value="publish" <?php selected( $ka_meta['status'], 'publish' ); ?>><?php esc_html_e( 'Published', 'ka-listing-bulk-importer' ); ?></option><?php endif; ?>
					</select>
				</label>
			</div>
			<a class="button ka-lbi-confirm" data-confirm="<?php esc_attr_e( 'Discard this preview?', 'ka-listing-bulk-importer' ); ?>" href="<?php echo esc_url( KA_LBI_Plugin::action_url( 'ka_lbi_cancel_batch', array( 'to' => $ka_is_csv ? 'import' : 'discover' ) ) ); ?>"><?php esc_html_e( 'Discard', 'ka-listing-bulk-importer' ); ?></a>
			<button type="submit" class="button button-primary button-hero"><span class="dashicons dashicons-download"></span><?php esc_html_e( 'Import', 'ka-listing-bulk-importer' ); ?></button>
		</div>
	</form>
	<?php
/* ------------------------------------------------------------------ done */
else :
	$ka_res = get_transient( 'ka_lbi_' . get_current_user_id() . '_results' );
	if ( ! is_array( $ka_res ) ) {
		echo '<div class="ka-lbi-card"><p>' . esc_html__( 'No results to show.', 'ka-listing-bulk-importer' ) . '</p></div>';
		return;
	}
	?>
	<div class="ka-lbi-kpis">
		<div class="ka-lbi-kpi"><span class="ka-lbi-kpi-i is-green dashicons dashicons-plus-alt"></span><div><strong><?php echo (int) count( $ka_res['created'] ); ?></strong><span><?php esc_html_e( 'created', 'ka-listing-bulk-importer' ); ?></span></div></div>
		<div class="ka-lbi-kpi"><span class="ka-lbi-kpi-i is-blue dashicons dashicons-update"></span><div><strong><?php echo (int) count( $ka_res['updated'] ); ?></strong><span><?php esc_html_e( 'updated', 'ka-listing-bulk-importer' ); ?></span></div></div>
		<div class="ka-lbi-kpi"><span class="ka-lbi-kpi-i is-gray dashicons dashicons-minus"></span><div><strong><?php echo (int) count( $ka_res['skipped'] ); ?></strong><span><?php esc_html_e( 'skipped', 'ka-listing-bulk-importer' ); ?></span></div></div>
		<div class="ka-lbi-kpi"><span class="ka-lbi-kpi-i is-red dashicons dashicons-warning"></span><div><strong><?php echo (int) count( $ka_res['failed'] ); ?></strong><span><?php esc_html_e( 'failed', 'ka-listing-bulk-importer' ); ?></span></div></div>
	</div>
	<div class="ka-lbi-card">
		<?php foreach ( array( 'created' => __( 'Created', 'ka-listing-bulk-importer' ), 'updated' => __( 'Updated', 'ka-listing-bulk-importer' ), 'failed' => __( 'Failed', 'ka-listing-bulk-importer' ) ) as $ka_k => $ka_label ) : ?>
			<?php if ( $ka_res[ $ka_k ] ) : ?>
				<h3 class="ka-lbi-h3"><?php echo esc_html( $ka_label ); ?></h3>
				<ul class="ka-lbi-list">
					<?php foreach ( $ka_res[ $ka_k ] as $ka_i ) : ?>
						<li><?php echo isset( $ka_i['id'] ) ? '<a href="' . esc_url( get_edit_post_link( $ka_i['id'], 'raw' ) ) . '">' . esc_html( $ka_i['title'] ) . '</a>' : esc_html( $ka_i['title'] . ' - ' . $ka_i['reason'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php endforeach; ?>
		<?php if ( $ka_res['photo_warnings'] ) : ?>
			<h3 class="ka-lbi-h3"><?php esc_html_e( 'Photo problems (the listing was still saved)', 'ka-listing-bulk-importer' ); ?></h3>
			<ul class="ka-lbi-list"><?php foreach ( $ka_res['photo_warnings'] as $ka_w ) { echo '<li><strong>' . esc_html( $ka_w['title'] ) . '</strong>: ' . esc_html( implode( '; ', $ka_w['errors'] ) ) . '</li>'; } ?></ul>
		<?php endif; ?>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-review' ) ); ?>"><?php esc_html_e( 'Open the review queue', 'ka-listing-bulk-importer' ); ?></a>
			<a class="button" href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-discover' ) ); ?>"><?php esc_html_e( 'New search', 'ka-listing-bulk-importer' ); ?></a>
			<a class="button" href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-import' ) ); ?>"><?php esc_html_e( 'Import another file', 'ka-listing-bulk-importer' ); ?></a>
		</p>
	</div>
	<?php
endif;
