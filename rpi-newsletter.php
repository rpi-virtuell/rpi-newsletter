<?php

/*
Plugin Name: Rpi Newsletter
Plugin URI: http://URI_Of_Page_Describing_Plugin_and_Updates
Description: RPI Newsletter Plugin for Newsletter Mainserver
Version: 1.0
Author: reintanz
Author URI: http://URI_Of_The_Plugin_Author
License: A "Slug" license name e.g. GPL2
*/
require_once 'classes/rpi-newsletter-cron.php';
require_once 'classes/rpi-post-importer.php';

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class RpiNewsletter
{

    public function __construct()
    {
        add_action('cron_post_import_newsletter', [$this, 'getAllInstancesAndImportPosts']);
        add_action('save_post', [$this, 'addInstanceTermOnSave'], 10, 3);
        //TODO delete this and function if development is finished
        add_shortcode('post_import_newsletter', [$this, 'test']);


        // Add custom Columns in Posttype Newsletter Post
        add_filter('manage_instanz_posts_columns', [$this, 'add_custom_columns']);
        // Populate the custom columns with data
        add_action('manage_instanz_posts_custom_column', [$this, 'custom_columns_content'], 10, 2);

        add_filter('manage_newsletter-post_posts_columns', [$this, 'add_custom_columns']);
//         Populate the custom columns with data
        add_action('manage_newsletter-post_posts_custom_column', [$this, 'custom_columns_content'], 10, 2);

        add_filter('the_content', [$this, 'redirect_to_origin_page']);

    }

    function redirect_to_origin_page($content)
    {
        if (is_single() && get_post_type() === 'post' && !is_admin()) {
            $origin_link = get_post_meta(get_the_ID(), 'import_link', true);
            if (!empty($origin_link)) {
                wp_redirect($origin_link);
            }
        } else {
            return $content;
        }
    }

    // Add new columns to the custom post type

    function add_custom_columns($columns)
    {
        $columns['term_instanz'] = __('Term Instanzen', 'rpi-newsletter');
//        $columns['custom_column_2'] = __('Custom Column 2', 'rpi-newsletter');
        return $columns;
    }

// Populate the custom columns with data

    function custom_columns_content($column, $post_id)
    {
        switch ($column) {
            case 'term_instanz':
                // Display data for Custom Column 1
                $terms = wp_get_post_terms($post_id, 'term_instanz', array('field' => 'names'));
                foreach ($terms as $term) {
                    echo '<a href="' . get_home_url() . '/wp-admin/edit.php?term_instanz=' . $term->slug . '&post_type=newsletter-post">' . $term->name . '</a>';
                }
                break;

        }
    }

    public function test()
    {
        ob_start();

        echo 'Shortcode Triggered';
        $this->getAllInstancesAndImportPosts();
        return ob_get_clean();
    }


    public function getAllInstancesAndImportPosts()
    {

        $args = [
            'post_type' => 'instanz',
            'numberposts' => -1
        ];

        $instances = get_posts($args);
        RpiNewsletter::log('Found ' . count($instances) . ' instances to process.');

        foreach ($instances as $instance) {
            $api_url = get_post_meta($instance->ID, 'api_url', true);

            while (have_rows('api_urls')): the_row();
                $url = get_sub_field('api_url');
                if (wp_http_validate_url($url))
                    $api_urls [] = $url;
            endwhile;


            $standard_terms = get_post_meta($instance->ID, 'standard_terms', true);
            $standard_user = get_post_meta($instance->ID, 'standard_user', true);
            $dryrun = get_post_meta($instance->ID, 'dryrun', true);
            $debugmode = get_post_meta($instance->ID, 'debugmode', true);
            $status_ignorelist = [];

            $graphql = get_post_meta($instance->ID, 'graphql_import', true);
            $graphql_body = get_post_meta($instance->ID, 'graphql_request_body', true);


            // Loop through rows.
            $row = 0;
            $term_mapping = array();
            while (have_rows('term_mapping')) : the_row();

                // Load sub field value.
                $term_mapping[$row]['target_tax'] = get_sub_field('target_tax');
                $term_mapping[$row]['source_tax'] = get_sub_field('source_tax');
                $term_mapping[$row]['default_term'] = get_sub_field('default_term');
                $row++;

                // End loop.
            endwhile;


            RpiNewsletter::log("Processing instance ID {$instance->ID} with API URL {$api_url}", $debugmode);
            $post_ids = [];

            $importer = new RPIPostImporter();
            $result = $importer->rpi_import_post($api_url, $status_ignorelist, $term_mapping, $dryrun, $debugmode, $graphql, $graphql_body);

            if ($result) {
                if (is_array($result)) {
                    foreach ($result as $key => $value) {
                        if (key_exists('id', $value)) {
                            $result[$key] = $value['id'];
                        }
                    }
                    RpiNewsletter::log('Array as Result detected : ' . var_export($result, true));
                }
                $post_ids = array_merge($post_ids, $result);


            }

            RpiNewsletter::log('Imported posts: ' . implode(', ', $post_ids));

            foreach ($post_ids as $post_id) {
                if (!empty($standard_user)) {

                    $post_arr = ['ID' => $post_id, 'post_author' => $standard_user];
                    $result = wp_update_post($post_arr, true);

                    if (is_wp_error($result)) {
                        RpiNewsletter::log("Error updating post author for post ID {$post_id}: " . $result->get_error_message(), $debugmode);
                    } else {
                        RpiNewsletter::log("Updated post author for post ID {$post_id} to user ID {$standard_user}", $debugmode);
                    }
                }

                if (empty(wp_get_post_terms($post_id, 'term_instanz'))) {
                    $target_instanz_term = wp_get_post_terms($instance->ID, 'term_instanz', array('fields' => 'ids'));
                    RpiNewsletter::log("Set Instanz Term of Imported Post : {$post_id}: " . implode(', ', $target_instanz_term), $debugmode);
                    wp_set_post_terms($post_id, $target_instanz_term, 'term_instanz', true);
                }

                if (!empty($standard_terms)) {
                    wp_set_post_terms($post_id, $standard_terms, 'post_tag', true);
                    RpiNewsletter::log("Set Tags for post ID {$post_id}: " . implode(', ', $standard_terms), $debugmode);
                }
            }

        }

        RpiNewsletter::log('Cron completed');
        return;
    }

    static function log($message, $logging = true)
    {
        if ($logging) {

            $log_file = WP_CONTENT_DIR . '/rpi_post_importer_log.txt'; // Path to the log file
            $timestamp = current_time('mysql');
            $entry = "{$timestamp}: {$message}\n";

            file_put_contents($log_file, $entry, FILE_APPEND);
        }
    }

    public function addInstanceTermOnSave($post_id, $post, $update)
    {

        if (is_a($post, 'WP_Post') && $post->post_type == 'instanz' && !has_term($post->post_name, 'term_instanz', $post->ID)) {


            $result = wp_create_term($post->post_name, 'term_instanz');
            if (is_wp_error($result)) {
                echo $result->get_error_message();
            }
            wp_set_post_terms($post->ID, $post->post_name, 'term_instanz', true);

        }
    }

}

new RpiNewsletter();