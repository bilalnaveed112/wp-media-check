<?php

namespace WPSQR_WPMDC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions
 * used across the admin area.
 */
class WPSQR_WPMDC {

	/**
	 * Holds the singleton instance of this class
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $instance
	 */
	private static $instance = null;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'media_row_actions', array( $this, 'restrict_media_deletion_remove_delete_action' ), 10, 2 );
		add_action( 'wp_ajax_check_attachment_usage', array( $this, 'check_attachment_usage' ) );
		add_filter( 'manage_media_columns', array( $this, 'add_attached_objects_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'populate_attached_objects_column' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_custom_media_field' ), 10, 2 );
	}

	/**
	 * Enqueue CSS and JavaScript files.
	 * These files will be loaded on the dashboard of the site.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function enqueue_assets() {

		// Enqueue the css files.
		$this->enueue_styles();
		// Enqueue the js files.
		$this->enueue_scripts();
	}

	/**
	 * Register the stylesheets for the plugin.
	 *
	 * @since 1.0.0
	 */
	public function enueue_styles() {
		wp_enqueue_style( 'wpsqr-wpmdc-style', WPSQR_WPMDC_DIR_URL . 'inc/assets/css/style.css', array(), WPSQR_WPMDC_VERSION, 'all' );
	}

	/**
	 * Register the JS scripts for the plugin.
	 *
	 * @since 1.0.0
	 */
	public function enueue_scripts() {
		wp_enqueue_script( 'wpsqr-wpmdc-script', WPSQR_WPMDC_DIR_URL . 'inc/assets/js/script.js', array( 'jquery' ), WPSQR_WPMDC_VERSION, true );
		wp_localize_script(
			'wpsqr-wpmdc-script',
			'wpmdc_ajax_object',
			array( 'security' => wp_create_nonce( 'wpsqr_ajax_nonce' ) ),
		);
	}

	/**
	 * Fired during plugin activation.
	 */
	public static function activate() {
		// handle.
	}

	/**
	 * Fired during plugin deactivation.
	 */
	public static function deactivate() {
		// handle.
	}

	/**
	 * Removes the delete action for media attachments that are in use.
	 *
	 * @param array  $actions An associative array of actions available for the media item.
	 * @param object $post    The media attachment post object.
	 *
	 * @return array Modified array of actions, with 'delete' action removed if the attachment is in use.
	 */
	public function restrict_media_deletion_remove_delete_action( $actions, $post ) {
		$posts = $this->get_attached_posts( $post->ID );
		if ( count( $posts ) > 0 ) {
			$actions['delete'] = '<a href="#" class="wpdmc_disable_delete disabled">' . __( 'Delete Permanently', 'wp-media-check' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Checks whether an attachment is being used in any posts and returns the result via JSON.
	 *
	 * @return void Outputs a JSON response with the status of the attachment usage.
	 */
	public function check_attachment_usage() {
		$wpmdc_nonce = filter_input( INPUT_POST, 'security', FILTER_SANITIZE_SPECIAL_CHARS );
		if ( ! wp_verify_nonce( $wpmdc_nonce, 'wpsqr_ajax_nonce' ) ) {
			$error_message = __( 'Authentication Error: Nonce verification failed.', 'wp-media-check' );
			wp_send_json_error( $error_message );
		}
		$attachment_id = filter_input( INPUT_POST, 'attachment_id', FILTER_VALIDATE_INT );
		$posts         = $this->get_attached_posts( $attachment_id );
		if ( count( $posts ) > 0 ) {
			wp_send_json_success(
				array(
					'find' => true,
					'used' => $posts,
				)
			);
		} else {
			wp_send_json_success(
				array(
					'find' => false,
				)
			);
		}
	}

	/**
	 * Retrieves the count of posts that use a specific attachment.
	 *
	 * @param int $post_id The ID of the attachment to check.
	 *
	 * @return int|false The count of posts using the attachment or `false` if no posts are found.
	 */
	public function get_attached_posts( $post_id ) {
		global $wpdb;

		$transient_key   = 'wpmdc_attached_posts_' . $post_id;
		$post_edit_links = get_transient( $transient_key );

		// Check if the transient is set and return it if available.
		if ( false !== $post_edit_links ) {
			return $post_edit_links;
		}
		// 1. Check if the image is used as a featured image (thumbnail).
		$featured_image_posts = new \WP_Query(
			array(
				'post_type'   => 'any',
				'post_status' => 'any',
				'meta_query'  => array(   //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_thumbnail_id',
						'value'   => $post_id,
						'compare' => '=',
					),
				),
			)
		);

		// 2. Check if the image is used in post content.
		$posts_with_image = get_posts(
			array(
				'post_type'   => 'any',
				'post_status' => 'any',
				's'           => $post_id,
			)
		);

		// 3. Check if the image is used in any post meta fields.
		$meta_query = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching 
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
				'%' . $wpdb->esc_like( $post_id ) . '%'
			)
		);

		// 4. Check if the image is used in any options (widgets, theme settings, etc.).
		$options_query = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching 
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s",
				'%' . $wpdb->esc_like( $post_id ) . '%'
			)
		);

		// 5. Check if the image is used in any taxonomy terms.
		$term_meta_query = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching 
			$wpdb->prepare(
				"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_value LIKE %s",
				'%' . $wpdb->esc_like( $post_id ) . '%'
			)
		);

		$post_edit_links = array();
		$existing_posts  = array();
		if ( ! empty( $featured_image_posts ) ) {
			while ( $featured_image_posts->have_posts() ) {
				$featured_image_posts->the_post();
				$post_id = get_the_ID();
				if ( ! in_array( $post_id, $existing_posts ) ) { //phpcs:ignore
					$post_edit_links[] = '<a href="' . get_edit_post_link() . '">' . get_the_title() . '</a>';
					$existing_posts[]  = $post_id;
				}
			}
		}

		if ( ! empty( $posts_with_image ) ) {
			foreach ( $posts_with_image as $post ) {
				$post_id = $post->ID;
				if ( ! in_array( $post_id, $existing_posts ) ) { //phpcs:ignore
					$post_edit_links[] = '<a href="' . get_edit_post_link( $post_id ) . '">' . $post->post_title . '</a> ';
					$existing_posts[]  = $post_id;
				}
			}
		}

		if ( ! empty( $meta_query ) ) {
			foreach ( $meta_query as $meta ) {
				$post_meta_id = $meta->post_id;
				if ( ! in_array( $post_meta_id, $existing_posts ) ) { //phpcs:ignore
					$post_edit_links[] = '<a href="' . get_edit_post_link( $post_meta_id ) . '">' . get_the_title( $post_meta_id ) . '  ( ' . get_post_status( $post_meta_id ) . ' ) </a>';
					$existing_posts[]  = $post_meta_id;
				}
			}
		}

		if ( ! empty( $options_query ) ) {
			foreach ( $options_query as $option ) {
				$option_name = $option->option_name;
				if ( ! in_array( $option_name, $existing_posts ) ) { //phpcs:ignore
					$post_edit_links[] = 'Option name : <strong>' . esc_html( $option_name ) . '</strong>';
					$existing_posts[]  = $option_name;
				}
			}
		}

		if ( ! empty( $term_meta_query ) ) {
			foreach ( $term_meta_query as $term ) {
				$term_id = $term->term_id;
				if ( ! in_array( $term_id, $existing_posts ) ) { //phpcs:ignore
					$term_name         = get_term( $term_id )->name;
					$taxonomy          = get_term( $term_id )->taxonomy;
					$post_edit_links[] = 'Term : <a href="' . get_edit_term_link( $term_id, $taxonomy ) . '">' . $term_name . ' </a>';
					$existing_posts[]  = $term_id;
				}
			}
		}
		wp_reset_postdata();

		// set_transient( $transient_key, $post_edit_links, 366000000 );

		return $post_edit_links;
	}

	/**
	 * Add "Attached Objects" column to the Media Library table.
	 *
	 * @param array $columns accessing columns of media.
	 */
	public function add_attached_objects_column( $columns ) {
		$columns['attached_objects'] = __( 'Attached Posts', 'wp-media-check' );
		return $columns;
	}

	/**
	 * Populate the "Attached Objects" column with linked IDs
	 *
	 * @param string $column_name accessing columns of media.
	 * @param string $post_id getting post id.
	 */
	public function populate_attached_objects_column( $column_name, $post_id ) {
		if ( 'attached_objects' === $column_name ) {
			// Get media usage links for the post ID.
			$post_edit_links = $this->get_attached_posts( $post_id );
			if ( ! empty( $post_edit_links ) ) {
				echo '<div class="wpmdc_attached">';
				$message = $this->getting_list_of_linked_posts( $post_edit_links );
				echo wp_kses_post( $message );
				echo '</div>';
			} else {
				esc_html_e( 'Not Used', 'wp-media-check' );
			}
		}
	}

	/**
	 * Add_custom_media_field.
	 *
	 * @param string $form_fields accessing fields of column.
	 * @param object $post getting post.
	 */
	public function add_custom_media_field( $form_fields, $post ) {
		$post_id = $post->ID;
		$message = '';

		// Get media usage links for the post ID.
		$post_edit_links = $this->get_attached_posts( $post_id );

		if ( ! empty( $post_edit_links ) ) {
			$message = $this->getting_list_of_linked_posts( $post_edit_links );
		} else {
			$message = __( 'Not Used', 'wp-media-check' );
		}

		$form_fields['attached_objects'] = array(
			'label' => __( 'Attached Posts', 'wp-media-check' ),
			'input' => 'html',
			'html'  => $message,
			'helps' => '',
		);

		return $form_fields;
	}

	/**
	 * Getting list of posts.
	 *
	 * @param array $posts array of posts in which image is being used.
	 */
	public function getting_list_of_linked_posts( $posts ) {
		if ( ! empty( $posts ) ) {
			$posts_list = '<ol>';
			foreach ( $posts as $link ) {
				$posts_list .= '<li>' . wp_kses_post( $link ) . '</li>';
			}
			$posts_list .= '</ol>';
			$posts_list .= '<p class="wpmdc_attached_error">' . esc_html__( 'Since the image is used in the posts above, it cannot be deleted.', 'wp-media-check' ) . '</p>';
		}

		return $posts_list;
	}

	/**
	 * The singleton method
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new WPSQR_WPMDC();
		}
		return self::$instance;
	}
}
