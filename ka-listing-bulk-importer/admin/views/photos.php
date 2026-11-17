<?php
/**
 * Enrich existing listings.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification -- prefill from links only
$ka_pre_missing = isset( $_GET['missing'] ) ? sanitize_key( wp_unslash( $_GET['missing'] ) ) : '';
$ka_pre_loc     = isset( $_GET['location'] ) ? absint( $_GET['location'] ) : 0;
// phpcs:enable
$ka_has_google = '' !== KA_LBI_Google::key();
$ka_lang       = KA_LBI_Lang::import_language();
$ka_s          = KA_LBI_Settings::all();
$ka_defaults   = array(
	'email'   => $ka_s['enrich_email'],
	'phone'   => $ka_s['enrich_phone'],
	'socials' => $ka_s['enrich_socials'],
	'photo'   => $ka_s['enrich_photo'],
	'logo'    => $ka_s['enrich_logo'],
	'hours'   => $ka_s['enrich_hours'] && $ka_has_google,
	'address' => $ka_has_google,
	'website' => $ka_has_google,
	'tagline' => $ka_s['enrich_tagline'],
);
$ka_history = array_slice( KA_LBI_Jobs::history( 'enrich' ), 0, 10 );
?>
<?php KA_LBI_Admin::job_card( 'enrich' ); ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ka-lbi-card">
	<?php wp_nonce_field( KA_LBI_Plugin::NONCE ); ?>
	<input type="hidden" name="action" value="ka_lbi_enrich">
	<div class="ka-lbi-card-h">
		<h2><span class="dashicons dashicons-admin-site-alt3"></span><?php esc_html_e( 'Fill missing data of existing listings', 'ka-listing-bulk-importer' ); ?></h2>
	</div>
	<p class="ka-lbi-lead"><?php esc_html_e( 'Reads each business\'s own website (home page and Impressum/contact page) and, if you like, Google Places. Only empty fields are filled - nothing you entered is ever overwritten. The results are copied to the other language versions too.', 'ka-listing-bulk-importer' ); ?></p>

	<div class="ka-lbi-cols">
		<div>
			<span class="ka-lbi-label"><?php esc_html_e( 'Which listings', 'ka-listing-bulk-importer' ); ?></span>
			<p><select name="location" aria-label="<?php esc_attr_e( 'City', 'ka-listing-bulk-importer' ); ?>">
				<option value="0"><?php esc_html_e( 'All cities', 'ka-listing-bulk-importer' ); ?></option>
				<?php foreach ( KA_LBI_Lang::terms( KA_LBI_Plugin::TAX_LOCATION, $ka_lang, array( 'orderby' => 'count', 'order' => 'DESC' ) ) as $ka_t ) : ?>
					<option value="<?php echo (int) $ka_t->term_id; ?>" <?php selected( $ka_pre_loc, $ka_t->term_id ); ?>><?php echo esc_html( $ka_t->name . ' (' . $ka_t->count . ')' ); ?></option>
				<?php endforeach; ?>
			</select></p>
			<p><select name="category" aria-label="<?php esc_attr_e( 'Category', 'ka-listing-bulk-importer' ); ?>">
				<option value="0"><?php esc_html_e( 'All categories', 'ka-listing-bulk-importer' ); ?></option>
				<?php foreach ( KA_LBI_Lang::terms( KA_LBI_Plugin::TAX_CATEGORY, $ka_lang ) as $ka_t ) : ?>
					<option value="<?php echo (int) $ka_t->term_id; ?>"><?php echo esc_html( ( $ka_t->parent ? '— ' : '' ) . html_entity_decode( $ka_t->name, ENT_QUOTES, 'UTF-8' ) ); ?></option>
				<?php endforeach; ?>
			</select></p>
			<p><select name="missing" aria-label="<?php esc_attr_e( 'Only listings missing', 'ka-listing-bulk-importer' ); ?>">
				<option value=""><?php esc_html_e( 'Any listing missing one of the fields', 'ka-listing-bulk-importer' ); ?></option>
				<option value="email" <?php selected( $ka_pre_missing, 'email' ); ?>><?php esc_html_e( 'Only listings without e-mail', 'ka-listing-bulk-importer' ); ?></option>
				<option value="photo" <?php selected( $ka_pre_missing, 'photo' ); ?>><?php esc_html_e( 'Only listings without photo', 'ka-listing-bulk-importer' ); ?></option>
				<option value="phone" <?php selected( $ka_pre_missing, 'phone' ); ?>><?php esc_html_e( 'Only listings without phone', 'ka-listing-bulk-importer' ); ?></option>
				<option value="website" <?php selected( $ka_pre_missing, 'website' ); ?>><?php esc_html_e( 'Only listings without website (Google)', 'ka-listing-bulk-importer' ); ?></option>
				<option value="gAddress" <?php selected( $ka_pre_missing, 'gaddress' ); ?>><?php esc_html_e( 'Only listings without address (Google)', 'ka-listing-bulk-importer' ); ?></option>
			</select></p>
			<label class="ka-lbi-check"><input type="checkbox" name="recheck" value="1"> <?php
				/* translators: %d: days */
				echo esc_html( sprintf( __( 'Also listings checked in the last %d days', 'ka-listing-bulk-importer' ), (int) $ka_s['enrich_days'] ) );
			?></label>
		</div>
		<div>
			<span class="ka-lbi-label"><?php esc_html_e( 'Fill in', 'ka-listing-bulk-importer' ); ?></span>
			<div class="ka-lbi-checkgrid">
				<?php foreach ( KA_LBI_Enrich::targets() as $ka_k => $ka_label ) : ?>
					<label class="ka-lbi-check"><input type="checkbox" name="what[]" value="<?php echo esc_attr( $ka_k ); ?>" <?php checked( ! empty( $ka_defaults[ $ka_k ] ) ); ?>> <?php echo esc_html( $ka_label ); ?></label>
				<?php endforeach; ?>
			</div>
			<span class="ka-lbi-label"><?php esc_html_e( 'Sources', 'ka-listing-bulk-importer' ); ?></span>
			<label class="ka-lbi-check"><input type="checkbox" name="sources[]" value="website" checked> <?php esc_html_e( 'The listing\'s own website - free', 'ka-listing-bulk-importer' ); ?></label>
			<label class="ka-lbi-check<?php echo $ka_has_google ? '' : ' is-off'; ?>"><input type="checkbox" name="sources[]" value="google" <?php checked( $ka_has_google ); ?> <?php disabled( ! $ka_has_google ); ?>> <?php esc_html_e( 'Google Places (New) - one paid request per listing', 'ka-listing-bulk-importer' ); ?><?php if ( ! $ka_has_google ) : ?> <em class="ka-lbi-muted">(<?php esc_html_e( 'no key', 'ka-listing-bulk-importer' ); ?>)</em><?php endif; ?></label>
		</div>
	</div>
	<p class="ka-lbi-muted ka-lbi-small"><?php
		/* translators: %d: number of listings */
		echo esc_html( sprintf( __( 'Up to %d listings are done right away; more run in the background with live progress.', 'ka-listing-bulk-importer' ), KA_LBI_Enrich::SYNC_CAP ) );
	?></p>
	<p><button type="submit" class="button button-primary" <?php disabled( (bool) KA_LBI_Jobs::get( 'enrich' ) ); ?>><span class="dashicons dashicons-controls-play"></span> <?php esc_html_e( 'Start', 'ka-listing-bulk-importer' ); ?></button></p>
</form>

<?php if ( $ka_history ) : ?>
	<div class="ka-lbi-card">
		<div class="ka-lbi-card-h"><h2><span class="dashicons dashicons-backup"></span><?php esc_html_e( 'Recent runs', 'ka-listing-bulk-importer' ); ?></h2></div>
		<table class="ka-lbi-table">
			<thead><tr><th><?php esc_html_e( 'When', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Scope', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Result', 'ka-listing-bulk-importer' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $ka_history as $ka_e ) : ?>
				<tr><td><?php echo esc_html( KA_LBI_Admin::ago( $ka_e['time'] ) ); ?></td><td><?php echo esc_html( $ka_e['label'] ); ?></td><td><?php echo esc_html( KA_LBI_Jobs::describe( $ka_e ) ); ?><?php echo $ka_e['cancelled'] ? '<br><span class="ka-lbi-muted">' . esc_html( $ka_e['cancelled'] ) . '</span>' : ''; ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
