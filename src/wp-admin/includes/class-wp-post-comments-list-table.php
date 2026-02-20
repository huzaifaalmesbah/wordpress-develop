<?php
/**
 * List Table API: WP_Post_Comments_List_Table class
 *
 * @package WordPress
 * @subpackage Administration
 * @since 4.4.0
 */

/**
 * Core class used to implement displaying post comments in a list table.
 *
 * @since 3.1.0
 *
 * @see WP_Comments_List_Table
 */
class WP_Post_Comments_List_Table extends WP_Comments_List_Table {

	/**
	 * Gets column info for the post comments table.
	 *
	 * @since 4.4.0
	 *
	 * @return array<int, mixed> Column info: columns, hidden columns, sortable columns, and primary column.
	 */
	protected function get_column_info() {
		return array(
			array(
				'author'  => __( 'Author' ),
				'comment' => _x( 'Comment', 'column name' ),
			),
			array(),
			array(),
			'comment',
		);
	}

	/**
	 * Gets a list of CSS classes for the WP_List_Table table tag.
	 *
	 * @since 4.4.0
	 *
	 * @return string[] Array of CSS classes for the table tag.
	 */
	protected function get_table_classes() {
		$classes   = parent::get_table_classes();
		$classes[] = 'wp-list-table';
		$classes[] = 'comments-box';
		return $classes;
	}

	/**
	 * Displays the post comments table.
	 *
	 * @since 4.4.0
	 *
	 * @param bool $output_empty Whether to output an empty list. Default false.
	 */
	public function display( $output_empty = false ) {
		$singular = $this->_args['singular'];

		wp_nonce_field( 'fetch-list-' . get_class( $this ), '_ajax_fetch_list_nonce' );
		?>
<table class="<?php echo implode( ' ', $this->get_table_classes() ); ?>" style="display:none;">
	<tbody id="the-comment-list"
		<?php
		if ( $singular ) {
			echo " data-wp-lists='list:$singular'";
		}
		?>
		>
		<?php
		if ( ! $output_empty ) {
			$this->display_rows_or_placeholder();
		}
		?>
	</tbody>
</table>
		<?php
	}

	/**
	 * Gets the number of comments to display per page.
	 *
	 * @since 4.4.0
	 *
	 * @param bool $comment_status Not used. Default false.
	 * @return int Number of comments to display per page.
	 */
	public function get_per_page( $comment_status = false ) {
		return 10;
	}
}
