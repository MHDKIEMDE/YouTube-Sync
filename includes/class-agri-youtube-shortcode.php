<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Agri_Youtube_Shortcode {

    public function __construct() {
        add_shortcode( 'agri_videos', [ $this, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );
    }

    public function enqueue_styles() {
        wp_add_inline_style( 'wp-block-library', '
            .agri-sc-grid { display:grid; gap:20px; }
            .agri-sc-grid.cols-1 { grid-template-columns:1fr; }
            .agri-sc-grid.cols-2 { grid-template-columns:repeat(2,1fr); }
            .agri-sc-grid.cols-3 { grid-template-columns:repeat(3,1fr); }
            .agri-sc-grid.cols-4 { grid-template-columns:repeat(4,1fr); }
            @media(max-width:768px){ .agri-sc-grid { grid-template-columns:1fr !important; } }
            .agri-sc-card { background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.1); }
            .agri-sc-thumb { position:relative; padding-bottom:56.25%; background:#000; }
            .agri-sc-thumb img { position:absolute; top:0; left:0; width:100%; height:100%; object-fit:cover; }
            .agri-sc-badge-live { position:absolute; top:8px; left:8px; background:#e00; color:#fff; font-size:11px; font-weight:700; padding:3px 8px; border-radius:4px; animation:agri-blink 1s step-start infinite; }
            @keyframes agri-blink { 50%{opacity:.4;} }
            .agri-sc-info { padding:12px; }
            .agri-sc-info h3 { margin:0 0 6px; font-size:14px; line-height:1.4; }
            .agri-sc-info a { text-decoration:none; color:inherit; }
            .agri-sc-info a:hover { color:#7ED957; }
            .agri-sc-meta { font-size:12px; color:#888; display:flex; gap:12px; flex-wrap:wrap; }
            .agri-sc-pagination { margin-top:24px; display:flex; gap:8px; justify-content:center; flex-wrap:wrap; }
            .agri-sc-pagination a, .agri-sc-pagination span { padding:6px 12px; border:1px solid #ddd; border-radius:4px; font-size:13px; text-decoration:none; color:#333; }
            .agri-sc-pagination .current { background:#7ED957; border-color:#5db346; color:#000; font-weight:700; }
        ' );
    }

    public function render( $atts ) {
        $atts = shortcode_atts( [
            'lang'     => '',
            'rubrique' => '',
            'limit'    => 12,
            'columns'  => 3,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'paginate' => 'false',
            'page'     => max( 1, get_query_var( 'paged', 1 ) ),
        ], $atts, 'agri_videos' );

        $post_type = get_option( 'agri_yt_post_type', 'movies' );
        $limit     = max( 1, intval( $atts['limit'] ) );
        $columns   = min( 4, max( 1, intval( $atts['columns'] ) ) );
        $paginate  = $atts['paginate'] === 'true';
        $page      = max( 1, intval( $atts['page'] ) );

        $meta_query = [ [ 'key' => '_agri_yt_video_id', 'compare' => 'EXISTS' ] ];

        if ( $atts['lang'] ) {
            $meta_query[] = [ 'key' => '_agri_yt_lang', 'value' => sanitize_text_field( $atts['lang'] ) ];
        }
        if ( $atts['rubrique'] ) {
            $meta_query[] = [ 'key' => '_agri_yt_rubrique', 'value' => sanitize_text_field( $atts['rubrique'] ) ];
        }

        $orderby_map = [
            'date'     => [ 'orderby' => 'date' ],
            'views'    => [ 'orderby' => 'meta_value_num', 'meta_key' => '_agri_yt_views' ],
            'likes'    => [ 'orderby' => 'meta_value_num', 'meta_key' => '_agri_yt_likes' ],
            'title'    => [ 'orderby' => 'title' ],
        ];
        $order_args = $orderby_map[ $atts['orderby'] ] ?? $orderby_map['date'];

        $query_args = array_merge( [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $paginate ? $limit : $limit,
            'paged'          => $paginate ? $page : 1,
            'order'          => strtoupper( $atts['order'] ) === 'ASC' ? 'ASC' : 'DESC',
            'meta_query'     => $meta_query,
        ], $order_args );

        $query = new WP_Query( $query_args );

        if ( ! $query->have_posts() ) {
            return '<p class="agri-no-videos">Aucune vidéo trouvée.</p>';
        }

        ob_start();
        echo '<div class="agri-sc-grid cols-' . esc_attr( $columns ) . '">';

        while ( $query->have_posts() ) {
            $query->the_post();
            $post_id  = get_the_ID();
            $video_id = get_post_meta( $post_id, '_agri_yt_video_id', true );
            $views    = intval( get_post_meta( $post_id, '_agri_yt_views', true ) );
            $duration = get_post_meta( $post_id, '_agri_yt_duration_fmt', true );
            $is_live  = (bool) get_post_meta( $post_id, '_agri_yt_is_live', true );
            $thumb    = get_the_post_thumbnail_url( $post_id, 'medium_large' );
            if ( ! $thumb && $video_id ) {
                $thumb = 'https://img.youtube.com/vi/' . esc_attr( $video_id ) . '/hqdefault.jpg';
            }
            ?>
            <div class="agri-sc-card">
                <div class="agri-sc-thumb">
                    <?php if ( $thumb ) : ?>
                        <a href="<?php the_permalink(); ?>">
                            <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy" />
                        </a>
                    <?php endif; ?>
                    <?php if ( $is_live ) : ?>
                        <span class="agri-sc-badge-live">🔴 EN DIRECT</span>
                    <?php endif; ?>
                </div>
                <div class="agri-sc-info">
                    <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                    <div class="agri-sc-meta">
                        <span><?php echo get_the_date(); ?></span>
                        <?php if ( $views ) : ?><span>👁 <?php echo number_format_i18n( $views ); ?></span><?php endif; ?>
                        <?php if ( $duration ) : ?><span>⏱ <?php echo esc_html( $duration ); ?></span><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php
        }

        echo '</div>';

        if ( $paginate ) {
            $big = 999999;
            echo '<div class="agri-sc-pagination">';
            echo paginate_links( [
                'base'    => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
                'format'  => '?paged=%#%',
                'current' => $page,
                'total'   => $query->max_num_pages,
                'type'    => 'list',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ] );
            echo '</div>';
        }

        wp_reset_postdata();
        return ob_get_clean();
    }
}
