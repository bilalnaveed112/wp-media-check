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
	 * @var      object    $image_processor
	 */
	public $image_processor;

	/**
	 * Holds value truw or false for image process.
	 *
	 * @var string    $is_continue_image_processing
	 */
	public $is_continue_image_processing;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		// Loading files.
		$this->load_dependencies();
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Adding page for plugin.
		add_action( 'admin_menu', array( $this, 'wpmdc_welcome_page' ) );
		add_action( 'admin_init', array( $this, 'wpmdc_redirect_on_activation' ) );

		// Adding media row actions.
		add_filter( 'media_row_actions', array( $this, 'restrict_media_deletion_remove_delete_action' ), 10, 2 );
		add_action( 'wp_ajax_check_attachment_usage', array( $this, 'check_attachment_usage' ) );
		add_filter( 'manage_media_columns', array( $this, 'add_attached_objects_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'populate_attached_objects_column' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_custom_media_field' ), 10, 2 );

		// Cache invalidation.
		add_action( 'save_post', array( $this, 'invalidate_cache_on_update' ), 10, 1 );
		add_action( 'delete_post', array( $this, 'invalidate_cache_on_update' ), 10, 1 );
		add_action( 'edited_term', array( $this, 'invalidate_cache_on_update' ), 10, 1 );
		add_action( 'deleted_term', array( $this, 'invalidate_cache_on_update' ), 10, 1 );

		// Handling ajax requests.
		add_action( 'wp_ajax_process_all_images', array( $this, 'wpmdc_process_all_images' ) );
		add_action( 'wp_ajax_cancel_image_processing', array( $this, 'wpmdc_cancel_image_processing' ) );
		add_action( 'wp_ajax_check_progress_status', array( $this, 'wpmdc_check_progress_status' ) );
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
		$image_processing_progress = get_option( 'wpmdc_image_processing_progress' );
		if ( $image_processing_progress ) {
			$total_processed = round( ( ( $image_processing_progress['processed'] / $image_processing_progress['total'] ) * 100 ) );
		}
		wp_localize_script(
			'wpsqr-wpmdc-script',
			'wpmdc_ajax_object',
			array(
				'security'           => wp_create_nonce( 'wpsqr_ajax_nonce' ),
				'is_process_running' => $this->is_continue_image_processing,
				'processed_images'   => $total_processed ? $total_processed : 0,
			),
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
		$this->image_processor              = new \WP_Image_Processing();
		$this->is_continue_image_processing = $this->image_processor->check_if_processing();
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
			esc_html( 'Welcome to WP Media Check', 'wp-media-check' ),
			esc_html( 'Media Check', 'wp-media-check' ),
			'manage_options',
			'wp_media_check',
			array( $this, 'wpmdc_page_content' ),
		);
	}

	/**
	 * Welcome Page Content of plugin.
	 */
	public function wpmdc_page_content() {

		$total_images               = $this->get_all_images();
		$meta_key                   = $this->image_processor->meta_key; // Replace with your actual meta key.
		$total_images_with_meta_key = $this->get_all_processed_images( $meta_key );
		$image_processing_progress  = get_option( 'wpmdc_image_processing_progress' );

		// Initialize the image processing progress option if it doesn't already exist.
		$image_processing_progress = array(
			'total'     => $total_images,
			'processed' => $total_images_with_meta_key,
			'pending'   => $total_images - $total_images_with_meta_key,
		);

		$total_processed = round( ( ( $total_images_with_meta_key / $total_images ) * 100 ) );
		update_option( 'wpmdc_image_processing_progress', $image_processing_progress );
		$processed_images = $image_processing_progress['processed'] ? $image_processing_progress['processed'] : 0;
		$pending_images   = $image_processing_progress['pending'] ? $image_processing_progress['pending'] : 0;

		// Showing progress bar if images are pending.
		$wpmdc_button_disabling  = ( $this->is_continue_image_processing || $pending_images == 0 ) ? 'disabled' : '';
		$wpmdc_button_displaying = $this->is_continue_image_processing ? 'wpmdc_d_block' : 'wpmdc_d_none';
		$estimated_time = $this->get_estimated_time( $pending_images );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Welcome to Media Check Plugin', 'wp-media-check' ); ?></h1>
				<div id="message" class="notice updated wpmdc_notification">
					<p><?php esc_html_e( 'Successfully installed plugin', 'wp-media-check' ); ?></p>
				</div>
			<p><?php esc_html_e( 'Thank you for installing ! Here is how to get started...', 'wp-media-check' ); ?></p>
			<input type="hidden" class="wpmdc_start_cancel_msg" value="<?php echo esc_attr( 'Cancellation process is in progress. please wait for a while.', 'wp-media-check' ); ?>">

			<!-- New design Start -->

			<div class="wpmdc_box__summary wpmdc_d__flex wpmdc_align--center">
				<div class="wpmdc_summary__image__space wpmdc_d__flex--center--center wpmdc_flex__direction--column">
					<div class="wpmdc_circle__score">
						<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
							<circle class="wpmdc_score__background--circle" cx="50" cy="50" r="42"></circle>
							<circle class="wpmdc_score__progress--circle" cx="50" cy="50" r="42" style=""></circle>
						</svg>
						<span class="wpmdc_score__pregress--percentage wpmdc_progress_bar"><?php esc_html_e( $total_processed ); ?>%</span>
					</div>
					<p class="wpmdc__progress__text"><?php esc_html_e( 'Images processed', 'wp-media-check' ); ?></p>
				</div>
				<div class="wpmdc_summary__segment">
					<div class="wpmdc_summary__detail wpmdc_d__flex wpmdc_flex__direction--column">
						<div class="wpmdc_summary__group">
							<span class="wpmdc_summary__count wpmdc_summary__processed__images wpmdc_d_block "><span id="processed-images"><?php esc_html_e( $processed_images ); ?></span><small class="wpmdc_summary__total__images"><span id="total-images"><?php esc_html_e( $total_images ); ?></span></small> </span>
							<span class="wpmdc_summary__text wpmdc_summary__processed__images--text wpmdc_d_block"><?php esc_html_e( 'Processed Images', 'wp-media-check' ); ?></span>
						</div>
						<div class="wpmdc_summary__group">
							<span class="wpmdc_summary__count wpmdc_summary__pending__images wpmdc_d_block " id="pending-images"><?php esc_html_e( $pending_images ); ?></span>
							<span class="wpmdc_summary__text wpmdc_summary__pending__images--text wpmdc_d_block"><?php esc_html_e( 'Pending Images', 'wp-media-check' ); ?></span>
						</div>
					</div>
				</div>
				<div class="wpmdc_summary__segment">
					<div class="wpmdc_summary__detail wpmdc_d__flex wpmdc_flex__direction--column">
						<div class="wpmdc_summary__group">
							<span class="wpmdc_summary__text wpmdc_summary__total__images--text wpmdc_d_block"><?php esc_html_e( 'Total estimated time ', 'wp-media-check' ); ?><strong class="wpmdc_d_block"><?php echo ' <span class="time_estimation">' . esc_html( $estimated_time['total_estimated_time'] ) . '</span><span class="time_unit">' . esc_html( $estimated_time['time_unit'] ) . '</span>'; ?> </strong></span>
						</div>
						<div class="wpmdc_summary__group wpmdc_summary__actions wpmdc_d__flex wpmdc_justify--betwen">
							<button type="button" class="button button-primary button-large process_all_images" <?php echo esc_attr( $wpmdc_button_disabling ); ?>><?php esc_html_e( 'Start Processing', 'wp-media-check' ); ?></button>
							<button type="button" class="button button-large cancel_process <?php echo esc_attr( $wpmdc_button_displaying ); ?>"><?php esc_html_e( 'Cancel', 'wp-media-check' ); ?></button>
						</div>
						
					</div>
				</div>
			</div>

			<!-- new design end  -->
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
			'meta_query'     => array(
				array(
					'key'     => $this->image_processor->meta_key,
					'compare' => 'NOT EXISTS',
				),
			),
		);
		$images = get_posts( $args );

		// Add each image to the background queue.
		if ( ! empty( $images ) ) {
			foreach ( $images as $image_id ) {
				$this->image_processor->push_to_queue( $image_id );
			}
			// Dispatch the queue.
			$this->image_processor->save()->dispatch();
			$message = __( 'Process has been started.', 'wp-media-check' );
			wp_send_json_success(
				array(
					'status'  => true,
					'message' => $message,
				)
			);
		} else {
			$message = __( 'All the images are already processed.', 'wp-media-check' );
			wp_send_json_success(
				array(
					'status'  => false,
					'message' => $message,
				)
			);
		}
		exit;
	}

	/**
	 * Invalidate the cache when a post's featured image or other related data is updated.
	 *
	 * @param string $post_id getting id of edited post.
	 */
	public function invalidate_cache_on_update( $post_id ) {

		// meta key.
		$meta_key = $this->image_processor->meta_key;
		$post     = get_post( $post_id );
		// Retrieve the saved values.
		if ( $post ) {
			$saved_images = get_post_meta( $post_id, $meta_key, true );
			if ( get_post_type( $post_id ) == 'attachment' ) {
				delete_post_meta( $post_id, $meta_key );
			}
		} else {
			$saved_images = get_term_meta( $post_id, $meta_key, true );
		}

		if ( ! empty( $saved_images ) ) {
			foreach ( $saved_images as $image_id ) {
				if ( $image_id && 'attachment' == get_post_type( $image_id ) ) {
					delete_post_meta( $image_id, $meta_key );
				}
			}
		}
		if ( $post ) {
			delete_post_meta( $post_id, $meta_key );
		} else {
			delete_term_meta( $post_id, $meta_key );
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
	 * Checking status of running background process.
	 */
	public function wpmdc_check_progress_status() {
		$wpmdc_nonce = filter_input( INPUT_POST, 'security', FILTER_SANITIZE_SPECIAL_CHARS );
		if ( ! wp_verify_nonce( $wpmdc_nonce, 'wpsqr_ajax_nonce' ) ) {
			$error_message = __( 'Authentication Error: Nonce verification failed.', 'wp-media-check' );
			wp_send_json_error( $error_message );
		}

		$image_processing_progress = get_option( 'wpmdc_image_processing_progress' );
		$pending_images   = $image_processing_progress['pending'] ? $image_processing_progress['pending'] : 0;
		$total_estimated_time = $this->get_estimated_time( $pending_images );
		wp_send_json_success( [ 'progress' => $image_processing_progress , 'estimated_time' => $total_estimated_time] );
		die();
	}

	/**
	 * Cancel process of background process.
	 */
	public function wpmdc_cancel_image_processing() {
		$wpmdc_nonce = filter_input( INPUT_POST, 'security', FILTER_SANITIZE_SPECIAL_CHARS );
		if ( ! wp_verify_nonce( $wpmdc_nonce, 'wpsqr_ajax_nonce' ) ) {
			$error_message = __( 'Authentication Error: Nonce verification failed.', 'wp-media-check' );
			wp_send_json_error( $error_message );
		}

		$WP_Background_Process = new \WP_Image_Processing();
		do {
			try {
				$WP_Background_Process->cancel_processing();
				usleep( 500000 ); // 0.5 second delay.
			} catch ( Exception $e ) {
				error_log( 'Error while canceling processing: ' . $e->getMessage() );
				wp_send_json_error( __( 'Error: Failed to cancel processing.', 'wp-media-check' ) );
				return;
			}
			// Check if processing is still ongoing.
		} while ( $WP_Background_Process->check_if_processing() );
		$message = esc_html__( 'Image processing has been stopped.', 'wp-media-check' );
		wp_send_json_success( $message );
	}

	public function get_all_images() {
		global $wpdb;
		// Query to count the total number of attachment posts (images) in the database.
		$query_of_total_images_in_wp = "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment'";
		$total_images                = (int) $wpdb->get_var( $query_of_total_images_in_wp ); //phpcs:ignore
		return $total_images;
	}

	public function get_all_processed_images( $meta_key ) {
		global $wpdb;
		// SQL query to count images with a specific meta key.
		$count_images_sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'attachment'
			AND pm.meta_key = %s",
			$meta_key
		);
		// Get the count of images.
		$total_images_with_meta_key = (int) $wpdb->get_var( $count_images_sql ); //phpcs:ignore
		return $total_images_with_meta_key;
	}

	public function get_estimated_time( $pending_images ) {
		$time_unit = esc_html( ' seconds', 'wp-media-check' );
		if ( $pending_images > 0 ) {
			$processing_time_per_img = 3 / 10;
			$total_estimated_time    = round( ( ( $processing_time_per_img * $pending_images ) / 60 ) );
			$time_unit               = esc_html( ' minutes', 'wp-media-check' );
			if ( $total_estimated_time < 1 ) {
				$total_estimated_time = round( ( ( $processing_time_per_img * $pending_images ) / 60 ), 2 );
			}
		} else {
			$total_estimated_time = 0;
		}

		$estimated_time = [
			'total_estimated_time' => $total_estimated_time,
			'time_unit' => $time_unit
		];
		return $estimated_time;
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
