<?php
/**
 * Discover form.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification -- prefill from links only
$ka_lang      = KA_LBI_Lang::import_language( isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '' );
$ka_pre_loc   = isset( $_GET['location'] ) ? KA_LBI_Lang::term_in( absint( $_GET['location'] ), $ka_lang ) : 0;
$ka_pre_cat   = isset( $_GET['category'] ) ? sanitize_title( wp_unslash( $_GET['category'] ) ) : '';
// phpcs:enable
$ka_locations = KA_LBI_Lang::terms( KA_LBI_Plugin::TAX_LOCATION, $ka_lang, array( 'orderby' => 'count', 'order' => 'DESC' ) );
$ka_usable    = KA_LBI_Providers::usable();
$ka_selected  = $ka_pre_cat ? array( $ka_pre_cat ) : array();
if ( $ka_pre_cat ) {
	// A top-level category from the coverage matrix stands for its sub categories too.
	$ka_t = KA_LBI_Lang::find_term( KA_LBI_Plugin::TAX_CATEGORY, $ka_pre_cat, $ka_lang );
	if ( $ka_t ) {
		foreach ( get_term_children( $ka_t->term_id, KA_LBI_Plugin::TAX_CATEGORY ) as $ka_child ) {
			$ka_c = get_term( $ka_child );
			if ( $ka_c && ! is_wp_error( $ka_c ) ) {
				$ka_selected[] = $ka_c->slug;
			}
		}
	}
}
$ka_batch = KA_LBI_Batch::get();
?>

<?php if ( $ka_batch && 'csv' !== $ka_batch['meta']['source'] ) : ?>
	<div class="ka-lbi-notice is-info"><span class="dashicons dashicons-info"></span><p>
		<?php
		/* translators: %d: number of rows */
		echo esc_html( sprintf( __( 'You have an unfinished preview with %d results.', 'ka-listing-bulk-importer' ), count( $ka_batch['records'] ) ) );
		?>
		<a href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-import', array( 'step' => 'preview' ) ) ); ?>"><?php esc_html_e( 'Continue reviewing', 'ka-listing-bulk-importer' ); ?></a>
	</p></div>
<?php endif; ?>

<?php KA_LBI_Admin::job_card( 'discover' ); ?>

<?php if ( ! $ka_usable ) : ?>
	<div class="ka-lbi-card ka-lbi-hero">
		<span class="dashicons dashicons-admin-network"></span>
		<h2><?php esc_html_e( 'Add a data source first', 'ka-listing-bulk-importer' ); ?></h2>
		<p><?php esc_html_e( 'Discover needs a Google Places (New) key - recommended, live and verified data - or a key for Gemini, Claude or ChatGPT.', 'ka-listing-bulk-importer' ); ?></p>
		<?php if ( current_user_can( KA_LBI_Plugin::SETTINGS_CAP ) ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-settings' ) ); ?>"><?php esc_html_e( 'Open settings', 'ka-listing-bulk-importer' ); ?></a>
		<?php endif; ?>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ka-lbi-discover" data-ka-lbi-discover data-lang="<?php echo esc_attr( $ka_lang ); ?>">
	<?php wp_nonce_field( KA_LBI_Plugin::NONCE ); ?>
	<input type="hidden" name="action" value="ka_lbi_discover">
	<input type="hidden" name="lang" value="<?php echo esc_attr( $ka_lang ); ?>">

	<section class="ka-lbi-card ka-lbi-step">
		<div class="ka-lbi-card-h">
			<h2><span class="ka-lbi-num">1</span><?php esc_html_e( 'Where?', 'ka-listing-bulk-importer' ); ?></h2>
			<?php if ( count( KA_LBI_Lang::languages() ) > 1 ) : ?>
				<label class="ka-lbi-inline"><?php esc_html_e( 'Import language', 'ka-listing-bulk-importer' ); ?>
					<select data-ka-lbi-lang-switch="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-discover' ) ); ?>">
						<?php foreach ( KA_LBI_Lang::languages() as $ka_slug => $ka_label ) : ?>
							<option value="<?php echo esc_attr( $ka_slug ); ?>" <?php selected( $ka_lang, $ka_slug ); ?>><?php echo esc_html( $ka_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>
		</div>
		<div class="ka-lbi-picker" data-ka-lbi-picker data-ka-lbi-cities>
			<div class="ka-lbi-picker-bar">
				<input type="search" class="ka-lbi-picker-filter" placeholder="<?php esc_attr_e( 'Filter cities…', 'ka-listing-bulk-importer' ); ?>" aria-label="<?php esc_attr_e( 'Filter cities', 'ka-listing-bulk-importer' ); ?>">
				<button type="button" class="button-link" data-pick="none"><?php esc_html_e( 'Clear', 'ka-listing-bulk-importer' ); ?></button>
				<span class="ka-lbi-picker-count"></span>
			</div>
			<div class="ka-lbi-chips">
				<?php foreach ( $ka_locations as $ka_t ) : ?>
					<label class="ka-lbi-chip" data-name="<?php echo esc_attr( KA_LBI_Lang::norm( $ka_t->name . ' ' . KA_LBI_Lang::search_name( $ka_t ) ) ); ?>">
						<input type="checkbox" name="locations[]" value="<?php echo (int) $ka_t->term_id; ?>" <?php checked( $ka_pre_loc, $ka_t->term_id ); ?>>
						<span><?php echo esc_html( html_entity_decode( $ka_t->name, ENT_QUOTES, 'UTF-8' ) ); ?></span>
						<?php if ( KA_LBI_Lang::search_name( $ka_t ) !== $ka_t->name ) : ?><small><?php echo esc_html( KA_LBI_Lang::search_name( $ka_t ) ); ?></small><?php endif; ?>
						<em class="ka-lbi-chip-n is-static"><?php echo (int) $ka_t->count; ?></em>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<details class="ka-lbi-more">
			<summary><?php esc_html_e( 'A city that is not on the site yet', 'ka-listing-bulk-importer' ); ?></summary>
			<textarea name="new_places" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'One per line or separated by commas, e.g. Lübeck, Kiel', 'ka-listing-bulk-importer' ); ?>"></textarea>
			<p class="ka-lbi-muted ka-lbi-small"><?php echo KA_LBI_Settings::get( 'create_locations' ) ? esc_html__( 'Missing locations are created automatically when listings are imported.', 'ka-listing-bulk-importer' ) : esc_html__( 'Creating locations is switched off in the settings - listings get no location.', 'ka-listing-bulk-importer' ); ?></p>
		</details>
	</section>

	<section class="ka-lbi-card ka-lbi-step">
		<div class="ka-lbi-card-h">
			<h2><span class="ka-lbi-num">2</span><?php esc_html_e( 'What?', 'ka-listing-bulk-importer' ); ?></h2>
			<span class="ka-lbi-muted ka-lbi-small"><span class="ka-lbi-legend is-n"></span><?php esc_html_e( 'listings already there', 'ka-listing-bulk-importer' ); ?> &nbsp; <span class="ka-lbi-legend is-recent"></span><?php esc_html_e( 'searched in the last 30 days', 'ka-listing-bulk-importer' ); ?></span>
		</div>
		<?php KA_LBI_Admin::category_picker( 'categories', $ka_selected, $ka_lang ); ?>
		<p class="ka-lbi-muted ka-lbi-small"><?php esc_html_e( 'Each category is one search per city. Nothing selected means all categories.', 'ka-listing-bulk-importer' ); ?></p>
	</section>

	<section class="ka-lbi-card ka-lbi-step">
		<div class="ka-lbi-card-h"><h2><span class="ka-lbi-num">3</span><?php esc_html_e( 'Source', 'ka-listing-bulk-importer' ); ?></h2></div>
		<div class="ka-lbi-sourcecards">
			<?php
			$ka_first = true;
			foreach ( KA_LBI_Providers::all() as $ka_slug => $ka_def ) :
				$ka_ok = in_array( $ka_slug, $ka_usable, true );
				?>
				<label class="ka-lbi-source<?php echo $ka_ok ? '' : ' is-off'; ?>">
					<input type="radio" name="provider" value="<?php echo esc_attr( $ka_slug ); ?>" data-cost="<?php echo esc_attr( KA_LBI_Providers::cost( $ka_slug ) ); ?>" data-ai="<?php echo $ka_def['is_ai'] ? 1 : 0; ?>" <?php disabled( ! $ka_ok ); ?> <?php checked( $ka_ok && $ka_first ); ?>>
					<span class="ka-lbi-source-b">
						<strong><?php echo esc_html( $ka_def['label'] ); ?><?php echo $ka_def['is_ai'] ? '' : ' ' . KA_LBI_Admin::pill( __( 'recommended', 'ka-listing-bulk-importer' ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></strong>
						<em><?php echo esc_html( $ka_def['long'] ); ?></em>
						<?php if ( $ka_def['is_ai'] && $ka_ok ) : ?><small><?php echo esc_html( KA_LBI_Providers::model( $ka_slug ) ); ?></small><?php endif; ?>
						<?php if ( ! $ka_ok ) : ?><small><?php echo '' === KA_LBI_Providers::key( $ka_slug ) ? esc_html__( 'no key saved', 'ka-listing-bulk-importer' ) : esc_html__( 'key test failed', 'ka-listing-bulk-importer' ); ?></small><?php endif; ?>
					</span>
				</label>
				<?php
				if ( $ka_ok ) {
					$ka_first = false;
				}
			endforeach;
			?>
		</div>
	</section>

	<section class="ka-lbi-card ka-lbi-step">
		<div class="ka-lbi-card-h"><h2><span class="ka-lbi-num">4</span><?php esc_html_e( 'Options', 'ka-listing-bulk-importer' ); ?></h2></div>
		<div class="ka-lbi-cols">
			<div>
				<label class="ka-lbi-label" for="ka-lbi-max"><?php esc_html_e( 'Results per search', 'ka-listing-bulk-importer' ); ?></label>
				<input type="number" id="ka-lbi-max" name="max" min="1" max="<?php echo (int) KA_LBI_Discover::MAX_RESULTS; ?>" value="20" class="small-text">
				<p class="ka-lbi-muted ka-lbi-small"><?php
					/* translators: %d: results */
					echo esc_html( sprintf( __( 'Background runs use at most %d per search (Settings).', 'ka-listing-bulk-importer' ), (int) KA_LBI_Settings::get( 'job_results' ) ) );
				?></p>
				<label class="ka-lbi-label" for="ka-lbi-status"><?php esc_html_e( 'Status of new listings', 'ka-listing-bulk-importer' ); ?></label>
				<select id="ka-lbi-status" name="status">
					<option value="pending"><?php esc_html_e( 'Pending review (recommended)', 'ka-listing-bulk-importer' ); ?></option>
					<option value="draft"><?php esc_html_e( 'Draft', 'ka-listing-bulk-importer' ); ?></option>
					<?php if ( current_user_can( 'publish_posts' ) ) : ?><option value="publish"><?php esc_html_e( 'Published', 'ka-listing-bulk-importer' ); ?></option><?php endif; ?>
				</select>
			</div>
			<div class="ka-lbi-radios">
				<span class="ka-lbi-label"><?php esc_html_e( 'Already existing businesses', 'ka-listing-bulk-importer' ); ?></span>
				<label><input type="radio" name="mode" value="all" checked> <strong><?php esc_html_e( 'Show them, I decide', 'ka-listing-bulk-importer' ); ?></strong><em><?php esc_html_e( 'Possible duplicates are marked and set to skip.', 'ka-listing-bulk-importer' ); ?></em></label>
				<label><input type="radio" name="mode" value="new_only"> <strong><?php esc_html_e( 'Only new ones', 'ka-listing-bulk-importer' ); ?></strong><em><?php esc_html_e( 'Leave out anything that looks like it exists.', 'ka-listing-bulk-importer' ); ?></em></label>
				<label><input type="radio" name="mode" value="update_existing"> <strong><?php esc_html_e( 'Update existing ones', 'ka-listing-bulk-importer' ); ?></strong><em><?php esc_html_e( 'Matches are preset to update the existing listing.', 'ka-listing-bulk-importer' ); ?></em></label>
			</div>
		</div>
	</section>

	<div class="ka-lbi-runbar" data-ka-lbi-runbar>
		<div class="ka-lbi-runbar-t"><strong data-k="combos">0</strong> <span><?php esc_html_e( 'searches', 'ka-listing-bulk-importer' ); ?></span> <span class="ka-lbi-muted" data-k="hint"></span></div>
		<button type="submit" class="button button-primary button-hero" <?php disabled( ! $ka_usable ); ?>><span class="dashicons dashicons-search"></span><?php esc_html_e( 'Start search', 'ka-listing-bulk-importer' ); ?></button>
	</div>
</form>

<?php $ka_recent = KA_LBI_Discover::recent( 12 ); ?>
<?php if ( $ka_recent ) : ?>
	<div class="ka-lbi-card">
		<div class="ka-lbi-card-h"><h2><span class="dashicons dashicons-backup"></span><?php esc_html_e( 'Recent searches', 'ka-listing-bulk-importer' ); ?></h2></div>
		<table class="ka-lbi-table">
			<thead><tr><th><?php esc_html_e( 'When', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'City', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Category', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Source', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Results', 'ka-listing-bulk-importer' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $ka_recent as $ka_e ) : ?>
				<tr><td><?php echo esc_html( KA_LBI_Admin::ago( $ka_e['time'] ) ); ?></td><td><?php echo esc_html( $ka_e['location'] ); ?></td><td><?php echo esc_html( html_entity_decode( $ka_e['category'], ENT_QUOTES, 'UTF-8' ) ); ?></td><td><?php echo esc_html( $ka_e['provider'] ); ?></td><td><?php echo (int) $ka_e['found']; ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
