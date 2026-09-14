<?php
/**
 * The create-post ability.
 *
 * @package LH_MCP_General_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new post as a draft.
 *
 * Draft-only is structural, not a default. There is no status parameter in
 * the input schema, nothing reads one if passed, and post_status is a literal
 * here - so no call through this ability can publish, schedule, or set any
 * member-visibility status, whatever the caller intends or is told to intend.
 * Publishing stays a human act; lh-agora/propose-changes is the reviewed path
 * for content that is already live.
 *
 * Everything is validated before wp_insert_post() runs. Meta and terms need a
 * post ID and so can only be written afterwards, which means validating them
 * late would leave a created post behind whenever one of them failed - an
 * empty draft the caller did not ask for and may not notice. Validate all of
 * it first, insert once, then apply.
 *
 * Declared unguarded, deliberately - see the note on the main plugin class.
 */
final class LH_MCP_General_Abilities_Create_Post extends LH_MCP_General_Abilities_Ability {

	/**
	 * Ability name.
	 *
	 * @return string
	 */
	public static function name(): string {
		return LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() . '/create-post';
	}

	/**
	 * Ability label.
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'Create Post', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
	}

	/**
	 * Ability description.
	 *
	 * @return string
	 */
	public static function description(): string {
		return __( 'Creates a new post or page as a draft, from Markdown or HTML content that is converted to Gutenberg block markup before saving. Always a draft: there is no status parameter, and nothing created here is publicly visible until a human publishes it. Only the post types on the writable allowlist (post and page by default) can be created - other content types have their own purpose-built abilities. Returns the new ID plus an edit link, and reports any content that degraded to a generic block wrapper.', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
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
			'required'   => array( 'title' ),
			'properties' => array_merge(
				array(
					'post_type' => array(
						'type'        => 'string',
						'description' => 'Post type to create. Defaults to "post". Must be on the writable allowlist - post and page by default.',
						'default'     => 'post',
					),
					'title'     => array(
						'type'        => 'string',
						'description' => 'Post title.',
					),
				),
				LH_MCP_General_Abilities_Post_Write_Support::shared_input_properties()
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
			'properties' => LH_MCP_General_Abilities_Post_Write_Support::shared_output_properties(),
		);
	}

	/**
	 * Creates the draft.
	 *
	 * @param  array|null $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		$post_type = LH_MCP_General_Abilities_Post_Write_Support::validate_post_type(
			isset( $input['post_type'] ) ? $input['post_type'] : 'post'
		);

		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}

		$post_type_object = get_post_type_object( $post_type );

		if ( ! isset( $post_type_object->cap->create_posts ) || ! current_user_can( $post_type_object->cap->create_posts ) ) {
			return new WP_Error(
				'cannot_create',
				sprintf( 'You do not have permission to create content of type "%s".', $post_type ),
				array( 'status' => 403 )
			);
		}

		$title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';

		if ( '' === trim( $title ) ) {
			return new WP_Error(
				'missing_title',
				'title is required and cannot be empty.',
				array( 'status' => 400 )
			);
		}

		$content = LH_MCP_General_Abilities_Post_Write_Support::resolve_content( $input );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$parent = 0;

		if ( isset( $input['post_parent'] ) ) {
			$parent = LH_MCP_General_Abilities_Post_Write_Support::validate_parent( $input['post_parent'], $post_type );

			if ( is_wp_error( $parent ) ) {
				return $parent;
			}
		}

		$meta = LH_MCP_General_Abilities_Post_Write_Support::validate_meta(
			isset( $input['meta'] ) ? $input['meta'] : array(),
			$post_type
		);

		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		$terms = LH_MCP_General_Abilities_Post_Write_Support::validate_terms(
			isset( $input['terms'] ) ? $input['terms'] : array(),
			$post_type
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$postarr = array(
			'post_type'    => $post_type,
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_content' => $content['content'],
			'post_author'  => get_current_user_id(),
			'post_parent'  => $parent,
		);

		if ( isset( $input['excerpt'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( (string) $input['excerpt'] );
		}

		/*
		 * wp_slash() before insert. wp_insert_post() runs the array through
		 * sanitize_post( $postarr, 'db' ), which strips one level of literal
		 * backslashes - corrupting the unicode escapes inside block-comment
		 * JSON, which is exactly what post_content holds here. This was a
		 * real, confirmed lh-portfolio bug before 1.4.6, and it fails
		 * silently: the post saves, and the damage only shows up later in
		 * the editor.
		 */
		$post_id = wp_insert_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;

		$meta_applied  = LH_MCP_General_Abilities_Post_Write_Support::apply_meta( $post_id, $meta );
		$terms_applied = LH_MCP_General_Abilities_Post_Write_Support::apply_terms( $post_id, $terms );

		$post = get_post( $post_id );

		$notice = '';

		if ( $content['fallback_count'] > 0 ) {
			$notice = sprintf(
				'%d element(s) could not be matched to a specific block and were saved as a generic wrapper. The draft saved correctly; see the fallbacks array for what degraded, and lh-mcp-block-transformer/get-pattern-guide for the HTML shapes that produce specific blocks.',
				$content['fallback_count']
			);
		}

		return array(
			'id'             => $post_id,
			'post_type'      => $post_type,
			'status'         => $post ? $post->post_status : 'draft',
			'title'          => $post ? $post->post_title : $title,
			'slug'           => $post ? $post->post_name : '',
			'edit_link'      => get_edit_post_link( $post_id, 'raw' ),
			'preview_link'   => get_preview_post_link( $post_id ),
			'block_count'    => $content['block_count'],
			'fallback_count' => $content['fallback_count'],
			'fallbacks'      => $content['fallbacks'],
			'meta_applied'   => $meta_applied,
			'terms_applied'  => $terms_applied,
			'notice'         => $notice,
		);
	}
}
