<?php
/**
 * Author E-E-A-T profile fields — user-meta storage + WP profile UI.
 *
 * Adds three per-user meta fields used by SWPS_Schema to render a rich
 * Person entity and ProfilePage for author archives:
 *   swps_sameas      — newline-separated list of sameAs URLs (social profiles etc.)
 *   swps_job_title   — plain-text job title
 *   swps_credentials — plain-text professional credentials / bio credentials
 *
 * Hooks:
 *   show_user_profile / edit_user_profile       — render the profile UI section
 *   personal_options_update / edit_user_profile_update — save & sanitize
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Author E-E-A-T profile fields.
 */
class SWPS_Author_Profile {

	/**
	 * Meta key for sameAs URLs (newline-separated).
	 */
	public const META_SAMEAS = 'swps_sameas';

	/**
	 * Meta key for job title.
	 */
	public const META_JOB_TITLE = 'swps_job_title';

	/**
	 * Meta key for credentials.
	 */
	public const META_CREDENTIALS = 'swps_credentials';

	/**
	 * Register WP hooks (called once from the plugin bootstrap).
	 */
	public function register_hooks(): void {
		add_action( 'show_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_fields' ) );
	}

	// -------------------------------------------------------------------------
	// Profile UI
	// -------------------------------------------------------------------------

	/**
	 * Render the E-E-A-T profile section on the WP user profile page.
	 *
	 * @param WP_User $user The user whose profile is being displayed.
	 */
	public function render_profile_fields( WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$sameas      = (string) get_user_meta( $user->ID, self::META_SAMEAS, true );
		$job_title   = (string) get_user_meta( $user->ID, self::META_JOB_TITLE, true );
		$credentials = (string) get_user_meta( $user->ID, self::META_CREDENTIALS, true );

		wp_nonce_field( 'swps_author_profile_' . $user->ID, 'swps_author_profile_nonce' );
		?>
		<h2><?php esc_html_e( 'Author E-E-A-T (StrataWP SEO)', 'stratawp-seo' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'These fields are used to build structured-data Person / ProfilePage schema nodes (E-E-A-T signals for Google Search).', 'stratawp-seo' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="swps_job_title"><?php esc_html_e( 'Job Title', 'stratawp-seo' ); ?></label></th>
				<td>
					<input type="text" name="swps_job_title" id="swps_job_title"
						value="<?php echo esc_attr( $job_title ); ?>"
						class="regular-text" />
					<p class="description"><?php esc_html_e( 'e.g. Senior Content Strategist', 'stratawp-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="swps_credentials"><?php esc_html_e( 'Credentials', 'stratawp-seo' ); ?></label></th>
				<td>
					<input type="text" name="swps_credentials" id="swps_credentials"
						value="<?php echo esc_attr( $credentials ); ?>"
						class="regular-text" />
					<p class="description"><?php esc_html_e( 'e.g. BSc Computer Science, Google Analytics Certified', 'stratawp-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="swps_sameas"><?php esc_html_e( 'sameAs URLs', 'stratawp-seo' ); ?></label></th>
				<td>
					<textarea name="swps_sameas" id="swps_sameas" rows="4" class="large-text"><?php echo esc_textarea( $sameas ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One URL per line — LinkedIn, Twitter/X, Wikipedia, personal site, etc.', 'stratawp-seo' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	// -------------------------------------------------------------------------
	// Save
	// -------------------------------------------------------------------------

	/**
	 * Save the E-E-A-T profile fields on profile update.
	 *
	 * @param int $user_id The ID of the user being saved.
	 */
	public function save_profile_fields( int $user_id ): void {
		if ( ! isset( $_POST['swps_author_profile_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['swps_author_profile_nonce'] ) ),
			'swps_author_profile_' . $user_id
		) ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// Job title.
		$job_title = isset( $_POST['swps_job_title'] )
			? sanitize_text_field( wp_unslash( $_POST['swps_job_title'] ) )
			: '';
		update_user_meta( $user_id, self::META_JOB_TITLE, $job_title );

		// Credentials.
		$credentials = isset( $_POST['swps_credentials'] )
			? sanitize_text_field( wp_unslash( $_POST['swps_credentials'] ) )
			: '';
		update_user_meta( $user_id, self::META_CREDENTIALS, $credentials );

		// sameAs URLs — sanitize each line as a URL.
		$raw_sameas = isset( $_POST['swps_sameas'] )
			? sanitize_textarea_field( wp_unslash( $_POST['swps_sameas'] ) )
			: '';

		$lines      = array_filter( array_map( 'trim', explode( "\n", $raw_sameas ) ) );
		$clean_urls = array();
		foreach ( $lines as $line ) {
			$url = esc_url_raw( $line );
			if ( '' !== $url ) {
				$clean_urls[] = $url;
			}
		}

		update_user_meta( $user_id, self::META_SAMEAS, implode( "\n", $clean_urls ) );
	}

	// -------------------------------------------------------------------------
	// Data access helpers (used by SWPS_Schema)
	// -------------------------------------------------------------------------

	/**
	 * Get the sameAs URL array for a user (empty array if none set).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string[] Array of valid URL strings.
	 */
	public static function get_sameas( int $user_id ): array {
		$raw = (string) get_user_meta( $user_id, self::META_SAMEAS, true );
		if ( '' === $raw ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
	}

	/**
	 * Get the job title for a user (empty string if not set).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	public static function get_job_title( int $user_id ): string {
		return (string) get_user_meta( $user_id, self::META_JOB_TITLE, true );
	}

	/**
	 * Get the credentials string for a user (empty string if not set).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	public static function get_credentials( int $user_id ): string {
		return (string) get_user_meta( $user_id, self::META_CREDENTIALS, true );
	}
}
