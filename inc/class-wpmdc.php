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
	 * Holds the singleton instance of image processing class
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $instance
	 */
	public $image_processor;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		$this->load_dependencies();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'media_row_actions', array( $this, 'restrict_media_deletion_remove_delete_action' ), 10, 2 );
		add_action( 'wp_ajax_check_attachment_usage', array( $this, 'check_attachment_usage' ) );
		add_filter( 'manage_media_columns', array( $this, 'add_attached_objects_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'populate_attached_objects_column' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_custom_media_field' ), 10, 2 );
		add_action( 'save_post', array( $this, 'invalidate_cache_on_update' ), 10, 1 );
		add_action( 'delete_post', array( $this, 'invalidate_cache_on_update' ), 10, 1 );
		add_action( 'edited_term', array( $this, 'invalidate_cache_on_update' ), 10, 1 );
		add_action( 'deleted_term', array( $this, 'invalidate_cache_on_update' ), 10, 1 );
		add_action( 'wp_ajax_process_all_images', array( $this, 'wpmdc_process_all_images' ) );
		add_action( 'admin_menu', array( $this, 'wpmdc_welcome_page' ) );
		add_action( 'admin_init', array( $this, 'wpmdc_redirect_on_activation' ) );
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
	 * Loading files.
	 */
	public function load_dependencies() {

		/**
		 * Retereving image processor.
		 */
		require_once WPSQR_WPMDC_DIR_PATH . 'inc/class-wp-image-processing.php';
		$this->image_processor = new \WP_Image_Processing();
	}

	/**
	 * Fired during plugin activation.
	 */
	public static function activate() {
		add_option( 'wpmdc_activation_redirect', true );
	}

	/**
	 * Redirect to plugin's page after installation.
	 */
	public function wpmdc_redirect_on_activation() {
		if ( get_option( 'wpmdc_activation_redirect', false ) ) {
			delete_option( 'wpmdc_activation_redirect' );
			if ( ! is_network_admin() && ! isset( $_GET['activate-multi'] ) ) {
				wp_safe_redirect( admin_url( 'tools.php?page=wp_media_check' ) );
				exit;
			}
		}
	}

	/**
	 * Welcome Page of plugin.
	 */
	public function wpmdc_welcome_page() {
		add_management_page(
			'Welcome to WP Media Check',
			'Media Check',
			'manage_options',
			'wp_media_check',
			array( $this, 'wpmdc_page_content' ),
		);
	}

	/**
	 * Welcome Page Content of plugin.
	 */
	public function wpmdc_page_content() {
		global $wpdb;
		// Query to count the total number of attachment posts (images) in the database.
		$total_images_sql = "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment'";
		$total_images     = (int) $wpdb->get_var( $total_images_sql ); //phpcs:ignore

		// Retrieve the image processing progress from the options table.
		$image_processing_progress = get_option( 'wpmdc_image_processing_progress' );
		$cached_data               = get_option( 'wpmdc_images_data', array() );

		// Initialize the image processing progress option if it doesn't already exist.
		if ( ! $image_processing_progress ) {
			$image_processing_progress = array(
				'total'     => $total_images,
				'processed' => count( $cached_data ),
				'pending'   => $total_images - count( $cached_data ),
			);
			update_option( 'wpmdc_image_processing_progress', $image_processing_progress );
		}
		$processed_images = $image_processing_progress['processed'] ? $image_processing_progress['processed'] : 0;
		$pending_images   = $image_processing_progress['pending'] ? $image_processing_progress['pending'] : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Welcome to Media Check Plugin', 'wp-media-check' ); ?></h1>
			<p><?php esc_html_e( 'Thank you for installing ! Here is how to get started...', 'wp-media-check' ); ?></p>
			<div id="wpmdc-image-processing-progress">
				<p><?php esc_html_e( 'Total Images:', 'wp-media-check' ); ?><span id="total-images"><?php esc_html_e( $total_images ); // phpcs:ignore?></span></p> 
				<p><?php esc_html_e( 'Processed:', 'wp-media-check' ); ?><span id="processed-images"><?php esc_html_e( $processed_images ); // phpcs:ignore ?></span></p>
				<p><?php esc_html_e( 'Pending:', 'wp-media-check' ); ?><span id="pending-images"><?php esc_html_e( $pending_images ); // phpcs:ignore ?></span></p>
			</div>
				<button class="button button-primary process_all_images" disabled><?php esc_html_e( 'Process All Images', 'wp-media-check' ); ?></button>
		</div>
		<?php
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
		$posts = $this->image_processor->get_attached_posts( $post->ID );
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
		$posts         = $this->image_processor->get_attached_posts( $attachment_id );
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
			$post_edit_links = $this->image_processor->get_attached_posts( $post_id );
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
		$post_edit_links = $this->image_processor->get_attached_posts( $post_id );

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
	 * Getting list of images and storing in cache.
	 */
	public function wpmdc_process_all_images() {

		$wpmdc_nonce = filter_input( INPUT_POST, 'security', FILTER_SANITIZE_SPECIAL_CHARS );
		if ( ! wp_verify_nonce( $wpmdc_nonce, 'wpsqr_ajax_nonce' ) ) {
			$error_message = __( 'Authentication Error: Nonce verification failed.', 'wp-media-check' );
			wp_send_json_error( $error_message );
		}

		// Get all image IDs from the database.
		$args   = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1, // Retrieve all attachments.
			'fields'         => 'ids', // Only retrieve IDs.
		);
		$images = get_posts( $args );

		// Add each image to the background queue.
		foreach ( $images as $image_id ) {
			$this->image_processor->push_to_queue( $image_id );
		}

		// Dispatch the queue.
		$this->image_processor->save()->dispatch();
		wp_send_json( 'Started Working' );
		exit;
	}

	/**
	 * Invalidate the cache when a post's featured image or other related data is updated.
	 *
	 * @param string $post_id getting id of edited post.
	 */
	public function invalidate_cache_on_update( $post_id ) {
		// Retrieve the cache.
		$cached_data = get_option( 'wpmdc_images_data', array() );

		// Check if the updated post is an image.
		if ( get_post_type( $post_id ) === 'attachment' ) {
			// Remove the post ID from the cached data.
			if ( isset( $cached_data[ $post_id ] ) ) {
				unset( $cached_data[ $post_id ] );
				update_option( 'wpmdc_images_data', $cached_data );
			}
		} else {
			$image_ids_for_post = array();
			$found              = false; // Flag to track if the post is found.
			$attached_images    = $this->get_all_image_ids_from_post( $post_id ); // Fethcing all images of post's and remove their cache.
			if ( ! empty( $attached_images ) ) {
				foreach ( $attached_images as $image_id ) {
					$image_ids_for_post[] = $image_id;
				}
			}

			// Iterate through cached data and removing cahche of images which are coonected to this post.
			foreach ( $cached_data as $image_id => $data ) {
				if ( $found ) {
					break; // Exit outermost loop if found.
				}

				// Check if 'data' array exists and extract its keys dynamically.
				if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
					// Extracting dynamic keys to check.
					$keys_to_check = array_keys( $data['data'] );

					foreach ( $keys_to_check as $key ) {
						if ( $found ) {
							break; // Exit middle loop if found.
						}

						if ( is_array( $data['data'][ $key ] ) ) {
							foreach ( $data['data'][ $key ] as $post_with_image ) {
								if ( isset( $post_with_image['id'] ) && $post_with_image['id'] == $post_id ) {
									$image_ids_for_post[] = $image_id;
									$found                = true; // Set the flag to true.
									break; // Exit innermost loop.
								}
							}
						}
					}
				}
			}

			// Remove the image IDs from cache.
			if ( ! empty( $image_ids_for_post ) ) {
				foreach ( $image_ids_for_post as $image_id ) {
					if ( isset( $cached_data[ $image_id ] ) ) {
						unset( $cached_data[ $image_id ] );
					}
				}
				update_option( 'wpmdc_images_data', $cached_data );
			}
		}
	}

	/**
	 * Getting all images attached to given post ID.
	 *
	 * @param string $post_id getting id of edited post.
	 */
	public function get_all_image_ids_from_post( $post_id ) {
		$image_ids = array();

		// 1. Get the Featured Image ID
		$featured_image_id = get_post_thumbnail_id( $post_id );
		if ( $featured_image_id ) {
			$image_ids[] = $featured_image_id;
		}

		// 2. Get Image IDs from Post Content
		$post_content = get_post_field( 'post_content', $post_id );
		preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $post_content, $matches );
		foreach ( $matches[1] as $src ) {
			if ( ! empty( $src ) && is_string( $src ) ) {
				$attachment_id = $this->get_attachment_id_by_url( $src );
				if ( $attachment_id ) {
					$image_ids[] = $attachment_id;
				}
			}
		}

		// 3. Get Image IDs from Post Meta
		$post_meta = get_post_meta( $post_id );
		foreach ( $post_meta as $meta_key => $meta_values ) {
			foreach ( $meta_values as $meta_value ) {
				if ( is_array( $meta_value ) ) {
					foreach ( $meta_value as $value ) {
						if ( ! empty( $value ) && is_string( $value ) ) {
							$attachment_id = attachment_url_to_postid( $value );
							if ( $attachment_id ) {
								$image_ids[] = $attachment_id;
							}
						}
					}
				} else {
					$attachment_id = attachment_url_to_postid( $meta_value );
					if ( $attachment_id ) {
						$image_ids[] = $attachment_id;
					}
				}
			}
		}
		$image_ids = array_unique( $image_ids );
		return $image_ids;
	}

	/**
	 * Getting image ID from its url.
	 *
	 * @param url $url getting url of image.
	 */
	public function get_attachment_id_by_url( $url ) {
		global $wpdb;

		// Check if $url is not empty and is a string.
		if ( empty( $url ) || ! is_string( $url ) ) {
			return false; // Return 0 if $url is not a valid string.
		}
		$url = esc_url( $url );

		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id == 0 ) { //phpcs:ignore
			$base_url      = preg_replace( '/-\d+x\d+/', '', $url );
			$attachment_id = attachment_url_to_postid( $base_url );
		}

		return $attachment_id ? $attachment_id : 0;
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
