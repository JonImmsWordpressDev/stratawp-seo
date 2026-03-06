<?php
/**
 * In-content image insertion.
 *
 * Inserts contextual images at semantic positions within generated post content.
 * Uses the configured image provider to source images.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Image_Inserter {

    private SWPS_Image_Provider $image_provider;

    public function __construct( SWPS_Image_Provider $image_provider ) {
        $this->image_provider = $image_provider;
    }

    /**
     * Insert images into a post's content at semantic positions.
     *
     * @param int   $post_id   The post ID.
     * @param array $ai_result The AI response data.
     */
    public function insert_images( int $post_id, array $ai_result ): void {
        if ( ! get_option( 'swps_insert_content_images', 0 ) ) {
            return;
        }

        $content   = get_post_field( 'post_content', $post_id );
        $max_images = (int) get_option( 'swps_content_images_count', 2 );
        $max_width  = (int) get_option( 'swps_image_max_width', 1200 );

        if ( empty( $content ) || $max_images < 1 ) {
            return;
        }

        // Split content into sections by H2.
        $sections = $this->split_by_headings( $content );

        if ( count( $sections ) < 2 ) {
            return; // Not enough sections for in-content images.
        }

        // Skip the first section (intro — featured image covers it).
        $target_sections = array_slice( $sections, 1 );

        // Pick evenly spaced sections for images.
        $total    = count( $target_sections );
        $to_fill  = min( $max_images, $total );
        $interval = max( 1, (int) floor( $total / $to_fill ) );
        $indices  = [];
        for ( $i = 0; $i < $to_fill; $i++ ) {
            $indices[] = $i * $interval;
        }

        // Extract visual concepts for each target section.
        $queries = [];
        foreach ( $indices as $idx ) {
            $section = $target_sections[ $idx ];
            $queries[ $idx ] = $this->extract_visual_concept( $section );
        }

        $queries = apply_filters( 'swps_content_images_queries', $queries, $post_id );

        // Search and insert images.
        $inserted = 0;
        foreach ( $queries as $idx => $query ) {
            if ( empty( $query ) ) {
                continue;
            }

            $image_url = $this->search_image( $query );
            if ( empty( $image_url ) ) {
                continue;
            }

            // Apply image selection filter.
            $section_heading = $this->extract_heading( $target_sections[ $idx ] );
            $image_data = apply_filters( 'swps_image_selection', [
                'url'     => $image_url,
                'query'   => $query,
                'alt'     => $section_heading ?: $query,
                'post_id' => $post_id,
            ], $post_id, $section_heading );

            if ( empty( $image_data ) || empty( $image_data['url'] ) ) {
                continue;
            }

            // Download and attach.
            $attachment_id = $this->download_image( $image_data['url'], $post_id, $query, $max_width );
            if ( is_wp_error( $attachment_id ) ) {
                continue;
            }

            // Set alt text.
            $alt_text = sanitize_text_field( $image_data['alt'] ?? $query );
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

            // Build the figure HTML.
            $img_src  = wp_get_attachment_url( $attachment_id );
            $metadata = wp_get_attachment_metadata( $attachment_id );
            $width    = $metadata['width'] ?? '';
            $height   = $metadata['height'] ?? '';

            $figure_html = sprintf(
                '<figure class="swps-content-image"><img src="%s" alt="%s" loading="lazy"%s%s /><figcaption>%s</figcaption></figure>',
                esc_url( $img_src ),
                esc_attr( $alt_text ),
                $width ? ' width="' . esc_attr( $width ) . '"' : '',
                $height ? ' height="' . esc_attr( $height ) . '"' : '',
                esc_html( $alt_text )
            );

            // Inject into the section (after first paragraph).
            // The actual index in the full content is $idx + 1 (because we skipped section 0).
            $section_index = $idx + 1;
            $content = $this->inject_into_section( $content, $section_index, $figure_html );

            $inserted++;

            do_action( 'swps_image_inserted', $attachment_id, $post_id, $alt_text, $section_index );

            if ( $inserted >= $max_images ) {
                break;
            }
        }

        // Update the post content if images were inserted.
        if ( $inserted > 0 ) {
            wp_update_post( [
                'ID'           => $post_id,
                'post_content' => $content,
            ] );
        }
    }

    /**
     * Split content into sections by H2 tags.
     *
     * @param string $content HTML content.
     * @return array Array of section HTML strings.
     */
    private function split_by_headings( string $content ): array {
        // Split on <h2 but keep the delimiter.
        $parts = preg_split( '/(?=<h2[\s>])/i', $content );
        return array_values( array_filter( $parts, fn( $p ) => trim( $p ) !== '' ) );
    }

    /**
     * Extract a 2-4 word visual concept from a section.
     *
     * @param string $section HTML of a content section.
     * @return string Search query.
     */
    private function extract_visual_concept( string $section ): string {
        // Get heading text.
        $heading = $this->extract_heading( $section );

        // Get first paragraph text.
        $first_para = '';
        if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $section, $match ) ) {
            $first_para = wp_strip_all_tags( $match[1] );
        }

        $text = $heading . ' ' . $first_para;
        $text = strtolower( wp_strip_all_tags( $text ) );

        // Remove stop words.
        $stop_words = [ 'how', 'to', 'the', 'a', 'an', 'is', 'are', 'was', 'were', 'what', 'why', 'when', 'where', 'which', 'who', 'your', 'our', 'their', 'this', 'that', 'for', 'and', 'but', 'with', 'from', 'about', 'into', 'best', 'top', 'guide', 'you', 'can', 'will', 'also', 'more', 'most', 'many', 'some', 'than', 'them', 'then', 'these', 'those', 'have', 'has', 'had', 'not', 'been', 'being', 'does', 'did', 'should', 'would', 'could', 'its', 'it', 'of', 'in', 'on', 'at', 'by' ];

        $words = preg_split( '/\W+/', $text );
        $words = array_filter( $words, function ( $w ) use ( $stop_words ) {
            return strlen( $w ) > 2 && ! in_array( $w, $stop_words, true ) && ! is_numeric( $w );
        } );

        return implode( ' ', array_slice( array_values( $words ), 0, 4 ) );
    }

    /**
     * Extract heading text from a section.
     */
    private function extract_heading( string $section ): string {
        if ( preg_match( '/<h[2-3][^>]*>(.*?)<\/h[2-3]>/i', $section, $match ) ) {
            return wp_strip_all_tags( $match[1] );
        }
        return '';
    }

    /**
     * Search for an image using the configured provider.
     *
     * @param string $query Search query.
     * @return string Image URL or empty string.
     */
    private function search_image( string $query ): string {
        $provider_slug = get_option( 'swps_image_provider', 'unsplash' );

        // Get the API key for the *selected* provider, not the injected one.
        $api_key = match ( $provider_slug ) {
            'unsplash' => (string) get_option( 'swps_unsplash_api_key', '' ),
            'pexels'   => (string) get_option( 'swps_pexels_api_key', '' ),
            'pixabay'  => (string) get_option( 'swps_pixabay_api_key', '' ),
            default    => '',
        };

        if ( empty( $api_key ) ) {
            return '';
        }

        $simplified = $this->simplify_query( $query );

        return match ( $provider_slug ) {
            'unsplash' => $this->search_unsplash( $simplified, $api_key ),
            'pexels'   => $this->search_pexels( $simplified, $api_key ),
            'pixabay'  => $this->search_pixabay( $simplified, $api_key ),
            default    => '',
        };
    }

    /**
     * Search Unsplash for a single image URL.
     */
    private function search_unsplash( string $query, string $api_key ): string {
        $response = wp_remote_get( 'https://api.unsplash.com/search/photos?' . http_build_query( [
            'query'    => $query,
            'per_page' => 1,
            'orientation' => 'landscape',
        ] ), [
            'headers' => [ 'Authorization' => 'Client-ID ' . $api_key ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['results'][0]['urls']['regular'] ?? '';
    }

    /**
     * Search Pexels for a single image URL.
     */
    private function search_pexels( string $query, string $api_key ): string {
        $response = wp_remote_get( 'https://api.pexels.com/v1/search?' . http_build_query( [
            'query'    => $query,
            'per_page' => 1,
            'orientation' => 'landscape',
        ] ), [
            'headers' => [ 'Authorization' => $api_key ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['photos'][0]['src']['large'] ?? '';
    }

    /**
     * Search Pixabay for a single image URL.
     */
    private function search_pixabay( string $query, string $api_key ): string {
        $response = wp_remote_get( 'https://pixabay.com/api/?' . http_build_query( [
            'key'         => $api_key,
            'q'           => $query,
            'per_page'    => 3,
            'image_type'  => 'photo',
            'orientation' => 'horizontal',
        ] ), [
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['hits'][0]['largeImageURL'] ?? '';
    }

    /**
     * Simplify a query by removing stop words.
     */
    private function simplify_query( string $query ): string {
        $stop = [ 'how', 'to', 'the', 'a', 'an', 'is', 'are', 'for', 'and', 'with', 'from', 'about', 'your' ];
        $words = explode( ' ', strtolower( $query ) );
        $words = array_filter( $words, fn( $w ) => ! in_array( $w, $stop, true ) && strlen( $w ) > 2 );
        return implode( ' ', array_slice( array_values( $words ), 0, 4 ) );
    }

    /**
     * Download an image, convert to WebP, and attach to the post.
     *
     * @param string $url      Image URL.
     * @param int    $post_id  Post to attach to.
     * @param string $query    The search query (used for filename).
     * @param int    $max_width Max image width.
     * @return int|WP_Error Attachment ID or error.
     */
    private function download_image( string $url, int $post_id, string $query, int $max_width ): int|WP_Error {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        // Resize if needed.
        $this->resize_image( $tmp, $max_width );

        // Convert to WebP.
        $webp = $this->convert_to_webp( $tmp );
        if ( $webp !== $tmp ) {
            @unlink( $tmp );
            $tmp = $webp;
            $ext = '.webp';
        } else {
            $ext = '.jpg';
        }

        $file_array = [
            'name'     => sanitize_title( $query ) . '-content' . $ext,
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, $post_id );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
        }

        return $attachment_id;
    }

    /**
     * Resize an image file to max width.
     */
    private function resize_image( string $file_path, int $max_width ): void {
        if ( ! function_exists( 'imagecreatefromstring' ) ) {
            return;
        }

        $data = file_get_contents( $file_path );
        if ( false === $data ) {
            return;
        }

        $image = @imagecreatefromstring( $data );
        if ( false === $image ) {
            return;
        }

        $orig_width  = imagesx( $image );
        $orig_height = imagesy( $image );

        if ( $orig_width <= $max_width ) {
            imagedestroy( $image );
            return;
        }

        $ratio     = $max_width / $orig_width;
        $new_height = (int) ( $orig_height * $ratio );

        $resized = imagecreatetruecolor( $max_width, $new_height );
        imagealphablending( $resized, false );
        imagesavealpha( $resized, true );
        imagecopyresampled( $resized, $image, 0, 0, 0, 0, $max_width, $new_height, $orig_width, $orig_height );

        imagejpeg( $resized, $file_path, 85 );
        imagedestroy( $image );
        imagedestroy( $resized );
    }

    /**
     * Convert an image to WebP.
     */
    private function convert_to_webp( string $file_path ): string {
        if ( ! function_exists( 'imagewebp' ) ) {
            return $file_path;
        }

        $data = file_get_contents( $file_path );
        if ( false === $data ) {
            return $file_path;
        }

        $image = @imagecreatefromstring( $data );
        if ( false === $image ) {
            return $file_path;
        }

        imagepalettetotruecolor( $image );
        imagealphablending( $image, true );
        imagesavealpha( $image, true );

        $webp_path = $file_path . '.webp';

        if ( ! imagewebp( $image, $webp_path, 85 ) ) {
            imagedestroy( $image );
            return $file_path;
        }

        imagedestroy( $image );
        return $webp_path;
    }

    /**
     * Inject an HTML block after the first paragraph of a specific section.
     *
     * @param string $content       Full post content.
     * @param int    $section_index 0-based section index (split by H2).
     * @param string $html          HTML to inject.
     * @return string Modified content.
     */
    private function inject_into_section( string $content, int $section_index, string $html ): string {
        $sections = $this->split_by_headings( $content );

        if ( ! isset( $sections[ $section_index ] ) ) {
            return $content;
        }

        $section = $sections[ $section_index ];

        // Find first closing </p> in this section and insert after it.
        $p_end = strpos( $section, '</p>' );
        if ( $p_end === false ) {
            // No paragraph — append to end of section.
            $sections[ $section_index ] = $section . "\n" . $html . "\n";
        } else {
            $insert_pos = $p_end + 4; // after </p>
            $sections[ $section_index ] = substr( $section, 0, $insert_pos ) . "\n" . $html . "\n" . substr( $section, $insert_pos );
        }

        return implode( '', $sections );
    }
}
