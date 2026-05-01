<?php
/**
 * Page header partial — used inside .wrap on every StrataWP admin page.
 *
 * Replaces the duplicated .swps-page-header markup that lived in each template.
 *
 * Expected vars (set by the including template):
 *   string $title       Page H1 text.
 *   string $subtitle    Optional one-line subtitle/description.
 *   array  $actions     Optional action buttons. Each item:
 *                       [ 'label' => 'Run audit', 'url' => '...', 'class' => 'swps-btn-grad', 'attrs' => 'data-x="y"' ]
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$swps_title    = isset( $title ) ? (string) $title : '';
$swps_subtitle = isset( $subtitle ) ? (string) $subtitle : '';
$swps_actions  = ( isset( $actions ) && is_array( $actions ) ) ? $actions : [];
?>
<div class="swps-page-head">
    <div class="swps-page-head-text">
        <h1><?php echo esc_html( $swps_title ); ?></h1>
        <?php if ( '' !== $swps_subtitle ) : ?>
            <p class="swps-page-head-sub"><?php echo wp_kses_post( $swps_subtitle ); ?></p>
        <?php endif; ?>
    </div>

    <?php if ( ! empty( $swps_actions ) ) : ?>
        <div class="swps-page-head-actions">
            <?php foreach ( $swps_actions as $swps_action ) :
                $a_label = isset( $swps_action['label'] ) ? (string) $swps_action['label'] : '';
                $a_url   = isset( $swps_action['url'] )   ? (string) $swps_action['url']   : '';
                $a_class = isset( $swps_action['class'] ) ? (string) $swps_action['class'] : 'swps-btn-secondary';
                $a_attrs = isset( $swps_action['attrs'] ) ? (string) $swps_action['attrs'] : '';
                if ( '' !== $a_url ) : ?>
                    <a class="swps-btn <?php echo esc_attr( $a_class ); ?>" href="<?php echo esc_url( $a_url ); ?>" <?php echo $a_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                        <?php echo esc_html( $a_label ); ?>
                    </a>
                <?php else : ?>
                    <button type="button" class="swps-btn <?php echo esc_attr( $a_class ); ?>" <?php echo $a_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                        <?php echo esc_html( $a_label ); ?>
                    </button>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
