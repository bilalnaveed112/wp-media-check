<?php

class WP_Image_Processing extends WP_Background_Process {

	protected $action = 'wp_image_processing';

	/**
	 * settings tasks for getting attached posts.
	 *
	 * @param string $image_id rendering id of attachment.
	 */
	protected function task( $image_id ) {
		$this->get_attached_posts( $image_id );
		$progress = get_option( 'wpmdc_image_processing_progress' );
		++$progress['processed'];
		--$progress['pending'];
		update_option( 'wpmdc_image_processing_progress', $progress );
		return false;
	}

	/**
	 * Triger after completion of all tasks.
	 */
	protected function complete() {
		parent::complete();
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
}
