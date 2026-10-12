<?php

/**
 * Tests for the wp_check_comment_flood() and check_comment_flood_db() functions.
 *
 * @group comment
 */
class Tests_Comment_WpCheckCommentFlood extends WP_UnitTestCase {

	/**
	 * IP address of the previous comment.
	 *
	 * @var string
	 */
	const IP = '192.0.2.10';

	/**
	 * IP address that never posted a comment.
	 *
	 * @var string
	 */
	const OTHER_IP = '192.0.2.20';

	/**
	 * Email address of the previous comment.
	 *
	 * @var string
	 */
	const EMAIL = 'previous@example.org';

	/**
	 * Email address that never posted a comment.
	 *
	 * @var string
	 */
	const OTHER_EMAIL = 'other@example.org';

	/**
	 * Post ID.
	 *
	 * @var int
	 */
	protected static $post_id;

	/**
	 * Timestamp the fixture comments are dated relative to.
	 *
	 * @var int
	 */
	private $now;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$post_id = $factory->post->create();
	}

	public function set_up() {
		parent::set_up();

		$this->now = time();
	}

	/**
	 * Creates a comment posted at the given timestamp.
	 *
	 * @param int   $timestamp Timestamp of the comment.
	 * @param array $args      Optional. Comment data overrides.
	 */
	private function create_comment_at( $timestamp, array $args = array() ) {
		self::factory()->comment->create(
			array_merge(
				array(
					'comment_post_ID'      => self::$post_id,
					'comment_author_IP'    => self::IP,
					'comment_author_email' => self::EMAIL,
					'comment_date_gmt'     => gmdate( 'Y-m-d H:i:s', $timestamp ),
					'user_id'              => 0,
				),
				$args
			)
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::check_comment_flood_db
	 */
	public function test_check_comment_flood_db_should_be_hooked_to_check_comment_flood() {
		$this->assertSame( 10, has_action( 'check_comment_flood', 'check_comment_flood_db' ) );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::check_comment_flood_db
	 */
	public function test_check_comment_flood_db_should_add_the_flood_check_filter() {
		global $wp_filter;

		remove_filter( 'wp_is_comment_flood', 'wp_check_comment_flood' );
		$this->assertFalse( has_filter( 'wp_is_comment_flood', 'wp_check_comment_flood' ), 'The filter should not be hooked before the call.' );

		check_comment_flood_db();

		$this->assertSame( 10, has_filter( 'wp_is_comment_flood', 'wp_check_comment_flood' ), 'The filter should be hooked at the default priority.' );
		$this->assertSame(
			5,
			$wp_filter['wp_is_comment_flood']->callbacks[10]['wp_check_comment_flood']['accepted_args'],
			'The filter should accept all five arguments.'
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_check_comment_flood
	 */
	public function test_should_trust_an_earlier_flood_decision() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$filter = new MockAction();
		add_filter( 'comment_flood_filter', array( $filter, 'filter' ) );

		$this->assertTrue( wp_check_comment_flood( true, self::OTHER_IP, self::OTHER_EMAIL, gmdate( 'Y-m-d H:i:s', $this->now ), true ) );
		$this->assertSame( 0, $filter->get_call_count(), 'The comment_flood_filter filter should not be applied.' );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_check_comment_flood
	 *
	 * @dataProvider data_should_only_throttle_users_who_cannot_moderate
	 *
	 * @param string $role     User role.
	 * @param bool   $expected Whether a flood is expected.
	 */
	public function test_should_only_throttle_users_who_cannot_moderate( $role, $expected ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

		$this->create_comment_at( $this->now - 10 );

		$trigger = new MockAction();
		add_action( 'comment_flood_trigger', array( $trigger, 'action' ) );

		$this->assertSame(
			$expected,
			wp_check_comment_flood( false, self::IP, self::EMAIL, gmdate( 'Y-m-d H:i:s', $this->now - 5 ), true )
		);
		$this->assertSame( $expected ? 1 : 0, $trigger->get_call_count(), 'Unexpected comment_flood_trigger call count.' );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_only_throttle_users_who_cannot_moderate() {
		return array(
			'administrator' => array( 'administrator', false ),
			'editor'        => array( 'editor', false ),
			'author'        => array( 'author', true ),
			'subscriber'    => array( 'subscriber', true ),
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_check_comment_flood
	 *
	 * @dataProvider data_should_match_logged_out_visitors_by_ip_or_email
	 *
	 * @param string $ip       IP address of the new comment.
	 * @param string $email    Email address of the new comment.
	 * @param bool   $expected Whether a flood is expected.
	 */
	public function test_should_match_logged_out_visitors_by_ip_or_email( $ip, $email, $expected ) {
		$this->create_comment_at( $this->now - 10 );

		$this->assertSame(
			$expected,
			wp_check_comment_flood( false, $ip, $email, gmdate( 'Y-m-d H:i:s', $this->now - 5 ), true )
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_match_logged_out_visitors_by_ip_or_email() {
		return array(
			'same IP and email'     => array( self::IP, self::EMAIL, true ),
			'same IP, other email'  => array( self::IP, self::OTHER_EMAIL, true ),
			'same IP, empty email'  => array( self::IP, '', true ),
			'other IP, same email'  => array( self::OTHER_IP, self::EMAIL, true ),
			'other IP and email'    => array( self::OTHER_IP, self::OTHER_EMAIL, false ),
			'other IP, empty email' => array( self::OTHER_IP, '', false ),
			'empty IP and email'    => array( '', '', false ),
			'empty IP, same email'  => array( '', self::EMAIL, true ),
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_check_comment_flood
	 */
	public function test_should_match_logged_in_users_by_user_id() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->create_comment_at(
			$this->now - 10,
			array(
				'user_id' => $user_id,
			)
		);

		$this->assertTrue(
			wp_check_comment_flood( false, self::OTHER_IP, self::OTHER_EMAIL, gmdate( 'Y-m-d H:i:s', $this->now - 5 ), true )
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_check_comment_flood
	 */
	public function test_should_not_match_logged_in_users_by_ip() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->create_comment_at( $this->now - 10 );

		$this->assertFalse(
			wp_check_comment_flood( false, self::IP, self::OTHER_EMAIL, gmdate( 'Y-m-d H:i:s', $this->now - 5 ), true )
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_check_comment_flood
	 *
	 * @dataProvider data_should_compare_against_the_previous_comment_time
	 *
	 * @param int  $previous_age Age of the previous comment in seconds.
	 * @param int  $interval     Seconds between the previous and the new comment.
	 * @param bool $expected     Whether a flood is expected.
	 */
	public function test_should_compare_against_the_previous_comment_time( $previous_age, $interval, $expected ) {
		$previous = $this->now - $previous_age;

		$this->create_comment_at( $previous );

		$this->assertSame(
			$expected,
			wp_check_comment_flood( false, self::IP, self::EMAIL, gmdate( 'Y-m-d H:i:s', $previous + $interval ), true )
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_compare_against_the_previous_comment_time() {
		return array(
			'same second'                       => array( 60, 0, true ),
			'5 seconds later'                   => array( 60, 5, true ),
			'14 seconds later'                  => array( 60, 14, true ),
			'15 seconds later'                  => array( 60, 15, false ),
			'a minute later'                    => array( 120, 60, false ),
			'previous comment over an hour old' => array( 2 * HOUR_IN_SECONDS, 5, false ),
			'previous comment half an hour old' => array( 30 * MINUTE_IN_SECONDS, 5, true ),
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_check_comment_flood
	 */
	public function test_should_pass_the_most_recent_comment_time_to_the_filter_and_action() {
		$this->create_comment_at( $this->now - 50 );
		$this->create_comment_at( $this->now - 10 );
		$this->create_comment_at( $this->now - 30 );

		$filter  = new MockAction();
		$trigger = new MockAction();
		add_filter( 'comment_flood_filter', array( $filter, 'filter' ), 9, 3 );
		add_action( 'comment_flood_trigger', array( $trigger, 'action' ), 10, 2 );

		$this->assertTrue( wp_check_comment_flood( false, self::IP, self::EMAIL, gmdate( 'Y-m-d H:i:s', $this->now - 5 ), true ) );
		$this->assertSame(
			array( array( false, $this->now - 10, $this->now - 5 ) ),
			$filter->get_args(),
			'Unexpected comment_flood_filter arguments.'
		);
		$this->assertSame(
			array( array( $this->now - 10, $this->now - 5 ) ),
			$trigger->get_args(),
			'Unexpected comment_flood_trigger arguments.'
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_check_comment_flood
	 */
	public function test_should_not_apply_the_filter_without_a_recent_comment() {
		$filter = new MockAction();
		add_filter( 'comment_flood_filter', array( $filter, 'filter' ) );

		$this->assertFalse( wp_check_comment_flood( false, self::IP, self::EMAIL, gmdate( 'Y-m-d H:i:s', $this->now ), true ) );
		$this->assertSame( 0, $filter->get_call_count() );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_check_comment_flood
	 */
	public function test_should_report_a_flood_when_the_filter_returns_true() {
		$this->create_comment_at( $this->now - 600 );

		add_filter( 'comment_flood_filter', '__return_true', 20 );

		$this->assertTrue( wp_check_comment_flood( false, self::IP, self::EMAIL, gmdate( 'Y-m-d H:i:s', $this->now ), true ) );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_check_comment_flood
	 */
	public function test_should_not_report_a_flood_when_the_filter_returns_false() {
		$this->create_comment_at( $this->now - 10 );

		$trigger = new MockAction();
		add_action( 'comment_flood_trigger', array( $trigger, 'action' ) );
		add_filter( 'comment_flood_filter', '__return_false', 20 );

		$this->assertFalse( wp_check_comment_flood( false, self::IP, self::EMAIL, gmdate( 'Y-m-d H:i:s', $this->now - 5 ), true ) );
		$this->assertSame( 0, $trigger->get_call_count(), 'The comment_flood_trigger action should not fire.' );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_check_comment_flood
	 */
	public function test_should_die_with_a_429_response_by_default() {
		$this->create_comment_at( $this->now - 10 );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'You are posting comments too quickly. Slow down.' );
		$this->expectExceptionCode( 429 );

		wp_check_comment_flood( false, self::IP, self::EMAIL, gmdate( 'Y-m-d H:i:s', $this->now - 5 ) );
	}

	/**
	 * @ticket 65819
	 *
	 * @covers ::wp_check_comment_flood
	 */
	public function test_should_die_with_the_filtered_message() {
		$this->create_comment_at( $this->now - 10 );

		$filter = new MockAction();
		add_filter( 'comment_flood_message', array( $filter, 'filter' ) );
		add_filter(
			'comment_flood_message',
			static function () {
				return 'Custom <b>flood</b> message';
			},
			20
		);

		try {
			wp_check_comment_flood( false, self::IP, self::EMAIL, gmdate( 'Y-m-d H:i:s', $this->now - 5 ) );
			$this->fail( 'Expected wp_die() to be called.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'Custom <b>flood</b> message', $e->getMessage(), 'Unexpected wp_die() message.' );
			$this->assertSame( 429, $e->getCode(), 'Unexpected wp_die() response code.' );
		}

		$this->assertSame(
			array( array( 'You are posting comments too quickly. Slow down.' ) ),
			$filter->get_args(),
			'Unexpected comment_flood_message arguments.'
		);
	}
}
