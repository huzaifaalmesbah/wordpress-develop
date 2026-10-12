<?php

/**
 * Tests for the wp_transition_post_status() and _transition_post_status() functions.
 *
 * @group post
 */
class Tests_Post_WpTransitionPostStatus extends WP_UnitTestCase {

	/**
	 * Draft post.
	 *
	 * @var WP_Post
	 */
	protected static $post;

	/**
	 * Draft page.
	 *
	 * @var WP_Post
	 */
	protected static $page;

	/**
	 * Action calls recorded by record_actions().
	 *
	 * @var array[]
	 */
	private $calls = array();

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$post = $factory->post->create_and_get( array( 'post_status' => 'draft' ) );
		self::$page = $factory->post->create_and_get(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
	}

	/**
	 * Records every call to the given actions, in the order they fire.
	 *
	 * @param string[] $hooks Action names.
	 */
	private function record_actions( array $hooks ) {
		foreach ( $hooks as $hook ) {
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
	 * Inserts a published row straight into the posts table, bypassing every cache.
	 *
	 * @param string $post_type Post type.
	 */
	private function insert_published_row( $post_type ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->posts,
			array(
				'post_type'             => $post_type,
				'post_status'           => 'publish',
				'post_title'            => 'Inserted directly',
				'post_content'          => '',
				'post_excerpt'          => '',
				'to_ping'               => '',
				'pinged'                => '',
				'post_content_filtered' => '',
			)
		);

		$this->assertSame( 1, $inserted, 'The fixture row was not inserted.' );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_transition_post_status
	 */
	public function test_should_fire_all_actions_in_order_with_arguments() {
		$this->record_actions( array( 'transition_post_status', 'draft_to_pending', 'pending_post' ) );

		wp_transition_post_status( 'pending', 'draft', self::$post );

		$this->assertSame(
			array(
				array( 'transition_post_status', array( 'pending', 'draft', self::$post ) ),
				array( 'draft_to_pending', array( self::$post ) ),
				array( 'pending_post', array( self::$post->ID, self::$post, 'draft' ) ),
			),
			$this->calls
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_transition_post_status
	 *
	 * @dataProvider data_should_fire_status_to_status_action_only_for_valid_statuses
	 *
	 * @param string $new_status  New post status.
	 * @param string $old_status  Old post status.
	 * @param bool   $should_fire Whether the `{$old_status}_to_{$new_status}` action should fire.
	 */
	public function test_should_fire_status_to_status_action_only_for_valid_statuses( $new_status, $old_status, $should_fire ) {
		$hook = "{$old_status}_to_{$new_status}";

		$this->record_actions( array( 'transition_post_status', $hook ) );

		wp_transition_post_status( $new_status, $old_status, self::$post );

		$expected = array(
			array( 'transition_post_status', array( $new_status, $old_status, self::$post ) ),
		);

		if ( $should_fire ) {
			$expected[] = array( $hook, array( self::$post ) );
		}

		$this->assertSame( $expected, $this->calls );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_fire_status_to_status_action_only_for_valid_statuses() {
		return array(
			'draft to private'              => array( 'private', 'draft', true ),
			'pending to draft'              => array( 'draft', 'pending', true ),
			'private to trash'              => array( 'trash', 'private', true ),
			'same status'                   => array( 'draft', 'draft', true ),
			'old status is "new"'           => array( 'draft', 'new', true ),
			'old status is "new" to auto'   => array( 'auto-draft', 'new', true ),
			'old status is not registered'  => array( 'draft', 'not-registered', false ),
			'new status is not registered'  => array( 'not-registered', 'draft', false ),
			'new status is "new"'           => array( 'new', 'draft', false ),
			'neither status is registered'  => array( 'not-registered', 'also-not-registered', false ),
			'old status is an empty string' => array( 'draft', '', false ),
			'new status is an empty string' => array( '', 'draft', false ),
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_transition_post_status
	 *
	 * @dataProvider data_should_fire_status_post_type_action_with_old_status
	 *
	 * @param string $new_status New post status.
	 * @param string $old_status Old post status.
	 */
	public function test_should_fire_status_post_type_action_with_old_status( $new_status, $old_status ) {
		$this->record_actions( array( "{$new_status}_post", "{$new_status}_page" ) );

		wp_transition_post_status( $new_status, $old_status, self::$page );

		$this->assertSame(
			array(
				array( "{$new_status}_page", array( self::$page->ID, self::$page, $old_status ) ),
			),
			$this->calls
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_fire_status_post_type_action_with_old_status() {
		return array(
			'draft to pending'             => array( 'pending', 'draft' ),
			'same status'                  => array( 'private', 'private' ),
			'old status is "new"'          => array( 'draft', 'new' ),
			'old status is not registered' => array( 'draft', 'not-registered' ),
			'old status is empty'          => array( 'draft', '' ),
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_transition_post_status
	 */
	public function test_should_not_fire_status_post_type_action_for_unregistered_new_status() {
		$this->record_actions( array( 'not-registered_post', 'new_post' ) );

		wp_transition_post_status( 'not-registered', 'draft', self::$post );
		wp_transition_post_status( 'new', 'draft', self::$post );

		$this->assertSame( array(), $this->calls );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_transition_post_status
	 */
	public function test_should_not_fire_status_post_type_action_for_unregistered_post_type() {
		$post            = clone self::$post;
		$post->post_type = 'not_registered_type';

		$this->record_actions( array( 'transition_post_status', 'draft_to_pending', 'pending_not_registered_type' ) );

		wp_transition_post_status( 'pending', 'draft', $post );

		$this->assertSame(
			array(
				array( 'transition_post_status', array( 'pending', 'draft', $post ) ),
				array( 'draft_to_pending', array( $post ) ),
			),
			$this->calls
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::_transition_post_status
	 */
	public function test_should_be_hooked_to_transition_post_status() {
		$this->assertSame( 5, has_action( 'transition_post_status', '_transition_post_status' ) );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::_transition_post_status
	 *
	 * @dataProvider data_should_set_empty_guid_only_when_first_published
	 *
	 * @param string $new_status   New post status.
	 * @param string $old_status   Old post status.
	 * @param string $initial_guid GUID stored before the transition.
	 * @param bool   $should_reset Whether the GUID should be set to the permalink.
	 */
	public function test_should_set_empty_guid_only_when_first_published( $new_status, $old_status, $initial_guid, $should_reset ) {
		global $wpdb;

		$wpdb->update( $wpdb->posts, array( 'guid' => $initial_guid ), array( 'ID' => self::$post->ID ) );
		clean_post_cache( self::$post->ID );

		_transition_post_status( $new_status, $old_status, self::$post );

		$expected = $should_reset ? 'http://' . WP_TESTS_DOMAIN . '/?p=' . self::$post->ID : $initial_guid;

		// Read the row directly, as the function does not clean the post cache.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT guid FROM $wpdb->posts WHERE ID = %d", self::$post->ID ) );

		$this->assertSame( $expected, $row->guid );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_set_empty_guid_only_when_first_published() {
		return array(
			'draft to publish, empty guid'    => array( 'publish', 'draft', '', true ),
			'new to publish, empty guid'      => array( 'publish', 'new', '', true ),
			'draft to publish, existing guid' => array( 'publish', 'draft', 'http://example.com/existing-guid', false ),
			'publish to publish, empty guid'  => array( 'publish', 'publish', '', false ),
			'draft to pending, empty guid'    => array( 'pending', 'draft', '', false ),
			'publish to draft, empty guid'    => array( 'draft', 'publish', '', false ),
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::_transition_post_status
	 *
	 * @expectedDeprecated private_to_published
	 */
	public function test_should_fire_deprecated_private_to_published_action_when_first_published() {
		$action = new MockAction();
		add_action( 'private_to_published', array( $action, 'action' ) );

		_transition_post_status( 'publish', 'private', self::$post );

		$this->assertSame( array( array( self::$post->ID ) ), $action->get_args() );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::_transition_post_status
	 *
	 * @dataProvider data_should_not_fire_deprecated_private_to_published_action
	 *
	 * @param string $new_status New post status.
	 * @param string $old_status Old post status.
	 */
	public function test_should_not_fire_deprecated_private_to_published_action( $new_status, $old_status ) {
		$action = new MockAction();
		add_action( 'private_to_published', array( $action, 'action' ) );

		_transition_post_status( $new_status, $old_status, self::$post );

		$this->assertSame( 0, $action->get_call_count() );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_not_fire_deprecated_private_to_published_action() {
		return array(
			'publish to publish' => array( 'publish', 'publish' ),
			'publish to private' => array( 'private', 'publish' ),
			'private to draft'   => array( 'draft', 'private' ),
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::_transition_post_status
	 *
	 * @dataProvider data_should_clear_last_post_caches_only_when_published_posts_change
	 *
	 * @param string $new_status   New post status.
	 * @param string $old_status   Old post status.
	 * @param bool   $should_clear Whether the caches should be cleared.
	 */
	public function test_should_clear_last_post_caches_only_when_published_posts_change( $new_status, $old_status, $should_clear ) {
		$cleared_keys = array(
			'lastpostmodified:server',
			'lastpostmodified:gmt',
			'lastpostmodified:blog',
			'lastpostdate:server',
			'lastpostdate:gmt',
			'lastpostdate:blog',
			'lastpostdate:server:post',
			'lastpostdate:gmt:post',
			'lastpostdate:blog:post',
		);

		$kept_keys = array(
			'lastpostdate:server:page',
			'lastpostdate:gmt:page',
			'lastpostdate:blog:page',
		);

		foreach ( array_merge( $cleared_keys, $kept_keys ) as $key ) {
			wp_cache_set( $key, '2026-01-02 03:04:05', 'timeinfo' );
		}

		_transition_post_status( $new_status, $old_status, self::$post );

		foreach ( $cleared_keys as $key ) {
			$this->assertSame(
				$should_clear ? false : '2026-01-02 03:04:05',
				wp_cache_get( $key, 'timeinfo' ),
				"Unexpected cache state for the $key key."
			);
		}

		foreach ( $kept_keys as $key ) {
			$this->assertSame(
				'2026-01-02 03:04:05',
				wp_cache_get( $key, 'timeinfo' ),
				"The $key key of another post type should not be cleared."
			);
		}
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_clear_last_post_caches_only_when_published_posts_change() {
		return array(
			'draft to publish'   => array( 'publish', 'draft', true ),
			'publish to draft'   => array( 'draft', 'publish', true ),
			'publish to publish' => array( 'publish', 'publish', true ),
			'draft to pending'   => array( 'pending', 'draft', false ),
			'draft to draft'     => array( 'draft', 'draft', false ),
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::_transition_post_status
	 *
	 * @dataProvider data_should_clear_post_counts_cache_only_when_status_changes
	 *
	 * @param string $new_status   New post status.
	 * @param string $old_status   Old post status.
	 * @param bool   $should_clear Whether the counts caches should be cleared.
	 */
	public function test_should_clear_post_counts_cache_only_when_status_changes( $new_status, $old_status, $should_clear ) {
		// The readable counts are only cached separately for users who cannot read private posts.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		// Prime the counts caches. No published posts or pages exist yet.
		$this->assertSame( 0, (int) wp_count_posts( 'post' )->publish, 'Unexpected initial post count.' );
		$this->assertSame( 0, (int) wp_count_posts( 'post', 'readable' )->publish, 'Unexpected initial readable post count.' );
		$this->assertSame( 0, (int) wp_count_posts( 'page' )->publish, 'Unexpected initial page count.' );

		$this->insert_published_row( 'post' );
		$this->insert_published_row( 'page' );

		$this->assertSame( 0, (int) wp_count_posts( 'post' )->publish, 'The post count should still be cached.' );
		$this->assertSame( 0, (int) wp_count_posts( 'post', 'readable' )->publish, 'The readable post count should still be cached.' );

		_transition_post_status( $new_status, $old_status, self::$post );

		$expected = $should_clear ? 1 : 0;

		$this->assertSame( $expected, (int) wp_count_posts( 'post' )->publish, 'Unexpected post count.' );
		$this->assertSame( $expected, (int) wp_count_posts( 'post', 'readable' )->publish, 'Unexpected readable post count.' );
		$this->assertSame( 0, (int) wp_count_posts( 'page' )->publish, 'The count of another post type should remain cached.' );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_clear_post_counts_cache_only_when_status_changes() {
		return array(
			'draft to pending'   => array( 'pending', 'draft', true ),
			'draft to publish'   => array( 'publish', 'draft', true ),
			'new to draft'       => array( 'draft', 'new', true ),
			'draft to draft'     => array( 'draft', 'draft', false ),
			'publish to publish' => array( 'publish', 'publish', false ),
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::_transition_post_status
	 *
	 * @dataProvider data_should_clear_scheduled_publication_for_the_post_only
	 *
	 * @param string $new_status New post status.
	 * @param string $old_status Old post status.
	 */
	public function test_should_clear_scheduled_publication_for_the_post_only( $new_status, $old_status ) {
		$timestamp = time() + HOUR_IN_SECONDS;

		wp_schedule_single_event( $timestamp, 'publish_future_post', array( self::$post->ID ) );
		wp_schedule_single_event( $timestamp, 'publish_future_post', array( self::$page->ID ) );

		$this->assertSame(
			$timestamp,
			wp_next_scheduled( 'publish_future_post', array( self::$post->ID ) ),
			'The fixture event was not scheduled.'
		);

		_transition_post_status( $new_status, $old_status, self::$post );

		$this->assertFalse(
			wp_next_scheduled( 'publish_future_post', array( self::$post->ID ) ),
			'The scheduled publication of the post should be cleared.'
		);
		$this->assertSame(
			$timestamp,
			wp_next_scheduled( 'publish_future_post', array( self::$page->ID ) ),
			'The scheduled publication of another post should be kept.'
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_clear_scheduled_publication_for_the_post_only() {
		return array(
			'future to draft'   => array( 'draft', 'future' ),
			'future to publish' => array( 'publish', 'future' ),
			'future to future'  => array( 'future', 'future' ),
			'draft to pending'  => array( 'pending', 'draft' ),
		);
	}
}
