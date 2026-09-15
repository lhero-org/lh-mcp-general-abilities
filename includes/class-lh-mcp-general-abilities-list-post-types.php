<?php
/**
 * The list-post-types ability.
 *
 * @package LH_MCP_General_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists registered post types, so a caller can discover valid post_type values
 * before calling list-posts.
 *
 * Registers without an output schema - output_schema() returns an empty array
 * and the base class then omits the key entirely, matching how this ability
 * was registered before the split. It is the only ability in the plugin
 * without one.
 *
 * Note this lists what is registered, which is not the same as what the write
 * abilities will accept: create-post and update-post enforce their own
 * allowlist via LH_MCP_General_Abilities_Post_Write_Support, and get-post its
 * own via the read-support class. A type appearing here says only that
 * WordPress knows about it.
 *
 * Split out of LH_MCP_General_Abilities_Posts, the last multi-ability domain
 * class.
 *
 * Declared unguarded, deliberately - see the note on the main plugin class.
 */
final class LH_MCP_General_Abilities_List_Post_Types extends LH_MCP_General_Abilities_Ability {

	/**
	 * Ability name.
	 *
	 * @return string
	 */
	public static function name(): string {
		return LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() . '/list-post-types';
	}

	/**
	 * Ability label.
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'List Post Types', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
	}

	/**
	 * Ability description.
	 *
	 * @return string
	 */
	public static function description(): string {
		return __( 'Returns all registered public post types with name, labels, REST base, and editor support. Call this before list-posts to discover which post_type values are valid.', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
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
	 * Input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'include_private' => array(
					'type'        => 'boolean',
					'description' => 'Include non-public post types. Default false.',
					'default'     => false,
				),
			),
		);
	}

	/**
	 * Returns the registered post types.
	 *
	 * @param  array|null $input Ability input parameters.
	 * @return array<string, mixed>
	 */
	public static function execute( $input = null ) {
		$include_private = ! empty( $input['include_private'] );

		$args = $include_private
			? array()
			: array( 'public' => true );

		$post_types = get_post_types( $args, 'objects' );
		$result     = array();

		foreach ( $post_types as $type ) {
			/*
			 * rest_base falls back to the post type name, via empty() rather
			 * than ??. WP_Post_Type sets this property to false, not null,
			 * when a type declares no REST base, so a null-coalesce never
			 * fires and the caller receives a literal false - which is
			 * actively misleading, since WordPress routes such a type on its
			 * name. Caught by PHPStan level 5 (nullCoalesce.property).
			 */
			$result[] = array(
				'name'            => $type->name,
				'label'           => $type->label,
				'singular'        => $type->labels->singular_name ?? $type->label,
				'description'     => $type->description,
				'rest_base'       => ! empty( $type->rest_base ) ? $type->rest_base : $type->name,
				'show_in_rest'    => (bool) $type->show_in_rest,
				'supports_editor' => post_type_supports( $type->name, 'editor' ),
				'hierarchical'    => (bool) $type->hierarchical,
			);
		}

		return array(
			'post_types' => $result,
			'total'      => count( $result ),
		);
	}
}
