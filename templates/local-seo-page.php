<?php
/**
 * Local SEO settings page (v4.2).
 *
 * Wrapped by SWPS_Admin_Shell. Captures NAP, hours, geo, area served and
 * outputs LocalBusiness JSON-LD on the homepage.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$local = stratawp_seo()->local_seo;
$d     = $local->get();
$types = SWPS_Local_SEO::BUSINESS_TYPES;
$days  = SWPS_Local_SEO::DAYS;

$preview = $local->build_schema();
?>
<div class="wrap">
	<?php
	$title    = __( 'Local SEO', 'stratawp-seo' );
	$subtitle = __( 'Add your business name, address, phone, and hours. StrataWP outputs LocalBusiness JSON-LD on your homepage so Google can show your hours, location, and contact info in search.', 'stratawp-seo' );
	$actions  = array();
	require SWPS_PLUGIN_DIR . 'templates/partials/page-header.php';
	?>

	<form method="post" action="options.php" class="swps-local-seo-form">
		<?php settings_fields( 'swps_local_seo_group' ); ?>

		<div class="swps-card" style="margin-bottom:16px">
			<label class="swps-toggle-row" style="display:flex;align-items:center;gap:10px">
				<input type="checkbox"
						name="<?php echo esc_attr( SWPS_Local_SEO::OPTION_KEY ); ?>[enabled]"
						value="1"
						<?php checked( ! empty( $d['enabled'] ) ); ?> />
				<span style="font-weight:600"><?php esc_html_e( 'Enable Local SEO schema output', 'stratawp-seo' ); ?></span>
			</label>
			<p class="description" style="margin:6px 0 0 28px">
				<?php esc_html_e( 'When enabled (and a business name + city are set), LocalBusiness JSON-LD is added to your homepage. If Yoast/RankMath/AIOSEO are active, StrataWP defers to them.', 'stratawp-seo' ); ?>
			</p>
		</div>

		<!-- Business identity -->
		<div class="swps-card" style="margin-bottom:16px">
			<h2 style="margin-top:0"><?php esc_html_e( 'Business identity', 'stratawp-seo' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
				<tr>
					<th scope="row"><label for="swps-bt"><?php esc_html_e( 'Business type', 'stratawp-seo' ); ?></label></th>
					<td>
						<select id="swps-bt" name="<?php echo esc_attr( SWPS_Local_SEO::OPTION_KEY ); ?>[business_type]">
							<?php foreach ( $types as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $d['business_type'], $val ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Picks the most specific schema.org type Google understands for your business.', 'stratawp-seo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="swps-name"><?php esc_html_e( 'Business name', 'stratawp-seo' ); ?></label></th>
					<td>
						<input id="swps-name" type="text" class="regular-text"
								name="<?php echo esc_attr( SWPS_Local_SEO::OPTION_KEY ); ?>[name]"
								value="<?php echo esc_attr( $d['name'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="swps-phone"><?php esc_html_e( 'Phone', 'stratawp-seo' ); ?></label></th>
					<td>
						<input id="swps-phone" type="text" class="regular-text"
								name="<?php echo esc_attr( SWPS_Local_SEO::OPTION_KEY ); ?>[phone]"
								value="<?php echo esc_attr( $d['phone'] ); ?>"
								placeholder="+1-555-555-1234" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="swps-email"><?php esc_html_e( 'Email', 'stratawp-seo' ); ?></label></th>
					<td>
						<input id="swps-email" type="email" class="regular-text"
								name="<?php echo esc_attr( SWPS_Local_SEO::OPTION_KEY ); ?>[email]"
								value="<?php echo esc_attr( $d['email'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="swps-price"><?php esc_html_e( 'Price range', 'stratawp-seo' ); ?></label></th>
					<td>
						<input id="swps-price" type="text" class="regular-text"
								name="<?php echo esc_attr( SWPS_Local_SEO::OPTION_KEY ); ?>[price_range]"
								value="<?php echo esc_attr( $d['price_range'] ); ?>"
								placeholder="$$ or $10-30" />
						<p class="description"><?php esc_html_e( 'Use $, $$, $$$, $$$$ — or a range like $10-30.', 'stratawp-seo' ); ?></p>
					</td>
				</tr>
				</tbody>
			</table>
		</div>

		<!-- Address -->
		<div class="swps-card" style="margin-bottom:16px">
			<h2 style="margin-top:0"><?php esc_html_e( 'Address', 'stratawp-seo' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
				<tr>
					<th scope="row"><label for="swps-street"><?php esc_html_e( 'Street', 'stratawp-seo' ); ?></label></th>
					<td>
						<input id="swps-street" type="text" class="regular-text"
								name="<?php echo esc_attr( SWPS_Local_SEO::OPTION_KEY ); ?>[street]"
								value="<?php echo esc_attr( $d['street'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="swps-loc"><?php esc_html_e( 'City', 'stratawp-seo' ); ?></label></th>
					<td>
						<input id="swps-loc" type="text" class="regular-text"
								name="<?php echo esc_attr( SWPS_Local_SEO::OPTION_KEY ); ?>[locality]"
								value="<?php echo esc_attr( $d['locality'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="swps-region"><?php esc_html_e( 'State / region', 'stratawp-seo' ); ?></label></th>
					<td>
						<input id="swps-region" type="text" class="regular-text"
								name="<?php echo esc_attr( SWPS_Local_SEO::OPTION_KEY ); ?>[region]"
								value="<?php echo esc_attr( $d['region'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="swps-zip"><?php esc_html_e( 'Postal code', 'stratawp-seo' ); ?></label></th>
					<td>
						<input id="swps-zip" type="text" class="regular-text"
								name="<?php echo esc_attr( SWPS_Local_SEO::OPTION_KEY ); ?>[postal_code]"
								value="<?php echo esc_attr( $d['postal_code'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="swps-country"><?php esc_html_e( 'Country', 'stratawp-seo' ); ?></label></th>
					<td>
						<input id="swps-country" type="text" class="regular-text"
								name="<?php echo esc_attr( SWPS_Local_SEO::OPTION_KEY ); ?>[country]"
								value="<?php echo esc_attr( $d['country'] ); ?>"
								placeholder="US, GB, DE..." />
						<p class="description"><?php esc_html_e( 'Two-letter ISO code preferred (US, GB, DE, AU…). Full names also work.', 'stratawp-seo' ); ?></p>
					</td>
				</tr>
				</tbody>
			</table>
		</div>

		<!-- Geo + place -->
		<div class="swps-card" style="margin-bottom:16px">
			<h2 style="margin-top:0"><?php esc_html_e( 'Map & geo', 'stratawp-seo' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
				<tr>
					<th scope="row"><label for="swps-lat"><?php esc_html_e( 'Latitude', 'stratawp-seo' ); ?></label></th>
					<td>
						<input id="swps-lat" type="text" class="regular-text"
								name="<?php echo esc_attr( SWPS_Local_SEO::OPTION_KEY ); ?>[latitude]"
								value="<?php echo esc_attr( $d['latitude'] ); ?>"
								placeholder="-37.8136" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="swps-lng"><?php esc_html_e( 'Longitude', 'stratawp-seo' ); ?></label></th>
					<td>
						<input id="swps-lng" type="text" class="regular-text"
								name="<?php echo esc_attr( SWPS_Local_SEO::OPTION_KEY ); ?>[longitude]"
								value="<?php echo esc_attr( $d['longitude'] ); ?>"
								placeholder="144.9631" />
						<p class="description"><?php esc_html_e( 'Open Google Maps, right-click your location, click the lat/lng to copy.', 'stratawp-seo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="swps-place"><?php esc_html_e( 'Google Place ID', 'stratawp-seo' ); ?></label></th>
					<td>
						<input id="swps-place" type="text" class="regular-text"
								name="<?php echo esc_attr( SWPS_Local_SEO::OPTION_KEY ); ?>[place_id]"
								value="<?php echo esc_attr( $d['place_id'] ); ?>"
								placeholder="ChIJ..." />
						<p class="description">
							<?php
							printf(
								/* translators: %s: place ID finder URL */
								esc_html__( 'Optional. Find yours at %s.', 'stratawp-seo' ),
								'<a href="https://developers.google.com/maps/documentation/javascript/examples/places-placeid-finder" target="_blank" rel="noopener noreferrer">developers.google.com</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							);
							?>
						</p>
					</td>
				</tr>
				</tbody>
			</table>
		</div>

		<!-- Hours -->
		<div class="swps-card" style="margin-bottom:16px">
			<h2 style="margin-top:0"><?php esc_html_e( 'Opening hours', 'stratawp-seo' ); ?></h2>
			<p class="description" style="margin-top:0">
				<?php esc_html_e( 'Use 24-hour format (e.g. 09:00 / 17:30). Tick "Closed" for days you are not open.', 'stratawp-seo' ); ?>
			</p>
			<table class="widefat striped" style="max-width:680px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Day', 'stratawp-seo' ); ?></th>
						<th><?php esc_html_e( 'Open', 'stratawp-seo' ); ?></th>
						<th><?php esc_html_e( 'Close', 'stratawp-seo' ); ?></th>
						<th><?php esc_html_e( 'Closed', 'stratawp-seo' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				foreach ( $days as $day_key => $day_short ) :
					$row  = $d['hours'][ $day_key ] ?? array(
						'open'   => '',
						'close'  => '',
						'closed' => 0,
					);
					$name = SWPS_Local_SEO::OPTION_KEY . '[hours][' . $day_key . ']';
					?>
					<tr>
						<td><strong><?php echo esc_html( $day_key ); ?></strong></td>
						<td>
							<input type="time" name="<?php echo esc_attr( $name ); ?>[open]"
									value="<?php echo esc_attr( $row['open'] ?? '' ); ?>" />
						</td>
						<td>
							<input type="time" name="<?php echo esc_attr( $name ); ?>[close]"
									value="<?php echo esc_attr( $row['close'] ?? '' ); ?>" />
						</td>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[closed]" value="1"
										<?php checked( ! empty( $row['closed'] ) ); ?> />
								<?php esc_html_e( 'Closed', 'stratawp-seo' ); ?>
							</label>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<!-- Area served -->
		<div class="swps-card" style="margin-bottom:16px">
			<h2 style="margin-top:0"><?php esc_html_e( 'Area served', 'stratawp-seo' ); ?></h2>
			<p class="description" style="margin-top:0">
				<?php esc_html_e( 'For service-area businesses (plumbers, electricians, etc.). One city or region per line — up to 25.', 'stratawp-seo' ); ?>
			</p>
			<textarea name="<?php echo esc_attr( SWPS_Local_SEO::OPTION_KEY ); ?>[area_served]"
						rows="6" class="large-text code"
						placeholder="Melbourne, VIC&#10;Geelong, VIC&#10;Ballarat, VIC"><?php echo esc_textarea( $d['area_served'] ); ?></textarea>
		</div>

		<?php submit_button( __( 'Save Local SEO settings', 'stratawp-seo' ) ); ?>
	</form>

	<?php if ( ! empty( $preview ) ) : ?>
		<div class="swps-card" style="margin-top:24px">
			<h2 style="margin-top:0"><?php esc_html_e( 'Schema preview', 'stratawp-seo' ); ?></h2>
			<p class="description" style="margin-top:0">
				<?php esc_html_e( 'This is the JSON-LD that will be injected into your homepage <head>. Test it with Google\'s Rich Results Test once your homepage is live.', 'stratawp-seo' ); ?>
			</p>
			<pre style="background:var(--swps-surface-2,#0f1115);color:var(--swps-text,#e7e9ee);padding:14px;border-radius:6px;overflow:auto;max-height:420px;font-size:12px;line-height:1.5"><?php echo esc_html( wp_json_encode( $preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
		</div>
	<?php endif; ?>
</div>
