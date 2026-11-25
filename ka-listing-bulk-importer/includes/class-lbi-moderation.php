<?php
/**
 * Moderation of listings submitted by users: automatic checks for the review queue, rejecting with reasons
 * (back to the submitter for changes, or completely) with an e-mail in the submitter's language, a history per
 * listing, and the rule "no links in the description" (form hint + check in the browser, removed on save).
 *
 * @package KA_Listing_Bulk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Moderation {

	const META_LOG    = '_ka_lbi_moderation';
	const MIN_WORDS   = 60;
	const OPT_NOLINKS = 'ka_lbi_no_links';

	/** Links: http(s)://, www. and bare domains with a common ending. */
	const LINK_RE = '~(?:https?://|www\.)[^\s<>"]+|\b[a-z0-9][a-z0-9-]*\.(?:de|com|net|org|info|eu|io|ir|at|ch|co|me|app|online|shop)\b(?:/[^\s<>"]*)?~iu';

	/** Addresses in other countries (Hamsaye lists businesses in Germany). */
	const FOREIGN_RE = '/t(ü|u)rk(iye|ey)|i̇?stanbul|ankara|izmir|antalya|\biran\b|tehran|ایران|تهران|ترکیه|استانبول|dubai|emirates|österreich|austria|wien|vienna|schweiz|switzerland|zürich|netherlands|nederland|london|united kingdom|canada|usa\b/iu';

	public static function init() {
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'strip_links' ), 20, 2 );
		add_action( 'wp_footer', array( __CLASS__, 'form_script' ), 50 );
	}

	/** Reasons an admin can give (key => text shown to the admin and in the e-mail). */
	public static function reasons() {
		return array(
			'no_photo'  => __( 'No photo or logo was added.', 'ka-listing-bulk-importer' ),
			'thin'      => __( 'The description is too short - please describe your services, experience and what makes you special.', 'ka-listing-bulk-importer' ),
			'quality'   => __( 'The text is not clear enough (spelling, language or formatting).', 'ka-listing-bulk-importer' ),
			'links'     => __( 'The description contains links or advertising - please enter your website only in the "Website" field.', 'ka-listing-bulk-importer' ),
			'contact'   => __( 'Contact details are missing or not correct (phone, address or website).', 'ka-listing-bulk-importer' ),
			'category'  => __( 'The category or city does not fit the business.', 'ka-listing-bulk-importer' ),
			'location'  => __( 'Hamsaye only lists businesses and services in Germany.', 'ka-listing-bulk-importer' ),
			'duplicate' => __( 'This business is already listed on Hamsaye.', 'ka-listing-bulk-importer' ),
			'other'     => __( 'Other reason (see the note below).', 'ka-listing-bulk-importer' ),
		);
	}

	/** Short labels for the review queue. */
	public static function issue_labels() {
		return array(
			'no_photo' => __( 'no photo', 'ka-listing-bulk-importer' ),
			'thin'     => __( 'short description', 'ka-listing-bulk-importer' ),
			'links'    => __( 'links in the description', 'ka-listing-bulk-importer' ),
			'contact'  => __( 'contact details missing', 'ka-listing-bulk-importer' ),
			'location' => __( 'address outside Germany', 'ka-listing-bulk-importer' ),
		);
	}

	/** ListingPro option of a listing (falls back to the plain meta the importer writes). */
	public static function option( $post_id, $key ) {
		$opts = get_post_meta( $post_id, 'lp_listingpro_options', true );
		$v    = is_array( $opts ) && isset( $opts[ $key ] ) ? $opts[ $key ] : '';
		return '' !== $v && null !== $v ? $v : get_post_meta( $post_id, $key, true );
	}

	public static function word_count( $html ) {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( (string) $html ) ) ) );
		return '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) );
	}

	/** Automatic checks of a listing: list of issue keys (see issue_labels()). */
	public static function issues( $post_id ) {
		$issues  = array();
		$content = (string) get_post_field( 'post_content', $post_id );
		$photos  = has_post_thumbnail( $post_id ) || self::option( $post_id, 'business_logo' ) || get_attached_media( 'image', $post_id );
		if ( ! $photos ) {
			$issues[] = 'no_photo';
		}
		if ( self::word_count( preg_replace( self::LINK_RE, '', $content ) ) < self::MIN_WORDS ) {
			$issues[] = 'thin';
		}
		if ( preg_match( self::LINK_RE, wp_strip_all_tags( $content ) ) || false !== stripos( $content, '<a ' ) ) {
			$issues[] = 'links';
		}
		if ( ! self::option( $post_id, 'phone' ) && ! self::option( $post_id, 'website' ) && ! self::option( $post_id, 'email' ) ) {
			$issues[] = 'contact';
		}
		$address = (string) self::option( $post_id, 'gAddress' );
		if ( '' !== $address && ! preg_match( '/germany|deutschland|آلمان/iu', $address ) && preg_match( self::FOREIGN_RE, $address ) ) {
			$issues[] = 'location';
		}
		return $issues;
	}

	/** The submitting user, if the listing was submitted by someone who is not an editor. */
	public static function submitter( $post_id ) {
		$user = get_userdata( (int) get_post_field( 'post_author', $post_id ) );
		if ( ! $user || user_can( $user, 'edit_others_posts' ) || get_post_meta( $post_id, '_ka_lbi_source', true ) ) {
			return null;
		}
		return $user;
	}

	/** All listings of a user that are not in the trash (published, pending, drafts). */
	public static function count_listings( $user_id ) {
		$q = new WP_Query( array( 'post_type' => KA_LBI_Plugin::POST_TYPE, 'author' => (int) $user_id, 'post_status' => array( 'publish', 'pending', 'draft', 'expired', 'private' ), 'posts_per_page' => 1, 'fields' => 'ids', 'lang' => '' ) );
		return (int) $q->found_posts;
	}

	public static function log( $post_id ) {
		$log = get_post_meta( $post_id, self::META_LOG, true );
		return is_array( $log ) ? $log : array();
	}

	/** Edit link of the listing in the submitter's dashboard (ListingPro "edit listing" page). */
	public static function edit_url( $post_id ) {
		$opts = get_option( 'listingpro_options' );
		$page = is_array( $opts ) && ! empty( $opts['edit-listing'] ) ? $opts['edit-listing'] : home_url( '/edit-listing/' );
		return add_query_arg( 'lp_post', (int) $post_id, $page );
	}

	/** admin-post.php?action=ka_lbi_moderate - reject one listing with reasons. */
	public static function handle() {
		KA_LBI_Plugin::guard();
		$id      = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$reasons = isset( $_POST['reasons'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['reasons'] ) ), array_keys( self::reasons() ) ) ) : array();
		$note    = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$mode    = isset( $_POST['mode'] ) && 'final' === $_POST['mode'] ? 'final' : 'changes';
		$notify  = ! empty( $_POST['notify'] );
		$args    = array();
		foreach ( array( 'source', 'location', 'paged' ) as $k ) {
			if ( ! empty( $_POST[ 'f_' . $k ] ) ) {
				$args[ $k ] = sanitize_text_field( wp_unslash( $_POST[ 'f_' . $k ] ) );
			}
		}
		if ( ! $id || KA_LBI_Plugin::POST_TYPE !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			KA_LBI_Plugin::back( 'ka-lbi-review', __( 'Nothing selected.', 'ka-listing-bulk-importer' ), 'info', $args );
		}
		if ( ! $reasons && '' === $note ) {
			KA_LBI_Plugin::back( 'ka-lbi-review', __( 'Choose at least one reason or write a note.', 'ka-listing-bulk-importer' ), 'error', $args );
		}
		$result = self::reject( $id, $reasons, $note, $mode, $notify );
		if ( is_wp_error( $result ) ) {
			KA_LBI_Plugin::back( 'ka-lbi-review', $result->get_error_message(), 'error', $args );
		}
		$msg = 'final' === $mode ? __( 'The listing was rejected and moved to the trash.', 'ka-listing-bulk-importer' ) : __( 'The listing was sent back to the submitter for changes (draft).', 'ka-listing-bulk-importer' );
		if ( $notify ) {
			$msg .= ' ' . ( 'sent' === $result ? __( 'The submitter was informed by e-mail.', 'ka-listing-bulk-importer' ) : __( 'The e-mail could not be sent.', 'ka-listing-bulk-importer' ) );
		}
		KA_LBI_Quality::forget();
		KA_LBI_Plugin::back( 'ka-lbi-review', $msg, 'sent' === $result || ! $notify ? 'success' : 'warning', $args );
	}

	/**
	 * Rejects a listing: draft ("changes") or trash ("final"), history entry, optional e-mail.
	 *
	 * @return string|WP_Error 'sent', 'not_sent' or 'no_mail'
	 */
	public static function reject( $post_id, array $reasons, $note, $mode, $notify ) {
		$user = self::submitter( $post_id );
		$log  = self::log( $post_id );
		$log[] = array(
			'time'    => time(),
			'by'      => get_current_user_id(),
			'mode'    => $mode,
			'reasons' => $reasons,
			'note'    => $note,
		);
		update_post_meta( $post_id, self::META_LOG, $log );

		$mail = 'no_mail';
		if ( $notify && $user && is_email( $user->user_email ) ) {
			$mail = self::send_mail( $post_id, $user, $reasons, $note, $mode ) ? 'sent' : 'not_sent';
		}
		if ( 'final' === $mode ) {
			if ( ! current_user_can( 'delete_post', $post_id ) || ! wp_trash_post( $post_id ) ) {
				return new WP_Error( 'ka_lbi_trash', __( 'The listing could not be moved to the trash.', 'ka-listing-bulk-importer' ) );
			}
		} else {
			$r = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ), true );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		return $mail;
	}

	/** E-mail to the submitter in their language (Persian when they have not chosen one). */
	public static function send_mail( $post_id, WP_User $user, array $reasons, $note, $mode ) {
		$locale = get_user_meta( $user->ID, 'locale', true );
		$locale = $locale ? $locale : get_locale();
		$switched = switch_to_locale( $locale );
		$texts    = self::reasons();
		$title    = get_the_title( $post_id );
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$rtl      = 0 === strpos( $locale, 'fa' );

		$subject = 'final' === $mode
			/* translators: %s: listing title */
			? sprintf( __( 'Your listing "%s" was not accepted', 'ka-listing-bulk-importer' ), $title )
			/* translators: %s: listing title */
			: sprintf( __( 'Your listing "%s" needs a few changes', 'ka-listing-bulk-importer' ), $title );
		$items = '';
		foreach ( $reasons as $r ) {
			$items .= '<li style="margin:0 0 6px">' . esc_html( $texts[ $r ] ) . '</li>';
		}
		$body  = '<div dir="' . ( $rtl ? 'rtl' : 'ltr' ) . '" style="font-family:Tahoma,Arial,sans-serif;font-size:15px;line-height:1.7;color:#1d2327;max-width:600px">';
		/* translators: %s: name of the person */
		$body .= '<p>' . esc_html( sprintf( __( 'Hello %s,', 'ka-listing-bulk-importer' ), $user->display_name ) ) . '</p>';
		$body .= '<p>' . esc_html( sprintf(
			/* translators: 1: listing title, 2: site name */
			'final' === $mode ? __( 'thank you for submitting "%1$s" to %2$s. Unfortunately we cannot publish this listing.', 'ka-listing-bulk-importer' ) : __( 'thank you for submitting "%1$s" to %2$s. Before we can publish it, please change the following:', 'ka-listing-bulk-importer' ),
			$title,
			$site
		) ) . '</p>';
		if ( $items ) {
			$body .= '<ul style="padding-' . ( $rtl ? 'right' : 'left' ) . ':20px">' . $items . '</ul>';
		}
		if ( '' !== $note ) {
			$body .= '<p style="background:#f6f7f7;border-radius:8px;padding:10px 14px">' . nl2br( esc_html( $note ) ) . '</p>';
		}
		if ( 'final' !== $mode ) {
			$body .= '<p><a href="' . esc_url( self::edit_url( $post_id ) ) . '" style="display:inline-block;background:#2271b1;color:#fff;text-decoration:none;border-radius:6px;padding:10px 18px">' . esc_html__( 'Edit my listing', 'ka-listing-bulk-importer' ) . '</a></p>';
			$body .= '<p>' . esc_html__( 'After you save your changes, the listing is checked again.', 'ka-listing-bulk-importer' ) . '</p>';
		}
		/* translators: %s: site name */
		$body .= '<p>' . esc_html( sprintf( __( 'Kind regards, the %s team', 'ka-listing-bulk-importer' ), $site ) ) . '</p></div>';

		$sent = wp_mail( $user->user_email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		if ( $switched ) {
			restore_previous_locale();
		}
		return $sent;
	}

	/** admin-post.php?action=ka_lbi_nolinks - switch the "no links in the description" rule. */
	public static function handle_toggle() {
		KA_LBI_Plugin::guard( KA_LBI_Plugin::SETTINGS_CAP );
		$on = ! empty( $_POST['on'] ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard()
		update_option( self::OPT_NOLINKS, $on ? 1 : 0 );
		KA_LBI_Plugin::back( 'ka-lbi-review', $on ? __( 'Links in descriptions are blocked for submitters.', 'ka-listing-bulk-importer' ) : __( 'Links in descriptions are allowed again.', 'ka-listing-bulk-importer' ) );
	}

	public static function links_blocked() {
		return (bool) get_option( self::OPT_NOLINKS, 1 );
	}

	/** Saving a listing as a non-editor: links are removed from the description. */
	public static function strip_links( $data, $postarr ) {
		// Only the ListingPro submit / edit form (it posts "postContent"); imports, cron and translations are untouched.
		if ( empty( $data['post_type'] ) || KA_LBI_Plugin::POST_TYPE !== $data['post_type'] || ! isset( $_POST['postContent'] ) // phpcs:ignore WordPress.Security.NonceVerification
			|| ! self::links_blocked() || current_user_can( 'edit_others_posts' ) ) {
			return $data;
		}
		$content = wp_unslash( (string) $data['post_content'] );
		$clean   = preg_replace( '~<a\b[^>]*>(.*?)</a>~is', '$1', $content );
		$clean   = preg_replace( self::LINK_RE, '', $clean );
		$clean   = preg_replace( '/[ \t]{2,}/', ' ', $clean );
		if ( $clean !== $content ) {
			$data['post_content'] = wp_slash( $clean );
		}
		return $data;
	}

	/** Submit / edit form: hint under the description and no submit while it contains a link. */
	public static function form_script() {
		if ( is_admin() || ! self::links_blocked() || current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		$text = array(
			'hint'  => __( 'Please do not add links or web addresses to the description - enter your website in the "Website" field.', 'ka-listing-bulk-importer' ),
			'error' => __( 'The description contains a link or web address. Please remove it - your website belongs in the "Website" field.', 'ka-listing-bulk-importer' ),
		);
		?>
		<script id="ka-lbi-nolinks">
		(function () {
			var form = document.getElementById('lp-submit-form');
			var area = document.getElementById('inputDescription');
			if (!form || !area) { return; }
			var t = <?php echo wp_json_encode( $text ); ?>;
			var re = new RegExp(<?php echo wp_json_encode( trim( self::LINK_RE, '~iu' ) ); ?>, 'i');
			var wrap = area.closest('.wp-editor-wrap') || area;
			var hint = document.createElement('p');
			hint.className = 'ka-lbi-nolinks-hint';
			hint.style.cssText = 'margin:6px 0 0;font-size:13px;color:#646970';
			hint.textContent = t.hint;
			wrap.parentNode.insertBefore(hint, wrap.nextSibling);
			function text() {
				var ed = window.tinymce && tinymce.get('inputDescription');
				var html = ed && !ed.isHidden() ? ed.getContent() : area.value;
				return /<a\s/i.test(html) ? 'www.x' : html.replace(/<[^>]+>/g, ' ');
			}
			function check(e) {
				if (!re.test(text())) { hint.style.color = '#646970'; hint.textContent = t.hint; return; }
				e.preventDefault();
				e.stopImmediatePropagation();
				hint.style.color = '#d63638';
				hint.textContent = t.error;
				wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}
			form.addEventListener('submit', check, true);
			var btn = document.getElementById('listingsubmitBTN');
			if (btn) { btn.addEventListener('click', check, true); }
		})();
		</script>
		<?php
	}
}
