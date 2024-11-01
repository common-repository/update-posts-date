<?php
/**
 * Update Posts Date
 *
 * Plugin Name: Update Posts Date
 * Plugin URI:  https://wordpress.org/plugins/update-posts-date/
 * Description: Update posts date automatically by setting the date to the current date.
 * Version:     1.1
 * Author:      EDC TEAM
 * Author URI:  https://edc.org.kw
 * License:     GPLv2 or later
 * License URI: http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: update-posts-date
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of the GNU
 * General Public License version 2, as published by the Free Software Foundation. You may NOT assume
 * that you can use any other version of the GPL.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */

 if ( ! defined( 'ABSPATH' ) ) {
	die( 'Invalid request.' );
}

define( 'UPDP_1_MINUTE', 60 );
define( 'UPDP_15_MINUTES', 15 * UPDP_1_MINUTE );
define( 'UPDP_30_MINUTES', 30 * UPDP_1_MINUTE );
define( 'UPDP_1_HOUR', 60 * UPDP_1_MINUTE );
define( 'UPDP_4_HOURS', 4 * UPDP_1_HOUR );
define( 'UPDP_6_HOURS', 6 * UPDP_1_HOUR );
define( 'UPDP_12_HOURS', 12 * UPDP_1_HOUR );
define( 'UPDP_24_HOURS', 24 * UPDP_1_HOUR );
define( 'UPDP_48_HOURS', 2 * UPDP_24_HOURS );
define( 'UPDP_72_HOURS', 3 * UPDP_24_HOURS );
define( 'UPDP_168_HOURS', 7 * UPDP_24_HOURS );
define( 'UPDP_INTERVAL', UPDP_12_HOURS );
define( 'UPDP_INTERVAL_SLOP', UPDP_4_HOURS );
define( 'UPDP_AGE_LIMIT', 120); // 120 days
define( 'UPDP_OMIT_CATS', "" );

function updp_load_textdomain() {
	load_plugin_textdomain( 'update-posts-date', false, dirname( plugin_basename(__FILE__) ) . '/languages' );
}
add_action('plugins_loaded', 'updp_load_textdomain');

function updp_options_setup() {
	$page = add_submenu_page( 'options-general.php', esc_html(__( 'Update Posts Date', 'update-posts-date' )), esc_html(__( 'Update Posts Date', 'update-posts-date' )), 'activate_plugins', 'update-posts-date', 'updp_options_page' );
	add_action( "admin_print_scripts-$page", "updp_admin_scripts" );
}

function updp_admin_scripts() {
	wp_enqueue_style( 'updp-styles', plugins_url( 'css/style.css', __FILE__ ) );
	if( is_rtl() ){
		wp_enqueue_style( 'updp-rtl-style', plugins_url( 'css/rtl.css', __FILE__ ) );
	}
}

register_activation_hook( __FILE__, 'updp_activate' );
register_deactivation_hook( __FILE__, 'updp_deactivate' );

add_action( 'init', 'updp' );
add_action( 'admin_menu', 'updp_options_setup' );
add_filter( 'the_content', 'updp_the_content' );
add_filter( 'plugin_row_meta', 'updp_plugin_meta', 10, 2 );

function updp_plugin_meta( $links, $file ) {
	if ( strpos( $file, 'index.php' ) !== false ) {
		$links = array_merge( $links, array( '<a href="' . esc_url( get_admin_url(null, 'options-general.php?page=update-posts-date') ) . '">' . esc_html(__( 'Settings', 'update-posts-date' )) . '</a>' ) );
	}

	return $links;
}

function updp_deactivate() {
	delete_option( 'updp_last_update' );
}

function updp_activate() {
	add_option( 'updp_interval', UPDP_INTERVAL );
	add_option( 'updp_interval_slop', UPDP_INTERVAL_SLOP );
	add_option( 'updp_age_limit', UPDP_AGE_LIMIT );
	add_option( 'updp_omit_cats', UPDP_OMIT_CATS );
	add_option( 'updp_show_original_pubdate', 1 );
	add_option( 'updp_pos', 0 );
}

function updp() {
	if ( updp_update_time() ) {
		update_option( 'updp_last_update', time() );
		updp_update_posts_date();
	}
}

function updp_update_posts_date () {
	global $wpdb;
	$updp_omit_cats = get_option( 'updp_omit_cats' );
	$updp_age_limit = get_option( 'updp_age_limit' );

	if ( !isset( $updp_omit_cats ) ) {
		$updp_omit_cats = UPDP_OMIT_CATS;
	}
	if ( !isset( $updp_age_limit ) ) {
		$updp_age_limit = UPDP_AGE_LIMIT;
	}
	
	$sql = "(SELECT ID, post_date
            FROM $wpdb->posts
            WHERE post_type = 'post'
                  AND post_status = 'publish'
                  AND post_date < '%s' - INTERVAL %d HOUR 
                  ";
    if ( $updp_omit_cats != '' ) {
    	$sql = $sql."AND NOT(ID IN (SELECT tr.object_id 
                                    FROM $wpdb->terms t 
                                          inner join $wpdb->term_taxonomy tax on t.term_id=tax.term_id and tax.taxonomy='category' 
                                          inner join $wpdb->term_relationships tr on tr.term_taxonomy_id=tax.term_taxonomy_id 
                                    WHERE t.term_id IN (%s)))";
    }            
	$sql = $sql . ")";
	$sql = $sql . "ORDER BY post_date ASC LIMIT 1";

	$getLimit = ($updp_age_limit * 24);
	if ( $updp_omit_cats != '' ) {
		$args = [current_time('mysql'), $getLimit, $updp_omit_cats];
	}else{
		$args = [current_time('mysql'), $getLimit];
	}

	$oldest_post = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );

	if ( isset( $oldest_post ) ) {
		updp_update_old_post( $oldest_post );
	}
}

function updp_update_old_post( $oldest_post ) {
	global $wpdb;

	$post = get_post( $oldest_post );
	$updp_original_pub_date = get_post_meta( $oldest_post, 'updp_original_pub_date', true ); 

	if ( !( isset( $updp_original_pub_date ) && $updp_original_pub_date!='' ) ) {
		$updp_original_pub_date = $wpdb->get_var( $wpdb->prepare( "SELECT post_date FROM $wpdb->posts WHERE ID = %d", [$oldest_post] ) );

		add_post_meta( $oldest_post, 'updp_original_pub_date', $updp_original_pub_date );
		$updp_original_pub_date = get_post_meta($oldest_post, 'updp_original_pub_date', true ); 
	}

	$updp_pos = get_option('updp_pos');
	if ( !isset( $updp_pos ) ) {
		$updp_pos = 0;
	}

	if ( $updp_pos == 1 ) {
		$new_time = date('Y-m-d H:i:s');
		$gmt_time = get_gmt_from_date($new_time);
	} else {
		$lastposts = get_posts( 'numberposts=1&offset=1' );
		foreach ($lastposts as $lastpost) {
			$post_date = strtotime( $lastpost->post_date );
			$new_time = date('Y-m-d H:i:s', mktime(date("H", $post_date), date("i",$post_date), date("s", $post_date)+1, date("m", $post_date), date("d", $post_date), date("Y", $post_date)));
			$gmt_time = get_gmt_from_date( $new_time );
		}
	}

	//$sql = "UPDATE $wpdb->posts SET post_date = '$new_time', post_date_gmt = '$gmt_time', post_modified = '$new_time', post_modified_gmt = '$gmt_time' WHERE ID = '$oldest_post'";		
	//$wpdb->query($sql);
	
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE $wpdb->posts SET post_date = '%s', post_date_gmt = '%s', post_modified = '%s', post_modified_gmt = '%s' WHERE ID = %d",
			$new_time, $gmt_time, $new_time, $gmt_time, $oldest_post
		)
	);

	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
	
	if(isset($GLOBALS['wp_fastest_cache']) && method_exists($GLOBALS['wp_fastest_cache'], 'singleDeleteCache')){
		$GLOBALS['wp_fastest_cache']->singleDeleteCache(false, $oldest_post);
	}
}

function updp_the_content( $content ) {
	global $post;

	$updp_show_original_pubdate = get_option( 'updp_show_original_pubdate' );
	if ( !isset( $updp_show_original_pubdate ) ) {
		$updp_show_original_pubdate = 1;
	}

	$updp_original_pub_date = get_post_meta( $post->ID, 'updp_original_pub_date', true );

	$dateline = '';
	if ( isset( $updp_original_pub_date ) && $updp_original_pub_date != '' ) {
		if ( $updp_show_original_pubdate ) {
			$dateline .= '<p id="updp">';
			$dateline .= '<small>';
			if ( $updp_show_original_pubdate ) {
				$dateline .= esc_html(__("Originally posted", 'update-posts-date')) . ' ' . $updp_original_pub_date . '. ';
			}
			$dateline .= '</small>';
			$dateline .= '</p>';
		}
	}

	if ( $updp_show_original_pubdate == 1 ) {
		$content = $dateline.$content;
	}elseif ( $updp_show_original_pubdate == 2 ) {
		$content = $content.$dateline;
	}

	return $content;
}

function updp_update_time() {
	$last = get_option( 'updp_last_update' );
	$interval = get_option( 'updp_interval' );
	$time = time();

	if ( !( isset( $interval ) && is_numeric( $interval ) ) ) {
		$interval = UPDP_INTERVAL;
	}

	$slop = get_option( 'updp_interval_slop' );
	if ( !( isset( $slop ) && is_numeric( $slop ) ) ) {
		$slop = UPDP_INTERVAL_SLOP;
	}
	
	if ( false === $last ) {
		$ret = 1;
	} else if ( is_numeric( $last ) ) { 
		if ( $slop == 0 ) {
			if ( ( $time - $last ) >= $interval ) {
				$ret = 1;
			} else {
				$ret = 0;
			}
		} else {
			if ( ( $time - $last ) >= ( $interval + rand( 0, $slop ) ) ) {
				$ret = 1;
			} else {
				$ret = 0;
			}
		}
	}

	return $ret;
}

function updp_interval_list() {
	$data = [];
	$data[] = [
		'name' => '1 ' . __( 'Minute', 'update-posts-date' ),
		'value' => UPDP_1_MINUTE
	];
	$data[] = [
		'name' => '15 ' . __( 'Minutes', 'update-posts-date' ),
		'value' => UPDP_15_MINUTES
	];
	$data[] = [
		'name' => '30 ' . __( 'Minutes', 'update-posts-date' ),
		'value' => UPDP_30_MINUTES
	];
	$data[] = [
		'name' => '1 ' . __( 'Hour', 'update-posts-date' ),
		'value' => UPDP_1_HOUR
	];
	$data[] = [
		'name' => '4 ' . __( 'Hours', 'update-posts-date' ),
		'value' => UPDP_4_HOURS
	];
	$data[] = [
		'name' => '6 ' . __( 'Hours', 'update-posts-date' ),
		'value' => UPDP_6_HOURS
	];
	$data[] = [
		'name' => '12 '. __( 'Hours', 'update-posts-date' ),
		'value' => UPDP_12_HOURS
	];
	$data[] = [
		'name' => '24 ' . __( 'Hours', 'update-posts-date' ),
		'value' => UPDP_24_HOURS
	];
	$data[] = [
		'name' => '2 ' . __( 'days', 'update-posts-date' ),
		'value' => UPDP_48_HOURS
	];
	$data[] = [
		'name' => '3 ' . __( 'days', 'update-posts-date' ),
		'value' => UPDP_72_HOURS
	];
	$data[] = [
		'name' => '7 ' . __( 'days', 'update-posts-date' ),
		'value' => UPDP_168_HOURS
	];
	return $data;
}

function updp_interval_slop_list() {
	$data = [];
	$data[] = [
		'name' => '1 ' . __( 'Hour', 'update-posts-date' ),
		'value' => UPDP_1_HOUR
	];
	$data[] = [
		'name' => '4 ' . __( 'Hours', 'update-posts-date' ),
		'value' => UPDP_4_HOURS
	];
	$data[] = [
		'name' => '6 ' . __( 'Hours', 'update-posts-date' ),
		'value' => UPDP_6_HOURS
	];
	$data[] = [
		'name' => '12 ' . __( 'Hours', 'update-posts-date' ),
		'value' => UPDP_12_HOURS
	];
	$data[] = [
		'name' => '24 ' . __( 'Hours', 'update-posts-date' ),
		'value' => UPDP_24_HOURS
	];

	return $data;
}

function updp_age_limit_list() {
	$data = [];
	$data[] = [
		'name' => '30 ' . __( 'Days', 'update-posts-date' ),
		'value' => 30
	];
	$data[] = [
		'name' => '60 ' . __( 'Days', 'update-posts-date' ),
		'value' => 60
	];
	$data[] = [
		'name' => '90 ' . __( 'Days', 'update-posts-date' ),
		'value' => 90
	];
	$data[] = [
		'name' => '120 ' . __( 'Days', 'update-posts-date' ),
		'value' => 120
	];
	$data[] = [
		'name' => '240 ' . __( 'Days', 'update-posts-date' ),
		'value' => 240
	];
	$data[] = [
		'name' => '365 ' . __( 'Days', 'update-posts-date' ),
		'value' => 365
	];
	$data[] = [
		'name' => '730 ' . __( 'Days', 'update-posts-date' ),
		'value' => 730
	];
	return $data;
}

function updp_pos_list() {
	$data = [];
	$data[] = [
		'name' => __( '1st Position', 'update-posts-date' ),
		'value' => 1
	];
	$data[] = [
		'name' => __( '2nd Position', 'update-posts-date' ),
		'value' => 2
	];
	return $data;
}

function updp_original_pubdate_list() {
	$data = [];
	$data[] = [
		'name' => __( 'Hide', 'update-posts-date' ),
		'value' => 0
	];
	$data[] = [
		'name' => __( 'Top', 'update-posts-date' ),
		'value' => 1
	];
	$data[] = [
		'name' => __( 'End', 'update-posts-date' ),
		'value' => 2
	];
	return $data;
}

function updp_create_select($id, $args, $val){
	$html = '';
	if( !empty($id) && is_array($args) ){
		$html .= '<select name="'.esc_attr($id).'" id="'.esc_attr($id).'">';
		foreach ($args as $v) {
			$name = ( isset($v['name']) ? esc_html($v['name']) : '' );
			$value = ( isset($v['value']) ? esc_html($v['value']) : '' );
			$selected = ( $val == $value ? ' selected' : '' );
			$html .= '<option value="' . $value . '"' . $selected . '>' . $name . '</option>';
		}
		$html .= '</select>';
	}
	return $html;
}

function updp_options_page() {
	if ( !current_user_can( 'manage_options' ) )  {
		wp_die( esc_html(__( 'You do not have sufficient permissions to access this page.', 'update-posts-date' )) );
	}

	if ( isset($_POST['updp_action']) && !empty($_POST['updp_action']) ) {

		if ( ! isset( $_POST['updp_update'] ) || ! wp_verify_nonce( sanitize_text_field($_POST['updp_update']), 'updp_nonce' ) ) {
			wp_die( esc_html(__( 'Sorry, your nonce did not verify.', 'update-posts-date' )) );
		}

		if ( isset( $_POST['updp_interval'] ) ) {
			$updp_interval = sanitize_text_field($_POST['updp_interval']);
			$updp_interval = intval( $updp_interval );
			update_option( 'updp_interval', $updp_interval );
		}

		if ( isset( $_POST['updp_interval_slop'] ) ) {
			$updp_interval_slop = sanitize_text_field( $_POST['updp_interval_slop'] );
			$updp_interval_slop = intval( $updp_interval_slop );
			update_option( 'updp_interval_slop', $updp_interval_slop );
		}

		if ( isset( $_POST['updp_age_limit'] ) ) {
			if ( is_numeric( $_POST['updp_age_limit'] ) ) {
				$updp_age_limit = sanitize_text_field($_POST['updp_age_limit']);
			} else {
				$updp_age_limit = UPDP_AGE_LIMIT;
			}
			update_option( 'updp_age_limit', $updp_age_limit );
		}

		if ( isset( $_POST['updp_show_original_pubdate'] ) ) {
			$updp_show_original_pubdate = sanitize_text_field( $_POST['updp_show_original_pubdate'] );
			$updp_show_original_pubdate = intval( $updp_show_original_pubdate );
			update_option( 'updp_show_original_pubdate', $updp_show_original_pubdate );
		}

		if ( isset( $_POST['updp_pos'] ) ) {
			$updp_pos = sanitize_text_field( $_POST['updp_pos'] );
			$updp_pos = intval( $updp_pos );
			update_option( 'updp_pos', $updp_pos );
		}

		if ( isset( $_POST['post_category'] ) ) {
			$updp_omit_custom_field_value = array_map('intval', $_POST['post_category']);
			$updp_omit_custom_field_value = implode( ',', $updp_omit_custom_field_value );
			update_option( 'updp_omit_cats', $updp_omit_custom_field_value );
		} else {
			update_option('updp_omit_cats', '');		
		}
		
		echo '<div id="message" class="updated fade">';
		echo '<p>';
		echo  esc_html(__( 'Options Updated.', 'update-posts-date' ));
		echo '</p>';
		echo '</div>';
	}

	$updp_omit_cats = sanitize_text_field( get_option( 'updp_omit_cats' ) );
	if ( !isset( $updp_omit_cats ) ) {
		$updp_omit_cats = UPDP_OMIT_CATS;
	}
	
	$updp_age_limit = intval( get_option( 'updp_age_limit' ) );
	if ( !isset( $updp_age_limit ) || $updp_age_limit == 0 ) {
		$updp_age_limit = UPDP_AGE_LIMIT;
	}

	$updp_show_original_pubdate = intval( get_option( 'updp_show_original_pubdate' ) );
	if ( !isset( $updp_show_original_pubdate ) && !( $updp_show_original_pubdate == 0 || $updp_show_original_pubdate == 1 || $updp_show_original_pubdate == 2 ) ) {
		$updp_show_original_pubdate = 1;
	}

	$updp_pos = intval( get_option( 'updp_pos' ) );
	if ( !( isset( $updp_pos ) ) ) {
		$updp_pos = 1;
	}

	$interval = intval( get_option( 'updp_interval' ) );
	if ( !( isset( $interval ) ) ) {
		$interval = UPDP_INTERVAL;
	}

	$slop = intval( get_option( 'updp_interval_slop' ) );
	if ( !( isset( $slop ) ) ) {
		$slop = UPDP_INTERVAL_SLOP;
	}

	echo '<div class="wrap">';
	echo '<h2>' . esc_html(__( 'Update Posts Date', 'update-posts-date' )) . '</h2>';
	echo '<p>' . esc_html(__( 'Posts on your site will be republished based on the conditions you specify below.', 'update-posts-date' )) . '</p>';
	echo '<p>' . esc_html(__( 'A republished post will have its date reset to the current date and so it will appear in feeds, on your front page and at the top of archive pages.', 'update-posts-date' )) . '</p>';
	echo '<p><strong>' . esc_html(__( 'WARNING', 'update-posts-date' )) . ':</strong> ' . esc_html(__( 'If your permalinks contain dates, disable this plugin immediately.', 'update-posts-date' )) . '</p>';
	echo '<div id="updp-items" class="postbox">';
	echo '<form id="updp" name="updp" action="' . esc_html(sanitize_text_field($_SERVER['REQUEST_URI'])) . '" method="post">';
	echo '<input type="hidden" name="updp_action" value="updp_update_settings">';

	wp_nonce_field('updp_nonce', 'updp_update');

	echo '<table class="form-table" role="presentation">';
	echo '<tbody>';

	echo '<tr>';
	echo '<th scope="row"><label for="updp_interval">' . esc_html(__( 'Minimum Interval Between Post Republishing', 'update-posts-date' )) . '</label></th>';
	echo '<td>';
	echo updp_create_select('updp_interval', updp_interval_list(), $interval);
	echo '</td>';
	echo '</tr>';

	echo '<tr>';
	echo '<th scope="row"><label for="updp_interval_slop">' . esc_html(__( 'Randomness Interval (added to minimum interval)', 'update-posts-date' )) . '</label></th>';
	echo '<td>';
	echo updp_create_select('updp_interval_slop', updp_interval_slop_list(), $slop);
	echo '</td>';
	echo '</tr>';

	echo '<tr>';
	echo '<th scope="row"><label for="updp_age_limit">' . esc_html(__( 'Post age before eligible for republishing', 'update-posts-date' )).'</label></th>';
	echo '<td>';
	echo updp_create_select('updp_age_limit', updp_age_limit_list(), $updp_age_limit);
	echo '</td>';
	echo '</tr>';

	echo '<tr>';
	echo '<th scope="row"><label for="updp_pos">' . esc_html(__( 'Republish post to position (choosing the 2nd position will leave the most recent post in place)', 'update-posts-date' )) . '</label></th>';
	echo '<td>';
	echo updp_create_select('updp_pos', updp_pos_list(), $updp_pos);
	echo '</td>';
	echo '</tr>';

	echo '<tr>';
	echo '<th scope="row"><label for="updp_show_original_pubdate">' . esc_html(__( 'Show original publication date at post end?', 'update-posts-date' )) . '</label></th>';
	echo '<td>';
	echo updp_create_select('updp_show_original_pubdate', updp_original_pubdate_list(), $updp_show_original_pubdate);
	echo '</td>';
	echo '</tr>';

	echo '<tr>';
	echo '<th scope="row"><label for="updp_cats">' . esc_html(__( 'Select categories to omit from republishing', 'update-posts-date' )) . '</label></th>';
	echo '<td>';
	echo '<ul>';
	echo wp_category_checklist( 0, 0, array_map( 'intval', explode( ',', $updp_omit_cats ) ), false, null, false );;
	echo '</ul>';
	echo '</td>';
	echo '</tr>';

	echo '</tbody>';
	echo '</table>';
	echo '<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="' . esc_html(__( 'Update Options', 'update-posts-date' )) . '"></p>';
	echo '</form>';
	echo '</div>';
	echo '</div>';
}