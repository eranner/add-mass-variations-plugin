<?php
/** 
 * Plugin Name: Add Mass Variations
 * Description: A plugin designed to easily update all products with a new variation.
 * Author: Eric Ranner
 * Version: 1.0
 * **/

 if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Hook to create the admin menu
add_action( 'admin_menu', 'cpvg_add_admin_page' );

// Add the admin page
function cpvg_add_admin_page() {
    add_menu_page(
        'Add Mass Variations', // Page title
        'Add Mass Variations',         // Menu title
        'manage_woocommerce',          // Capability
        'add-mass-variations',         // Menu slug
        'cpvg_admin_page_callback',    // Callback function
        'dashicons-admin-tools',       // Icon
        20                             // Position
    );
}

function cpvg_admin_page_callback() {
    $attributes = wc_get_attribute_taxonomies(); // Returns an array of objects
    ?>
    <div class="wrap">
        <h1>Add Mass Variations</h1>
        <p>Add variations to all products in your store at the click of a button! Enter the variation name and price below. <b>You can exclude products by category name in the field provided.</b></p>
        <form method="post" action="">
            <label for="variation-attribute">Choose attribute:</label>
            <select name="variation-attribute" id="variation-attribute">
                <?php
                if (!empty($attributes)) {
                    foreach ($attributes as $attribute) {
                        ?>
                        <option value="<?php echo esc_attr($attribute->attribute_name); ?>">
                            <?php echo esc_html($attribute->attribute_label); ?>
                        </option>
                        <?php
                    }
                } else {
                    ?>
                    <option value="">No attributes found</option>
                    <?php
                }
                ?>
            </select>
            <br><br>
            <label for="variation-name">Variation Name:</label>
            <input type="text" name="variation-name" id="variation-name">
            <br><br>
            <label for="variation-price">Set Price:</label>
            <input type="text" name="variation-price" id="variation-price">
            <br><br>
            <label for="variation-exclusions">Categories to Exclude (separated by a comma):</label>
            <input type="text" name="variation-exclusions" id="variation-exclusions">
            <input type="hidden" name="cpvg_create_variation" value="1">
            <?php submit_button('Add Variation'); ?>
        </form>
    </div>
    <?php

    // Check if the form is submitted
    if (isset($_POST['cpvg_create_variation']) && $_POST['cpvg_create_variation'] == '1') {
        $variation_name = isset($_POST['variation-name']) ? trim($_POST['variation-name']) : '';
        $variation_price = isset($_POST['variation-price']) ? trim($_POST['variation-price']) : '';
        $variation_exclusions = isset($_POST['variation-exclusions']) ? trim($_POST['variation-exclusions']) : '';
        $variation_attribute = isset($_POST['variation-attribute']) ? trim($_POST['variation-attribute']) : '';
        // Validate the inputs
        if (empty($variation_name)) {
            echo '<p style="color: red;">Please enter a valid variation name.</p>';
        } elseif (is_numeric($variation_price) === false) {
            echo '<p style="color: red;">Please enter a valid number for the price.</p>';
        } elseif (empty($variation_attribute)){
            echo '<p style="color: red;">No attributes detected. Please first add an attribute in the products menu.</p>';
        } else {
            // Sanitize and process the data
            $clean_price = floatval($variation_price);
            $variation_array = explode(',', $variation_exclusions);
            cpvg_add_variations($variation_name, $clean_price, $variation_array, $variation_attribute);
        }
    }
}


function cpvg_add_variations($variation_name, $clean_price, $variation_array, $variation_attribute){
    global $wpdb;

    $product_ids = $wpdb->get_col("
    SELECT ID FROM {$wpdb->posts}
    WHERE post_type = 'product'
    AND post_status = 'publish'
");
$productUpdates = 0;
foreach ($product_ids as $product_id) {
   $response = cpvg_add_variation_via_sql($variation_name, $clean_price, $product_id, $variation_array, $variation_attribute);
    if($response === 'success'){
        $productUpdates += 1;
    }
}
if($productUpdates > 0){
    echo '<p style="color: green;">Succesfully added variation to '.$productUpdates.' products!</p>';
} else {
    echo '<p style="color: red;">No products were added. Check to make sure you haven\'t excluded all product categories.</p>';

}
}


function cpvg_add_variation_via_sql($variation_name, $variation_price, $current_id, $variation_array, $variation_attribute) {
    $numberOfProductsUpdated = 0;
    $product_id = $current_id; // Replace with your product ID
    $attribute_slug = 'pa_'.$variation_attribute; // Attribute slug (e.g., pa_size, pa_color)
    $new_attribute_value = $variation_name; // New attribute value
    $new_attribute_slug = sanitize_title( $new_attribute_value ); // Generate slug (e.g., small-cup)
    
    // Step 0: Check if the product is in the "Ready to Ship" category

    foreach($variation_array as $key =>$exclusion){
        $exclusion = strtolower(str_replace(' ', '-', trim($exclusion)));
        // echo $key . ' ' .$exclusion;
        $product_categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'slugs' ) );

        if ( in_array( $exclusion, $product_categories ) ) {
            // Stop execution if the product is in the excluded category
            return;
        }
    }
    // Step 1: Ensure the term exists in the taxonomy
    $term = term_exists( $new_attribute_value, $attribute_slug );
    if ( ! $term ) {
        $term = wp_insert_term( $new_attribute_value, $attribute_slug );
        if ( is_wp_error( $term ) ) {
            error_log( "Error creating term: " . $term->get_error_message() );
            echo '<p style="color: red;">Error creating term: ' . $term->get_error_message() . '</p>';
            return;
        }
    }

    // Step 2: Link the term to the product
    wp_set_object_terms( $product_id, $new_attribute_value, $attribute_slug, true );

    // Step 3: Retrieve and update existing product attributes
    $product_attributes = get_post_meta( $product_id, '_product_attributes', true );

    if ( ! is_array( $product_attributes ) ) {
        $product_attributes = array();
    }

    // Check if the attribute already exists
    if ( ! isset( $product_attributes[ $attribute_slug ] ) ) {
        // Create the new attribute if it doesn't exist
        $product_attributes[ $attribute_slug ] = array(
            'name'         => $attribute_slug,
            'value'        => '',
            'position'     => 0,
            'is_visible'   => 1,
            'is_variation' => 1,
            'is_taxonomy'  => 1,
        );
    }

    // Step 4: Save the updated attributes
    update_post_meta( $product_id, '_product_attributes', $product_attributes );

    // Step 5: Add the variation
    $variation_id = wp_insert_post( array(
        'post_title'   => 'Variation for ' . $new_attribute_value,
        'post_name'    => 'product-' . $product_id . '-variation-' . $new_attribute_slug,
        'post_status'  => 'publish',
        'post_parent'  => $product_id,
        'post_type'    => 'product_variation',
        'menu_order'   => 0,
    ) );

    if ( is_wp_error( $variation_id ) ) {
        error_log( "Error creating variation: " . $variation_id->get_error_message() );
        echo '<p style="color: red;">Error creating variation: ' . $variation_id->get_error_message() . '</p>';
        return;
    }

    // Step 6: Add variation metadata
    update_post_meta( $variation_id, 'attribute_' . $attribute_slug, $new_attribute_slug ); // Use slug for attribute value
    update_post_meta( $variation_id, '_regular_price', $variation_price );
    update_post_meta( $variation_id, '_price', $variation_price );
    update_post_meta( $variation_id, '_stock_status', 'instock' );
    update_post_meta( $variation_id, '_manage_stock', 'no' );
    // update_post_meta( $variation_id, '_stock', 10 ); // Set stock quantity

    // Step 7: Refresh WooCommerce lookup tables
    wc_delete_product_transients( $product_id );
    wc_update_product_lookup_tables( $product_id );

    // Step 8: Log success
    return 'success';

}

