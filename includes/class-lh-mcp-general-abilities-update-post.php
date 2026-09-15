<?php
/**
 * The update-post ability.
 *
 * @package LH_MCP_General_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Revises an existing unpublished post.
 *
 * Patch semantics: a field is written only if it was supplied. Omitting
 * content_md and content_html leaves the body untouched rather than emptying
 * it, which matters because the natural way to retitle a post is to send only
 * a title.
 *
 * Which posts may be touched is decided by an allowlist of current statuses -
 * draft, pending, auto-draft - not by excluding publish. A !== 'publish'
 * check would fail open on lh-membership's restricted, confidential and
 * logged-in statuses, all of which are live member-visible content, and on
 * every status registered after this was written. Naming what is permitted
 * means a status nobody anticipated is refused by default rather than
 * accepted by default.
 *
 * There is no status parameter, so this ability cannot change a post's status
 * to anything - it can only decline to touch posts already past drafting.
 * The single exception is auto-draft, which is normalised to draft on save:
 * an auto-draft is WordPress's placeholder for a post that was opened but
 * never saved, and it is garbage-collected after seven days, so writing real
 * content into one and leaving it auto-draft would quietly schedule that work
 * for deletion. The block editor does the same normalisation on first save.
 *
 * Declared unguarded, deliberately - see the note on the main plugin class.
 */
final class LH_MCP_General_Abilities_Update_Post extends LH_MCP_General_Abilities_Ability {

	/**
	 * Ability name.
	 *
	 * @return string
	 */
	public static function name(): string {
		return LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() . '/update-post';
	}

	/**
	 * Ability label.
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'Update Post', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
	}

	/**
	 * Ability description.
	 *
	 * @return string
	 */
	public static function description(): string {
		return __( 'Revises an existing draft or pending post. Only the fields supplied are changed; anything omitted is left alone. Refuses any post that is already published or otherwise live - use lh-agora/propose-changes for those, which routes through human review. There is no status parameter, so this can never publish anything. Markdown or HTML content is converted to Gutenberg block markup before saving. Use get-post first to read the current content.', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
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
			'required'   => array( 'post_id' ),
			'properties' => array_merge(
				array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'ID of the post to revise. Must currently be a draft, pending, or auto-draft.',
					),
					'title'   => array(
						'type'        => 'string',
						'description' => 'New title. Omit to leave the existing title unchanged.',
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
			'properties' => array_merge(
				LH_MCP_General_Abilities_Post_Write_Support::shared_output_properties(),
				array(
					'updated_fields' => array(
						'type'        => 'array',
						'description' => 'Which post fields this call actually changed. Meta and terms are reported separately.',
						'items'       => array( 'type' => 'string' ),
					),
				)
			),
		);
	}

	/**
	 * Applies the revision.
	 *
	 * @param  array|null $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		if ( $post_id <= 0 ) {
			return new WP_Error(
				'missing_param',
				'post_id is required.',
				array( 'status' => 400 )
			);
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'not_found',
				sprintf( 'Post %d does not exist.', $post_id ),
				array( 'status' => 404 )
			);
		}

		$post_type = LH_MCP_General_Abilities_Post_Write_Support::validate_post_type( $post->post_type );

		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}

		/*
		 * Per-post capability, routed through edit_post rather than an author
		 * comparison so map_meta_cap applies - that is what makes roles,
		 * edit_others_posts, and any capability filter on this network apply
		 * here too. The ability-level edit_posts gate only establishes that
		 * the caller edits content somewhere on the site.
		 */
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'forbidden',
				sprintf( 'You do not have permission to edit post %d.', $post_id ),
				array( 'status' => 403 )
			);
		}

		$editable = LH_MCP_General_Abilities_Post_Write_Support::editable_statuses();

		if ( ! in_array( $post->post_status, $editable, true ) ) {
			return new WP_Error(
				'status_not_editable',
				sprintf(
					'Post %d has status "%s", so it cannot be revised through this ability. Only %s may be edited here. Nothing has been written. This post is already live in some form - use lh-agora/propose-changes, which routes the revision through human review before anything published changes.',
					$post_id,
					$post->post_status,
					implode( ', ', $editable )
				),
				array( 'status' => 409 )
			);
		}

		$content = LH_MCP_General_Abilities_Post_Write_Support::resolve_content( $input );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$meta = LH_MCP_General_Abilities_Post_Write_Support::validate_meta(
			isset( $input['meta'] ) ? $input['meta'] : array(),
			$post->post_type
		);

		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		$terms = LH_MCP_General_Abilities_Post_Write_Support::validate_terms(
			isset( $input['terms'] ) ? $input['terms'] : array(),
			$post->post_type
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$postarr        = array( 'ID' => $post_id );
		$updated_fields = array();

		if ( isset( $input['title'] ) ) {
			$title = sanitize_text_field( (string) $input['title'] );

			if ( '' === trim( $title ) ) {
				return new WP_Error(
					'invalid_title',
					'title cannot be set to an empty string. Omit it to leave the existing title unchanged.',
					array( 'status' => 400 )
				);
			}

			$postarr['post_title'] = $title;
			$updated_fields[]      = 'title';
		}

		if ( $content['supplied'] ) {
			$postarr['post_content'] = $content['content'];
			$updated_fields[]        = 'content';
		}

		if ( isset( $input['excerpt'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( (string) $input['excerpt'] );
			$updated_fields[]        = 'excerpt';
		}

		if ( isset( $input['post_parent'] ) ) {
			$parent = LH_MCP_General_Abilities_Post_Write_Support::validate_parent( $input['post_parent'], $post->post_type, $post_id );

			if ( is_wp_error( $parent ) ) {
				return $parent;
			}

			$postarr['post_parent'] = $parent;
			$updated_fields[]       = 'post_parent';
		}

		// See the class docblock: an auto-draft left as-is would be garbage-collected after seven days.
		if ( 'auto-draft' === $post->post_status ) {
			$postarr['post_status'] = 'draft';
			$updated_fields[]       = 'status (auto-draft normalised to draft)';
		}

		if ( count( $postarr ) < 2 && empty( $meta ) && empty( $terms ) ) {
			return new WP_Error(
				'nothing_to_update',
				'No fields were supplied to change. Pass at least one of title, content_md, content_html, excerpt, post_parent, meta or terms.',
				array( 'status' => 400 )
			);
		}

		if ( count( $postarr ) > 1 ) {
			// wp_slash() before update - see the note in create-post for why omitting it corrupts block markup silently.
			$result = wp_update_post( wp_slash( $postarr ), true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$meta_applied  = LH_MCP_General_Abilities_Post_Write_Support::apply_meta( $post_id, $meta );
		$terms_applied = LH_MCP_General_Abilities_Post_Write_Support::apply_terms( $post_id, $terms );

		$post = get_post( $post_id );

		$notice = '';

		if ( $content['fallback_count'] > 0 ) {
			$notice = sprintf(
				'%d element(s) could not be matched to a specific block and were saved as a generic wrapper. The revision saved correctly; see the fallbacks array for what degraded, and lh-mcp-block-transformer/get-pattern-guide for the HTML shapes that produce specific blocks.',
				$content['fallback_count']
			);
		}

		return array(
			'id'             => $post_id,
			'post_type'      => $post->post_type,
			'status'         => $post->post_status,
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'edit_link'      => get_edit_post_link( $post_id, 'raw' ),
			'preview_link'   => get_preview_post_link( $post_id ),
			'block_count'    => $content['block_count'],
			'fallback_count' => $content['fallback_count'],
			'fallbacks'      => $content['fallbacks'],
			'meta_applied'   => $meta_applied,
			'terms_applied'  => $terms_applied,
			'updated_fields' => $updated_fields,
			'notice'         => $notice,
		);
	}
}
