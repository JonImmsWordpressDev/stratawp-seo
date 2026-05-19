<?php
$profile_id = isset( $_GET['profile_id'] ) ? absint( $_GET['profile_id'] ) : 0;
$is_new     = empty( $profile_id );
$profile    = $is_new ? null : stratawp_seo()->voice_profile->get( $profile_id );

if ( ! $is_new && ! $profile ) {
	echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Voice profile not found.', 'stratawp-seo' ) . '</p></div></div>';
	return;
}
?>
<div class="wrap swps-wrap">
	<h1>
		<?php echo $is_new ? esc_html__( 'Add New Voice Profile', 'stratawp-seo' ) : esc_html__( 'Edit Voice Profile', 'stratawp-seo' ); ?>
	</h1>

	<form id="swps-voice-profile-form" class="swps-card" style="max-width: 700px; padding: 20px;">
		<?php wp_nonce_field( 'swps_nonce', 'nonce' ); ?>
		<input type="hidden" name="profile_id" value="<?php echo esc_attr( $profile_id ); ?>" />

		<table class="form-table">
			<tr>
				<th><label for="vp-name"><?php esc_html_e( 'Profile Name', 'stratawp-seo' ); ?></label></th>
				<td><input type="text" id="vp-name" name="name" class="regular-text" required value="<?php echo esc_attr( $profile['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g., Brand Voice, Technical Blog', 'stratawp-seo' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="vp-tone"><?php esc_html_e( 'Tone', 'stratawp-seo' ); ?></label></th>
				<td>
					<select id="vp-tone" name="tone">
						<?php foreach ( array( 'professional', 'casual', 'authoritative', 'friendly', 'formal', 'witty', 'conversational' ) as $t ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $profile['tone'] ?? 'professional', $t ); ?>><?php echo esc_html( ucfirst( $t ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="vp-formality"><?php esc_html_e( 'Formality', 'stratawp-seo' ); ?></label></th>
				<td>
					<input type="range" id="vp-formality" name="formality" min="1" max="10" value="<?php echo esc_attr( $profile['formality'] ?? 5 ); ?>" />
					<span id="vp-formality-label"><?php echo esc_html( $profile['formality'] ?? 5 ); ?>/10</span>
					<p class="description"><?php esc_html_e( '1 = very casual, 10 = very formal', 'stratawp-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="vp-sentence-length"><?php esc_html_e( 'Sentence Length', 'stratawp-seo' ); ?></label></th>
				<td>
					<select id="vp-sentence-length" name="sentence_length">
						<?php foreach ( array( 'short', 'medium', 'long', 'varied' ) as $sl ) : ?>
							<option value="<?php echo esc_attr( $sl ); ?>" <?php selected( $profile['sentence_length'] ?? 'varied', $sl ); ?>><?php echo esc_html( ucfirst( $sl ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="vp-vocabulary"><?php esc_html_e( 'Vocabulary Level', 'stratawp-seo' ); ?></label></th>
				<td>
					<select id="vp-vocabulary" name="vocabulary_level">
						<?php foreach ( array( 'simple', 'moderate', 'advanced', 'technical' ) as $vl ) : ?>
							<option value="<?php echo esc_attr( $vl ); ?>" <?php selected( $profile['vocabulary_level'] ?? 'moderate', $vl ); ?>><?php echo esc_html( ucfirst( $vl ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="vp-person"><?php esc_html_e( 'Person', 'stratawp-seo' ); ?></label></th>
				<td>
					<select id="vp-person" name="person">
						<option value="first" <?php selected( $profile['person'] ?? 'second', 'first' ); ?>><?php esc_html_e( 'First (I/we)', 'stratawp-seo' ); ?></option>
						<option value="second" <?php selected( $profile['person'] ?? 'second', 'second' ); ?>><?php esc_html_e( 'Second (you)', 'stratawp-seo' ); ?></option>
						<option value="third" <?php selected( $profile['person'] ?? 'second', 'third' ); ?>><?php esc_html_e( 'Third (they/one)', 'stratawp-seo' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="vp-example"><?php esc_html_e( 'Example Content', 'stratawp-seo' ); ?></label></th>
				<td>
					<textarea id="vp-example" name="example_content" class="large-text" rows="5" placeholder="<?php esc_attr_e( 'Paste 1-2 paragraphs that represent your ideal writing style...', 'stratawp-seo' ); ?>"><?php echo esc_textarea( $profile['example_content'] ?? '' ); ?></textarea>
					<p class="description"><?php esc_html_e( 'The AI will use this as a style reference (first 500 characters).', 'stratawp-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="vp-avoid"><?php esc_html_e( 'Avoid Phrases', 'stratawp-seo' ); ?></label></th>
				<td>
					<textarea id="vp-avoid" name="avoid_phrases" class="large-text" rows="2" placeholder="<?php esc_attr_e( 'game-changer, leverage, synergy, cutting-edge', 'stratawp-seo' ); ?>"><?php echo esc_textarea( implode( ', ', $profile['avoid_phrases'] ?? array() ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Comma-separated list of phrases the AI should never use.', 'stratawp-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="vp-prefer"><?php esc_html_e( 'Preferred Phrases', 'stratawp-seo' ); ?></label></th>
				<td>
					<textarea id="vp-prefer" name="preferred_phrases" class="large-text" rows="2" placeholder="<?php esc_attr_e( 'practical, straightforward, hands-on', 'stratawp-seo' ); ?>"><?php echo esc_textarea( implode( ', ', $profile['preferred_phrases'] ?? array() ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Comma-separated list of preferred expressions.', 'stratawp-seo' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary" id="swps-save-profile"><?php echo $is_new ? esc_html__( 'Create Profile', 'stratawp-seo' ) : esc_html__( 'Update Profile', 'stratawp-seo' ); ?></button>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=swps-voice-profiles' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'stratawp-seo' ); ?></a>
		</p>
	</form>
</div>
