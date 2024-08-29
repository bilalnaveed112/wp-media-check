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
		add_action( 'save_post', array( $this, 'invalidate_cache_on_update' ), 10, 1 );
		add_action( 'wpmdc_cache_all_images_event', array( $this, 'wpmdc_cache_all_images_event' ) );
		add_filter( 'cron_schedules', array( $this, 'wpmdc_cron_schedules' ) );
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
		// if ( ! wp_next_scheduled( 'wpmdc_cache_all_images_event' ) ) {
		//  wp_schedule_event( time() + 60, 'five_minutes', 'wpmdc_cache_all_images_event' );
		// }
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
	 * @param int $image_id The ID of the attachment to check.
	 *
	 * @return int|false The count of posts using the attachment or `false` if no posts are found.
	 */
	public function get_attached_posts( $image_id ) {
		$cached_data = get_option( 'wpmdc_images_data', array() ); // Retrieve the cached data from options.

		// Check if the specific post ID exists in the cached data.
		if ( isset( $cached_data[ $image_id ] ) ) {
			if ( ! empty( $cached_data[ $image_id ]['links'] ) ) {
				return $cached_data[ $image_id ]['links'];
			}
		}
		global $wpdb;

		// 1. Check if the image is used as a featured image (thumbnail).
		$featured_image_posts = new \WP_Query(
			array(
				'post_type'   => 'any',
				'post_status' => 'any',
				'meta_query'  => array(   //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_thumbnail_id',
						'value'   => $image_id,
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
				's'           => $image_id,
			)
		);

		// 3. Check if the image is used in any post meta fields.
		$meta_query = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching 
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
				'%' . $wpdb->esc_like( $image_id ) . '%'
			)
		);

		// 4. Check if the image is used in any options (widgets, theme settings, etc.).
		$options_query = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching 
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s",
				'%' . $wpdb->esc_like( $image_id ) . '%'
			)
		);

		// 5. Check if the image is used in any taxonomy terms.
		$term_meta_query = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching 
			$wpdb->prepare(
				"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_value LIKE %s",
				'%' . $wpdb->esc_like( $image_id ) . '%'
			)
		);

		$post_edit_links    = array();
		$existing_post_data = array();
		$existing_posts     = array();
		if ( ! empty( $featured_image_posts ) ) {
			while ( $featured_image_posts->have_posts() ) {
				$featured_image_posts->the_post();
				$post_id = get_the_ID();
				if ( ! in_array( $post_id, $existing_posts ) ) { //phpcs:ignore
					$post_edit_links[]                            = '<a href="' . get_edit_post_link( $post_id ) . '">' . get_the_title() . '</a>';
					$existing_post_data['featured_image_posts'][] = array(
						'id'    => $post_id,
						'title' => get_the_title( $post_id ),
						'link'  => get_edit_post_link( $post_id ),
					);
					$existing_posts[]                             = $post_id;
				}
			}
		}

		if ( ! empty( $posts_with_image ) ) {
			foreach ( $posts_with_image as $post ) {
				$post_id = $post->ID;
				if ( ! in_array( $post_id, $existing_posts ) ) { //phpcs:ignore
					$post_edit_links[]                        = '<a href="' . get_edit_post_link( $post_id ) . '">' . $post->post_title . '</a> ';
					$existing_post_data['posts_with_image'][] = array(
						'id'    => $post_id,
						'title' => $post->post_title,
						'link'  => get_edit_post_link( $post_id ),
					);
					$existing_posts[]                         = $post_id;
				}
			}
		}

		if ( ! empty( $meta_query ) ) {
			foreach ( $meta_query as $meta ) {
				$post_meta_id = $meta->post_id;
				if ( ! in_array( $post_meta_id, $existing_posts ) ) { //phpcs:ignore
					$post_edit_links[]                     = '<a href="' . get_edit_post_link( $post_meta_id ) . '">' . get_the_title( $post_meta_id ) . '  ( ' . get_post_status( $post_meta_id ) . ' ) </a>';
					$existing_post_data['posts_in_meta'][] = array(
						'id'     => $post_meta_id,
						'title'  => get_the_title( $post_meta_id ),
						'status' => get_post_status( $post_meta_id ),
						'link'   => get_edit_post_link( $post_meta_id ),
					);
					$existing_posts[]                      = $post_meta_id;
				}
			}
		}

		if ( ! empty( $options_query ) ) {
			foreach ( $options_query as $option ) {
				$option_name = $option->option_name;
				if ( ! in_array( $option_name, $existing_posts ) && $option_name !== 'wpmdc_images_data' ) { //phpcs:ignore
					$post_edit_links[]               = 'Option name : <strong>' . esc_html( $option_name ) . '</strong>';
					$existing_post_data['options'][] = array(
						'name' => $option_name,
					);
					$existing_posts[]                = $option_name;
				}
			}
		}

		if ( ! empty( $term_meta_query ) ) {
			foreach ( $term_meta_query as $term ) {
				$term_id = $term->term_id;
				if ( ! in_array( $term_id, $existing_posts ) ) { //phpcs:ignore
					$term_info                     = get_term( $term_id );
					$term_name                     = $term_info->name;
					$taxonomy                      = $term_info->taxonomy;
					$post_edit_links[]             = 'Term : <a href="' . get_edit_term_link( $term_id, $taxonomy ) . '">' . $term_name . ' </a>';
					$existing_post_data['terms'][] = array(
						'id'       => $term_id,
						'name'     => $term_info->name,
						'taxonomy' => $term_info->taxonomy,
						'link'     => get_edit_term_link( $term_id, $term_info->taxonomy ),
					);
					$existing_posts[]              = $term_id;
				}
			}
		}
		wp_reset_postdata();
		if ( ! empty( $existing_post_data ) && ! empty( $post_edit_links ) ) {
			$cached_data[ $image_id ]['data']  = $existing_post_data;
			$cached_data[ $image_id ]['links'] = $post_edit_links;
			update_option( 'wpmdc_images_data', $cached_data );
		}

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
	 * Getting list of images and storing in cache.
	 */
	public function wpmdc_cache_all_images_event() {
		// Get the current progress from options or start fresh.
		$paged      = get_option( 'wpmdc_cache_images_paged', 1 );
		$batch_size = 500; // Number of images per batch.

		// Query attachments.
		$args        = array(
			'post_type'      => 'attachment',
			'posts_per_page' => $batch_size,
			'paged'          => $paged,
			'post_status'    => 'any',
		);
		$attachments = new \WP_Query( $args );

		// If no more attachments, clear scheduled event and reset progress.
		if ( ! $attachments->have_posts() ) {
			wp_clear_scheduled_hook( 'wpmdc_cache_all_images_event' );
			delete_option( 'wpmdc_cache_images_paged' );
			return;
		}

		// Process each attachment.
		foreach ( $attachments->posts as $post ) {
			$post_id = $post->ID;
			$this->get_attached_posts( $post_id );
		}

		// Increment the page number for the next batch and save it.
		++$paged;
		update_option( 'wpmdc_cache_images_paged', $paged );
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
			$attached_images    = $this->get_all_image_ids_from_post( $post_id );
			$found              = false; // Flag to track if the post is found.
			if ( ! empty( $attached_images ) ) {
				foreach ( $attached_images as $image_id ) {
					$image_ids_for_post[] = $image_id;
				}
			}

			// Iterate through cached data.
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
	 * Adding Custom time for scheduling.
	 *
	 * @param string $schedules getting id of edited post.
	 */
	public function wpmdc_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['five_minutes'] ) ) {
			$schedules['five_minutes'] = array(
				'interval' => 300,
				'display'  => esc_html__( 'Every Minute' ),
			);
		}
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
