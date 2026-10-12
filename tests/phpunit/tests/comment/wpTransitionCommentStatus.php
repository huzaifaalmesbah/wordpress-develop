<?php

/**
 * Tests for the wp_transition_comment_status() function.
 *
 * @group comment
 *
 * @covers ::wp_transition_comment_status
 */
class Tests_Comment_WpTransitionCommentStatus extends WP_UnitTestCase {

	/**
	 * Comment objects keyed by comment type.
	 *
	 * @var WP_Comment[]
	 */
	protected static $comments = array();

	/**
	 * Action calls recorded by record_actions().
	 *
	 * @var array[]
	 */
	private $calls = array();

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		$post_id = $factory->post->create();

		foreach ( array( 'comment', 'pingback', 'trackback', 'note' ) as $type ) {
			self::$comments[ $type ] = $factory->comment->create_and_get(
				array(
					'comment_post_ID' => $post_id,
					'comment_type'    => $type,
				)
			);
		}
	}

	/**
	 * Records every call to the given actions, in the order they fire.
	 *
	 * @param string[] $hooks Action names.
	 */
	private function record_actions( array $hooks ) {
		foreach ( array_unique( $hooks ) as $hook ) {
			add_action(
				$hook,
				function ( ...$args ) use ( $hook ) {
					$this->calls[] = array( $hook, $args );
				},
				10,
				3
			);
		}
	}

	/**
	 * @ticket 65819
	 *
	 * @dataProvider data_should_fire_actions_with_translated_statuses
	 *
	 * @param int|string $new_status          New comment status.
	 * @param int|string $old_status          Old comment status.
	 * @param string     $expected_new_status New status expected in the actions.
	 * @param string     $expected_old_status Old status expected in the actions.
	 */
	public function test_should_fire_actions_with_translated_statuses( $new_status, $old_status, $expected_new_status, $expected_old_status ) {
		$comment = self::$comments['comment'];

		$this->record_actions(
			array(
				'transition_comment_status',
				"comment_{$expected_old_status}_to_{$expected_new_status}",
				"comment_{$expected_new_status}_comment",
				// The raw statuses must not be used in the action names.
				"comment_{$old_status}_to_{$new_status}",
				"comment_{$new_status}_comment",
			)
		);

		wp_transition_comment_status( $new_status, $old_status, $comment );

		$this->assertSame(
			array(
				array( 'transition_comment_status', array( $expected_new_status, $expected_old_status, $comment ) ),
				array( "comment_{$expected_old_status}_to_{$expected_new_status}", array( $comment ) ),
				array( "comment_{$expected_new_status}_comment", array( $comment->comment_ID, $comment ) ),
			),
			$this->calls
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_fire_actions_with_translated_statuses() {
		return array(
			'hold to approve'             => array( 'approve', 'hold', 'approved', 'unapproved' ),
			'approve to hold'             => array( 'hold', 'approve', 'unapproved', 'approved' ),
			'integer 0 to 1'              => array( 1, 0, 'approved', 'unapproved' ),
			'integer 1 to 0'              => array( 0, 1, 'unapproved', 'approved' ),
			'numeric string 0 to 1'       => array( '1', '0', 'approved', 'unapproved' ),
			'numeric string 1 to 0'       => array( '0', '1', 'unapproved', 'approved' ),
			'approve to spam'             => array( 'spam', 'approve', 'spam', 'approved' ),
			'spam to hold'                => array( 'hold', 'spam', 'unapproved', 'spam' ),
			'hold to trash'               => array( 'trash', 'hold', 'trash', 'unapproved' ),
			'trash to numeric string 1'   => array( '1', 'trash', 'approved', 'trash' ),
			'spam to delete'              => array( 'delete', 'spam', 'delete', 'spam' ),
			'already translated statuses' => array( 'approved', 'unapproved', 'approved', 'unapproved' ),
			'unknown statuses'            => array( 'custom-new', 'custom-old', 'custom-new', 'custom-old' ),
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @dataProvider data_should_only_fire_the_comment_type_action_when_the_status_is_unchanged
	 *
	 * @param int|string $new_status      New comment status.
	 * @param int|string $old_status      Old comment status.
	 * @param string     $expected_status Status expected in the action name.
	 */
	public function test_should_only_fire_the_comment_type_action_when_the_status_is_unchanged( $new_status, $old_status, $expected_status ) {
		$comment = self::$comments['comment'];

		$this->record_actions(
			array(
				'transition_comment_status',
				"comment_{$expected_status}_to_{$expected_status}",
				"comment_{$old_status}_to_{$new_status}",
				"comment_{$expected_status}_comment",
			)
		);

		wp_transition_comment_status( $new_status, $old_status, $comment );

		$this->assertSame(
			array(
				array( "comment_{$expected_status}_comment", array( $comment->comment_ID, $comment ) ),
			),
			$this->calls
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_only_fire_the_comment_type_action_when_the_status_is_unchanged() {
		return array(
			'approve and integer 1'        => array( 'approve', 1, 'approved' ),
			'numeric string 1 and approve' => array( '1', 'approve', 'approved' ),
			'approve and approved'         => array( 'approve', 'approved', 'approved' ),
			'hold and integer 0'           => array( 'hold', 0, 'unapproved' ),
			'numeric string 0 and hold'    => array( '0', 'hold', 'unapproved' ),
			'integer 0 and unapproved'     => array( 0, 'unapproved', 'unapproved' ),
			'spam and spam'                => array( 'spam', 'spam', 'spam' ),
			'trash and trash'              => array( 'trash', 'trash', 'trash' ),
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @dataProvider data_should_use_the_comment_type_in_the_action_name
	 *
	 * @param string $comment_type Comment type.
	 */
	public function test_should_use_the_comment_type_in_the_action_name( $comment_type ) {
		$comment = self::$comments[ $comment_type ];
		$types   = array( 'comment', 'pingback', 'trackback', 'note' );

		$this->record_actions(
			array_map(
				static function ( $type ) {
					return "comment_spam_{$type}";
				},
				$types
			)
		);

		wp_transition_comment_status( 'spam', 'approve', $comment );

		$this->assertSame(
			array(
				array( "comment_spam_{$comment_type}", array( $comment->comment_ID, $comment ) ),
			),
			$this->calls
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_use_the_comment_type_in_the_action_name() {
		return array(
			'comment'   => array( 'comment' ),
			'pingback'  => array( 'pingback' ),
			'trackback' => array( 'trackback' ),
			'note'      => array( 'note' ),
		);
	}

	/**
	 * @ticket 65819
	 */
	public function test_should_pass_the_comment_id_as_a_numeric_string() {
		$comment = self::$comments['comment'];

		$action = new MockAction();
		add_action( 'comment_approved_comment', array( $action, 'action' ), 10, 2 );

		wp_transition_comment_status( 'approve', 'hold', $comment );

		$args = $action->get_args();

		$this->assertIsString( $args[0][0], 'The comment ID should be a string.' );
		$this->assertSame( $comment->comment_ID, $args[0][0], 'Unexpected comment ID.' );
	}
}
