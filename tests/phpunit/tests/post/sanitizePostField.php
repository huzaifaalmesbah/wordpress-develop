<?php

/**
 * Tests for the sanitize_post_field() function.
 *
 * @group post
 *
 * @covers ::sanitize_post_field
 */
class Tests_Post_SanitizePostField extends WP_UnitTestCase {

	/**
	 * Arbitrary post ID passed to the function, which never looks the post up.
	 *
	 * @var int
	 */
	const POST_ID = 123;

	/**
	 * Markup and entity fixture used to tell the escaping functions apart.
	 *
	 * @var string
	 */
	const MARKUP = '<b>Bold</b> &amp; "double" \'single\'';

	/**
	 * Whether the `$wp_rich_edit` global was set before the test.
	 *
	 * @var bool
	 */
	private $had_wp_rich_edit;

	/**
	 * Original value of the `$wp_rich_edit` global.
	 *
	 * @var mixed
	 */
	private $original_wp_rich_edit;

	/**
	 * Filter calls recorded by record_filters().
	 *
	 * @var array[]
	 */
	private $calls = array();

	public function set_up() {
		parent::set_up();

		$this->had_wp_rich_edit      = array_key_exists( 'wp_rich_edit', $GLOBALS );
		$this->original_wp_rich_edit = $this->had_wp_rich_edit ? $GLOBALS['wp_rich_edit'] : null;
	}

	public function tear_down() {
		if ( $this->had_wp_rich_edit ) {
			$GLOBALS['wp_rich_edit'] = $this->original_wp_rich_edit;
		} else {
			unset( $GLOBALS['wp_rich_edit'] );
		}

		parent::tear_down();
	}

	/**
	 * Records every call to each hook the function could fire for a field,
	 * and appends the hook name to the filtered value.
	 *
	 * @param string $field Post field name.
	 */
	private function record_filters( $field ) {
		$field_no_prefix = str_replace( 'post_', '', $field );

		$hooks = array(
			"edit_{$field}",
			"{$field_no_prefix}_edit_pre",
			"edit_post_{$field}",
			"pre_{$field}",
			"{$field_no_prefix}_save_pre",
			"pre_post_{$field}",
			"{$field}_pre",
			$field,
			"post_{$field}",
		);

		foreach ( array_unique( $hooks ) as $hook ) {
			add_filter(
				$hook,
				function ( ...$args ) use ( $hook ) {
					$this->calls[] = array( $hook, $args );

					return $args[0] . '|' . $hook;
				},
				10,
				3
			);
		}
	}

	/**
	 * @ticket 65819
	 *
	 * @dataProvider data_should_cast_integer_fields
	 *
	 * @param string $field    Post field name.
	 * @param mixed  $value    Field value.
	 * @param string $context  Sanitization context.
	 * @param int    $expected Expected value.
	 */
	public function test_should_cast_integer_fields( $field, $value, $context, $expected ) {
		$this->assertSame( $expected, sanitize_post_field( $field, $value, self::POST_ID, $context ) );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_cast_integer_fields() {
		return array(
			'ID, raw'                         => array( 'ID', '12abc', 'raw', 12 ),
			'ID, edit'                        => array( 'ID', '12abc', 'edit', 12 ),
			'ID, db'                          => array( 'ID', '12abc', 'db', 12 ),
			'ID, display'                     => array( 'ID', '12abc', 'display', 12 ),
			'ID, attribute'                   => array( 'ID', '12abc', 'attribute', 12 ),
			'ID, js'                          => array( 'ID', '12abc', 'js', 12 ),
			'post_parent, raw'                => array( 'post_parent', '34', 'raw', 34 ),
			'post_parent, edit'               => array( 'post_parent', '34', 'edit', 34 ),
			'post_parent, db'                 => array( 'post_parent', '34', 'db', 34 ),
			'post_parent, display'            => array( 'post_parent', '34', 'display', 34 ),
			'menu_order, negative, attribute' => array( 'menu_order', '-3', 'attribute', -3 ),
			'menu_order, float string, js'    => array( 'menu_order', '5.9', 'js', 5 ),
			'empty string'                    => array( 'post_parent', '', 'display', 0 ),
			'zero string'                     => array( 'menu_order', '0', 'edit', 0 ),
			'zero'                            => array( 'menu_order', 0, 'db', 0 ),
			'false'                           => array( 'ID', false, 'display', 0 ),
			'non-numeric string'              => array( 'ID', 'abc', 'raw', 0 ),
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @dataProvider data_should_restore_integer_type_after_filters
	 *
	 * @param string $field   Post field name.
	 * @param string $context Sanitization context.
	 * @param string $hook    Filter that returns a string.
	 */
	public function test_should_restore_integer_type_after_filters( $field, $context, $hook ) {
		add_filter(
			$hook,
			static function () {
				return '15 apples';
			}
		);

		$this->assertSame( 15, sanitize_post_field( $field, 4, self::POST_ID, $context ) );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_restore_integer_type_after_filters() {
		return array(
			'unprefixed field, edit'    => array( 'ID', 'edit', 'edit_post_ID' ),
			'unprefixed field, db'      => array( 'menu_order', 'db', 'menu_order_pre' ),
			'unprefixed field, display' => array( 'menu_order', 'display', 'post_menu_order' ),
			'prefixed field, edit'      => array( 'post_parent', 'edit', 'parent_edit_pre' ),
			'prefixed field, db'        => array( 'post_parent', 'db', 'parent_save_pre' ),
			'prefixed field, attribute' => array( 'post_parent', 'attribute', 'post_parent' ),
			'prefixed field, js'        => array( 'post_parent', 'js', 'post_parent' ),
		);
	}

	/**
	 * @ticket 65819
	 *
	 * @dataProvider data_should_return_ancestors_as_non_negative_integers
	 *
	 * @param mixed  $value    Field value.
	 * @param string $context  Sanitization context.
	 * @param int[]  $expected Expected value.
	 */
	public function test_should_return_ancestors_as_non_negative_integers( $value, $context, $expected ) {
		$this->record_filters( 'ancestors' );

		$this->assertSame( $expected, sanitize_post_field( 'ancestors', $value, self::POST_ID, $context ) );
		$this->assertSame( array(), $this->calls, 'No filter should be applied to the ancestors field.' );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_return_ancestors_as_non_negative_integers() {
		return array(
			'mixed values, raw'     => array( array( '3', -4, 'abc', 5.9 ), 'raw', array( 3, 4, 0, 5 ) ),
			'mixed values, edit'    => array( array( '3', -4, 'abc', 5.9 ), 'edit', array( 3, 4, 0, 5 ) ),
			'mixed values, db'      => array( array( '3', -4, 'abc', 5.9 ), 'db', array( 3, 4, 0, 5 ) ),
			'mixed values, display' => array( array( '3', -4, 'abc', 5.9 ), 'display', array( 3, 4, 0, 5 ) ),
			'preserves keys'        => array(
				array(
					'parent'      => '8',
					'grandparent' => '2',
				),
				'display',
				array(
					'parent'      => 8,
					'grandparent' => 2,
				),
			),
			'scalar string'         => array( '7', 'display', array( 7 ) ),
			'zero'                  => array( 0, 'display', array( 0 ) ),
			'empty array'           => array( array(), 'display', array() ),
		);
	}

	/**
	 * @ticket 65819
	 */
	public function test_should_return_raw_value_without_applying_filters() {
		$this->record_filters( 'post_title' );

		$this->assertSame( self::MARKUP, sanitize_post_field( 'post_title', self::MARKUP, self::POST_ID, 'raw' ) );
		$this->assertSame( array(), $this->calls );
	}

	/**
	 * @ticket 65819
	 *
	 * @dataProvider data_should_apply_filters_for_context
	 *
	 * @param string  $field          Post field name.
	 * @param string  $context        Sanitization context.
	 * @param array[] $expected_calls Expected filter calls, in order.
	 * @param string  $expected       Expected value.
	 */
	public function test_should_apply_filters_for_context( $field, $context, $expected_calls, $expected ) {
		$this->record_filters( $field );

		$actual = sanitize_post_field( $field, 'value', self::POST_ID, $context );

		$this->assertSame( $expected_calls, $this->calls, 'The filters applied or their arguments do not match.' );
		$this->assertSame( $expected, $actual, 'The filtered value does not match.' );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_apply_filters_for_context() {
		return array(
			'prefixed field, edit'        => array(
				'post_name',
				'edit',
				array(
					array( 'edit_post_name', array( 'value', 123 ) ),
					array( 'name_edit_pre', array( 'value|edit_post_name', 123 ) ),
				),
				'value|edit_post_name|name_edit_pre',
			),
			'unprefixed field, edit'      => array(
				'pinged',
				'edit',
				array(
					array( 'edit_post_pinged', array( 'value', 123 ) ),
				),
				'value|edit_post_pinged',
			),
			'prefixed field, db'          => array(
				'post_name',
				'db',
				array(
					array( 'pre_post_name', array( 'value' ) ),
					array( 'name_save_pre', array( 'value|pre_post_name' ) ),
				),
				'value|pre_post_name|name_save_pre',
			),
			'unprefixed field, db'        => array(
				'pinged',
				'db',
				array(
					array( 'pre_post_pinged', array( 'value' ) ),
					array( 'pinged_pre', array( 'value|pre_post_pinged' ) ),
				),
				'value|pre_post_pinged|pinged_pre',
			),
			'prefixed field, display'     => array(
				'post_name',
				'display',
				array(
					array( 'post_name', array( 'value', 123, 'display' ) ),
				),
				'value|post_name',
			),
			'unprefixed field, display'   => array(
				'pinged',
				'display',
				array(
					array( 'post_pinged', array( 'value', 123, 'display' ) ),
				),
				'value|post_pinged',
			),
			'prefixed field, attribute'   => array(
				'post_name',
				'attribute',
				array(
					array( 'post_name', array( 'value', 123, 'attribute' ) ),
				),
				'value|post_name',
			),
			'unprefixed field, attribute' => array(
				'pinged',
				'attribute',
				array(
					array( 'post_pinged', array( 'value', 123, 'attribute' ) ),
				),
				'value|post_pinged',
			),
			'prefixed field, js'          => array(
				'post_name',
				'js',
				array(
					array( 'post_name', array( 'value', 123, 'js' ) ),
				),
				'value|post_name',
			),
			'unprefixed field, js'        => array(
				'pinged',
				'js',
				array(
					array( 'post_pinged', array( 'value', 123, 'js' ) ),
				),
				'value|post_pinged',
			),
			'prefixed field, sample'      => array(
				'post_name',
				'sample',
				array(
					array( 'post_name', array( 'value', 123, 'sample' ) ),
				),
				'value|post_name',
			),
			'unknown context'             => array(
				'pinged',
				'unknown',
				array(
					array( 'post_pinged', array( 'value', 123, 'unknown' ) ),
				),
				'value|post_pinged',
			),
		);
	}

	/**
	 * @ticket 65819
	 */
	public function test_should_default_to_display_context() {
		$this->record_filters( 'post_name' );

		$actual = sanitize_post_field( 'post_name', self::MARKUP, self::POST_ID );

		$this->assertSame(
			array(
				array( 'post_name', array( self::MARKUP, 123, 'display' ) ),
			),
			$this->calls,
			'The display filter should be applied with the display context.'
		);
		$this->assertSame( self::MARKUP . '|post_name', $actual, 'The value should not be escaped.' );
	}

	/**
	 * @ticket 65819
	 *
	 * @dataProvider data_should_escape_value_for_context
	 *
	 * @param string $field    Post field name.
	 * @param string $context  Sanitization context.
	 * @param string $expected Expected value.
	 */
	public function test_should_escape_value_for_context( $field, $context, $expected ) {
		add_filter( 'user_can_richedit', '__return_false' );

		$this->assertSame( $expected, sanitize_post_field( $field, self::MARKUP, self::POST_ID, $context ) );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_should_escape_value_for_context() {
		$textarea  = '&lt;b&gt;Bold&lt;/b&gt; &amp;amp; &quot;double&quot; &#039;single&#039;';
		$attribute = '&lt;b&gt;Bold&lt;/b&gt; &amp; &quot;double&quot; &#039;single&#039;';
		$js        = '&lt;b&gt;Bold&lt;/b&gt; &amp; &quot;double&quot; \\\'single\\\'';

		return array(
			'edit, post_content without rich editing' => array( 'post_content', 'edit', $textarea ),
			'edit, post_excerpt'                      => array( 'post_excerpt', 'edit', $textarea ),
			'edit, post_title'                        => array( 'post_title', 'edit', $textarea ),
			'edit, post_password'                     => array( 'post_password', 'edit', $textarea ),
			'edit, other prefixed field'              => array( 'post_name', 'edit', $attribute ),
			'edit, unprefixed field'                  => array( 'pinged', 'edit', $attribute ),
			'attribute, prefixed field'               => array( 'post_title', 'attribute', $attribute ),
			'attribute, unprefixed field'             => array( 'pinged', 'attribute', $attribute ),
			'js, prefixed field'                      => array( 'post_title', 'js', $js ),
			'js, unprefixed field'                    => array( 'pinged', 'js', $js ),
			'display, prefixed field'                 => array( 'post_title', 'display', self::MARKUP ),
			'display, unprefixed field'               => array( 'pinged', 'display', self::MARKUP ),
			'db, prefixed field'                      => array( 'post_name', 'db', self::MARKUP ),
			'db, unprefixed field'                    => array( 'pinged', 'db', self::MARKUP ),
		);
	}

	/**
	 * @ticket 65819
	 */
	public function test_should_not_escape_post_content_for_editing_when_rich_editing_is_available() {
		add_filter( 'user_can_richedit', '__return_true' );

		$this->assertSame( self::MARKUP, sanitize_post_field( 'post_content', self::MARKUP, self::POST_ID, 'edit' ) );
	}

	/**
	 * @ticket 65819
	 */
	public function test_should_escape_other_text_fields_for_editing_when_rich_editing_is_available() {
		add_filter( 'user_can_richedit', '__return_true' );

		$this->assertSame(
			'&lt;b&gt;Bold&lt;/b&gt; &amp;amp; &quot;double&quot; &#039;single&#039;',
			sanitize_post_field( 'post_title', self::MARKUP, self::POST_ID, 'edit' )
		);
	}
}
