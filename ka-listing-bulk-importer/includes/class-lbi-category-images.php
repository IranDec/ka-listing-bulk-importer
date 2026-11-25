<?php
/**
 * Default image per listing category: a listing without photo and gallery shows the image of its category
 * (or of a parent category) on the public site - lists, the listing page, social previews - instead of the
 * one ListingPro default image. Set on the category's edit screen; admin screens, checks, imports and cron
 * still see that the listing has no photo of its own.
 *
 * @package KA_Listing_Bulk_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Category_Images {

	const META = 'ka_lbi_default_image';

	public static function init() {
		add_filter( 'post_thumbnail_id', array( __CLASS__, 'thumbnail_id' ), 10, 2 );
		if ( is_admin() ) {
			$tax = KA_LBI_Plugin::TAX_CATEGORY;
			add_action( $tax . '_add_form_fields', array( __CLASS__, 'add_field' ) );
			add_action( $tax . '_edit_form_fields', array( __CLASS__, 'edit_field' ) );
			add_action( 'created_' . $tax, array( __CLASS__, 'save' ) );
			add_action( 'edited_' . $tax, array( __CLASS__, 'save' ) );
			add_filter( 'manage_edit-' . $tax . '_columns', array( __CLASS__, 'column' ) );
			add_filter( 'manage_' . $tax . '_custom_column', array( __CLASS__, 'column_value' ), 10, 3 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		}
	}

	/** Only on the public site (pages and their AJAX lists), never in admin screens, REST, cron or WP-CLI. */
	private static function public_request() {
		if ( wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		return ! is_admin() || wp_doing_ajax();
	}

	/** Image of a category, or of its nearest parent that has one (attachment ID, 0 = none). */
	public static function for_term( $term_id ) {
		$seen = array();
		while ( $term_id && ! isset( $seen[ $term_id ] ) ) {
			$seen[ $term_id ] = true;
			$id = (int) get_term_meta( $term_id, self::META, true );
			if ( $id && wp_attachment_is_image( $id ) ) {
				return $id;
			}
			$term    = get_term( $term_id );
			$term_id = $term && ! is_wp_error( $term ) ? (int) $term->parent : 0;
		}
		return 0;
	}

	/** Category image for a listing without photo and gallery (0 = none). */
	public static function for_listing( $post_id ) {
		static $cache = array();
		if ( isset( $cache[ $post_id ] ) ) {
			return $cache[ $post_id ];
		}
		$cache[ $post_id ] = 0;
		$gallery = trim( (string) get_post_meta( $post_id, 'gallery_image_ids', true ) );
		if ( '' !== $gallery && 'false' !== $gallery ) {
			return 0;
		}
		$terms = get_the_terms( $post_id, KA_LBI_Plugin::TAX_CATEGORY );
		if ( $terms && ! is_wp_error( $terms ) ) {
			// a sub-category before its parent
			usort( $terms, function ( $a, $b ) {
				return (int) ( 0 === (int) $a->parent ) - (int) ( 0 === (int) $b->parent );
			} );
			foreach ( $terms as $t ) {
				$id = self::for_term( $t->term_id );
				if ( $id ) {
					$cache[ $post_id ] = $id;
					break;
				}
			}
		}
		return $cache[ $post_id ];
	}

	/** Filter post_thumbnail_id: listings without own photo get their category's image on the public site. */
	public static function thumbnail_id( $thumbnail_id, $post ) {
		if ( $thumbnail_id || ! $post || KA_LBI_Plugin::POST_TYPE !== get_post_type( $post ) || ! self::public_request() ) {
			return $thumbnail_id;
		}
		$id = self::for_listing( is_object( $post ) ? $post->ID : (int) $post );
		return $id ? $id : $thumbnail_id;
	}

	public static function assets( $hook ) {
		if ( in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) && isset( $_GET['taxonomy'] ) && KA_LBI_Plugin::TAX_CATEGORY === $_GET['taxonomy'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_enqueue_media();
		}
	}

	private static function picker( $value ) {
		$src = $value ? wp_get_attachment_image_url( $value, 'medium' ) : '';
		?>
		<div class="ka-lbi-catimg">
			<input type="hidden" name="ka_lbi_default_image" value="<?php echo (int) $value; ?>">
			<?php wp_nonce_field( 'ka_lbi_catimg', 'ka_lbi_catimg_nonce' ); ?>
			<img src="<?php echo esc_url( $src ); ?>" alt="" style="display:<?php echo $src ? 'block' : 'none'; ?>;max-width:320px;height:auto;margin:0 0 8px;border-radius:8px">
			<button type="button" class="button" data-ka-lbi-catimg-pick><?php esc_html_e( 'Choose image', 'ka-listing-bulk-importer' ); ?></button>
			<button type="button" class="button-link" data-ka-lbi-catimg-remove style="<?php echo $value ? '' : 'display:none'; ?>;margin-inline-start:8px"><?php esc_html_e( 'Remove', 'ka-listing-bulk-importer' ); ?></button>
			<p class="description"><?php esc_html_e( 'Shown for listings of this category that have no photo of their own (sub-categories without an image use this one; without any, the ListingPro default image is used).', 'ka-listing-bulk-importer' ); ?></p>
		</div>
		<script>
		(function () {
			var box = document.currentScript.previousElementSibling;
			var input = box.querySelector('input[name="ka_lbi_default_image"]'), img = box.querySelector('img'), rm = box.querySelector('[data-ka-lbi-catimg-remove]');
			var frame;
			box.querySelector('[data-ka-lbi-catimg-pick]').addEventListener('click', function () {
				if (!window.wp || !wp.media) { return; }
				frame = frame || wp.media({ title: <?php echo wp_json_encode( __( 'Default image of the category', 'ka-listing-bulk-importer' ) ); ?>, library: { type: 'image' }, multiple: false });
				frame.off('select').on('select', function () {
					var a = frame.state().get('selection').first().toJSON();
					input.value = a.id;
					img.src = (a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url);
					img.style.display = 'block';
					rm.style.display = '';
				});
				frame.open();
			});
			rm.addEventListener('click', function () { input.value = '0'; img.style.display = 'none'; rm.style.display = 'none'; });
		})();
		</script>
		<?php
	}

	public static function add_field() {
		echo '<div class="form-field"><label>' . esc_html__( 'Default image', 'ka-listing-bulk-importer' ) . '</label>';
		self::picker( 0 );
		echo '</div>';
	}

	public static function edit_field( $term ) {
		echo '<tr class="form-field"><th scope="row"><label>' . esc_html__( 'Default image', 'ka-listing-bulk-importer' ) . '</label></th><td>';
		self::picker( (int) get_term_meta( $term->term_id, self::META, true ) );
		echo '</td></tr>';
	}

	public static function save( $term_id ) {
		if ( ! isset( $_POST['ka_lbi_catimg_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ka_lbi_catimg_nonce'] ) ), 'ka_lbi_catimg' )
			|| ! current_user_can( 'manage_categories' ) || ! isset( $_POST['ka_lbi_default_image'] ) ) {
			return;
		}
		$id = absint( $_POST['ka_lbi_default_image'] );
		if ( $id && wp_attachment_is_image( $id ) ) {
			update_term_meta( $term_id, self::META, $id );
		} else {
			delete_term_meta( $term_id, self::META );
		}
	}

	public static function column( $columns ) {
		$out = array();
		foreach ( $columns as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'name' === $k ) {
				$out['ka_lbi_default_image'] = __( 'Default image', 'ka-listing-bulk-importer' );
			}
		}
		return $out;
	}

	public static function column_value( $content, $column, $term_id ) {
		if ( 'ka_lbi_default_image' !== $column ) {
			return $content;
		}
		$own = (int) get_term_meta( $term_id, self::META, true );
		$id  = $own ? $own : self::for_term( $term_id );
		if ( ! $id ) {
			return '<span class="ka-lbi-muted">—</span>';
		}
		return wp_get_attachment_image( $id, array( 96, 62 ), false, array( 'style' => 'border-radius:4px;height:auto' . ( $own ? '' : ';opacity:.5' ), 'title' => $own ? '' : __( 'from the parent category', 'ka-listing-bulk-importer' ) ) );
	}
}
