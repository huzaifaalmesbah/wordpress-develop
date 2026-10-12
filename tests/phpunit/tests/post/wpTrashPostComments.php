<?php

/**
 * Tests for the wp_trash_post_comments() and wp_untrash_post_comments() functions.
 *
 * @group post
 * @group comment
 */
class Tests_Post_WpTrashPostComments extends WP_UnitTestCase {

	/**
	 * Post with comments in several statuses.
	 *
	 * @var int
	 */
	protected static $post_id;

	/**
	 * Post without comments.
	 *
	 * @var int
	 */
	protected static $empty_post_id;

	/**
	 * Post whose comments must never be changed.
	 *
	 * @var int
	 */
	protected static $other_post_id;

	/**
	 * Comment IDs of the main post, keyed by their original status.
	 *
	 * @var int[]
	 */
	protected static $comment_ids = array();

	/**
	 * Approved comment of the other post.
	 *
	 * @var int
	 */
	protected static $other_comment_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$post_id       = $factory->post->create();
		self::$empty_post_id = $factory->post->create();
		self::$other_post_id = $factory->post->create();

		foreach ( array( '1', '0', 'spam', 'trash' ) as $status ) {
			self::$comment_ids[ $status ] = $factory->comment->create(
				array(
					'comment_post_ID'  => self::$post_id,
					'comment_approved' => $status,
				)
			);
		}

		self::$other_comment_id = $factory->comment->create(
			array(
				'comment_post_ID'  => self::$other_post_id,
				'comment_approved' => '1',
			)
		);
	}

	/**
	 * Reads the stored status of each comment straight from the database.
	 *
	 * @return string[] Comment statuses keyed by comment ID.
	 */
	private function get_stored_statuses() {
		global $wpdb;

		$statuses = array();
		$rows     = $wpdb->get_results( "SELECT comment_ID, comment_approved FROM $wpdb->comments ORDER BY comment_ID ASC" );

		foreach ( $rows as $row ) {
			$statuses[ (int) $row->comment_ID ] = $row->comment_approved;
		}

		return $statuses;
	}

	/**
	 * Returns the original status of every fixture comment, keyed by comment ID.
	 *
	 * @return string[]
	 */
	private function get_original_statuses() {
		return array(
			self::$comment_ids['1']     => '1',
			self::$comment_ids['0']     => '0',
			self::$comment_ids['spam']  => 'spam',
			self::$comment_ids['trash'] => 'trash',
			self::$other_comment_id     => '1',
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_trash_post_comments
	 */
	public function test_trash_should_return_null_for_a_post_that_does_not_exist() {
		$action = new MockAction();
		add_action( 'trash_post_comments', array( $action, 'action' ) );

		$this->assertNull( wp_trash_post_comments( PHP_INT_MAX ) );
		$this->assertSame( 0, $action->get_call_count(), 'The trash_post_comments action should not fire.' );
		$this->assertSame( $this->get_original_statuses(), $this->get_stored_statuses(), 'No comment should be changed.' );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_trash_post_comments
	 */
	public function test_trash_should_return_null_for_a_post_without_comments() {
		$before = new MockAction();
		$after  = new MockAction();
		add_action( 'trash_post_comments', array( $before, 'action' ) );
		add_action( 'trashed_post_comments', array( $after, 'action' ) );

		$this->assertNull( wp_trash_post_comments( self::$empty_post_id ) );
		$this->assertSame( array( array( self::$empty_post_id ) ), $before->get_args(), 'The trash_post_comments action should fire with the post ID.' );
		$this->assertSame( 0, $after->get_call_count(), 'The trashed_post_comments action should not fire.' );
		$this->assertSame( array(), get_post_meta( self::$empty_post_id, '_wp_trash_meta_comments_status' ), 'No statuses should be stored.' );
		$this->assertSame( $this->get_original_statuses(), $this->get_stored_statuses(), 'No comment should be changed.' );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_trash_post_comments
	 */
	public function test_trash_should_mark_all_comments_of_the_post_and_store_their_statuses() {
		$expected_stored = array(
			self::$comment_ids['1']     => '1',
			self::$comment_ids['0']     => '0',
			self::$comment_ids['spam']  => 'spam',
			self::$comment_ids['trash'] => 'trash',
		);

		$this->assertSame( 4, wp_trash_post_comments( self::$post_id ), 'The number of updated comments should be returned.' );

		$this->assertSame(
			array(
				self::$comment_ids['1']     => 'post-trashed',
				self::$comment_ids['0']     => 'post-trashed',
				self::$comment_ids['spam']  => 'post-trashed',
				self::$comment_ids['trash'] => 'post-trashed',
				self::$other_comment_id     => '1',
			),
			$this->get_stored_statuses(),
			'Only the comments of the post should be marked as post-trashed.'
		);

		$this->assertSame(
			array( $expected_stored ),
			get_post_meta( self::$post_id, '_wp_trash_meta_comments_status' ),
			'The original statuses should be stored once in post meta.'
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_trash_post_comments
	 */
	public function test_trash_should_fire_actions_with_post_id_and_statuses() {
		$before = new MockAction();
		$after  = new MockAction();
		add_action( 'trash_post_comments', array( $before, 'action' ), 10, 2 );
		add_action( 'trashed_post_comments', array( $after, 'action' ), 10, 2 );

		wp_trash_post_comments( self::$post_id );

		$this->assertSame( array( array( self::$post_id ) ), $before->get_args(), 'Unexpected trash_post_comments arguments.' );
		$this->assertSame(
			array(
				array(
					self::$post_id,
					array(
						self::$comment_ids['1']     => '1',
						self::$comment_ids['0']     => '0',
						self::$comment_ids['spam']  => 'spam',
						self::$comment_ids['trash'] => 'trash',
					),
				),
			),
			$after->get_args(),
			'Unexpected trashed_post_comments arguments.'
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_trash_post_comments
	 */
	public function test_trash_should_clean_the_comment_cache() {
		// Prime the comment cache.
		$this->assertSame( '1', get_comment( self::$comment_ids['1'] )->comment_approved );

		wp_trash_post_comments( self::$post_id );

		$this->assertSame( 'post-trashed', get_comment( self::$comment_ids['1'] )->comment_approved );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_trash_post_comments
	 */
	public function test_trash_should_accept_a_post_object() {
		$this->assertSame( 4, wp_trash_post_comments( get_post( self::$post_id ) ) );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_trash_post_comments
	 * @covers ::wp_untrash_post_comments
	 */
	public function test_should_default_to_the_global_post() {
		$original_post   = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = get_post( self::$post_id );

		try {
			$trashed  = wp_trash_post_comments();
			$statuses = $this->get_stored_statuses();
			wp_untrash_post_comments();
		} finally {
			$GLOBALS['post'] = $original_post;
		}

		$this->assertSame( 4, $trashed, 'The comments of the global post should be trashed.' );
		$this->assertSame( 'post-trashed', $statuses[ self::$comment_ids['1'] ], 'The comments of the global post should be marked.' );
		$this->assertSame( $this->get_original_statuses(), $this->get_stored_statuses(), 'The comments of the global post should be restored.' );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_untrash_post_comments
	 */
	public function test_untrash_should_return_null_for_a_post_that_does_not_exist() {
		$this->assertNull( wp_untrash_post_comments( PHP_INT_MAX ) );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_untrash_post_comments
	 */
	public function test_untrash_should_return_true_when_no_statuses_are_stored() {
		$before = new MockAction();
		$after  = new MockAction();
		add_action( 'untrash_post_comments', array( $before, 'action' ) );
		add_action( 'untrashed_post_comments', array( $after, 'action' ) );

		$this->assertTrue( wp_untrash_post_comments( self::$post_id ) );
		$this->assertSame( 0, $before->get_call_count(), 'The untrash_post_comments action should not fire.' );
		$this->assertSame( 0, $after->get_call_count(), 'The untrashed_post_comments action should not fire.' );
		$this->assertSame( $this->get_original_statuses(), $this->get_stored_statuses(), 'No comment should be changed.' );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_untrash_post_comments
	 */
	public function test_untrash_should_restore_each_comment_to_its_original_status() {
		wp_trash_post_comments( self::$post_id );

		$this->assertNull( wp_untrash_post_comments( self::$post_id ) );
		$this->assertSame( $this->get_original_statuses(), $this->get_stored_statuses(), 'The original statuses should be restored.' );
		$this->assertSame(
			array(),
			get_post_meta( self::$post_id, '_wp_trash_meta_comments_status' ),
			'The stored statuses should be deleted.'
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_untrash_post_comments
	 */
	public function test_untrash_should_fire_actions_with_post_id() {
		wp_trash_post_comments( self::$post_id );

		$before = new MockAction();
		$after  = new MockAction();
		add_action( 'untrash_post_comments', array( $before, 'action' ), 10, 2 );
		add_action( 'untrashed_post_comments', array( $after, 'action' ), 10, 2 );

		wp_untrash_post_comments( get_post( self::$post_id ) );

		$this->assertSame( array( array( self::$post_id ) ), $before->get_args(), 'Unexpected untrash_post_comments arguments.' );
		$this->assertSame( array( array( self::$post_id ) ), $after->get_args(), 'Unexpected untrashed_post_comments arguments.' );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_untrash_post_comments
	 */
	public function test_untrash_should_clean_the_comment_cache() {
		wp_trash_post_comments( self::$post_id );

		// Prime the comment cache.
		$this->assertSame( 'post-trashed', get_comment( self::$comment_ids['spam'] )->comment_approved );

		wp_untrash_post_comments( self::$post_id );

		$this->assertSame( 'spam', get_comment( self::$comment_ids['spam'] )->comment_approved );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_untrash_post_comments
	 */
	public function test_untrash_should_restore_a_stored_post_trashed_status_as_unapproved() {
		global $wpdb;

		$comment_id = self::$comment_ids['1'];

		$wpdb->update( $wpdb->comments, array( 'comment_approved' => 'post-trashed' ), array( 'comment_ID' => $comment_id ) );
		clean_comment_cache( $comment_id );
		add_post_meta( self::$post_id, '_wp_trash_meta_comments_status', array( $comment_id => 'post-trashed' ) );

		wp_untrash_post_comments( self::$post_id );

		$this->assertSame( '0', get_comment( $comment_id )->comment_approved );
	}
}
