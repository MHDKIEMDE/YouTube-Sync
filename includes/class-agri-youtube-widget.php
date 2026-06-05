<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Agri_Youtube_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'agri_youtube_widget',
            'AgriTV — Dernières vidéos',
            [ 'description' => 'Affiche les dernières vidéos YouTube importées dans une sidebar.' ]
        );
    }

    public function widget( $args, $instance ) {
        $title    = ! empty( $instance['title'] ) ? $instance['title'] : 'Dernières vidéos';
        $number   = ! empty( $instance['number'] ) ? intval( $instance['number'] ) : 5;
        $lang     = ! empty( $instance['lang'] ) ? $instance['lang'] : '';
        $show_thumb = ! empty( $instance['show_thumb'] );
        $post_type  = get_option( 'agri_yt_post_type', 'movies' );

        $meta_query = [ [ 'key' => '_agri_yt_video_id', 'compare' => 'EXISTS' ] ];
        if ( $lang ) {
            $meta_query[] = [ 'key' => '_agri_yt_lang', 'value' => $lang ];
        }

        $videos = get_posts( [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $number,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => $meta_query,
        ] );

        if ( empty( $videos ) ) return;

        echo $args['before_widget'];
        echo $args['before_title'] . esc_html( $title ) . $args['after_title'];

        echo '<ul class="agri-widget-list" style="list-style:none;margin:0;padding:0;">';
        foreach ( $videos as $video ) {
            $post_id  = $video->ID;
            $video_id = get_post_meta( $post_id, '_agri_yt_video_id', true );
            $is_live  = (bool) get_post_meta( $post_id, '_agri_yt_is_live', true );
            $thumb    = get_the_post_thumbnail_url( $post_id, 'thumbnail' );
            if ( ! $thumb && $video_id ) {
                $thumb = 'https://img.youtube.com/vi/' . esc_attr( $video_id ) . '/default.jpg';
            }
            ?>
            <li style="display:flex;gap:10px;margin-bottom:12px;align-items:flex-start;">
                <?php if ( $show_thumb && $thumb ) : ?>
                    <a href="<?php echo get_permalink( $post_id ); ?>" style="flex-shrink:0;position:relative;display:block;">
                        <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $video->post_title ); ?>"
                            style="width:80px;height:56px;object-fit:cover;border-radius:4px;" loading="lazy" />
                        <?php if ( $is_live ) : ?>
                            <span style="position:absolute;bottom:3px;left:3px;background:#e00;color:#fff;font-size:9px;font-weight:700;padding:1px 5px;border-radius:3px;">LIVE</span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
                <div style="flex:1;min-width:0;">
                    <a href="<?php echo get_permalink( $post_id ); ?>"
                        style="font-size:13px;line-height:1.4;color:inherit;text-decoration:none;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                        <?php echo esc_html( $video->post_title ); ?>
                    </a>
                    <div style="font-size:11px;color:#888;margin-top:3px;">
                        <?php echo get_the_date( 'd/m/Y', $post_id ); ?>
                    </div>
                </div>
            </li>
            <?php
        }
        echo '</ul>';
        echo $args['after_widget'];
    }

    public function form( $instance ) {
        $title      = $instance['title']      ?? 'Dernières vidéos';
        $number     = $instance['number']     ?? 5;
        $lang       = $instance['lang']       ?? '';
        $show_thumb = $instance['show_thumb'] ?? true;
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>">Titre :</label>
            <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>"
                name="<?php echo $this->get_field_name('title'); ?>"
                type="text" value="<?php echo esc_attr($title); ?>" />
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('number'); ?>">Nombre de vidéos :</label>
            <input class="tiny-text" id="<?php echo $this->get_field_id('number'); ?>"
                name="<?php echo $this->get_field_name('number'); ?>"
                type="number" min="1" max="20" value="<?php echo intval($number); ?>" />
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('lang'); ?>">Langue :</label>
            <select class="widefat" id="<?php echo $this->get_field_id('lang'); ?>"
                name="<?php echo $this->get_field_name('lang'); ?>">
                <option value="" <?php selected($lang, ''); ?>>Toutes</option>
                <option value="fr" <?php selected($lang, 'fr'); ?>>Français</option>
                <option value="en" <?php selected($lang, 'en'); ?>>English</option>
            </select>
        </p>
        <p>
            <input class="checkbox" type="checkbox" id="<?php echo $this->get_field_id('show_thumb'); ?>"
                name="<?php echo $this->get_field_name('show_thumb'); ?>"
                <?php checked($show_thumb); ?> />
            <label for="<?php echo $this->get_field_id('show_thumb'); ?>">Afficher les miniatures</label>
        </p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        return [
            'title'      => sanitize_text_field( $new_instance['title'] ?? '' ),
            'number'     => max( 1, intval( $new_instance['number'] ?? 5 ) ),
            'lang'       => sanitize_text_field( $new_instance['lang'] ?? '' ),
            'show_thumb' => ! empty( $new_instance['show_thumb'] ),
        ];
    }
}

function agri_yt_register_widget() {
    register_widget( 'Agri_Youtube_Widget' );
}
add_action( 'widgets_init', 'agri_yt_register_widget' );
