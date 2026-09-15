<?php
/**
 * The list-posts ability.
 *
 * @package LH_MCP_General_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists posts of any registered post type, with pagination, status filtering
 * and field subsetting.
 *
 * Deliberately not constrained by the readable post-type allowlist that
 * get-post uses: this returns titles, slugs, links and dates, not content, and
 * it has always accepted any registered type. Whether it should be narrowed is
 * a real question but its own decision, not a side effect of the split - so
 * the behaviour is carried over exactly.
 *
 * Split out of LH_MCP_General_Abilities_Posts, the last multi-ability domain
 * class; iso_datetime() now comes from the shared read-support class.
 *
 * Declared unguarded, deliberately - see the note on the main plugin class.
 */
final class LH_MCP_General_Abilities_List_Posts extends LH_MCP_General_Abilities_Ability {

	/**
	 * Ability name.
	 *
	 * @return string
	 */
	public static function name(): string {
		return LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() . '/list-posts';
	}

	/**
	 * Ability label.
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'List Posts', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
	}

	/**
	 * Ability description.
	 *
	 * @return string
	 */
	public static function description(): string {
		return __( 'List posts of any registered post type with pagination, status filtering, and field subsetting. Use list-post-types first to discover valid post_type values.', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
	}

	/**
	 * Ability category.
	 *
	 * @return string
	 */
	public static function category(): string {
		return 'content-management';
	}

	/**
	 * The fields a caller may request.
	 *
	 * @return string[]
	 */
	private static function available_fields(): array {
		return array( 'id', 'title', 'slug', 'link', 'status', 'date', 'modified', 'excerpt' );
	}

	/**
	 * The fields returned when none are requested.
	 *
	 * @return string[]
	 */
	private static function default_fields(): array {
		return array( 'id', 'title', 'slug', 'link', 'status', 'date' );
	}

	/**
	 * Post statuses this ability will filter on.
	 *
	 * @return string[]
	 */
	private static function allowed_statuses(): array {
		return array( 'publish', 'draft', 'pending', 'private', 'any' );
	}

	/**
	 * Input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_type' => array(
					'type'        => 'string',
					'description' => 'Post type to list (e.g. post, page, or any registered CPT). Use list-post-types to discover valid values.',
					'default'     => 'post',
				),
				'status'    => array(
					'type'        => 'string',
					'description' => 'Post status filter. One of: publish, draft, pending, private, any.',
					'enum'        => self::allowed_statuses(),
					'default'     => 'publish',
				),
				'per_page'  => array(
					'type'        => 'integer',
					'description' => 'Results per page. Default 20, max 100.',
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'      => array(
					'type'        => 'integer',
					'description' => 'Page number (1-based).',
					'default'     => 1,
					'minimum'     => 1,
				),
				'search'    => array(
					'type'        => 'string',
					'description' => 'Optional keyword search against post title and content.',
				),
				'fields'    => array(
					'type'        => 'array',
					'description' => 'Subset of fields to return. Available: id, title, slug, link, status, date, modified, excerpt. Defaults to: id, title, slug, link, status, date.',
					'items'       => array(
						'type' => 'string',
						'enum' => self::available_fields(),
					),
				),
			),
		);
	}

	/**
	 * Output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_type' => array( 'type' => 'string' ),
				'status'    => array( 'type' => 'string' ),
				'total'     => array( 'type' => 'integer', 'description' => 'Total matching posts across all pages.' ),
				'pages'     => array( 'type' => 'integer', 'description' => 'Total number of pages.' ),
				'page'      => array( 'type' => 'integer', 'description' => 'Current page.' ),
				'per_page'  => array( 'type' => 'integer' ),
				'items'     => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'       => array( 'type' => 'integer' ),
							'title'    => array( 'type' => 'string' ),
							'slug'     => array( 'type' => 'string' ),
							'link'     => array( 'type' => 'string' ),
							'status'   => array( 'type' => 'string' ),
							'date'     => array( 'type' => array( 'string', 'null' ) ),
							'modified' => array( 'type' => array( 'string', 'null' ) ),
							'excerpt'  => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Runs the query.
	 *
	 * @param  array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input     = is_array( $input ) ? $input : array();
		$post_type = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'post';
		$status    = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'publish';
		$per_page  = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
		$page      = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$search    = isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '';
		$fields    = isset( $input['fields'] ) ? (array) $input['fields'] : array();

		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error( 'invalid_post_type', "Post type '{$post_type}' does not exist.", array( 'status' => 400 ) );
		}

		if ( ! in_array( $status, self::allowed_statuses(), true ) ) {
			return new WP_Error( 'invalid_status', "Status '{$status}' is not allowed.", array( 'status' => 400 ) );
		}

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		);

		if ( $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );

		$return_fields = empty( $fields )
			? self::default_fields()
			: array_intersect( $fields, self::available_fields() );

		$items = array();

		foreach ( $query->posts as $post ) {
			$item = array();

			foreach ( $return_fields as $field ) {
				switch ( $field ) {
					case 'id':
						$item['id'] = $post->ID;
						break;
					case 'title':
						$item['title'] = $post->post_title;
						break;
					case 'slug':
						$item['slug'] = $post->post_name;
						break;
					case 'link':
						$item['link'] = get_permalink( $post->ID );
						break;
					case 'status':
						$item['status'] = $post->post_status;
						break;
					case 'date':
						$item['date'] = LH_MCP_General_Abilities_Post_Read_Support::iso_datetime( $post, 'date' );
						break;
					case 'modified':
						$item['modified'] = LH_MCP_General_Abilities_Post_Read_Support::iso_datetime( $post, 'modified' );
						break;
					case 'excerpt':
						$item['excerpt'] = $post->post_excerpt;
						break;
				}
			}

			$items[] = $item;
		}

		return array(
			'post_type' => $post_type,
			'status'    => $status,
			'total'     => (int) $query->found_posts,
			'pages'     => (int) $query->max_num_pages,
			'page'      => $page,
			'per_page'  => $per_page,
			'items'     => $items,
		);
	}
}
