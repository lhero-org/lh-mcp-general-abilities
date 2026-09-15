<?php
/**
 * The list-mcp-resources ability.
 *
 * @package LH_MCP_General_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists every publicly registered MCP resource-typed ability.
 *
 * This exists because MCP resources are application-controlled per the spec:
 * on every current Claude surface a human has to attach one explicitly through
 * the Connectors panel or an @-mention. Listing them as a tool call lets the
 * model discover what is available without that manual step. Pair it with
 * read-mcp-resource to fetch one.
 *
 * Uses the plugin-wide edit_posts gate with no override. Nothing here exposes
 * more than the target abilities' own registrations already declare public,
 * and reading a resource's contents is read-mcp-resource's job, where the
 * target's own permission callback runs.
 *
 * Split out of LH_MCP_General_Abilities_MCP_Utilities, which held this and
 * read-mcp-resource; the shared public-resource test now lives on
 * LH_MCP_General_Abilities_MCP_Resource_Support rather than being duplicated
 * in both.
 *
 * Declared unguarded, deliberately - see the note on the main plugin class.
 */
final class LH_MCP_General_Abilities_List_MCP_Resources extends LH_MCP_General_Abilities_Ability {

	/**
	 * Ability name.
	 *
	 * @return string
	 */
	public static function name(): string {
		return LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() . '/list-mcp-resources';
	}

	/**
	 * Ability label.
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'List MCP Resources', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
	}

	/**
	 * Ability description.
	 *
	 * @return string
	 */
	public static function description(): string {
		return __( 'Lists all publicly registered MCP resource-typed abilities (name, uri, description, mimeType). Resources are application-controlled per the MCP spec and on every current Claude surface require explicit human action to attach (Connectors panel / @-mention) - this ability lets the model discover them on its own. Pair with read-mcp-resource to retrieve the content of a specific one.', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
	}

	/**
	 * Ability category.
	 *
	 * @return string
	 */
	public static function category(): string {
		return 'mcp-utilities';
	}

	/**
	 * Input schema. Uses a sentinel '_' property, per documented LH
	 * convention, since this ability takes no real input and the abilities
	 * adapter rejects a truly empty schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'_' => array(
					'type'        => 'string',
					'description' => 'Unused. This ability takes no parameters.',
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
				'resources' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'        => array( 'type' => 'string', 'description' => 'The full ability name, e.g. lh-mcp-resource-smoke-test/get-site-info-resource.' ),
							'uri'         => array( 'type' => 'string', 'description' => 'The resource URI - pass this to read-mcp-resource.' ),
							'description' => array( 'type' => 'string' ),
							'mimeType'    => array( 'type' => array( 'string', 'null' ) ),
						),
					),
				),
				'total'     => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * Scans the ability registry for public resource-typed abilities.
	 *
	 * @param  array|null $input Ability input parameters (unused).
	 * @return array<string, mixed>
	 */
	public static function execute( $input = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$resources = array();

		foreach ( wp_get_abilities() as $ability ) {
			$mcp = LH_MCP_General_Abilities_MCP_Resource_Support::resource_meta( $ability );

			if ( null === $mcp ) {
				continue;
			}

			$resources[] = array(
				'name'        => $ability->get_name(),
				'uri'         => $mcp['uri'] ?? '',
				'description' => $ability->get_description(),
				'mimeType'    => $mcp['mimeType'] ?? null,
			);
		}

		return array(
			'resources' => $resources,
			'total'     => count( $resources ),
		);
	}
}
