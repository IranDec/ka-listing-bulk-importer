<?php
/**
 * Settings.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ka_s     = KA_LBI_Settings::all();
$ka_langs = KA_LBI_Lang::languages();
$ka_help  = array(
	'google' => __( 'Google Cloud Console: create a project, link billing, enable "Places API (New)", create an API key and restrict it to that API (and set a daily quota).', 'ka-listing-bulk-importer' ),
	'gemini' => __( 'aistudio.google.com - "Get API key".', 'ka-listing-bulk-importer' ),
	'claude' => __( 'console.anthropic.com - Settings - API keys.', 'ka-listing-bulk-importer' ),
	'openai' => __( 'platform.openai.com - API keys.', 'ka-listing-bulk-importer' ),
);
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ka-lbi-settings">
	<?php wp_nonce_field( KA_LBI_Plugin::NONCE ); ?>
	<input type="hidden" name="action" value="ka_lbi_save_settings">

	<div class="ka-lbi-card">
		<div class="ka-lbi-card-h"><h2><span class="dashicons dashicons-admin-network"></span><?php esc_html_e( 'Data sources', 'ka-listing-bulk-importer' ); ?></h2></div>
		<p class="ka-lbi-lead"><?php esc_html_e( 'Google Places returns live, verified business data. The AI sources answer from model knowledge - useful for ideas, but always check address and phone. Keys are stored on this server only and are never shown again; leave a field empty to keep the saved key.', 'ka-listing-bulk-importer' ); ?></p>
		<div class="ka-lbi-provgrid">
			<?php foreach ( KA_LBI_Providers::all() as $ka_slug => $ka_def ) :
				$ka_key  = KA_LBI_Providers::key( $ka_slug );
				$ka_st   = KA_LBI_Providers::status( $ka_slug );
				$ka_list = KA_LBI_Providers::model_list( $ka_slug );
				$ka_mod  = KA_LBI_Providers::model( $ka_slug );
				?>
				<div class="ka-lbi-prov">
					<div class="ka-lbi-prov-h">
						<strong><?php echo esc_html( $ka_def['label'] ); ?></strong>
						<?php
						if ( '' === $ka_key ) {
							echo KA_LBI_Admin::pill( __( 'no key', 'ka-listing-bulk-importer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						} elseif ( $ka_st && empty( $ka_st['ok'] ) ) {
							echo KA_LBI_Admin::pill( __( 'not working', 'ka-listing-bulk-importer' ), 'bad' ); // phpcs:ignore WordPress.Security.EscapeOutput
						} elseif ( $ka_st ) {
							echo KA_LBI_Admin::pill( __( 'working', 'ka-listing-bulk-importer' ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput
						} else {
							echo KA_LBI_Admin::pill( __( 'not tested', 'ka-listing-bulk-importer' ), 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						?>
					</div>
					<p class="ka-lbi-muted ka-lbi-small"><?php echo esc_html( $ka_help[ $ka_slug ] ); ?></p>
					<label class="ka-lbi-label" for="ka-lbi-key-<?php echo esc_attr( $ka_slug ); ?>"><?php esc_html_e( 'API key', 'ka-listing-bulk-importer' ); ?></label>
					<input type="password" autocomplete="new-password" id="ka-lbi-key-<?php echo esc_attr( $ka_slug ); ?>" name="key[<?php echo esc_attr( $ka_slug ); ?>]" class="widefat" placeholder="<?php echo $ka_key ? esc_attr( '•••••••• ' . substr( $ka_key, -4 ) . ' - ' . __( 'saved, leave empty to keep', 'ka-listing-bulk-importer' ) ) : ''; ?>">
					<?php if ( $ka_key ) : ?>
						<label class="ka-lbi-check ka-lbi-small"><input type="checkbox" name="key[remove_<?php echo esc_attr( $ka_slug ); ?>]" value="1"> <?php esc_html_e( 'Remove the saved key', 'ka-listing-bulk-importer' ); ?></label>
					<?php endif; ?>
					<?php if ( $ka_def['is_ai'] ) : ?>
						<label class="ka-lbi-label" for="ka-lbi-model-<?php echo esc_attr( $ka_slug ); ?>"><?php esc_html_e( 'Model', 'ka-listing-bulk-importer' ); ?></label>
						<?php if ( $ka_list ) : ?>
							<select id="ka-lbi-model-<?php echo esc_attr( $ka_slug ); ?>" name="model[<?php echo esc_attr( $ka_slug ); ?>]" class="widefat">
								<?php
								$ka_seen = false;
								foreach ( $ka_list as $ka_m ) {
									$ka_seen = $ka_seen || $ka_m['id'] === $ka_mod;
									echo '<option value="' . esc_attr( $ka_m['id'] ) . '"' . selected( $ka_mod, $ka_m['id'], false ) . '>' . esc_html( $ka_m['id'] ) . '</option>';
								}
								if ( ! $ka_seen ) {
									echo '<option value="' . esc_attr( $ka_mod ) . '" selected>' . esc_html( $ka_mod ) . '</option>';
								}
								?>
							</select>
						<?php else : ?>
							<input type="text" id="ka-lbi-model-<?php echo esc_attr( $ka_slug ); ?>" name="model[<?php echo esc_attr( $ka_slug ); ?>]" class="widefat" value="<?php echo esc_attr( $ka_mod ); ?>">
						<?php endif; ?>
					<?php endif; ?>
					<label class="ka-lbi-label" for="ka-lbi-cost-<?php echo esc_attr( $ka_slug ); ?>"><?php esc_html_e( 'Estimated cost per result (USD)', 'ka-listing-bulk-importer' ); ?></label>
					<input type="number" step="0.001" min="0" id="ka-lbi-cost-<?php echo esc_attr( $ka_slug ); ?>" name="cost[<?php echo esc_attr( $ka_slug ); ?>]" class="small-text" value="<?php echo esc_attr( get_option( KA_LBI_Providers::cost_key( $ka_slug, $ka_mod ), '' ) ); ?>">
					<div class="ka-lbi-prov-f">
						<span><?php
							/* translators: %s: amount */
							echo esc_html( sprintf( __( 'Spent so far (estimate): $%s', 'ka-listing-bulk-importer' ), number_format_i18n( KA_LBI_Providers::spend( $ka_slug ), 2 ) ) );
						?></span>
						<?php if ( KA_LBI_Providers::spend( $ka_slug ) > 0 ) : ?><a href="<?php echo esc_url( KA_LBI_Plugin::action_url( 'ka_lbi_reset_spend', array( 'provider' => $ka_slug ) ) ); ?>"><?php esc_html_e( 'reset', 'ka-listing-bulk-importer' ); ?></a><?php endif; ?>
						<?php if ( $ka_key ) : ?><a href="<?php echo esc_url( KA_LBI_Plugin::action_url( 'ka_lbi_test_key', array( 'provider' => $ka_slug ) ) ); ?>"><?php esc_html_e( 'Test again', 'ka-listing-bulk-importer' ); ?></a><?php endif; ?>
					</div>
					<?php if ( $ka_st ) : ?>
						<p class="ka-lbi-small <?php echo $ka_st['ok'] ? 'ka-lbi-muted' : 'ka-lbi-warn-text'; ?>"><?php echo esc_html( $ka_st['message'] . ' (' . KA_LBI_Admin::ago( $ka_st['checked'] ) . ')' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="ka-lbi-grid">
		<div class="ka-lbi-card">
			<div class="ka-lbi-card-h"><h2><span class="dashicons dashicons-search"></span><?php esc_html_e( 'Discover', 'ka-listing-bulk-importer' ); ?></h2></div>
			<label class="ka-lbi-label" for="ka-lbi-gq"><?php esc_html_e( 'Google search phrase', 'ka-listing-bulk-importer' ); ?></label>
			<input type="text" id="ka-lbi-gq" name="google_query" class="widefat" value="<?php echo esc_attr( $ka_s['google_query'] ); ?>">
			<p class="ka-lbi-muted ka-lbi-small"><?php esc_html_e( 'Must contain {category} and {city}.', 'ka-listing-bulk-importer' ); ?></p>
			<?php if ( $ka_langs ) : ?>
				<label class="ka-lbi-label" for="ka-lbi-sl"><?php esc_html_e( 'Names used in searches', 'ka-listing-bulk-importer' ); ?></label>
				<select id="ka-lbi-sl" name="search_language"><?php foreach ( $ka_langs as $ka_k => $ka_l ) { echo '<option value="' . esc_attr( $ka_k ) . '"' . selected( $ka_s['search_language'], $ka_k, false ) . '>' . esc_html( $ka_l ) . '</option>'; } ?></select>
				<p class="ka-lbi-muted ka-lbi-small"><?php esc_html_e( 'Category and city names are translated to this language for the query (German finds most on Google in Germany).', 'ka-listing-bulk-importer' ); ?></p>
			<?php endif; ?>
			<label class="ka-lbi-label" for="ka-lbi-jr"><?php esc_html_e( 'Results per search in background runs', 'ka-listing-bulk-importer' ); ?></label>
			<input type="number" id="ka-lbi-jr" name="job_results" min="1" max="60" class="small-text" value="<?php echo (int) $ka_s['job_results']; ?>">
			<label class="ka-lbi-check"><input type="checkbox" name="skip_closed" value="1" <?php checked( $ka_s['skip_closed'] ); ?>> <?php esc_html_e( 'Leave out businesses Google marks as closed', 'ka-listing-bulk-importer' ); ?></label>
			<label class="ka-lbi-check"><input type="checkbox" name="create_locations" value="1" <?php checked( $ka_s['create_locations'] ); ?>> <?php esc_html_e( 'Create missing cities (locations) automatically', 'ka-listing-bulk-importer' ); ?></label>
		</div>

		<div class="ka-lbi-card">
			<div class="ka-lbi-card-h"><h2><span class="dashicons dashicons-translation"></span><?php esc_html_e( 'Languages & notifications', 'ka-listing-bulk-importer' ); ?></h2></div>
			<?php if ( $ka_langs ) : ?>
				<label class="ka-lbi-label" for="ka-lbi-il"><?php esc_html_e( 'Write new listings in', 'ka-listing-bulk-importer' ); ?></label>
				<select id="ka-lbi-il" name="language">
					<option value=""><?php esc_html_e( 'Default language of the site', 'ka-listing-bulk-importer' ); ?></option>
					<?php foreach ( $ka_langs as $ka_k => $ka_l ) { echo '<option value="' . esc_attr( $ka_k ) . '"' . selected( $ka_s['language'], $ka_k, false ) . '>' . esc_html( $ka_l ) . '</option>'; } ?>
				</select>
				<label class="ka-lbi-check"><input type="checkbox" name="translate" value="1" <?php checked( $ka_s['translate'] ); ?> <?php disabled( ! KA_LBI_Lang::translator_available() ); ?>> <?php esc_html_e( 'Ask KA Translate for the other languages as soon as an imported listing is published', 'ka-listing-bulk-importer' ); ?></label>
				<?php if ( ! KA_LBI_Lang::translator_available() ) : ?><p class="ka-lbi-muted ka-lbi-small"><?php esc_html_e( 'KA Translate is not active.', 'ka-listing-bulk-importer' ); ?></p><?php endif; ?>
			<?php else : ?>
				<p class="ka-lbi-muted"><?php esc_html_e( 'Polylang is not active - listings are written in one language.', 'ka-listing-bulk-importer' ); ?></p>
			<?php endif; ?>
			<label class="ka-lbi-check"><input type="checkbox" name="notify" value="1" <?php checked( $ka_s['notify'] ); ?>> <?php esc_html_e( 'E-mail a summary when a background run finishes', 'ka-listing-bulk-importer' ); ?></label>
			<label class="ka-lbi-label" for="ka-lbi-ne"><?php esc_html_e( 'Send to', 'ka-listing-bulk-importer' ); ?></label>
			<input type="email" id="ka-lbi-ne" name="notify_email" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" value="<?php echo esc_attr( $ka_s['notify_email'] ); ?>">
		</div>

		<div class="ka-lbi-card">
			<div class="ka-lbi-card-h"><h2><span class="dashicons dashicons-admin-site-alt3"></span><?php esc_html_e( 'Enrichment defaults', 'ka-listing-bulk-importer' ); ?></h2></div>
			<div class="ka-lbi-checkgrid">
				<label class="ka-lbi-check"><input type="checkbox" name="enrich_email" value="1" <?php checked( $ka_s['enrich_email'] ); ?>> <?php esc_html_e( 'E-mail', 'ka-listing-bulk-importer' ); ?></label>
				<label class="ka-lbi-check"><input type="checkbox" name="enrich_phone" value="1" <?php checked( $ka_s['enrich_phone'] ); ?>> <?php esc_html_e( 'Phone', 'ka-listing-bulk-importer' ); ?></label>
				<label class="ka-lbi-check"><input type="checkbox" name="enrich_socials" value="1" <?php checked( $ka_s['enrich_socials'] ); ?>> <?php esc_html_e( 'Social links', 'ka-listing-bulk-importer' ); ?></label>
				<label class="ka-lbi-check"><input type="checkbox" name="enrich_photo" value="1" <?php checked( $ka_s['enrich_photo'] ); ?>> <?php esc_html_e( 'Photo', 'ka-listing-bulk-importer' ); ?></label>
				<label class="ka-lbi-check"><input type="checkbox" name="enrich_logo" value="1" <?php checked( $ka_s['enrich_logo'] ); ?>> <?php esc_html_e( 'Logo', 'ka-listing-bulk-importer' ); ?></label>
				<label class="ka-lbi-check"><input type="checkbox" name="enrich_hours" value="1" <?php checked( $ka_s['enrich_hours'] ); ?>> <?php esc_html_e( 'Opening hours', 'ka-listing-bulk-importer' ); ?></label>
				<label class="ka-lbi-check"><input type="checkbox" name="enrich_tagline" value="1" <?php checked( $ka_s['enrich_tagline'] ); ?>> <?php esc_html_e( 'Tagline', 'ka-listing-bulk-importer' ); ?></label>
			</div>
			<label class="ka-lbi-label" for="ka-lbi-ed"><?php esc_html_e( 'Check the same listing again after (days)', 'ka-listing-bulk-importer' ); ?></label>
			<input type="number" id="ka-lbi-ed" name="enrich_days" min="0" max="365" class="small-text" value="<?php echo (int) $ka_s['enrich_days']; ?>">
		</div>

		<div class="ka-lbi-card">
			<div class="ka-lbi-card-h"><h2><span class="dashicons dashicons-format-image"></span><?php esc_html_e( 'Photos', 'ka-listing-bulk-importer' ); ?></h2></div>
			<p class="ka-lbi-muted ka-lbi-small"><?php esc_html_e( 'Every photo is downloaded into the Media Library (never hotlinked) and can be shrunk with the server\'s own image library - no external service.', 'ka-listing-bulk-importer' ); ?></p>
			<label class="ka-lbi-check"><input type="checkbox" name="optimize_photos" value="1" <?php checked( '1', get_option( KA_LBI_Settings::OPTION_OPTIMIZE_PHOTOS, '1' ) ); ?>> <?php esc_html_e( 'Resize and convert to WebP', 'ka-listing-bulk-importer' ); ?></label>
			<div class="ka-lbi-cols is-tight">
				<div><label class="ka-lbi-label" for="ka-lbi-ow"><?php esc_html_e( 'Max width (px)', 'ka-listing-bulk-importer' ); ?></label><input type="number" id="ka-lbi-ow" name="optimize_max_width" min="200" max="4000" step="50" class="small-text" value="<?php echo (int) get_option( KA_LBI_Settings::OPTION_OPTIMIZE_MAX_WIDTH, 1600 ); ?>"></div>
				<div><label class="ka-lbi-label" for="ka-lbi-oq"><?php esc_html_e( 'WebP quality', 'ka-listing-bulk-importer' ); ?></label><input type="number" id="ka-lbi-oq" name="optimize_quality" min="1" max="100" class="small-text" value="<?php echo (int) get_option( KA_LBI_Settings::OPTION_OPTIMIZE_QUALITY, 82 ); ?>"></div>
			</div>
		</div>
	</div>

	<div class="ka-lbi-runbar is-static">
		<span class="ka-lbi-muted"><?php esc_html_e( 'New or changed keys are tested when you save.', 'ka-listing-bulk-importer' ); ?></span>
		<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save settings', 'ka-listing-bulk-importer' ); ?></button>
	</div>
</form>
