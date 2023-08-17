<?php
/*
Plugin Name: RS Archived Pages and Posts
Description: Adds the ability to move a post or page to a separate "archive" post type. Archived items can only be viewed by administrators, and can be restored to their original post type at any time.
Version: 1.0
Author: Radley Sustaire
Author URI: https://radleysustaire.com/
*/

// Register 'archive' custom post type
function rs_archive_post_type() {
    $args = array(
        'label' => 'Archive',
		'labels' => array(
			'name'               => 'Archived Posts',
			'singular_name'      => 'Archive',
			'menu_name'          => 'Archived Posts',
			'name_admin_bar'     => 'Archive',
			'add_new'            => 'Add New',
			'add_new_item'       => 'Add New Archive',
			'new_item'           => 'New Archive',
			'edit_item'          => 'Edit Archive',
			'view_item'          => 'View Archive',
			'all_items'          => 'All Archives',
			'search_items'       => 'Search Archives',
			'parent_item_colon'  => 'Parent Archives:',
			'not_found'          => 'No archives found.',
			'not_found_in_trash' => 'No archives found in Trash.',
		),
		
		// Capabilities. Mainly to remove the "Add new" buttons.
		'capability_type' => 'post',
		'capabilities' => array(
			'create_posts' => false, // Removes support for the "Add New" function
		),
		'map_meta_cap' => true,
		
		
		// Primary visibility options
		'public'             => true,
		'show_ui'            => true,
		'show_in_menu'       => true,
		'query_var'          => true,
		'publicly_queryable' => true,
		'rewrite'            => true,
		'exclude_from_search' => true,
		
		// Dashboard functionality
		'menu_icon'          => 'dashicons-archive',
		'menu_position'    	 => 95,
		'capability_type'    => 'page',
		
		// Structure
		'has_archive'        => false,
		'hierarchical'       => false,
		'supports'           => array( 'title', 'editor', 'thumbnail' ),
		'show_in_rest'       => true, // True to enable the Block Editor
		
		// Taxonomies
		'taxonomies'         => array(),
    );
    register_post_type('archive', $args);
}
add_action('init', 'rs_archive_post_type');

// Do not allow creating new archive items directly
function rs_archive_disable_add_new($caps, $cap, $user_id, $args) {
    if ($cap === 'edit_post' && isset($args[0]) && $args[0] === 'archive') {
        $caps[] = 'do_not_allow';
    }
    return $caps;
}
add_filter('map_meta_cap', 'rs_archive_disable_add_new', 10, 4);


// Only allow admins to view
function rs_archive_limit_view() {
	if ( is_singular('archive') ) {
		if ( ! current_user_can('manage_options') ) {
			wp_die('Archived items cannot be viewed, except by administrators', 'Archives Restricted', array( 'back_link' => true));
			exit;
		}
	}
}
add_action( 'template_redirect', 'rs_archive_limit_view' );

// Which post types support being archived?
// Tip: To add support for custom post types, use the filter "rs_archive/supported_post_types".
// This filter has an array of post types as the only argument.
function rs_archive_get_supported_post_types() {
	$post_types = array( 'post', 'page' );
	
	$post_types = apply_filters( 'rs_archive/supported_post_types', $post_types );
	
	// ensure that archives themselves are never archive-able
	if ( $k = array_search( 'archive', $post_types, true ) ) {
		unset($post_types[$k]);
	}
	
	return $post_types;
}

// Add meta box for converting post to archive and vice versa
function rs_archive_meta_box() {
	foreach( rs_archive_get_supported_post_types() as $post_type ) {
		add_meta_box( 'rs_archive_convert_box', 'Convert to Archive', 'rs_archive_convert_callback', $post_type, 'side' );
	}
	
	add_meta_box( 'rs_archive_restore_box', 'Recover from Archive', 'rs_archive_restore_callback', 'archive', 'side' );
}
add_action('add_meta_boxes', 'rs_archive_meta_box', 1000);

// Get post type label for human display ("Product" instead of "wc-product")
function rs_archive_get_post_type_label( $post_type ) {
	
	$pt = get_post_type_object( $post_type );
	
	if ( $pt && $pt->labels && $pt->labels->singular_name ) {
		// Get post type label (wc-product -> Product)
		$post_type_label = $pt->labels->singular_name;
	}else{
		// Convert post type key to proper name (client-testimonials -> Client Testimonials)
		$post_type_label = ucwords(str_replace(['-','_'], ' ', $post->post_type));
	}
	
	return $post_type_label ?: '(unknown post type)';
}

// Meta box callback function for posts, pages, etc
function rs_archive_convert_callback($post) {
	$post_id = $post->ID;
	
	$post_type_label = rs_archive_get_post_type_label( $post->post_type );
	
	$post_type_label = strtolower($post_type_label);
    ?>
    <label for="archive_checkbox">
        <input type="checkbox" name="archive_checkbox" id="archive_checkbox" value="1">
        <?php echo esc_html( 'Move this '. esc_html($post_type_label) .' to archive' ); ?>
    </label>
    <?php
    wp_nonce_field( 'convert-to-archive', 'rs_archive_nonce');
}

// Meta box callback function for archived posts
function rs_archive_restore_callback($post) {
	$post_id = $post->ID;
	
	$original_post_type = get_post_meta( $post_id, '_archived_post_type', true );
	$original_post_type_label = rs_archive_get_post_type_label( $original_post_type );
	
    ?>
    <label for="archive_checkbox">
        <input type="checkbox" name="archive_checkbox" id="archive_checkbox" value="1">
        <?php echo esc_html( 'Restore '. esc_html(strtolower($original_post_type_label)) .' from archive' ); ?>
    </label>
    <?php
	
	
	$date_ymd = get_post_meta( $post_id, '_archived_date', true );
	
	if ( $date_ymd ) {
		$diff = human_time_diff( strtotime($date_ymd) );
		$date = date('F j, Y g:i:s', strtotime($date_ymd));
		
		$diff = str_replace('min', 'minute', $diff);
		
		echo '<p class="description" style="margin: 1em 0 0;">This '. esc_html(strtolower($original_post_type_label)) .' was archived <abbr title="' . $date . '">'. esc_html($diff) . ' ago</abbr>.</p>';
	}
	
    wp_nonce_field( 'restore-from-archive', 'rs_archive_nonce');
}

// Save meta box - for regular posts, pages, etc
function rs_archive_save_meta_convert($post_id) {
	// Check if post type can be archived
	if ( ! in_array( get_post_type($post_id), rs_archive_get_supported_post_types() ) ) {
		return;
	}
	
	// Check if nonce was submitted
	$nonce = isset($_POST['rs_archive_nonce']) ? stripslashes($_POST['rs_archive_nonce']) : false;
    if ( ! $nonce || ! wp_verify_nonce( $nonce, 'convert-to-archive' ) ) {
        return;
    }

	// Ignore during autosaves
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

	// Check permissions
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

	// Archive this post
    if ( isset( $_POST['archive_checkbox'] ) ) {
		
		$post_type = get_post_type( $post_id );
		
		$converted = rs_archive_convert_post_type( $post_id, 'archive' );
		
		if ( $converted ) {
			delete_post_meta( $post_id, '_archive_show_restored_notice' );
			
			update_post_meta( $post_id, '_archived_post_type', $post_type );
			update_post_meta( $post_id, '_archive_show_converted_notice', 1 );
			update_post_meta( $post_id, '_archived_date', current_time('Y-m-d H:i:s') );
		}
    }
}
add_action('save_post', 'rs_archive_save_meta_convert');


// Save meta box data - for archive items
function rs_archive_save_meta_restore($post_id) {
	// Check if this post is already an archive item
	if ( get_post_type($post_id) != 'archive' ) {
		return;
	}
	
	// Check if nonce was submitted
	$nonce = isset($_POST['rs_archive_nonce']) ? stripslashes($_POST['rs_archive_nonce']) : false;
    if ( ! $nonce || ! wp_verify_nonce( $nonce, 'restore-from-archive' ) ) {
        return;
    }

	// Ignore during autosaves
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

	// Check permissions
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

	// Restore this post
    if ( isset( $_POST['archive_checkbox'] ) ) {
		
		$original_post_type = get_post_meta($post_id, '_archived_post_type', true);
		
		if ( ! $original_post_type ) {
			w1p_die('Cannot restore item from archive: The original post type was not found in post meta');
			exit;
		}
		
		$converted = rs_archive_convert_post_type( $post_id, $original_post_type );
		
		if ( $converted ) {
			delete_post_meta( $post_id, '_archive_show_converted_notice' );
			delete_post_meta( $post_id, '_archived_post_type' );
			
			update_post_meta( $post_id, '_archive_show_restored_notice', 1 );
			update_post_meta( $post_id, '_restored_date', current_time('Y-m-d H:i:s') );
		}
    }
}
add_action('save_post', 'rs_archive_save_meta_restore');


// Convert post to a different post type
function rs_archive_convert_post_type( $post_id, $new_post_type ) {
	
    // Get the post object
    $post = get_post($post_id);
	
    // Check if the post object exists and if it's not already an 'archive' post type
    if ( $post && $post->post_type !== $new_post_type ) {
        // Update the post type
        $updated_post = array(
            'ID' => $post_id,
            'post_type' => $new_post_type,
        );

		// Un-hook the save post action
		remove_action('save_post', 'rs_archive_save_meta_convert');
		remove_action('save_post', 'rs_archive_save_meta_restore');

        // Update the post using wp_update_post
        $result = wp_update_post($updated_post);

		// Re-hook the save post action
		add_action('save_post', 'rs_archive_save_meta_convert');
		add_action('save_post', 'rs_archive_save_meta_restore');
		
		if ( ! $result || is_wp_error($result) ) {
			return false;
		}else{
			return true;
		}
    }
	
	return false;
}


// Show notice on dashboard when a post is converted or restored from the archive.
function rs_archive_notice() {
	if ( ! function_exists( 'get_current_screen' ) ) return;
	
	// Only show notices on post edit screens
    $current_screen = get_current_screen();
    if ($current_screen->base !== 'post') return;

    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
	if ( ! $post_id ) $post_id = get_the_ID();
	if ( ! $post_id ) return;
	
    $current_post_type = get_post_type($post_id);
	$current_post_type_label = rs_archive_get_post_type_label( $current_post_type );

    // Check if we are editing a regular post type
	if ( in_array( $current_post_type, rs_archive_get_supported_post_types() ) ) {
	
        // Check if the post was just restored from an archive
        $archive_restored = get_post_meta($post_id, '_archive_show_restored_notice', true);
        
        if ( $archive_restored ) {
            echo '<div class="notice notice-success is-dismissible">';
			echo '<p>This ' . esc_html($current_post_type) . ' has been restored.</p>';
            echo '</div>';
			
			delete_post_meta( $post_id, '_archive_show_restored_notice' );
        }
    }

    // Check if we are editing an archive
    else if ( $current_post_type === 'archive' ) {
		
        // Check if the original post was just archived
        $archive_restored = get_post_meta( $post_id, '_archive_show_converted_notice', true );

        if ($archive_restored ) {
			$original_post_type = get_post_meta($post_id, '_archived_post_type', true);
			$original_post_type_label = rs_archive_get_post_type_label( $original_post_type );
			
			echo '<div class="notice notice-success is-dismissible">';
            echo '<p><span class="dashicons dashicons-archive" style="margin-right: 5px;"></span> This ' . esc_html(strtolower($original_post_type_label)) . ' has been archived.</p>';
			echo '</div>';
			
			delete_post_meta( $post_id, '_archive_show_converted_notice' );
        }
		
    }
	
}
add_action( 'admin_notices', 'rs_archive_notice' );

// Add custom column to archive list screen
function rs_archive_rs_column($columns) {
	$pos = count($columns) - 1;
	
	$columns = array_merge(
		array_slice( $columns, 0, $pos, true ),
		array( 'archived_post_type' => 'Post Type' ),
		array_slice( $columns, $pos, null, true )
	);
	
    return $columns;
}
add_filter('manage_edit-archive_columns', 'rs_archive_rs_column');

// Add CSS to set column size
function rs_archive_column_size_css() {
	if ( ! function_exists( 'get_current_screen' ) ) return;
	
	// Only add css on archive edit screen
    $current_screen = get_current_screen();
    if ($current_screen->id !== 'edit-archive') return;
	
	?>
	<style>
	table.fixed .column-archived_post_type { width: min( 15vw, 200px ); }
	</style>
	<?php
}
add_action('admin_print_scripts', 'rs_archive_column_size_css');

// Display post meta value in custom column
function rs_archive_rs_column_content($column, $post_id) {
    if ($column === 'archived_post_type') {
        $archived_post_type = get_post_meta($post_id, '_archived_post_type', true);
		$post_type_label = rs_archive_get_post_type_label( $archived_post_type );
        echo $post_type_label;
		echo '<span class="row-actions"> ('. $archived_post_type . ')</span>';
    }
}
add_action('manage_archive_posts_rs_column', 'rs_archive_rs_column_content', 10, 2);

// Make the custom column sortable
function rs_archive_sortable_column($columns) {
    $columns['archived_post_type'] = 'archived_post_type';
    return $columns;
}
add_filter('manage_edit-archive_sortable_columns', 'rs_archive_sortable_column');

