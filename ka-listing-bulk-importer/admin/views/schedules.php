<?php
/**
 * Scheduled Discover searches.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ka_list = KA_LBI_Schedules::all();
$ka_edit = isset( $_GET['edit'] ) ? KA_LBI_Schedules::find( sanitize_text_field( wp_unslash( $_GET['edit'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
$ka_lang = KA_LBI_Lang::import_language( $ka_edit ? $ka_edit['lang'] : '' );
$ka_log  = array_slice( KA_LBI_Schedules::log(), 0, 25 );
$ka_stat = array(
	'ran'              => array( __( 'Started', 'ka-listing-bulk-importer' ), 'ok' ),
	'error'            => array( __( 'Error', 'ka-listing-bulk-importer' ), 'bad' ),
	'skipped_not_due'  => array( __( 'Not due', 'ka-listing-bulk-importer' ), 'muted' ),
	'skipped_disabled' => array( __( 'Paused', 'ka-listing-bulk-importer' ), 'muted' ),
	'skipped_busy'     => array( __( 'Waiting', 'ka-listing-bulk-importer' ), 'warn' ),
);
$ka_next = wp_next_scheduled( KA_LBI_Plugin::CRON_HOOK );
?>
<div class="ka-lbi-card">
	<div class="ka-lbi-card-h">
		<h2><span class="dashicons dashicons-clock"></span><?php esc_html_e( 'Scheduled searches', 'ka-listing-bulk-importer' ); ?></h2>
		<?php if ( $ka_next ) : ?>
			<span class="ka-lbi-muted ka-lbi-small"><?php
				/* translators: %s: date and time */
				echo esc_html( sprintf( __( 'Next check: %s', 'ka-listing-bulk-importer' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ka_next ) ) );
			?></span>
		<?php endif; ?>
	</div>
	<p class="ka-lbi-lead"><?php esc_html_e( 'A due schedule starts a background search. Clearly new businesses are added as Pending for the review queue; existing or unclear results are skipped. You get an e-mail summary.', 'ka-listing-bulk-importer' ); ?></p>
	<?php if ( ! $ka_list ) : ?>
		<p class="ka-lbi-empty"><?php esc_html_e( 'No schedules yet.', 'ka-listing-bulk-importer' ); ?></p>
	<?php else : ?>
		<table class="ka-lbi-table">
			<thead><tr><th><?php esc_html_e( 'City', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Categories', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Source', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Every', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Last run', 'ka-listing-bulk-importer' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $ka_list as $ka_s ) : ?>
				<tr class="<?php echo empty( $ka_s['enabled'] ) ? 'is-paused' : ''; ?>">
					<td><strong><?php echo esc_html( $ka_s['location'] ); ?></strong><?php echo empty( $ka_s['enabled'] ) ? ' ' . KA_LBI_Admin::pill( __( 'paused', 'ka-listing-bulk-importer' ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td><?php echo esc_html( mb_strimwidth( html_entity_decode( KA_LBI_Schedules::categories_label( $ka_s ), ENT_QUOTES, 'UTF-8' ), 0, 60, '…' ) ); ?></td>
					<td><?php echo esc_html( KA_LBI_Providers::label( $ka_s['provider'] ) ); ?> · <?php echo (int) $ka_s['max']; ?></td>
					<td><?php echo 'daily' === $ka_s['freq'] ? esc_html__( 'day', 'ka-listing-bulk-importer' ) : esc_html__( 'week', 'ka-listing-bulk-importer' ); ?></td>
					<td><?php echo $ka_s['last_run'] ? esc_html( KA_LBI_Admin::ago( $ka_s['last_run'] ) ) : '—'; ?></td>
					<td class="ka-lbi-right ka-lbi-nowrap">
						<a class="button button-small" href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-schedules', array( 'edit' => $ka_s['id'] ) ) ); ?>#ka-lbi-sch-form"><?php esc_html_e( 'Edit', 'ka-listing-bulk-importer' ); ?></a>
						<a class="button button-small" href="<?php echo esc_url( KA_LBI_Plugin::action_url( 'ka_lbi_run_schedule', array( 'id' => $ka_s['id'] ) ) ); ?>"><?php esc_html_e( 'Run now', 'ka-listing-bulk-importer' ); ?></a>
						<a class="button button-small" href="<?php echo esc_url( KA_LBI_Plugin::action_url( 'ka_lbi_toggle_schedule', array( 'id' => $ka_s['id'] ) ) ); ?>"><?php echo empty( $ka_s['enabled'] ) ? esc_html__( 'Resume', 'ka-listing-bulk-importer' ) : esc_html__( 'Pause', 'ka-listing-bulk-importer' ); ?></a>
						<a class="button button-small ka-lbi-danger ka-lbi-confirm" data-confirm="<?php esc_attr_e( 'Remove this schedule?', 'ka-listing-bulk-importer' ); ?>" href="<?php echo esc_url( KA_LBI_Plugin::action_url( 'ka_lbi_delete_schedule', array( 'id' => $ka_s['id'] ) ) ); ?>"><span class="dashicons dashicons-trash"></span></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ka-lbi-card" id="ka-lbi-sch-form">
	<?php wp_nonce_field( KA_LBI_Plugin::NONCE ); ?>
	<input type="hidden" name="action" value="ka_lbi_save_schedule">
	<input type="hidden" name="lang" value="<?php echo esc_attr( $ka_lang ); ?>">
	<?php if ( $ka_edit ) : ?><input type="hidden" name="id" value="<?php echo esc_attr( $ka_edit['id'] ); ?>"><?php endif; ?>
	<div class="ka-lbi-card-h"><h2><span class="dashicons dashicons-<?php echo $ka_edit ? 'edit' : 'plus-alt'; ?>"></span><?php echo $ka_edit ? esc_html__( 'Edit schedule', 'ka-listing-bulk-importer' ) : esc_html__( 'New schedule', 'ka-listing-bulk-importer' ); ?></h2></div>
	<div class="ka-lbi-cols">
		<div>
			<label class="ka-lbi-label" for="ka-lbi-sch-loc"><?php esc_html_e( 'City', 'ka-listing-bulk-importer' ); ?></label>
			<input type="text" id="ka-lbi-sch-loc" name="location" list="ka-lbi-sch-locs" class="regular-text" required value="<?php echo esc_attr( $ka_edit ? $ka_edit['location'] : '' ); ?>">
			<datalist id="ka-lbi-sch-locs"><?php foreach ( KA_LBI_Lang::terms( KA_LBI_Plugin::TAX_LOCATION, $ka_lang ) as $ka_t ) { echo '<option value="' . esc_attr( $ka_t->name ) . '">'; } ?></datalist>
			<label class="ka-lbi-label" for="ka-lbi-sch-prov"><?php esc_html_e( 'Source', 'ka-listing-bulk-importer' ); ?></label>
			<select id="ka-lbi-sch-prov" name="provider"><?php foreach ( KA_LBI_Providers::all() as $ka_slug => $ka_def ) { echo '<option value="' . esc_attr( $ka_slug ) . '"' . selected( $ka_edit ? $ka_edit['provider'] : 'google', $ka_slug, false ) . '>' . esc_html( $ka_def['label'] ) . '</option>'; } ?></select>
			<div class="ka-lbi-cols is-tight">
				<div>
					<label class="ka-lbi-label" for="ka-lbi-sch-max"><?php esc_html_e( 'Results per category', 'ka-listing-bulk-importer' ); ?></label>
					<input type="number" id="ka-lbi-sch-max" name="max" min="1" max="<?php echo (int) KA_LBI_Discover::MAX_RESULTS; ?>" class="small-text" value="<?php echo (int) ( $ka_edit ? $ka_edit['max'] : 15 ); ?>">
				</div>
				<div>
					<label class="ka-lbi-label" for="ka-lbi-sch-freq"><?php esc_html_e( 'Frequency', 'ka-listing-bulk-importer' ); ?></label>
					<select id="ka-lbi-sch-freq" name="freq">
						<option value="weekly"><?php esc_html_e( 'Weekly', 'ka-listing-bulk-importer' ); ?></option>
						<option value="daily" <?php selected( $ka_edit ? $ka_edit['freq'] : '', 'daily' ); ?>><?php esc_html_e( 'Daily', 'ka-listing-bulk-importer' ); ?></option>
					</select>
				</div>
			</div>
			<label class="ka-lbi-check"><input type="checkbox" name="enabled" value="1" <?php checked( ! $ka_edit || ! empty( $ka_edit['enabled'] ) ); ?>> <?php esc_html_e( 'Active', 'ka-listing-bulk-importer' ); ?></label>
		</div>
		<div>
			<span class="ka-lbi-label"><?php esc_html_e( 'Categories (none = all)', 'ka-listing-bulk-importer' ); ?></span>
			<?php KA_LBI_Admin::category_picker( 'categories', $ka_edit ? $ka_edit['categories'] : array(), $ka_lang ); ?>
		</div>
	</div>
	<p>
		<button type="submit" class="button button-primary"><?php echo $ka_edit ? esc_html__( 'Save schedule', 'ka-listing-bulk-importer' ) : esc_html__( 'Add schedule', 'ka-listing-bulk-importer' ); ?></button>
		<?php if ( $ka_edit ) : ?><a class="button" href="<?php echo esc_url( KA_LBI_Plugin::url( 'ka-lbi-schedules' ) ); ?>"><?php esc_html_e( 'Cancel', 'ka-listing-bulk-importer' ); ?></a><?php endif; ?>
	</p>
</form>

<?php if ( $ka_log ) : ?>
	<div class="ka-lbi-card">
		<div class="ka-lbi-card-h"><h2><span class="dashicons dashicons-media-text"></span><?php esc_html_e( 'Run log', 'ka-listing-bulk-importer' ); ?></h2></div>
		<table class="ka-lbi-table">
			<thead><tr><th><?php esc_html_e( 'When', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Schedule', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Outcome', 'ka-listing-bulk-importer' ); ?></th><th><?php esc_html_e( 'Details', 'ka-listing-bulk-importer' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $ka_log as $ka_e ) :
				$ka_p = isset( $ka_stat[ $ka_e['status'] ] ) ? $ka_stat[ $ka_e['status'] ] : array( $ka_e['status'], 'muted' );
				?>
				<tr>
					<td><?php echo esc_html( KA_LBI_Admin::ago( $ka_e['time'] ) ); ?></td>
					<td><?php echo esc_html( $ka_e['location'] . ' · ' . $ka_e['provider'] ); ?></td>
					<td><?php echo KA_LBI_Admin::pill( $ka_p[0], $ka_p[1] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td class="ka-lbi-muted"><?php
					if ( $ka_e['message'] ) {
						echo esc_html( $ka_e['message'] );
					} elseif ( 'ran' === $ka_e['status'] ) {
						/* translators: %d: number of searches */
						echo esc_html( sprintf( __( '%d searches queued', 'ka-listing-bulk-importer' ), (int) $ka_e['found'] ) );
					}
					?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
