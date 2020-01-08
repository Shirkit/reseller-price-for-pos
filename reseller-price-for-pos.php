<?php
/**
 * Plugin Name:       Reseller Price for POS
 * Plugin URI:        https://github.com/Shirkit/reseller-price-for-pos
 * Description:       Adds a reseller price to WooCommerce and auto-process the difference into coupons.
 * Version:           1.0.0
 * Author:            Shirkit
 * License:           MIT License
 * License URI:       https://raw.githubusercontent.com/Shirkit/reseller-price-for-pos/master/LICENSE
 * GitHub Plugin URI: https://github.com/Shirkit/reseller-price-for-pos
 */


// Adds the field to the product
add_action( 'woocommerce_product_options_pricing', 'wc_cost_product_field' );
function wc_cost_product_field() {
	woocommerce_wp_text_input( array( 'id' => 'reseller_price', 'class' => 'wc_input_price short', 'label' => __( 'Preço de Revenda', 'woocommerce' ) . ' (' . get_woocommerce_currency_symbol() . ')' ) );
}

// Save handling for the data
add_action( 'save_post', 'wc_cost_save_product' );
function wc_cost_save_product( $product_id ) {

	 // stop the quick edit interferring as this will stop it saving properly, when a user uses quick edit feature
	 if (array_key_exists('_inline_edit', $_POST) && wp_verify_nonce($_POST['_inline_edit'], 'inlineeditnonce'))
		return;

	// If this is a auto save do nothing, we only save when update button is clicked
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		return;

	remove_action( 'save_post', 'wc_cost_save_product' );
	if ( isset( $_POST['reseller_price'] ) && $_POST['reseller_price'] ) {
		if ( is_numeric( $_POST['reseller_price'] ) OR number_format ( $_POST['reseller_price'] , 2 , "," , "." ) ) {
			update_post_meta( $product_id, 'reseller_price', $_POST['reseller_price'] );
			do_action('update_reseller_price', $product_id, $_POST['reseller_price']);
		}
	} else {
		delete_post_meta( $product_id, 'reseller_price' );
		do_action('delete_reseller_price', $product_id);
	}
	add_action( 'save_post', 'wc_cost_save_product' );
}

// Additional column to Products view
function reseller_custom_posts_columns( $posts_columns ) {
	$posts_columns['reseller_price'] = __( 'Preço de Revenda', 'woocommerce' );
	return $posts_columns;
}
add_filter( 'manage_product_posts_columns', 'reseller_custom_posts_columns' );

function reseller_custom_column_display( $column_name, $post_id ) {
	if ( 'reseller_price' == $column_name ) {
		$reseller_price = get_post_meta( $post_id, 'reseller_price', true );

		if ( $reseller_price ) {
			echo esc_html( $reseller_price );
		} else {
			esc_html_e( 'N/A', 'woocommerce' );
		}
	}
}
add_action( 'manage_product_posts_custom_column', 'reseller_custom_column_display', 10, 2 );

// Functionality and display for the quickedit
function reseller_quickedit_fields( $column_name, $post_type ) {
	if ( 'reseller_price' != $column_name )
		return;

	//$time_recorded = get_post_meta( $post_id, 'generatewp_edit_time', true );
	?>
<fieldset class="inline-edit-col-left">
  <div class="inline-edit-col">
    &nbsp;
  </div>
</fieldset>
<fieldset class="inline-edit-col-left">
  <div class="inline-edit-col">
    <label>
      <span class="title"><?php esc_html_e( 'Revenda', 'woocommerce' ); ?></span>
      <span class="input-text-wrap">
        <input type="text" name="reseller_price" class="" value="">
      </span>
    </label>
  </div>
</fieldset>
<?php
}
add_action( 'quick_edit_custom_box', 'reseller_quickedit_fields', 10, 2 );

function generatewp_quickedit_save_post( $post_id, $post ) {
	// if called by autosave, then bail here
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		return;

	// if this "post" post type?
	if ( $post->post_type != 'post' )
		return;

	// does this user have permissions?
	 if ( ! current_user_can( 'edit_post', $post_id ) )
		 return;

	// update!
	if ( isset( $_POST['generatewp_edit_time'] ) ) {
		update_post_meta( $post_id, 'generatewp_edit_time', $_POST['generatewp_edit_time'] );
	}
}
add_action( 'save_post', 'generatewp_quickedit_save_post', 10, 2 );

function generatewp_quickedit_javascript() {
	$current_screen = get_current_screen();
	if ( $current_screen->id != 'edit-post' || $current_screen->post_type != 'post' )
		return;

	// Ensure jQuery library loads
	wp_enqueue_script( 'jquery' );
	?>
<script type="text/javascript">
  jQuery(function($) {
    $('#the-list').on('click', 'a.editinline', function(e) {
      e.preventDefault();
      var editTime = $(this).data('edit-time');
      inlineEditPost.revert();
      $('.generatewpedittime').val(editTime ? editTime : '');
    });
  });
</script>
<?php
}
add_action( 'admin_print_footer_scripts-edit.php', 'generatewp_quickedit_javascript' );

function generatewp_quickedit_set_data( $actions, $post ) {
	$found_value = get_post_meta( $post->ID, 'generatewp_edit_time', true );

	if ( $found_value ) {
		if ( isset( $actions['inline hide-if-no-js'] ) ) {
			$new_attribute = sprintf( 'data-edit-time="%s"', esc_attr( $found_value ) );
			$actions['inline hide-if-no-js'] = str_replace( 'class=', "$new_attribute class=", $actions['inline hide-if-no-js'] );
		}
	}

	return $actions;
}
add_filter('post_row_actions', 'generatewp_quickedit_set_data', 10, 2);

// Autogenerate reseller price coupons
add_action('update_reseller_price', 'generate_reseller_coupon', 10, 2);
add_action('delete_reseller_price', 'delete_reseller_coupon', 10, 1);

function delete_reseller_coupon($product_id) {
	$coupon_id = wc_get_coupon_id_by_code('autorevenda_' . $product_id);

	if (!($coupon_id == false || get_post_status($coupon_id) === false)) {
		update_post_meta( $coupon_id, 'coupon_amount', 0 );
	}
}

function generate_reseller_coupon($product_id, $reseller_price) {
	$coupon_id = wc_get_coupon_id_by_code('autorevenda_' . $product_id);
	$product_price = get_post_meta($product_id, '_regular_price', true);

	if ($coupon_id == false || get_post_status($coupon_id) === false) {
		$coupon = array(
			'post_title' => 'autorevenda_' . $product_id,
			'post_content' => '',
			'post_status' => 'publish',
			'post_author' => 1,
			'post_type'		=> 'shop_coupon'
		);
		$coupon_id = wp_insert_post( $coupon );
	}

	// Add meta
	update_post_meta( $coupon_id, 'discount_type', 'fixed_product' );
	update_post_meta( $coupon_id, 'coupon_amount', ($product_price + 0) - ($reseller_price + 0) );
	update_post_meta( $coupon_id, 'individual_use', 'no' );
	update_post_meta( $coupon_id, 'product_ids', '' . $product_id );
	update_post_meta( $coupon_id, 'exclude_product_ids', '' );
	update_post_meta( $coupon_id, 'usage_limit', '' );
	update_post_meta( $coupon_id, 'expiry_date', '' );
	update_post_meta( $coupon_id, 'apply_before_tax', 'yes' );
	update_post_meta( $coupon_id, 'free_shipping', 'no' );
}
?>
