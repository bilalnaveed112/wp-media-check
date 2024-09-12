<?php
/**
 * WP_Image_Processing.php
 *
 * This file contains the WP_Image_Processing class
 *
 * @package wp-media-check
 */

/**
 * WP_Image_Processing Class.
 *
 * This class extends WP_Background_Process to handle image processing tasks
 * in the background, ensuring that long-running image processing operations
 * do not block the main thread and can be performed asynchronously.
 *
 * @since 1.0.0
 */
class WP_Image_Processing extends WP_Background_Process {

	/**
	 * The type of process this class handles.
	 *
	 * @var string
	 */
	protected $action = 'wp_image_processing';

	/**
	 * The type of process this class handles.
	 *
	 * @var string
	 */
	public $meta_key = 'wpmdc_added_images';

	/**
	 * Settings tasks for getting attached posts.
	 *
	 * @param string $image_id rendering id of attachment.
	 */
	protected function task( $image_id ) {
		$progress = get_option( 'wpmdc_image_processing_progress' );
		if ( $progress['total'] != $progress['processed'] ) {
			$this->get_attached_posts( $image_id );
			++$progress['processed'];
			--$progress['pending'];
			update_option( 'wpmdc_image_processing_progress', $progress );
		}
		return false;
	}

	/**
	 * Called when the background process is finished.
	 *
	 * This method is called once all tasks have been processed. You can use
	 * it to clean up, log results, or trigger any other final operations.
	 *
	 * @return void
	 */
	protected function complete() {
		parent::complete();
	}

	public function check_if_processing() {
		return $this->is_process_running();
	}

	public function cancel_processing() {
		return $this->cancel_process();
	}

	/**
	 * Retrieves the count of posts that use a specific attachment.
	 *
	 * @param int $image_id The ID of the attachment to check.
	 *
	 * @return int|false The count of posts using the attachment or `false` if no posts are found.
	 */
	public function get_attached_posts( $image_id ) {
		$saved_post_links = get_post_meta( $image_id, $this->meta_key, true ); // Retrieve the cached data from options.

		// Check if the specific post ID exists in the cached data.
		if ( ! empty( $saved_post_links ) ) {
			return $saved_post_links;
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
		$image_url = wp_get_attachment_url( $image_id );
		if ( $image_url ) {
			$options_query = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching 
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_value LIKE %s",
					'%' . $wpdb->esc_like( $image_url ) . '%'
				)
			);
		}

		// 5. Check if the image is used in any taxonomy terms.
		$term_meta_query = $wpdb->get_results( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching 
			$wpdb->prepare(
				"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_value LIKE %s",
				'%' . $wpdb->esc_like( $image_id ) . '%'
			)
		);

		$post_edit_links = array();
		$existing_posts  = array();
		if ( ! empty( $featured_image_posts ) ) {
			while ( $featured_image_posts->have_posts() ) {
				$featured_image_posts->the_post();
				$post_id = get_the_ID();
				if ( ! in_array( $post_id, $existing_posts ) ) { //phpcs:ignore
					$post_edit_links[] = '<a href="' . get_edit_post_link( $post_id ) . '">' . get_the_title() . '</a>';
					$existing_posts[]  = $post_id;
					$this->add_post_meta( $post_id, $image_id );
				}
			}
		}

		if ( ! empty( $posts_with_image ) ) {
			foreach ( $posts_with_image as $post ) {
				$post_id = $post->ID;
				if ( ! in_array( $post_id, $existing_posts ) && $post->post_type != 'attachment' && $post->post_type != 'revision' ) { //phpcs:ignore
					$post_edit_links[] = '<a href="' . get_edit_post_link( $post_id ) . '">' . $post->post_title . '</a> ';
					$existing_posts[]  = $post_id;
					$this->add_post_meta( $post_id, $image_id );
				}
			}
		}

		if ( ! empty( $meta_query ) ) {
			foreach ( $meta_query as $meta ) {
				$post         = get_post( $meta );
				$post_meta_id = $post->ID;
				if ( $post_meta_id && ! in_array( $post_meta_id, $existing_posts ) && $post->post_type != 'attachment' && $post->post_type != 'revision' && $post_meta_id != $image_id ) { //phpcs:ignore
					$post_edit_links[] = '<a href="' . get_edit_post_link( $post_meta_id ) . '">' . get_the_title( $post_meta_id ) . '  ( ' . get_post_status( $post_meta_id ) . ' ) </a>';
					$existing_posts[]  = $post_meta_id;
					$this->add_post_meta( $post_meta_id, $image_id );
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
					$term_info         = get_term( $term_id );
					$term_name         = $term_info->name;
					$taxonomy          = $term_info->taxonomy;
					$post_edit_links[] = 'Term : <a href="' . get_edit_term_link( $term_id, $taxonomy ) . '">' . $term_name . ' </a>';
					$existing_posts[]  = $term_id;
					$this->add_term_meta( $term_id, $image_id );
				}
			}
		}
		wp_reset_postdata();
		if ( ! empty( $post_edit_links ) ) {
			update_post_meta( $image_id, $this->meta_key, $post_edit_links );
		}
		return $post_edit_links;
	}

	public function add_post_meta( $post_id, $image_id ) {
		$existing_image_ids = get_post_meta( $post_id, $this->meta_key, true );
		$existing_image_ids = is_array( $existing_image_ids ) ? $existing_image_ids : array();
		if ( ! in_array( $image_id, $existing_image_ids ) ) {
			$existing_image_ids[] = $image_id;
			update_post_meta( $post_id, $this->meta_key, $existing_image_ids );
		}
	}

	public function add_term_meta( $post_id, $image_id ) {
		$existing_image_ids = get_term_meta( $post_id, $this->meta_key, true );
		$existing_image_ids = is_array( $existing_image_ids ) ? $existing_image_ids : array();
		if ( ! in_array( $image_id, $existing_image_ids ) ) {
			$existing_image_ids[] = $image_id;
			update_term_meta( $post_id, $this->meta_key, $existing_image_ids );
		}
	}
}
