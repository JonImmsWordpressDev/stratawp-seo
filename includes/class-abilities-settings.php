<?php
/**
 * Settings panel for StrataWP SEO Abilities.
 *
 * Adds a "Machine Access" submenu page under the StrataWP SEO menu with:
 *  - Per-ability enable checkboxes, grouped by READ / WRITE.
 *  - A connection snippet block (REST URL, application-password note, MCP Adapter note).
 *
 * Also registers with the WP Abilities API (WP ≥ 6.9) when the core functions
 * are present, feature-detected via function_exists().
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings panel for Machine Access ability toggles and connection snippet.
 */
class SWPS_Abilities_Settings {

	/** Page slug. */
	private const SLUG = 'swps-machine-access';

	/** WP Abilities category slug for this plugin's abilities. */
	private const CATEGORY_SLUG = 'stratawp-seo';

	/**
	 * Abilities registry.
	 *
	 * @var SWPS_Abilities
	 */
	private SWPS_Abilities $abilities;

	/**
	 * Constructor.
	 *
	 * @param SWPS_Abilities $abilities Registry.
	 */
	public function __construct( SWPS_Abilities $abilities ) {
		$this->abilities = $abilities;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'swps_audit_ability_run', array( $this, 'run_audit_background' ) );

		// WP Abilities API integration — feature-detected.
		if ( function_exists( 'wp_register_ability_category' ) ) {
			add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		}
		if ( function_exists( 'wp_register_ability' ) ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register_wp_abilities' ) );
		}
	}

	// =========================================================================
	// Admin menu
	// =========================================================================

	/**
	 * Register "Machine Access" submenu.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'stratawp-seo',
			__( 'Machine Access', 'stratawp-seo' ),
			__( 'Machine Access', 'stratawp-seo' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	// =========================================================================
	// Settings registration
	// =========================================================================

	/**
	 * Register the abilities-enabled setting.
	 */
	public function register_settings(): void {
		register_setting(
			self::SLUG,
			SWPS_Abilities::OPT_ENABLED,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_enabled' ),
			)
		);
	}

	/**
	 * Sanitize the enabled map.
	 *
	 * @param mixed $raw Raw POST data.
	 * @return array<string, bool>
	 */
	public function sanitize_enabled( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$valid_names = array_column( $this->abilities->get_defs(), 'name' );
		$clean       = array();

		foreach ( $valid_names as $name ) {
			$clean[ $name ] = isset( $raw[ $name ] ) && '1' === (string) $raw[ $name ];
		}

		return $clean;
	}

	// =========================================================================
	// Page render
	// =========================================================================

	/**
	 * Render the Machine Access settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'stratawp-seo' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Machine Access', 'stratawp-seo' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Enable or disable individual abilities. READ abilities default on; WRITE abilities default off.', 'stratawp-seo' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( self::SLUG ); ?>
				<?php $this->render_ability_table(); ?>
				<?php submit_button( __( 'Save Changes', 'stratawp-seo' ) ); ?>
			</form>

			<?php $this->render_connection_snippet(); ?>
		</div>
		<?php
	}

	/**
	 * Render the abilities enable/disable table.
	 */
	private function render_ability_table(): void {
		$defs       = $this->abilities->get_defs();
		$read_defs  = array_filter( $defs, static fn( array $d ): bool => ! $d['write'] );
		$write_defs = array_filter( $defs, static fn( array $d ): bool => $d['write'] );
		?>
		<h2><?php esc_html_e( 'Read Abilities', 'stratawp-seo' ); ?></h2>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Description', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Enabled', 'stratawp-seo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $read_defs as $def ) : ?>
					<?php $this->render_ability_row( $def ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2 style="margin-top:1.5em;"><?php esc_html_e( 'Write Abilities', 'stratawp-seo' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Write abilities are disabled by default. Enable only those you intentionally want AI agents to call.', 'stratawp-seo' ); ?></p>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Description', 'stratawp-seo' ); ?></th>
					<th><?php esc_html_e( 'Enabled', 'stratawp-seo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $write_defs as $def ) : ?>
					<?php $this->render_ability_row( $def ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render a single ability table row.
	 *
	 * @param array $def Ability definition.
	 */
	private function render_ability_row( array $def ): void {
		$name       = $def['name'];
		$is_enabled = $this->abilities->is_enabled( $name );
		$field_name = SWPS_Abilities::OPT_ENABLED . '[' . esc_attr( $name ) . ']';
		?>
		<tr>
			<td><code><?php echo esc_html( $name ); ?></code><br><strong><?php echo esc_html( $def['label'] ); ?></strong></td>
			<td><?php echo esc_html( $def['description'] ); ?></td>
			<td>
				<input
					type="checkbox"
					name="<?php echo esc_attr( $field_name ); ?>"
					value="1"
					<?php checked( $is_enabled ); ?>
				/>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the connection snippet block.
	 */
	private function render_connection_snippet(): void {
		$rest_url = esc_url( rest_url( 'swps/v1/abilities' ) );
		?>
		<hr>
		<h2><?php esc_html_e( 'Connect an AI Agent', 'stratawp-seo' ); ?></h2>
		<p>
			<?php
			esc_html_e(
				'Use the REST endpoint below to connect Claude, ChatGPT, or any REST-capable AI bridge. Authentication requires a WordPress Application Password.',
				'stratawp-seo'
			);
			?>
		</p>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Abilities endpoint', 'stratawp-seo' ); ?></th>
				<td><code><?php echo esc_url( $rest_url ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Authentication', 'stratawp-seo' ); ?></th>
				<td>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: link to WP application passwords screen */
							__( 'Create an <a href="%s">Application Password</a> for a manage_options user, then pass it via HTTP Basic auth.', 'stratawp-seo' ),
							esc_url( admin_url( 'user-edit.php?user_id=' . get_current_user_id() . '#application-passwords-section' ) )
						),
						array( 'a' => array( 'href' => array() ) )
					);
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'WordPress MCP Adapter', 'stratawp-seo' ); ?></th>
				<td>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: link to WP plugin directory */
							__( 'Install the official <a href="%s" target="_blank" rel="noopener">WordPress MCP Adapter</a> plugin to expose these abilities directly to MCP-compatible agents on WP ≥ 6.9.', 'stratawp-seo' ),
							'https://wordpress.org/plugins/mcp-adapter/'
						),
						array(
							'a' => array(
								'href'   => array(),
								'target' => array(),
								'rel'    => array(),
							),
						)
					);
					?>
				</td>
			</tr>
		</table>
		<?php
	}

	// =========================================================================
	// WP Abilities API integration
	// =========================================================================

	/**
	 * Register the ability category with the WP core registry.
	 *
	 * Hooked on wp_abilities_api_categories_init (WP ≥ 6.9).
	 */
	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY_SLUG,
			array(
				'label'       => __( 'StrataWP SEO', 'stratawp-seo' ),
				'description' => __( 'SEO management abilities from the StrataWP SEO plugin.', 'stratawp-seo' ),
			)
		);
	}

	/**
	 * Register all abilities with the WP core registry.
	 *
	 * Hooked on wp_abilities_api_init (WP ≥ 6.9).
	 */
	public function register_wp_abilities(): void {
		$registry = $this->abilities;

		foreach ( $registry->get_defs() as $def ) {
			if ( ! $registry->is_enabled( $def['name'] ) ) {
				continue;
			}

			$annotations = $def['write']
				? array( 'readonly' => false )
				: array( 'readonly' => true );

			wp_register_ability(
				$def['name'],
				array(
					'label'               => $def['label'],
					'description'         => $def['description'],
					'category'            => self::CATEGORY_SLUG,
					'input_schema'        => $def['input_schema'] ?? array(),
					'output_schema'       => $def['output_schema'] ?? array(),
					'execute_callback'    => static function ( $input = array() ) use ( $registry, $def ) {
						// Route through the full pipeline: enabled re-check,
						// input validation, callback, activity log.
						return $registry->execute( $def['name'], is_array( $input ) ? $input : array() );
					},
					'permission_callback' => static function (): bool {
						return current_user_can( 'manage_options' );
					},
					'meta'                => array(
						'annotations'  => $annotations,
						'show_in_rest' => true,
					),
				)
			);
		}
	}

	// =========================================================================
	// Background audit hook
	// =========================================================================

	/**
	 * Run the full audit when the background event fires.
	 *
	 * Hooked on swps_audit_ability_run (single scheduled event).
	 */
	public function run_audit_background(): void {
		stratawp_seo()->seo_audit->run_all();
	}
}
