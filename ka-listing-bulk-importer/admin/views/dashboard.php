<?php
/**
 * Overview.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ka_stats   = KA_LBI_Quality::stats( isset( $_GET['refresh'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
$ka_total   = max( 1, $ka_stats['total'] );
$ka_review  = KA_LBI_Review::count();
$ka_langs   = KA_LBI_Lang::languages();
$ka_history = KA_LBI_Batch::history();
?>
<div class="ka-lbi-kpis">
	<div class="ka-lbi-kpi">
		<span class="ka-lbi-kpi-i is-blue dashicons dashicons-store"></span>
		<div><strong><?php echo esc_html( number_format_i18n( $ka_stats['total'] ) ); ?></strong><span><?php esc_html_e( 'Listings (source language)', 'ka-listing-bulk-importer' ); ?></span>
		<?php if ( $ka_stats['languages'] ) : ?>
			<em class="ka-lbi-kpi-sub"><?php foreach ( $ka_stats['languages'] as $ka_l => $ka_n ) { echo esc_html( strtoupper( $ka_l ) . ' ' . number_format_i18n( $ka_n ) ) . ' &nbsp; '; } ?></em>
		<?php endif; ?></div>
	</div>
	<a class="ka-lbi-kpi" href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-review' ) ); ?>">
		<span class="ka-lbi-kpi-i is-orange dashicons dashicons-yes-alt"></span>
		<div><strong><?php echo esc_html( number_format_i18n( $ka_review ) ); ?></strong><span><?php esc_html_e( 'Waiting for review', 'ka-listing-bulk-importer' ); ?></span></div>
	</a>
	<div class="ka-lbi-kpi">
		<span class="ka-lbi-kpi-i is-green dashicons dashicons-awards"></span>
		<div><strong><?php echo esc_html( round( 100 * $ka_stats['complete'] / $ka_total ) ); ?>%</strong><span><?php esc_html_e( 'Complete profiles', 'ka-listing-bulk-importer' ); ?></span><em class="ka-lbi-kpi-sub"><?php esc_html_e( 'photo, phone, website, address, map', 'ka-listing-bulk-importer' ); ?></em></div>
	</div>
	<a class="ka-lbi-kpi" href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-quality', array( 'tab' => 'missing', 'field' => 'photo' ) ) ); ?>">
		<span class="ka-lbi-kpi-i is-red dashicons dashicons-format-image"></span>
		<div><strong><?php echo esc_html( number_format_i18n( $ka_stats['total'] - $ka_stats['has']['photo'] ) ); ?></strong><span><?php esc_html_e( 'Without a photo', 'ka-listing-bulk-importer' ); ?></span></div>
	</a>
</div>

<?php KA_LBI_Admin::job_card( 'discover' ); ?>
<?php KA_LBI_Admin::job_card( 'enrich' ); ?>

<div class="ka-lbi-grid">
	<div class="ka-lbi-card">
		<div class="ka-lbi-card-h">
			<h2><span class="dashicons dashicons-chart-bar"></span><?php esc_html_e( 'Data completeness', 'ka-listing-bulk-importer' ); ?></h2>
			<a class="ka-lbi-muted" href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-dashboard', array( 'refresh' => 1 ) ) ); ?>"><?php esc_html_e( 'Refresh', 'ka-listing-bulk-importer' ); ?></a>
		</div>
		<div class="ka-lbi-meters">
			<?php foreach ( KA_LBI_Quality::checks() as $ka_k => $ka_label ) :
				$ka_pct = round( 100 * $ka_stats['has'][ $ka_k ] / $ka_total );
				$ka_tone = $ka_pct >= 80 ? 'good' : ( $ka_pct >= 50 ? 'mid' : 'low' );
				?>
				<div class="ka-lbi-meter">
					<span class="ka-lbi-meter-l"><?php echo esc_html( $ka_label ); ?></span>
					<span class="ka-lbi-meter-b is-<?php echo esc_attr( $ka_tone ); ?>"><span style="width:<?php echo (int) $ka_pct; ?>%"></span></span>
					<span class="ka-lbi-meter-v"><?php echo (int) $ka_pct; ?>%</span>
					<a class="ka-lbi-meter-a" href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-quality', array( 'tab' => 'missing', 'field' => $ka_k ) ) ); ?>"><?php
						/* translators: %s: number of listings */
						echo esc_html( sprintf( __( '%s missing', 'ka-listing-bulk-importer' ), number_format_i18n( $ka_stats['total'] - $ka_stats['has'][ $ka_k ] ) ) );
					?></a>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="ka-lbi-muted ka-lbi-small"><?php
			/* translators: %s: time */
			echo esc_html( sprintf( __( 'Counted %s. KA Translate copies are not counted - they follow their source listing.', 'ka-listing-bulk-importer' ), KA_LBI_Admin::ago( $ka_stats['time'] ) ) );
		?></p>
	</div>

	<div class="ka-lbi-card">
		<div class="ka-lbi-card-h"><h2><span class="dashicons dashicons-admin-links"></span><?php esc_html_e( 'Start something', 'ka-listing-bulk-importer' ); ?></h2></div>
		<div class="ka-lbi-actions">
			<a href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-discover' ) ); ?>"><span class="dashicons dashicons-search"></span><strong><?php esc_html_e( 'Discover businesses', 'ka-listing-bulk-importer' ); ?></strong><em><?php esc_html_e( 'Google Places or AI, by city and category', 'ka-listing-bulk-importer' ); ?></em></a>
			<a href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-photos' ) ); ?>"><span class="dashicons dashicons-admin-site-alt3"></span><strong><?php esc_html_e( 'Enrich listings', 'ka-listing-bulk-importer' ); ?></strong><em><?php esc_html_e( 'E-mail, socials, photos from their websites', 'ka-listing-bulk-importer' ); ?></em></a>
			<a href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-import' ) ); ?>"><span class="dashicons dashicons-upload"></span><strong><?php esc_html_e( 'Import a CSV file', 'ka-listing-bulk-importer' ); ?></strong><em><?php esc_html_e( 'Map columns, preview, import', 'ka-listing-bulk-importer' ); ?></em></a>
			<a href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-quality', array( 'tab' => 'duplicates' ) ) ); ?>"><span class="dashicons dashicons-admin-page"></span><strong><?php esc_html_e( 'Find duplicates', 'ka-listing-bulk-importer' ); ?></strong><em><?php esc_html_e( 'Same phone, website or name', 'ka-listing-bulk-importer' ); ?></em></a>
		</div>
		<h3 class="ka-lbi-h3"><?php esc_html_e( 'Data sources', 'ka-listing-bulk-importer' ); ?></h3>
		<ul class="ka-lbi-sources">
			<?php foreach ( KA_LBI_Providers::all() as $ka_slug => $ka_def ) :
				$ka_st = KA_LBI_Providers::status( $ka_slug );
				if ( '' === KA_LBI_Providers::key( $ka_slug ) ) {
					$ka_pill = KA_LBI_Admin::pill( __( 'no key', 'ka-listing-bulk-importer' ) );
				} elseif ( $ka_st && empty( $ka_st['ok'] ) ) {
					$ka_pill = KA_LBI_Admin::pill( __( 'key not working', 'ka-listing-bulk-importer' ), 'bad' );
				} else {
					$ka_pill = KA_LBI_Admin::pill( __( 'ready', 'ka-listing-bulk-importer' ), 'ok' );
				}
				?>
				<li><span><?php echo esc_html( $ka_def['label'] ); ?></span><?php echo $ka_pill; // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="ka-lbi-muted">$<?php echo esc_html( number_format_i18n( KA_LBI_Providers::spend( $ka_slug ), 2 ) ); ?></span></li>
			<?php endforeach; ?>
		</ul>
		<?php if ( KA_LBI_Lang::active() ) : ?>
			<p class="ka-lbi-muted ka-lbi-small">
				<?php
				/* translators: %s: language name */
				echo esc_html( sprintf( __( 'New listings are written in %s.', 'ka-listing-bulk-importer' ), $ka_langs[ KA_LBI_Lang::import_language() ] ) );
				echo ' ';
				echo KA_LBI_Lang::translator_available() ? esc_html__( 'KA Translate adds the other languages after publishing.', 'ka-listing-bulk-importer' ) : esc_html__( 'KA Translate is not active - other languages are not created automatically.', 'ka-listing-bulk-importer' );
				?>
			</p>
		<?php endif; ?>
	</div>
</div>

<div class="ka-lbi-card">
	<div class="ka-lbi-card-h"><h2><span class="dashicons dashicons-backup"></span><?php esc_html_e( 'Recent imports', 'ka-listing-bulk-importer' ); ?></h2></div>
	<?php if ( ! $ka_history ) : ?>
		<p class="ka-lbi-empty"><?php esc_html_e( 'Nothing imported yet.', 'ka-listing-bulk-importer' ); ?></p>
	<?php else : ?>
		<table class="ka-lbi-table">
			<thead><tr><th><?php esc_html_e( 'When', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Source', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'By', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Created', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Updated', 'ka-listing-bulk-importer' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( array_slice( $ka_history, 0, 12 ) as $ka_b ) :
				$ka_user = ! empty( $ka_b['user'] ) ? get_userdata( $ka_b['user'] ) : null;
				$ka_n    = count( (array) $ka_b['post_ids'] );
				?>
				<tr>
					<td title="<?php echo esc_attr( $ka_b['time'] ); ?>"><?php echo esc_html( KA_LBI_Admin::ago( $ka_b['time'] ) ); ?></td>
					<td><?php echo esc_html( empty( $ka_b['source'] ) ? '—' : ( 'csv' === $ka_b['source'] ? 'CSV' : KA_LBI_Providers::label( $ka_b['source'] ) ) ); ?></td>
					<td><?php echo esc_html( $ka_user ? $ka_user->display_name : __( 'Schedule', 'ka-listing-bulk-importer' ) ); ?></td>
					<td><?php echo (int) $ka_n; ?></td>
					<td><?php echo (int) count( isset( $ka_b['updated'] ) ? (array) $ka_b['updated'] : array() ); ?></td>
					<td class="ka-lbi-right">
						<?php if ( $ka_n ) : ?>
							<a class="button button-small ka-lbi-confirm" data-confirm="<?php esc_attr_e( 'Move all listings created by this import (and their translations) to the trash?', 'ka-listing-bulk-importer' ); ?>" href="<?php echo esc_url( KA_LBI_Plugin::action_url( 'ka_lbi_undo', array( 'batch' => $ka_b['batch_id'] ) ) ); ?>"><?php esc_html_e( 'Undo', 'ka-listing-bulk-importer' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
