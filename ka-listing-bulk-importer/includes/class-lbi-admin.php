<?php
/**
 * Admin screens: one "Bulk Importer" entry under Listings with tabs inside.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Admin {

	/** slug => [label, capability, icon, visible in the WP menu] */
	public static function pages() {
		return array(
			'ka-lbi-dashboard' => array( __( 'Overview', 'ka-listing-bulk-importer' ), KA_LBI_Plugin::CAP, 'dashboard' ),
			'ka-lbi-discover'  => array( __( 'Discover', 'ka-listing-bulk-importer' ), KA_LBI_Plugin::CAP, 'search' ),
			'ka-lbi-review'    => array( __( 'Review', 'ka-listing-bulk-importer' ), KA_LBI_Plugin::CAP, 'yes-alt' ),
			'ka-lbi-import'    => array( __( 'CSV import', 'ka-listing-bulk-importer' ), KA_LBI_Plugin::CAP, 'upload' ),
			'ka-lbi-photos'    => array( __( 'Enrich', 'ka-listing-bulk-importer' ), KA_LBI_Plugin::CAP, 'admin-site-alt3' ),
			'ka-lbi-quality'   => array( __( 'Quality', 'ka-listing-bulk-importer' ), KA_LBI_Plugin::CAP, 'chart-bar' ),
			'ka-lbi-schedules' => array( __( 'Schedules', 'ka-listing-bulk-importer' ), KA_LBI_Plugin::SETTINGS_CAP, 'clock' ),
			'ka-lbi-settings'  => array( __( 'Settings', 'ka-listing-bulk-importer' ), KA_LBI_Plugin::SETTINGS_CAP, 'admin-generic' ),
		);
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );

		$post = array(
			'ka_lbi_upload'          => array( 'KA_LBI_Csv', 'handle_upload' ),
			'ka_lbi_map'             => array( 'KA_LBI_Csv', 'handle_map' ),
			'ka_lbi_template'        => array( 'KA_LBI_Csv', 'handle_template' ),
			'ka_lbi_export_preview'  => array( 'KA_LBI_Csv', 'handle_export_preview' ),
			'ka_lbi_import'          => array( __CLASS__, 'handle_import' ),
			'ka_lbi_cancel_batch'    => array( __CLASS__, 'handle_cancel_batch' ),
			'ka_lbi_undo'            => array( __CLASS__, 'handle_undo' ),
			'ka_lbi_discover'        => array( 'KA_LBI_Discover', 'handle' ),
			'ka_lbi_cancel_job'      => array( __CLASS__, 'handle_cancel_job' ),
			'ka_lbi_enrich'          => array( 'KA_LBI_Enrich', 'handle_start' ),
			'ka_lbi_enrich_one'      => array( 'KA_LBI_Enrich', 'handle_one' ),
			'ka_lbi_review'          => array( 'KA_LBI_Review', 'handle' ),
			'ka_lbi_moderate'        => array( 'KA_LBI_Moderation', 'handle' ),
			'ka_lbi_nolinks'         => array( 'KA_LBI_Moderation', 'handle_toggle' ),
			'ka_lbi_merge'           => array( 'KA_LBI_Quality', 'handle_merge' ),
			'ka_lbi_dup_ignore'      => array( 'KA_LBI_Quality', 'handle_ignore' ),
			'ka_lbi_repair'          => array( __CLASS__, 'handle_repair' ),
			'ka_lbi_save_settings'   => array( __CLASS__, 'handle_settings' ),
			'ka_lbi_test_key'        => array( __CLASS__, 'handle_test_key' ),
			'ka_lbi_reset_spend'     => array( __CLASS__, 'handle_reset_spend' ),
			'ka_lbi_save_schedule'   => array( 'KA_LBI_Schedules', 'handle_save' ),
			'ka_lbi_toggle_schedule' => array( 'KA_LBI_Schedules', 'handle_toggle' ),
			'ka_lbi_delete_schedule' => array( 'KA_LBI_Schedules', 'handle_delete' ),
			'ka_lbi_run_schedule'    => array( 'KA_LBI_Schedules', 'handle_run' ),
		);
		foreach ( $post as $action => $cb ) {
			add_action( 'admin_post_' . $action, $cb );
		}
		add_action( 'wp_ajax_ka_lbi_poll_job', array( 'KA_LBI_Jobs', 'ajax_poll' ) );
		add_action( 'wp_ajax_ka_lbi_coverage', array( 'KA_LBI_Discover', 'ajax_coverage' ) );
	}

	public static function current() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return isset( self::pages()[ $page ] ) ? $page : '';
	}

	public static function menu() {
		$parent = 'edit.php?post_type=' . KA_LBI_Plugin::POST_TYPE;
		$count  = current_user_can( KA_LBI_Plugin::CAP ) ? KA_LBI_Review::count() : 0;
		$bubble = $count ? ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . (int) $count . '</span></span>' : '';
		foreach ( self::pages() as $slug => $p ) {
			$visible = 'ka-lbi-dashboard' === $slug;
			$hook    = add_submenu_page(
				$visible ? $parent : '',
				$p[0] . ' - ' . __( 'Bulk Importer', 'ka-listing-bulk-importer' ),
				$visible ? __( 'Bulk Importer', 'ka-listing-bulk-importer' ) . $bubble : '',
				$p[1],
				$slug,
				array( __CLASS__, 'render' )
			);
			$title = $p[0] . ' - ' . __( 'Bulk Importer', 'ka-listing-bulk-importer' );
			// Hidden pages have no menu title; admin-header.php needs one.
			add_action( 'load-' . $hook, function () use ( $title ) {
				$GLOBALS['title'] = $title;
				// Never cache these screens: a cached page carries an expired nonce.
				if ( ! defined( 'DONOTCACHEPAGE' ) ) {
					define( 'DONOTCACHEPAGE', true );
				}
				nocache_headers();
			} );
		}
	}

	public static function parent_file( $file ) {
		return self::current() ? 'edit.php?post_type=' . KA_LBI_Plugin::POST_TYPE : $file;
	}

	public static function submenu_file( $file ) {
		return self::current() ? 'ka-lbi-dashboard' : $file;
	}

	public static function assets() {
		if ( self::current() ) {
			self::enqueue();
		}
	}

	/** Styles, script and texts of the plugin's screens (also used on the listing list and edit screen). */
	public static function enqueue() {
		wp_enqueue_style( 'ka-lbi-admin', KA_LBI_URL . 'assets/admin.css', array(), KA_LBI_VERSION );
		wp_enqueue_script( 'ka-lbi-admin', KA_LBI_URL . 'assets/admin.js', array(), KA_LBI_VERSION, true );
		wp_localize_script( 'ka-lbi-admin', 'kaLbi', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( KA_LBI_Plugin::NONCE ),
			'i18n'  => array(
				'confirm'      => __( 'Please confirm', 'ka-listing-bulk-importer' ),
				'yes'          => __( 'Yes, continue', 'ka-listing-bulk-importer' ),
				'cancel'       => __( 'Cancel', 'ka-listing-bulk-importer' ),
				'selected'     => __( 'selected', 'ka-listing-bulk-importer' ),
				/* translators: %s: name and e-mail of the submitter */
				'notify_to'    => __( 'Send the reasons by e-mail to %s', 'ka-listing-bulk-importer' ),
				'no_mail'      => __( 'No e-mail: this listing was not submitted by a registered user.', 'ka-listing-bulk-importer' ),
				'all_cats'     => __( 'none selected = all categories', 'ka-listing-bulk-importer' ),
				'searches'     => __( 'searches', 'ka-listing-bulk-importer' ),
				'background'   => __( 'runs in the background (more than 24 searches)', 'ka-listing-bulk-importer' ),
				'preview'      => __( 'ends in a preview', 'ka-listing-bulk-importer' ),
				'about'        => __( 'about', 'ka-listing-bulk-importer' ),
				'existing'     => __( 'listings already in this category', 'ka-listing-bulk-importer' ),
				'recent'       => __( 'searched in the last 30 days', 'ka-listing-bulk-importer' ),
				'done'         => __( 'Finished - reloading…', 'ka-listing-bulk-importer' ),
				'no_city'      => __( 'Pick at least one city.', 'ka-listing-bulk-importer' ),
				'counts'       => array(
					'created'     => __( 'new', 'ka-listing-bulk-importer' ),
					'skipped'     => __( 'skipped', 'ka-listing-bulk-importer' ),
					'empty'       => __( 'empty', 'ka-listing-bulk-importer' ),
					'failed'      => __( 'failed', 'ka-listing-bulk-importer' ),
					'improved'    => __( 'improved', 'ka-listing-bulk-importer' ),
					'fields'      => __( 'fields filled', 'ka-listing-bulk-importer' ),
					'unreachable' => __( 'not reachable', 'ka-listing-bulk-importer' ),
				),
			),
		) );
	}

	/* ------------------------------------------------------------------ rendering */

	public static function render() {
		$page = self::current();
		$p    = self::pages()[ $page ];
		if ( ! current_user_can( $p[1] ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ka-listing-bulk-importer' ), 403 );
		}
		echo '<div class="wrap ka-lbi">';
		self::header( $page );
		self::notice();
		$view = KA_LBI_DIR . 'admin/views/' . substr( $page, 7 ) . '.php';
		if ( file_exists( $view ) ) {
			include $view;
		}
		self::footer();
		echo '</div>';
	}

	private static function header( $active ) {
		$review = KA_LBI_Review::count();
		echo '<div class="ka-lbi-head"><div class="ka-lbi-brand"><span class="ka-lbi-logo" aria-hidden="true"><span class="dashicons dashicons-location-alt"></span></span><div><h1>' . esc_html__( 'Listing Bulk Importer', 'ka-listing-bulk-importer' ) . '</h1><span class="ka-lbi-sub">' . esc_html( get_bloginfo( 'name' ) ) . '</span></div></div>';
		$jobs = array_filter( array_map( array( 'KA_LBI_Jobs', 'get' ), KA_LBI_Jobs::types() ) );
		if ( $jobs ) {
			echo '<span class="ka-lbi-live"><span class="ka-lbi-dot"></span>' . esc_html( _n( 'Background run active', 'Background runs active', count( $jobs ), 'ka-listing-bulk-importer' ) ) . '</span>';
		}
		// WordPress moves other plugins' notices below this marker instead of into our header.
		echo '</div><hr class="wp-header-end"><nav class="ka-lbi-tabs" aria-label="' . esc_attr__( 'Bulk Importer sections', 'ka-listing-bulk-importer' ) . '">';
		foreach ( self::pages() as $slug => $p ) {
			if ( ! current_user_can( $p[1] ) ) {
				continue;
			}
			$extra = 'ka-lbi-review' === $slug && $review ? ' <span class="ka-lbi-count">' . (int) $review . '</span>' : '';
			printf(
				'<a href="%s" class="%s"%s><span class="dashicons dashicons-%s" aria-hidden="true"></span>%s%s</a>',
				esc_url( KA_LBI_Plugin::url( $slug ) ),
				$slug === $active ? 'is-active' : '',
				$slug === $active ? ' aria-current="page"' : '',
				esc_attr( $p[2] ),
				esc_html( $p[0] ),
				$extra // phpcs:ignore WordPress.Security.EscapeOutput
			);
		}
		echo '</nav>';
	}

	private static function notice() {
		$key = 'ka_lbi_notice_' . get_current_user_id();
		$n   = get_transient( $key );
		if ( ! is_array( $n ) ) {
			return;
		}
		delete_transient( $key );
		$type = in_array( $n['type'], array( 'success', 'error', 'info', 'warning' ), true ) ? $n['type'] : 'info';
		echo '<div class="ka-lbi-notice is-' . esc_attr( $type ) . '" role="' . ( 'error' === $type ? 'alert' : 'status' ) . '"><span class="dashicons dashicons-' . esc_attr( 'error' === $type ? 'warning' : ( 'success' === $type ? 'yes-alt' : 'info' ) ) . '"></span><p>' . esc_html( $n['text'] ) . '</p></div>';
	}

	private static function footer() {
		printf(
			'<footer class="ka-lbi-footer"><div class="ka-lbi-footer-in"><strong>KA Listing Bulk Importer</strong> <span class="ka-lbi-ver">v%1$s</span><span class="ka-lbi-sep">·</span>%2$s <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a> · <a href="%3$s" target="_blank" rel="noopener noreferrer">adschi.com</a></div></footer>',
			esc_html( KA_LBI_VERSION ),
			esc_html__( 'Developed by', 'ka-listing-bulk-importer' ),
			esc_url( KA_LBI_Plugin::AUTHOR_URL ),
			esc_html( KA_LBI_Plugin::AUTHOR_NAME )
		);
	}

	/* ------------------------------------------------------------------ view helpers */

	public static function pill( $text, $tone = 'muted' ) {
		return '<span class="ka-lbi-pill is-' . esc_attr( $tone ) . '">' . esc_html( $text ) . '</span>';
	}

	/** Completeness dots for a listing. */
	public static function dots( $post_id ) {
		$labels = KA_LBI_Quality::checks();
		$out    = '<span class="ka-lbi-dots">';
		foreach ( KA_LBI_Quality::flags( $post_id ) as $k => $ok ) {
			$out .= '<span class="' . ( $ok ? 'on' : 'off' ) . '" title="' . esc_attr( $labels[ $k ] . ': ' . ( $ok ? __( 'yes', 'ka-listing-bulk-importer' ) : __( 'missing', 'ka-listing-bulk-importer' ) ) ) . '"></span>';
		}
		return $out . '</span>';
	}

	public static function ago( $mysql ) {
		$t = strtotime( (string) $mysql );
		if ( ! $t ) {
			return '';
		}
		/* translators: %s: human time difference */
		return sprintf( __( '%s ago', 'ka-listing-bulk-importer' ), human_time_diff( $t, current_time( 'timestamp' ) ) );
	}

	/** Live progress card for a running job, or the summary of the last finished one. */
	public static function job_card( $type ) {
		$job = KA_LBI_Jobs::get( $type );
		if ( ! $job ) {
			$last = KA_LBI_Jobs::last( $type );
			if ( $last && strtotime( $last['time'] ) > current_time( 'timestamp' ) - 3 * DAY_IN_SECONDS ) {
				echo '<div class="ka-lbi-card ka-lbi-last"><div class="ka-lbi-card-h"><h2><span class="dashicons dashicons-flag"></span>' . esc_html__( 'Last background run', 'ka-listing-bulk-importer' ) . '</h2><span class="ka-lbi-muted">' . esc_html( self::ago( $last['time'] ) ) . '</span></div>';
				echo '<p><strong>' . esc_html( $last['label'] ) . '</strong></p><p>' . esc_html( KA_LBI_Jobs::describe( $last ) ) . '</p>';
				if ( $last['cancelled'] ) {
					echo '<p class="ka-lbi-warn-text">' . esc_html( $last['cancelled'] ) . '</p>';
				}
				if ( $last['last_error'] ) {
					/* translators: %s: error */
					echo '<p class="ka-lbi-muted">' . esc_html( sprintf( __( 'Last error: %s', 'ka-listing-bulk-importer' ), $last['last_error'] ) ) . '</p>';
				}
				echo '</div>';
			}
			return;
		}
		$p = KA_LBI_Jobs::payload( $job );
		?>
		<div class="ka-lbi-card ka-lbi-job" data-ka-lbi-job="<?php echo esc_attr( $type ); ?>">
			<div class="ka-lbi-card-h">
				<h2><span class="dashicons dashicons-update ka-lbi-spin"></span><?php echo 'discover' === $type ? esc_html__( 'Background search running', 'ka-listing-bulk-importer' ) : esc_html__( 'Enrichment running', 'ka-listing-bulk-importer' ); ?></h2>
				<a class="button ka-lbi-confirm" data-confirm="<?php esc_attr_e( 'Stop this run? Everything already added stays.', 'ka-listing-bulk-importer' ); ?>" href="<?php echo esc_url( KA_LBI_Plugin::action_url( 'ka_lbi_cancel_job', array( 'type' => $type ) ) ); ?>"><?php esc_html_e( 'Stop', 'ka-listing-bulk-importer' ); ?></a>
			</div>
			<p class="ka-lbi-job-label"><?php echo esc_html( $p['label'] ); ?></p>
			<div class="ka-lbi-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $p['pct']; ?>"><span style="width:<?php echo (int) $p['pct']; ?>%"></span></div>
			<p class="ka-lbi-job-nums"><strong data-k="done"><?php echo (int) $p['done']; ?></strong> / <span data-k="total"><?php echo (int) $p['total']; ?></span> · <span data-k="pct"><?php echo (int) $p['pct']; ?></span>%
				<span class="ka-lbi-job-counts" data-k="counts"></span></p>
			<ul class="ka-lbi-job-log" data-k="messages"><?php foreach ( $p['messages'] as $m ) { echo '<li>' . esc_html( $m ) . '</li>'; } ?></ul>
			<p class="ka-lbi-muted" data-k="error"><?php echo esc_html( $p['last_error'] ); ?></p>
			<p class="ka-lbi-muted ka-lbi-small"><?php esc_html_e( 'Updates live. It also continues in the background when this page is closed; a summary is e-mailed at the end.', 'ka-listing-bulk-importer' ); ?></p>
		</div>
		<?php
	}

	/** Category checkboxes as searchable chips. */
	public static function category_picker( $name, array $selected, $lang ) {
		$terms = KA_LBI_Lang::terms( KA_LBI_Plugin::TAX_CATEGORY, $lang );
		$tree  = array();
		foreach ( $terms as $t ) {
			$tree[ $t->parent ][] = $t;
		}
		echo '<div class="ka-lbi-picker" data-ka-lbi-picker>';
		echo '<div class="ka-lbi-picker-bar"><input type="search" class="ka-lbi-picker-filter" placeholder="' . esc_attr__( 'Filter categories…', 'ka-listing-bulk-importer' ) . '" aria-label="' . esc_attr__( 'Filter categories', 'ka-listing-bulk-importer' ) . '"><button type="button" class="button-link" data-pick="all">' . esc_html__( 'Select all', 'ka-listing-bulk-importer' ) . '</button><button type="button" class="button-link" data-pick="none">' . esc_html__( 'Clear', 'ka-listing-bulk-importer' ) . '</button><span class="ka-lbi-picker-count"></span></div>';
		echo '<div class="ka-lbi-chips">';
		$walk = function ( $parent, $depth ) use ( &$walk, $tree, $name, $selected ) {
			foreach ( isset( $tree[ $parent ] ) ? $tree[ $parent ] : array() as $t ) {
				printf(
					'<label class="ka-lbi-chip%5$s" data-slug="%1$s" data-name="%2$s"><input type="checkbox" name="%3$s[]" value="%1$s"%4$s><span>%6$s</span><em class="ka-lbi-chip-n"></em></label>',
					esc_attr( $t->slug ),
					esc_attr( KA_LBI_Lang::norm( $t->name ) ),
					esc_attr( $name ),
					checked( in_array( $t->slug, $selected, true ), true, false ),
					$depth ? ' is-child' : ' is-parent',
					esc_html( html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) )
				);
				$walk( $t->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		echo '</div></div>';
	}

	/** Language select (only with Polylang). */
	public static function language_select( $name, $current, $auto_submit = false ) {
		$langs = KA_LBI_Lang::languages();
		if ( count( $langs ) < 2 ) {
			return;
		}
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( 'ka-lbi-' . $name ) . '"' . ( $auto_submit ? ' data-ka-lbi-autosubmit' : '' ) . '>';
		foreach ( $langs as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $current, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/* ------------------------------------------------------------------ handlers */

	public static function handle_import() {
		KA_LBI_Plugin::guard();
		$actions = isset( $_POST['ka_lbi_action'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['ka_lbi_action'] ) ) : array();
		$edits   = isset( $_POST['ka_lbi_edit'] ) ? (array) wp_unslash( $_POST['ka_lbi_edit'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field in KA_LBI_Batch::import()
		$status  = isset( $_POST['ka_lbi_status'] ) ? sanitize_key( wp_unslash( $_POST['ka_lbi_status'] ) ) : 'pending';
		if ( 'publish' === $status && ! current_user_can( 'publish_posts' ) ) {
			$status = 'pending';
		}
		$out = KA_LBI_Batch::import( $actions, $edits, $status );
		if ( null === $out ) {
			KA_LBI_Plugin::fail( __( 'The preview has expired. Please run the search or upload again.', 'ka-listing-bulk-importer' ), 'ka-lbi-import' );
		}
		KA_LBI_Quality::forget();
		wp_safe_redirect( KA_LBI_Plugin::url( 'ka-lbi-import', array( 'step' => 'done' ) ) );
		exit;
	}

	public static function handle_cancel_batch() {
		KA_LBI_Plugin::guard();
		KA_LBI_Batch::clear();
		KA_LBI_Plugin::back( isset( $_GET['to'] ) && 'discover' === $_GET['to'] ? 'ka-lbi-discover' : 'ka-lbi-import' );
	}

	public static function handle_undo() {
		KA_LBI_Plugin::guard();
		$n = KA_LBI_Batch::undo( isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : '' );
		KA_LBI_Quality::forget();
		/* translators: %d: number of listings */
		KA_LBI_Plugin::back( 'ka-lbi-dashboard', sprintf( _n( '%d listing moved to the trash.', '%d listings moved to the trash.', $n, 'ka-listing-bulk-importer' ), $n ) );
	}

	public static function handle_cancel_job() {
		KA_LBI_Plugin::guard();
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		if ( in_array( $type, KA_LBI_Jobs::types(), true ) ) {
			KA_LBI_Jobs::cancel( $type );
		}
		KA_LBI_Plugin::back( 'enrich' === $type ? 'ka-lbi-photos' : 'ka-lbi-discover', __( 'Stopped.', 'ka-listing-bulk-importer' ) );
	}

	public static function handle_repair() {
		KA_LBI_Plugin::guard();
		$r = KA_LBI_Store::repair_all();
		/* translators: 1: listings checked, 2: crash fixes, 3: synced edit screens */
		KA_LBI_Plugin::back( 'ka-lbi-quality', sprintf( __( 'Checked %1$d listings: %2$d page-crash fixes, %3$d edit screens re-synced.', 'ka-listing-bulk-importer' ), $r['total'], $r['fixed'], $r['synced'] ), 'success', array( 'tab' => 'tools' ) );
	}

	public static function handle_settings() {
		KA_LBI_Plugin::guard( KA_LBI_Plugin::SETTINGS_CAP );
		KA_LBI_Settings::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized key by key in save()
		KA_LBI_Providers::save(
			isset( $_POST['key'] ) ? (array) wp_unslash( $_POST['key'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			isset( $_POST['model'] ) ? (array) wp_unslash( $_POST['model'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			isset( $_POST['cost'] ) ? (array) wp_unslash( $_POST['cost'] ) : array() // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		);
		KA_LBI_Plugin::back( 'ka-lbi-settings', __( 'Settings saved.', 'ka-listing-bulk-importer' ) );
	}

	public static function handle_test_key() {
		KA_LBI_Plugin::guard( KA_LBI_Plugin::SETTINGS_CAP );
		$slug = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '';
		if ( ! KA_LBI_Providers::exists( $slug ) || '' === KA_LBI_Providers::key( $slug ) ) {
			KA_LBI_Plugin::fail( __( 'Save a key first.', 'ka-listing-bulk-importer' ), 'ka-lbi-settings' );
		}
		$r = KA_LBI_Providers::test( $slug );
		KA_LBI_Plugin::back( 'ka-lbi-settings', KA_LBI_Providers::label( $slug ) . ': ' . $r['message'], $r['ok'] ? 'success' : 'error' );
	}

	public static function handle_reset_spend() {
		KA_LBI_Plugin::guard( KA_LBI_Plugin::SETTINGS_CAP );
		$slug = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '';
		if ( KA_LBI_Providers::exists( $slug ) ) {
			delete_option( KA_LBI_Providers::OPTION_SPEND . $slug );
		}
		KA_LBI_Plugin::back( 'ka-lbi-settings', __( 'Counter reset.', 'ka-listing-bulk-importer' ) );
	}
}
