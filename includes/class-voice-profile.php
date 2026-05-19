<?php
/**
 * Voice Profile management.
 *
 * Custom post type for storing brand voice configurations that shape
 * AI-generated content via system prompt injection.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Voice_Profile {

	public const POST_TYPE = 'swps_voice_profile';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'init', array( self::class, 'register_post_type' ) );
		add_filter( 'swps_system_prompt', array( $this, 'inject_voice_profile' ), 5, 3 );
	}

	/**
	 * Register the custom post type.
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'               => __( 'Voice Profiles', 'stratawp-seo' ),
					'singular_name'      => __( 'Voice Profile', 'stratawp-seo' ),
					'add_new_item'       => __( 'Add New Voice Profile', 'stratawp-seo' ),
					'edit_item'          => __( 'Edit Voice Profile', 'stratawp-seo' ),
					'new_item'           => __( 'New Voice Profile', 'stratawp-seo' ),
					'all_items'          => __( 'All Voice Profiles', 'stratawp-seo' ),
					'not_found'          => __( 'No voice profiles found.', 'stratawp-seo' ),
					'not_found_in_trash' => __( 'No voice profiles found in Trash.', 'stratawp-seo' ),
				),
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
				'capabilities' => array(
					'create_posts' => 'manage_options',
				),
			)
		);
	}

	/**
	 * Create a voice profile.
	 *
	 * @param string $name Profile name.
	 * @param array  $meta Profile settings.
	 * @return int|WP_Error Post ID or error.
	 */
	public function create( string $name, array $meta ): int|WP_Error {
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => sanitize_text_field( $name ),
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->save_meta( $post_id, $meta );
		return $post_id;
	}

	/**
	 * Update a voice profile.
	 *
	 * @param int    $profile_id Profile post ID.
	 * @param string $name       Profile name.
	 * @param array  $meta       Profile settings.
	 * @return int|WP_Error Post ID or error.
	 */
	public function update( int $profile_id, string $name, array $meta ): int|WP_Error {
		$post = get_post( $profile_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'invalid_profile', __( 'Voice profile not found.', 'stratawp-seo' ) );
		}

		$result = wp_update_post(
			array(
				'ID'         => $profile_id,
				'post_title' => sanitize_text_field( $name ),
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->save_meta( $profile_id, $meta );
		return $profile_id;
	}

	/**
	 * Delete a voice profile.
	 *
	 * @param int $profile_id Profile post ID.
	 * @return bool
	 */
	public function delete( int $profile_id ): bool {
		$post = get_post( $profile_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}

		// If this was the active profile, clear the option.
		if ( (int) get_option( 'swps_voice_profile', 0 ) === $profile_id ) {
			update_option( 'swps_voice_profile', 0 );
		}
		return false !== wp_delete_post( $profile_id, true );
	}

	/**
	 * Get all voice profiles.
	 *
	 * @return array Array of profile data arrays.
	 */
	public function get_all(): array {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		return array_map( array( $this, 'format_profile' ), $posts );
	}

	/**
	 * Get a single voice profile.
	 *
	 * @param int $profile_id Profile post ID.
	 * @return array|null Profile data or null.
	 */
	public function get( int $profile_id ): ?array {
		$post = get_post( $profile_id );
		if ( ! $post || $post->post_type !== self::POST_TYPE ) {
			return null;
		}
		return $this->format_profile( $post );
	}

	/**
	 * Get the active voice profile ID.
	 *
	 * @return int Profile ID or 0 if none.
	 */
	public function get_active_id(): int {
		return (int) get_option( 'swps_voice_profile', 0 );
	}

	/**
	 * Compile a voice profile into a system prompt block.
	 *
	 * @param int $profile_id Profile post ID.
	 * @return string Compiled prompt text.
	 */
	public function compile( int $profile_id ): string {
		$profile = $this->get( $profile_id );
		if ( ! $profile ) {
			return '';
		}

		// Strip newlines/control chars to prevent prompt injection.
		$clean = static function ( string $s ): string {
			return wp_strip_all_tags( preg_replace( '/[\r\n\t]+/', ' ', $s ) );
		};

		$parts = array();

		if ( ! empty( $profile['tone'] ) ) {
			$parts[] = sprintf( 'Write in a %s tone.', $clean( $profile['tone'] ) );
		}

		if ( ! empty( $profile['formality'] ) ) {
			$parts[] = sprintf( 'Formality level: %d/10.', $profile['formality'] );
		}

		if ( ! empty( $profile['sentence_length'] ) ) {
			$parts[] = sprintf( 'Use %s sentences.', $clean( $profile['sentence_length'] ) );
		}

		if ( ! empty( $profile['vocabulary_level'] ) ) {
			$parts[] = sprintf( 'Vocabulary: %s.', $clean( $profile['vocabulary_level'] ) );
		}

		if ( ! empty( $profile['person'] ) ) {
			$label = match ( $profile['person'] ) {
				'first'  => 'first person (I/we)',
				'second' => 'second person (you)',
				'third'  => 'third person (they/one)',
				default  => $clean( $profile['person'] ) . ' person',
			};
			$parts[] = sprintf( 'Write in %s.', $label );
		}

		if ( ! empty( $profile['avoid_phrases'] ) ) {
			$sanitized = array_map( $clean, (array) $profile['avoid_phrases'] );
			$parts[]   = sprintf( 'Never use these phrases: %s.', implode( ', ', $sanitized ) );
		}

		if ( ! empty( $profile['preferred_phrases'] ) ) {
			$sanitized = array_map( $clean, (array) $profile['preferred_phrases'] );
			$parts[]   = sprintf( 'Prefer these expressions: %s.', implode( ', ', $sanitized ) );
		}

		if ( ! empty( $profile['example_content'] ) ) {
			$excerpt = $clean( mb_substr( $profile['example_content'], 0, 500 ) );
			$parts[] = sprintf( "Match the writing style of this sample:\n\"%s\"", $excerpt );
		}

		$compiled = "VOICE PROFILE — {$clean( $profile['name'] )}:\n" . implode( "\n", $parts );

		return apply_filters( 'swps_voice_compile', $compiled, $profile_id );
	}

	/**
	 * Inject the active voice profile into the system prompt.
	 *
	 * Hooks into swps_system_prompt at priority 5 (before other filters).
	 *
	 * @param string $prompt System prompt.
	 * @param string $tone   Tone setting.
	 * @param string $style  Style setting.
	 * @return string Modified prompt.
	 */
	public function inject_voice_profile( string $prompt, string $tone, string $style ): string {
		$profile_id = $this->get_active_id();
		if ( $profile_id <= 0 ) {
			return $prompt;
		}

		$voice_block = $this->compile( $profile_id );
		if ( empty( $voice_block ) ) {
			return $prompt;
		}

		// Replace the generic TONE line with the compiled voice profile.
		// Use callback to avoid backreference interpretation in replacement.
		$replaced = false;
		$prompt   = preg_replace_callback(
			'/^TONE:.*$/m',
			function () use ( $voice_block, &$replaced ) {
				$replaced = true;
				return $voice_block;
			},
			$prompt,
			1
		);

		// Append if no TONE line was found.
		if ( ! $replaced ) {
			$prompt .= "\n\n" . $voice_block;
		}

		return $prompt;
	}

	/**
	 * Get profile options for a select dropdown.
	 *
	 * @return array [id => name] pairs with 0 => "None".
	 */
	public function get_options(): array {
		$options = array( 0 => __( '— None (use tone/style settings) —', 'stratawp-seo' ) );

		foreach ( $this->get_all() as $profile ) {
			$options[ $profile['id'] ] = $profile['name'];
		}

		return $options;
	}

	/**
	 * Save profile meta fields.
	 */
	private function save_meta( int $post_id, array $meta ): void {
		$fields = array(
			'tone'              => 'sanitize_text_field',
			'formality'         => 'absint',
			'sentence_length'   => 'sanitize_text_field',
			'vocabulary_level'  => 'sanitize_text_field',
			'person'            => 'sanitize_text_field',
			'example_content'   => 'sanitize_textarea_field',
			'avoid_phrases'     => null, // JSON array.
			'preferred_phrases' => null, // JSON array.
		);

		foreach ( $fields as $field => $sanitize ) {
			if ( ! array_key_exists( $field, $meta ) ) {
				continue;
			}

			$value = $meta[ $field ];

			if ( $sanitize ) {
				$value = call_user_func( $sanitize, $value );
			} elseif ( is_array( $value ) ) {
				// Sanitize array elements.
				$value = array_filter( array_map( 'sanitize_text_field', $value ) );
			} elseif ( is_string( $value ) ) {
				// Normalize newlines to commas, then parse comma-separated string into array.
				$value = str_replace( array( "\r\n", "\r", "\n" ), ',', $value );
				$value = array_filter( array_map( 'trim', explode( ',', $value ) ) );
			}

			update_post_meta( $post_id, '_swps_vp_' . $field, $value );
		}
	}

	/**
	 * Format a profile post into a data array.
	 */
	private function format_profile( WP_Post $post ): array {
		return array(
			'id'                => $post->ID,
			'name'              => $post->post_title,
			'tone'              => get_post_meta( $post->ID, '_swps_vp_tone', true ) ?: '',
			'formality'         => ( '' !== ( $f = get_post_meta( $post->ID, '_swps_vp_formality', true ) ) ) ? (int) $f : 5,
			'sentence_length'   => get_post_meta( $post->ID, '_swps_vp_sentence_length', true ) ?: 'varied',
			'vocabulary_level'  => get_post_meta( $post->ID, '_swps_vp_vocabulary_level', true ) ?: 'moderate',
			'person'            => get_post_meta( $post->ID, '_swps_vp_person', true ) ?: 'second',
			'example_content'   => get_post_meta( $post->ID, '_swps_vp_example_content', true ) ?: '',
			'avoid_phrases'     => get_post_meta( $post->ID, '_swps_vp_avoid_phrases', true ) ?: array(),
			'preferred_phrases' => get_post_meta( $post->ID, '_swps_vp_preferred_phrases', true ) ?: array(),
		);
	}
}
