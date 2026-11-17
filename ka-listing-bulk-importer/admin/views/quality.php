<?php
/**
 * Quality: coverage matrix, missing data, duplicates, tools.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification -- read-only navigation
$ka_tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'coverage';
$ka_field = isset( $_GET['field'] ) ? sanitize_text_field( wp_unslash( $_GET['field'] ) ) : 'photo';
$ka_loc   = isset( $_GET['location'] ) ? absint( $_GET['location'] ) : 0;
$ka_paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
// phpcs:enable
$ka_checks = KA_LBI_Quality::checks();
if ( ! isset( $ka_checks[ $ka_field ] ) ) {
	$ka_field = 'photo';
}
$ka_tabs = array(
	'coverage'   => __( 'Coverage', 'ka-listing-bulk-importer' ),
	'missing'    => __( 'Missing data', 'ka-listing-bulk-importer' ),
	'duplicates' => __( 'Duplicates', 'ka-listing-bulk-importer' ),
	'tools'      => __( 'Tools', 'ka-listing-bulk-importer' ),
);
$ka_why = array( 'google' => __( 'same Google place', 'ka-listing-bulk-importer' ), 'title' => __( 'same name', 'ka-listing-bulk-importer' ), 'phone' => __( 'same phone', 'ka-listing-bulk-importer' ), 'website' => __( 'same website', 'ka-listing-bulk-importer' ) );
?>
<nav class="ka-lbi-subtabs">
	<?php foreach ( $ka_tabs as $ka_k => $ka_label ) : ?>
		<a href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-quality', array( 'tab' => $ka_k ) ) ); ?>" class="<?php echo $ka_k === $ka_tab ? 'is-active' : ''; ?>"><?php echo esc_html( $ka_label ); ?></a>
	<?php endforeach; ?>
</nav>

<?php
/* ------------------------------------------------------------------ coverage */
if ( 'coverage' === $ka_tab ) :
	$ka_cov = KA_LBI_Quality::coverage();
	?>
	<div class="ka-lbi-card">
		<div class="ka-lbi-card-h">
			<h2><span class="dashicons dashicons-grid-view"></span><?php esc_html_e( 'Published listings per city and main category', 'ka-listing-bulk-importer' ); ?></h2>
			<span class="ka-lbi-muted ka-lbi-small"><?php esc_html_e( 'Empty and light cells are gaps - click one to discover businesses for it.', 'ka-listing-bulk-importer' ); ?></span>
		</div>
		<?php if ( ! $ka_cov['cities'] ) : ?>
			<p class="ka-lbi-empty"><?php esc_html_e( 'No published listings with a city yet.', 'ka-listing-bulk-importer' ); ?></p>
		<?php else : ?>
			<div class="ka-lbi-matrix-wrap">
				<table class="ka-lbi-matrix">
					<thead><tr><th></th>
						<?php foreach ( $ka_cov['cats'] as $ka_c ) : ?>
							<th scope="col"><span><?php echo esc_html( html_entity_decode( $ka_c->name, ENT_QUOTES, 'UTF-8' ) ); ?></span></th>
						<?php endforeach; ?>
						<th scope="col"><span><?php esc_html_e( 'Total', 'ka-listing-bulk-importer' ); ?></span></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $ka_cov['cities'] as $ka_lid => $ka_city ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $ka_city->name ); ?></th>
							<?php foreach ( $ka_cov['cats'] as $ka_cid => $ka_c ) :
								$ka_n     = isset( $ka_cov['cells'][ $ka_lid ][ $ka_cid ] ) ? (int) $ka_cov['cells'][ $ka_lid ][ $ka_cid ] : 0;
								$ka_level = $ka_n ? min( 5, (int) ceil( 5 * $ka_n / max( 1, $ka_cov['max'] ) ) ) : 0;
								?>
								<td class="is-l<?php echo (int) $ka_level; ?>"><a href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-discover', array( 'location' => $ka_lid, 'category' => $ka_c->slug ) ) ); ?>" title="<?php echo esc_attr( $ka_city->name . ' / ' . html_entity_decode( $ka_c->name, ENT_QUOTES, 'UTF-8' ) . ': ' . $ka_n ); ?>"><?php echo $ka_n ? (int) $ka_n : '+'; ?></a></td>
							<?php endforeach; ?>
							<td class="is-total"><?php echo (int) $ka_cov['city_total'][ $ka_lid ]; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
	<?php
/* ------------------------------------------------------------------ missing */
elseif ( 'missing' === $ka_tab ) :
	$ka_q = KA_LBI_Quality::missing( $ka_field, $ka_paged, 25, $ka_loc );
	?>
	<div class="ka-lbi-card">
		<div class="ka-lbi-card-h">
			<form method="get" class="ka-lbi-inline-form">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( KA_LBI_Plugin::POST_TYPE ); ?>">
				<input type="hidden" name="page" value="ka-lbi-quality">
				<input type="hidden" name="tab" value="missing">
				<label><?php esc_html_e( 'Missing', 'ka-listing-bulk-importer' ); ?>
					<select name="field" data-ka-lbi-autosubmit><?php foreach ( $ka_checks as $ka_k => $ka_label ) { echo '<option value="' . esc_attr( $ka_k ) . '"' . selected( $ka_field, $ka_k, false ) . '>' . esc_html( $ka_label ) . '</option>'; } ?></select>
				</label>
				<select name="location" data-ka-lbi-autosubmit aria-label="<?php esc_attr_e( 'City', 'ka-listing-bulk-importer' ); ?>">
					<option value="0"><?php esc_html_e( 'All cities', 'ka-listing-bulk-importer' ); ?></option>
					<?php foreach ( KA_LBI_Lang::terms( KA_LBI_Plugin::TAX_LOCATION, '', array( 'orderby' => 'count', 'order' => 'DESC' ) ) as $ka_t ) : ?>
						<option value="<?php echo (int) $ka_t->term_id; ?>" <?php selected( $ka_loc, $ka_t->term_id ); ?>><?php echo esc_html( $ka_t->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<strong><?php
					/* translators: %d: number of listings */
					echo esc_html( sprintf( _n( '%d listing', '%d listings', $ka_q->found_posts, 'ka-listing-bulk-importer' ), $ka_q->found_posts ) );
				?></strong>
			</form>
			<?php if ( $ka_q->found_posts && in_array( $ka_field, array( 'photo', 'phone', 'email', 'website', 'gAddress' ), true ) ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-photos', array( 'missing' => strtolower( $ka_field ), 'location' => $ka_loc ) ) ); ?>"><?php esc_html_e( 'Fill these automatically', 'ka-listing-bulk-importer' ); ?></a>
			<?php endif; ?>
		</div>
		<?php if ( ! $ka_q->have_posts() ) : ?>
			<p class="ka-lbi-empty"><?php esc_html_e( 'Nothing missing here.', 'ka-listing-bulk-importer' ); ?></p>
		<?php else : ?>
			<table class="ka-lbi-table">
				<thead><tr><th><?php esc_html_e( 'Listing', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'City', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Status', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Profile', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Last check', 'ka-listing-bulk-importer' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php
				while ( $ka_q->have_posts() ) :
					$ka_q->the_post();
					$ka_id   = get_the_ID();
					$ka_locs = get_the_terms( $ka_id, KA_LBI_Plugin::TAX_LOCATION );
					$ka_done = (int) get_post_meta( $ka_id, KA_LBI_Enrich::META_DONE, true );
					?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $ka_id, 'raw' ) ); ?>"><?php the_title(); ?></a></td>
						<td><?php echo $ka_locs && ! is_wp_error( $ka_locs ) ? esc_html( $ka_locs[0]->name ) : '—'; ?></td>
						<td><?php echo KA_LBI_Admin::pill( get_post_status_object( get_post_status() )->label, 'publish' === get_post_status() ? 'ok' : 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo KA_LBI_Admin::dots( $ka_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td class="ka-lbi-muted"><?php echo $ka_done ? esc_html( human_time_diff( $ka_done ) ) : '—'; ?></td>
						<td class="ka-lbi-right"><a class="button button-small" href="<?php echo esc_url( KA_LBI_Plugin::action_url( 'ka_lbi_enrich_one', array( 'post' => $ka_id, 'back' => 'ka-lbi-quality' ) ) ); ?>"><?php esc_html_e( 'Enrich now', 'ka-listing-bulk-importer' ); ?></a></td>
					</tr>
				<?php endwhile; wp_reset_postdata(); ?>
				</tbody>
			</table>
			<?php
			if ( $ka_q->max_num_pages > 1 ) {
				echo '<div class="ka-lbi-pager">' . wp_kses_post( paginate_links( array(
					'base'    => add_query_arg( 'paged', '%#%', KA_LBI_Plugin::url( 'ka-lbi-quality', array( 'tab' => 'missing', 'field' => $ka_field, 'location' => $ka_loc ) ) ),
					'format'  => '',
					'current' => $ka_paged,
					'total'   => (int) $ka_q->max_num_pages,
				) ) ) . '</div>';
			}
			?>
		<?php endif; ?>
	</div>
	<?php
/* ------------------------------------------------------------------ duplicates */
elseif ( 'duplicates' === $ka_tab ) :
	$ka_groups = KA_LBI_Quality::duplicate_groups();
	?>
	<?php if ( ! $ka_groups ) : ?>
		<div class="ka-lbi-card ka-lbi-hero is-small"><span class="dashicons dashicons-smiley"></span><h2><?php esc_html_e( 'No duplicates found', 'ka-listing-bulk-importer' ); ?></h2></div>
	<?php endif; ?>
	<?php foreach ( $ka_groups as $ka_g ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ka-lbi-card ka-lbi-dupgroup">
			<?php wp_nonce_field( KA_LBI_Plugin::NONCE ); ?>
			<div class="ka-lbi-card-h">
				<h2><?php echo esc_html( implode( ' · ', array_map( function ( $w ) use ( $ka_why ) {
					return isset( $ka_why[ $w ] ) ? $ka_why[ $w ] : $w;
				}, $ka_g['why'] ) ) ); ?></h2>
				<span class="ka-lbi-muted ka-lbi-small"><?php esc_html_e( 'Pick the listing to keep', 'ka-listing-bulk-importer' ); ?></span>
			</div>
			<div class="ka-lbi-dupcols">
				<?php foreach ( $ka_g['ids'] as $ka_i => $ka_id ) :
					$ka_locs = get_the_terms( $ka_id, KA_LBI_Plugin::TAX_LOCATION );
					$ka_lang = KA_LBI_Lang::post_language( $ka_id );
					?>
					<label class="ka-lbi-dupcard">
						<input type="hidden" name="ids[]" value="<?php echo (int) $ka_id; ?>">
						<input type="radio" name="keep" value="<?php echo (int) $ka_id; ?>" <?php checked( 0, $ka_i ); ?>>
						<span class="ka-lbi-dupcard-b">
							<span class="ka-lbi-qthumb"><?php echo has_post_thumbnail( $ka_id ) ? get_the_post_thumbnail( $ka_id, array( 56, 56 ) ) : '<span class="dashicons dashicons-format-image"></span>'; ?></span>
							<strong><?php echo esc_html( get_the_title( $ka_id ) ); ?></strong>
							<em>#<?php echo (int) $ka_id; ?> · <?php echo esc_html( get_post_status_object( get_post_status( $ka_id ) )->label ); ?><?php echo $ka_lang ? ' · ' . esc_html( strtoupper( $ka_lang ) ) : ''; ?><?php echo $ka_locs && ! is_wp_error( $ka_locs ) ? ' · ' . esc_html( $ka_locs[0]->name ) : ''; ?></em>
							<small><?php echo esc_html( implode( ' · ', array_filter( array( get_post_meta( $ka_id, 'phone', true ), KA_LBI_Store::host_key( get_post_meta( $ka_id, 'website', true ) ), get_post_meta( $ka_id, 'gAddress', true ) ) ) ) ); ?></small>
							<?php echo KA_LBI_Admin::dots( $ka_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<a href="<?php echo esc_url( get_edit_post_link( $ka_id, 'raw' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open', 'ka-listing-bulk-importer' ); ?> ↗</a>
						</span>
					</label>
				<?php endforeach; ?>
			</div>
			<p class="ka-lbi-dupact">
				<button type="submit" name="action" value="ka_lbi_merge" class="button button-primary" data-confirm="<?php esc_attr_e( 'Merge into the selected listing? The others move to the trash (restorable).', 'ka-listing-bulk-importer' ); ?>"><?php esc_html_e( 'Merge into selected', 'ka-listing-bulk-importer' ); ?></button>
				<button type="submit" name="action" value="ka_lbi_dup_ignore" class="button"><?php esc_html_e( 'Different businesses', 'ka-listing-bulk-importer' ); ?></button>
			</p>
		</form>
	<?php endforeach; ?>
	<?php
/* ------------------------------------------------------------------ tools */
else :
	?>
	<div class="ka-lbi-card">
		<div class="ka-lbi-card-h"><h2><span class="dashicons dashicons-admin-tools"></span><?php esc_html_e( 'Repair ListingPro data', 'ka-listing-bulk-importer' ); ?></h2></div>
		<p><?php esc_html_e( 'Listings not created through the theme\'s paid submission form can crash their own page on PHP 8, and their edit screen can show empty address/phone/website fields although the data is saved. This fixes every listing in one pass. Safe to run again; it never replaces real data.', 'ka-listing-bulk-importer' ); ?></p>
		<p><a class="button button-primary" href="<?php echo esc_url( KA_LBI_Plugin::action_url( 'ka_lbi_repair' ) ); ?>"><?php esc_html_e( 'Repair all listings', 'ka-listing-bulk-importer' ); ?></a></p>
	</div>
	<?php
endif;
