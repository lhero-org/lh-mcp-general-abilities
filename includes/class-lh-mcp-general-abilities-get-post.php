<?php
/**
 * The get-post ability.
 *
 * @package LH_MCP_General_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves a single post by ID or slug, with both its stored block markup and
 * a Markdown rendering of it.
 *
 * Constrained to the readable post-type allowlist on
 * LH_MCP_General_Abilities_Post_Read_Support - post and page by default - and
 * checks read_post per post on top of the plugin-wide edit_posts gate, so a
 * caller who may edit content generally still cannot read a specific post they
 * have no rights to.
 *
 * content_raw is always complete. content_markdown is the part that can be
 * absent, and markdown_source plus notice say why in terms a caller can act
 * on: 'classic' means the post predates the block editor and there is nothing
 * to convert, which is not a fault, while 'unavailable' and 'failed' are.
 *
 * Split out of LH_MCP_General_Abilities_Posts, the last multi-ability domain
 * class; its shared helpers moved to the read-support class rather than being
 * duplicated across the three abilities that came out of it.
 *
 * Declared unguarded, deliberately - see the note on the main plugin class.
 */
final class LH_MCP_General_Abilities_Get_Post extends LH_MCP_General_Abilities_Ability {

	/**
	 * Ability name.
	 *
	 * @return string
	 */
	public static function name(): string {
		return LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() . '/get-post';
	}

	/**
	 * Ability label.
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'Get Post', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
	}

	/**
	 * Ability description.
	 *
	 * @return string
	 */
	public static function description(): string {
		return __( 'Retrieve a full post by ID or slug. Returns the raw block markup plus a Markdown rendering of it, produced by LH MCP Block Transformer. If that plugin is unavailable, content_markdown is null, markdown_source says why, and notice carries an instruction to pass on to the user — content_raw is always complete regardless.', LH_MCP_General_Abilities_Plugin::return_plugin_text_domain() ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
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
				'id'        => array(
					'type'        => 'integer',
					'description' => 'Post ID.',
				),
				'slug'      => array(
					'type'        => 'string',
					'description' => 'Post slug.',
				),
				'post_type' => array(
					'type'        => 'string',
					'description' => 'Post type to read. Default: auto-detect from ID/slug.',
					'enum'        => LH_MCP_General_Abilities_Post_Read_Support::return_readable_post_types(),
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
				'id'               => array( 'type' => 'integer' ),
				'title'            => array( 'type' => 'string' ),
				'slug'             => array( 'type' => 'string', 'description' => 'Post slug. Empty for a draft that has never been published — WordPress does not assign one until first publish.' ),
				'status'           => array( 'type' => 'string' ),
				'date'             => array(
					'type'        => array( 'string', 'null' ),
					'description' => 'Publication date as an ISO 8601 UTC timestamp. For a post that has never been published this is the authored date converted from the site timezone, since WordPress does not set a GMT date until first publish. Null only when the post has no usable date in either column.',
				),
				'modified'         => array(
					'type'        => array( 'string', 'null' ),
					'description' => 'Last-modified date as an ISO 8601 UTC timestamp, resolved the same way as date.',
				),
				'content_raw'      => array( 'type' => 'string', 'description' => 'The post\'s stored content: Gutenberg block markup for a block-editor post, plain HTML for a classic one. Always complete, never affected by markdown conversion.' ),
				'content_markdown' => array(
					'type'        => array( 'string', 'null' ),
					'description' => 'Markdown rendering of content_raw via LH MCP Block Transformer. Null when the conversion could not run or did not apply — see markdown_source and notice.',
				),
				'markdown_source'  => array(
					'type'        => 'string',
					'enum'        => array( 'lh-mcp-block-transformer', 'empty', 'classic', 'unavailable', 'failed' ),
					'description' => 'Where content_markdown came from. "classic" means the post has no block markup to convert because it predates the block editor — expected, not an error. "unavailable" means the transformer plugin is not active; "failed" means it errored.',
				),
				'notice'           => array(
					'type'        => 'string',
					'description' => 'Empty in the normal case. When non-empty it explains why content_markdown is null and what, if anything, to do about it. Read it before deciding whether there is a problem to report — some values describe expected situations that need no action.',
				),
				'categories'       => array(
					'type'        => 'array',
					'description' => 'Category slugs. A post may carry the site default category even if none was ever set explicitly — WordPress assigns it on insert.',
					'items'       => array( 'type' => 'string' ),
				),
				'tags'             => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'preview_link'     => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Fetches the post.
	 *
	 * @param  array|null $input Ability input parameters.
	 * @return array|WP_Error
	 */
	public static function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		$readable = LH_MCP_General_Abilities_Post_Read_Support::return_readable_post_types();
		$post     = null;

		if ( ! empty( $input['id'] ) ) {
			$post = get_post( (int) $input['id'] );
		} elseif ( ! empty( $input['slug'] ) ) {
			$posts = get_posts(
				array(
					'name'        => $input['slug'],
					'post_type'   => ! empty( $input['post_type'] ) ? $input['post_type'] : $readable,
					'post_status' => array( 'draft', 'publish', 'pending', 'private' ),
					'numberposts' => 1,
				)
			);

			$post = ! empty( $posts ) ? $posts[0] : null;
		} else {
			return new WP_Error( 'missing_param', 'Either id or slug is required.', array( 'status' => 400 ) );
		}

		if ( ! $post || ! in_array( $post->post_type, $readable, true ) ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		// Per-post capability check.
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to read this post.', array( 'status' => 403 ) );
		}

		$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'slugs' ) );
		$tags       = wp_get_post_tags( $post->ID, array( 'fields' => 'slugs' ) );

		$featured_id  = (int) get_post_thumbnail_id( $post->ID );
		$featured_url = $featured_id ? wp_get_attachment_url( $featured_id ) : null;

		$markdown = LH_MCP_General_Abilities_Post_Read_Support::content_markdown( $post );

		return array(
			'id'                 => $post->ID,
			'title'              => $post->post_title,
			'slug'               => $post->post_name,
			'status'             => $post->post_status,
			'date'               => LH_MCP_General_Abilities_Post_Read_Support::iso_datetime( $post, 'date' ),
			'modified'           => LH_MCP_General_Abilities_Post_Read_Support::iso_datetime( $post, 'modified' ),
			'content_raw'        => $post->post_content,
			'content_markdown'   => $markdown['content'],
			'markdown_source'    => $markdown['source'],
			'notice'             => $markdown['notice'],
			'categories'         => $categories,
			'tags'               => $tags,
			'excerpt'            => $post->post_excerpt,
			'featured_media_id'  => $featured_id ? $featured_id : null,
			'featured_media_url' => $featured_url,
			'link'               => get_permalink( $post->ID ),
			'preview_link'       => get_preview_post_link( $post->ID ),
		);
	}
}
