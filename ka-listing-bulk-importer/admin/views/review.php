<?php
/**
 * Review queue.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification -- read-only filters
$ka_source   = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
$ka_location = isset( $_GET['location'] ) ? absint( $_GET['location'] ) : 0;
$ka_paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
// phpcs:enable
$ka_q       = KA_LBI_Review::query( $ka_source, $ka_location, $ka_paged );
$ka_why     = array( 'google' => __( 'same Google place', 'ka-listing-bulk-importer' ), 'title' => __( 'same name', 'ka-listing-bulk-importer' ), 'phone' => __( 'same phone', 'ka-listing-bulk-importer' ), 'website' => __( 'same website', 'ka-listing-bulk-importer' ) );
$ka_sources = array(
	''       => __( 'All sources', 'ka-listing-bulk-importer' ),
	'google' => 'Google Maps',
	'ai'     => __( 'AI suggestions', 'ka-listing-bulk-importer' ),
	'csv'    => 'CSV',
	'manual' => __( 'Submitted by users', 'ka-listing-bulk-importer' ),
);
?>
<div class="ka-lbi-card">
	<div class="ka-lbi-card-h">
		<h2><span class="dashicons dashicons-yes-alt"></span><?php
			/* translators: %d: number of listings */
			echo esc_html( sprintf( _n( '%d listing waiting', '%d listings waiting', $ka_q->found_posts, 'ka-listing-bulk-importer' ), $ka_q->found_posts ) );
		?></h2>
		<form method="get" class="ka-lbi-inline-form">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( KA_LBI_Plugin::POST_TYPE ); ?>">
			<input type="hidden" name="page" value="ka-lbi-review">
			<select name="source" data-ka-lbi-autosubmit aria-label="<?php esc_attr_e( 'Source', 'ka-listing-bulk-importer' ); ?>">
				<?php foreach ( $ka_sources as $ka_k => $ka_label ) : ?>
					<option value="<?php echo esc_attr( $ka_k ); ?>" <?php selected( $ka_source, $ka_k ); ?>><?php echo esc_html( $ka_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="location" data-ka-lbi-autosubmit aria-label="<?php esc_attr_e( 'City', 'ka-listing-bulk-importer' ); ?>">
				<option value="0"><?php esc_html_e( 'All cities', 'ka-listing-bulk-importer' ); ?></option>
				<?php foreach ( KA_LBI_Lang::terms( KA_LBI_Plugin::TAX_LOCATION, '', array( 'orderby' => 'count', 'order' => 'DESC' ) ) as $ka_t ) : ?>
					<option value="<?php echo (int) $ka_t->term_id; ?>" <?php selected( $ka_location, $ka_t->term_id ); ?>><?php echo esc_html( $ka_t->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</form>
		<?php if ( current_user_can( KA_LBI_Plugin::SETTINGS_CAP ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ka-lbi-inline-form ka-lbi-rule">
				<?php wp_nonce_field( KA_LBI_Plugin::NONCE ); ?>
				<input type="hidden" name="action" value="ka_lbi_nolinks">
				<label title="<?php esc_attr_e( 'Submitters cannot save links or web addresses in the description; their website goes into the Website field.', 'ka-listing-bulk-importer' ); ?>">
					<input type="checkbox" name="on" value="1" data-ka-lbi-autosubmit <?php checked( KA_LBI_Moderation::links_blocked() ); ?>>
					<?php esc_html_e( 'No links in descriptions', 'ka-listing-bulk-importer' ); ?>
				</label>
			</form>
		<?php endif; ?>
	</div>

	<?php if ( ! $ka_q->have_posts() ) : ?>
		<div class="ka-lbi-hero is-small"><span class="dashicons dashicons-smiley"></span><h2><?php esc_html_e( 'All caught up', 'ka-listing-bulk-importer' ); ?></h2><p><?php esc_html_e( 'No pending listings here.', 'ka-listing-bulk-importer' ); ?></p></div>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ka-lbi-bulkform>
			<?php wp_nonce_field( KA_LBI_Plugin::NONCE ); ?>
			<input type="hidden" name="action" value="ka_lbi_review">
			<input type="hidden" name="f_source" value="<?php echo esc_attr( $ka_source ); ?>">
			<input type="hidden" name="f_location" value="<?php echo (int) $ka_location; ?>">
			<input type="hidden" name="f_paged" value="<?php echo (int) $ka_paged; ?>">
			<input type="hidden" name="one" value="">
			<input type="hidden" name="do" value="" disabled>
			<div class="ka-lbi-bulkbar">
				<label><input type="checkbox" data-ka-lbi-checkall> <?php esc_html_e( 'Select all', 'ka-listing-bulk-importer' ); ?></label>
				<span class="ka-lbi-muted" data-ka-lbi-selcount></span>
				<button type="submit" name="do" value="approve" class="button button-primary" data-needs-selection><?php esc_html_e( 'Publish selected', 'ka-listing-bulk-importer' ); ?></button>
				<button type="submit" name="do" value="enrich" class="button" data-needs-selection><?php esc_html_e( 'Enrich selected', 'ka-listing-bulk-importer' ); ?></button>
				<button type="submit" name="do" value="reject" class="button ka-lbi-danger" data-needs-selection data-confirm="<?php esc_attr_e( 'Move the selected listings to the trash?', 'ka-listing-bulk-importer' ); ?>"><?php esc_html_e( 'Reject selected', 'ka-listing-bulk-importer' ); ?></button>
			</div>
			<div class="ka-lbi-queue">
				<?php
				while ( $ka_q->have_posts() ) :
					$ka_q->the_post();
					$ka_id   = get_the_ID();
					$ka_src  = KA_LBI_Review::source_label( $ka_id );
					$ka_cat  = get_the_terms( $ka_id, KA_LBI_Plugin::TAX_CATEGORY );
					$ka_loc  = get_the_terms( $ka_id, KA_LBI_Plugin::TAX_LOCATION );
					$ka_dups = KA_LBI_Store::find_duplicates( array(
						'post_title' => get_the_title(),
						'phone'      => get_post_meta( $ka_id, 'phone', true ),
						'website'    => get_post_meta( $ka_id, 'website', true ),
						'place_id'   => get_post_meta( $ka_id, '_ka_lbi_place_id', true ),
					), $ka_id );
					$ka_res  = get_post_meta( $ka_id, KA_LBI_Enrich::META_NOTES, true );
					$ka_user = KA_LBI_Moderation::submitter( $ka_id );
					$ka_iss  = KA_LBI_Moderation::issues( $ka_id );
					$ka_log  = KA_LBI_Moderation::log( $ka_id );
					$ka_last = $ka_log ? end( $ka_log ) : null;
					?>
					<div class="ka-lbi-qrow">
						<input type="checkbox" name="ids[]" value="<?php echo (int) $ka_id; ?>" aria-label="<?php echo esc_attr( get_the_title() ); ?>">
						<div class="ka-lbi-qthumb"><?php echo has_post_thumbnail() ? get_the_post_thumbnail( $ka_id, array( 64, 64 ) ) : '<span class="dashicons dashicons-format-image"></span>'; ?></div>
						<div class="ka-lbi-qbody">
							<div class="ka-lbi-qtitle">
								<a href="<?php echo esc_url( get_edit_post_link( $ka_id, 'raw' ) ); ?>"><strong><?php the_title(); ?></strong></a>
								<?php echo KA_LBI_Admin::pill( $ka_src[0], $ka_src[1] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<span class="ka-lbi-muted ka-lbi-small"><?php echo esc_html( KA_LBI_Admin::ago( get_the_date( 'Y-m-d H:i:s' ) ) ); ?></span>
							</div>
							<div class="ka-lbi-qmeta">
								<?php if ( $ka_cat && ! is_wp_error( $ka_cat ) ) : ?><span><span class="dashicons dashicons-category"></span><?php echo esc_html( html_entity_decode( implode( ', ', wp_list_pluck( $ka_cat, 'name' ) ), ENT_QUOTES, 'UTF-8' ) ); ?></span><?php endif; ?>
								<?php if ( $ka_loc && ! is_wp_error( $ka_loc ) ) : ?><span><span class="dashicons dashicons-admin-multisite"></span><?php echo esc_html( implode( ', ', wp_list_pluck( $ka_loc, 'name' ) ) ); ?></span><?php endif; ?>
								<?php if ( $ka_v = get_post_meta( $ka_id, 'gAddress', true ) ) : ?><span><span class="dashicons dashicons-location"></span><?php echo esc_html( $ka_v ); ?></span><?php endif; ?>
								<?php if ( $ka_v = get_post_meta( $ka_id, 'phone', true ) ) : ?><span><span class="dashicons dashicons-phone"></span><?php echo esc_html( $ka_v ); ?></span><?php endif; ?>
								<?php if ( $ka_v = get_post_meta( $ka_id, 'website', true ) ) : ?><span><span class="dashicons dashicons-admin-site-alt3"></span><a href="<?php echo esc_url( $ka_v ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( KA_LBI_Store::host_key( $ka_v ) ? KA_LBI_Store::host_key( $ka_v ) : $ka_v ); ?></a></span><?php endif; ?>
							</div>
							<?php if ( $ka_user ) : ?>
								<div class="ka-lbi-qsubmitter">
									<span class="dashicons dashicons-admin-users"></span>
									<a href="<?php echo esc_url( get_edit_user_link( $ka_user->ID ) ); ?>"><strong><?php echo esc_html( $ka_user->display_name ); ?></strong></a>
									<a href="mailto:<?php echo esc_attr( $ka_user->user_email ); ?>"><?php echo esc_html( $ka_user->user_email ); ?></a>
									<span class="ka-lbi-muted"><?php
										/* translators: 1: time since registration, 2: number of listings */
										echo esc_html( sprintf( __( 'registered %1$s · %2$d listings', 'ka-listing-bulk-importer' ), KA_LBI_Admin::ago( $ka_user->user_registered ), KA_LBI_Moderation::count_listings( $ka_user->ID ) ) );
									?></span>
								</div>
							<?php endif; ?>
							<?php if ( $ka_last ) : ?>
								<div class="ka-lbi-qhistory">
									<span class="dashicons dashicons-backup"></span>
									<?php
									$ka_rs = KA_LBI_Moderation::reasons();
									echo esc_html( sprintf(
										/* translators: 1: date, 2: reasons */
										__( 'Sent back on %1$s: %2$s', 'ka-listing-bulk-importer' ),
										wp_date( get_option( 'date_format' ), $ka_last['time'] ),
										implode( ' · ', array_map( function ( $r ) use ( $ka_rs ) {
											return isset( $ka_rs[ $r ] ) ? wp_trim_words( $ka_rs[ $r ], 6, '…' ) : $r;
										}, $ka_last['reasons'] ) ) . ( '' !== $ka_last['note'] ? ' · "' . wp_trim_words( $ka_last['note'], 10, '…' ) . '"' : '' )
									) );
									if ( count( $ka_log ) > 1 ) {
										/* translators: %d: number of times */
										echo ' <span class="ka-lbi-muted">(' . esc_html( sprintf( __( '%d times', 'ka-listing-bulk-importer' ), count( $ka_log ) ) ) . ')</span>';
									}
									?>
								</div>
							<?php endif; ?>
							<div class="ka-lbi-qflags">
								<?php $ka_il = KA_LBI_Moderation::issue_labels(); foreach ( $ka_iss as $ka_i ) : ?>
									<?php echo KA_LBI_Admin::pill( $ka_il[ $ka_i ], 'bad' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php endforeach; ?>
								<?php echo KA_LBI_Admin::dots( $ka_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php if ( KA_LBI_Providers::is_ai( (string) get_post_meta( $ka_id, '_ka_lbi_source', true ) ) ) : ?>
									<?php echo KA_LBI_Admin::pill( __( 'AI suggestion - verify', 'ka-listing-bulk-importer' ), 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php endif; ?>
								<?php foreach ( array_slice( $ka_dups, 0, 1 ) as $ka_d ) : ?>
									<span class="ka-lbi-pill is-bad"><?php esc_html_e( 'Possible duplicate:', 'ka-listing-bulk-importer' ); ?> <a href="<?php echo esc_url( $ka_d['edit'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( mb_strimwidth( $ka_d['title'], 0, 40, '…' ) ); ?></a> (<?php echo esc_html( implode( ', ', array_map( function ( $w ) use ( $ka_why ) {
										return isset( $ka_why[ $w ] ) ? $ka_why[ $w ] : $w;
									}, $ka_d['why'] ) ) ); ?>)</span>
								<?php endforeach; ?>
								<?php if ( is_array( $ka_res ) && ! empty( $ka_res['filled'] ) ) : ?>
									<?php
									/* translators: %s: filled fields */
									echo KA_LBI_Admin::pill( sprintf( __( 'enriched: %s', 'ka-listing-bulk-importer' ), implode( ', ', $ka_res['filled'] ) ), 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput
									?>
								<?php endif; ?>
							</div>
						</div>
						<div class="ka-lbi-qact">
							<button type="button" class="button button-primary button-small" title="<?php esc_attr_e( 'Publish', 'ka-listing-bulk-importer' ); ?>" data-ka-lbi-one="approve" data-id="<?php echo (int) $ka_id; ?>"><span class="dashicons dashicons-yes"></span></button>
							<a class="button button-small" href="<?php echo esc_url( KA_LBI_Plugin::action_url( 'ka_lbi_enrich_one', array( 'post' => $ka_id, 'back' => 'ka-lbi-review' ) ) ); ?>" title="<?php esc_attr_e( 'Enrich now', 'ka-listing-bulk-importer' ); ?>"><span class="dashicons dashicons-admin-site-alt3"></span></a>
							<a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $ka_id, 'raw' ) ); ?>" title="<?php esc_attr_e( 'Edit', 'ka-listing-bulk-importer' ); ?>"><span class="dashicons dashicons-edit"></span></a>
							<button type="button" class="button button-small ka-lbi-danger" title="<?php esc_attr_e( 'Reject with a reason', 'ka-listing-bulk-importer' ); ?>" data-ka-lbi-reject="<?php echo (int) $ka_id; ?>" data-title="<?php echo esc_attr( get_the_title() ); ?>" data-issues="<?php echo esc_attr( implode( ',', $ka_iss ) ); ?>" data-submitter="<?php echo $ka_user ? esc_attr( $ka_user->display_name . ' <' . $ka_user->user_email . '>' ) : ''; ?>"><span class="dashicons dashicons-dismiss"></span></button>
						</div>
					</div>
				<?php endwhile; wp_reset_postdata(); ?>
			</div>
		</form>
		<template id="ka-lbi-reject-tpl">
			<div class="ka-lbi-modal">
				<form class="ka-lbi-modal-box ka-lbi-reject" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" role="dialog" aria-modal="true" aria-labelledby="ka-lbi-rt">
					<?php wp_nonce_field( KA_LBI_Plugin::NONCE ); ?>
					<input type="hidden" name="action" value="ka_lbi_moderate">
					<input type="hidden" name="post" value="">
					<input type="hidden" name="f_source" value="<?php echo esc_attr( $ka_source ); ?>">
					<input type="hidden" name="f_location" value="<?php echo (int) $ka_location; ?>">
					<input type="hidden" name="f_paged" value="<?php echo (int) $ka_paged; ?>">
					<h3 id="ka-lbi-rt"><?php esc_html_e( 'Reject listing', 'ka-listing-bulk-importer' ); ?></h3>
					<p class="ka-lbi-muted" data-title></p>
					<fieldset class="ka-lbi-reasons">
						<legend><?php esc_html_e( 'Reasons (those found by the automatic check are already ticked)', 'ka-listing-bulk-importer' ); ?></legend>
						<?php foreach ( KA_LBI_Moderation::reasons() as $ka_k => $ka_text ) : ?>
							<label><input type="checkbox" name="reasons[]" value="<?php echo esc_attr( $ka_k ); ?>"> <?php echo esc_html( $ka_text ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<label class="ka-lbi-block"><?php esc_html_e( 'Note to the submitter (optional)', 'ka-listing-bulk-importer' ); ?>
						<textarea name="note" rows="3" dir="auto"></textarea>
					</label>
					<div class="ka-lbi-modes">
						<label><input type="radio" name="mode" value="changes" checked> <strong><?php esc_html_e( 'Send back for changes', 'ka-listing-bulk-importer' ); ?></strong> <span class="ka-lbi-muted"><?php esc_html_e( 'draft; the e-mail contains a link to edit the listing', 'ka-listing-bulk-importer' ); ?></span></label>
						<label><input type="radio" name="mode" value="final"> <strong><?php esc_html_e( 'Reject completely', 'ka-listing-bulk-importer' ); ?></strong> <span class="ka-lbi-muted"><?php esc_html_e( 'moved to the trash', 'ka-listing-bulk-importer' ); ?></span></label>
					</div>
					<label class="ka-lbi-notify"><input type="checkbox" name="notify" value="1" checked> <span data-notify-text></span></label>
					<div class="ka-lbi-modal-act">
						<button type="button" class="button" data-no><?php esc_html_e( 'Cancel', 'ka-listing-bulk-importer' ); ?></button>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Reject', 'ka-listing-bulk-importer' ); ?></button>
					</div>
				</form>
			</div>
		</template>
		<?php
		$ka_pages = (int) $ka_q->max_num_pages;
		if ( $ka_pages > 1 ) {
			echo '<div class="ka-lbi-pager">' . wp_kses_post( paginate_links( array(
				'base'    => add_query_arg( 'paged', '%#%', KA_LBI_Plugin::url( 'ka-lbi-review', array( 'source' => $ka_source, 'location' => $ka_location ) ) ),
				'format'  => '',
				'current' => $ka_paged,
				'total'   => $ka_pages,
			) ) ) . '</div>';
		}
		?>
	<?php endif; ?>
</div>
