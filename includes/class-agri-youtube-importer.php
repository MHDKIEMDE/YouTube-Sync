<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Agri_Youtube_Importer {

    private $api;
    private $post_type;
    private $post_status;

    public function __construct() {
        $this->api         = new Agri_Youtube_API();
        $this->post_type   = get_option( 'agri_yt_post_type', 'movies' );
        $this->post_status = get_option( 'agri_yt_post_status', 'publish' );
    }

    // -------------------------------------------------------------------------
    // SYNC PRINCIPAL — lit toutes les playlists et les classe FR/EN
    // -------------------------------------------------------------------------

    public function sync() {
        $playlists = $this->api->get_playlists();
        $imported  = 0;
        $skipped   = 0;

        foreach ( $playlists as $playlist ) {
            $playlist_id    = $playlist['id'];
            $playlist_title = $playlist['snippet']['title'] ?? '';

            $lang    = $this->detect_language( $playlist_title );
            $rubrique = $this->extract_rubrique( $playlist_title );

            $videos = $this->api->get_videos_by_playlist( $playlist_id, 50 );
            if ( empty( $videos ) ) continue;

            $new_entries = [];
            foreach ( $videos as $video ) {
                $video_id = $video['snippet']['resourceId']['videoId'] ?? null;
                if ( ! $video_id ) continue;
                if ( $this->video_exists( $video_id ) ) {
                    $skipped++;
                    continue;
                }
                $new_entries[] = [ 'video' => $video, 'id' => $video_id ];
            }

            if ( empty( $new_entries ) ) continue;

            // Stats en batch
            $all_stats = [];
            foreach ( array_chunk( $new_entries, 50 ) as $chunk ) {
                $batch     = $this->api->get_videos_stats( array_column( $chunk, 'id' ) );
                $all_stats = array_merge( $all_stats, $batch );
            }

            foreach ( $new_entries as $entry ) {
                $stats   = $all_stats[ $entry['id'] ] ?? [];
                $post_id = $this->import_video( $entry['video'], $entry['id'], $stats, $lang, $rubrique );
                if ( $post_id ) {
                    $imported++;
                    update_post_meta( $post_id, '_agri_yt_playlist_id', $playlist_id );
                    update_post_meta( $post_id, '_agri_yt_playlist_title', sanitize_text_field( $playlist_title ) );
                }
            }
        }

        update_option( 'agri_yt_last_sync', current_time( 'mysql' ) );
        update_option( 'agri_yt_last_sync_result', compact( 'imported', 'skipped' ) );

        return compact( 'imported', 'skipped' );
    }

    /**
     * Import d'une seule vidéo via WebSub — détecte langue/rubrique depuis ses playlists.
     */
    public function import_single( $video, $video_id ) {
        if ( $this->video_exists( $video_id ) ) return false;
        $stats = $this->api->get_videos_stats( $video_id )[ $video_id ] ?? [];
        // Sans contexte de playlist, on détecte sur le titre de la vidéo
        $lang     = $this->detect_language( $video['snippet']['title'] ?? '' );
        $rubrique = '';
        return $this->import_video( $video, $video_id, $stats, $lang, $rubrique );
    }

    /**
     * Met à jour les stats (vues, likes, durée) des vidéos déjà importées — cron horaire.
     */
    public function update_stats() {
        $posts = get_posts( [
            'post_type'      => $this->post_type,
            'meta_key'       => '_agri_yt_video_id',
            'posts_per_page' => 200,
            'fields'         => 'ids',
        ]);
        if ( empty( $posts ) ) return;

        $video_map = [];
        foreach ( $posts as $post_id ) {
            $vid = get_post_meta( $post_id, '_agri_yt_video_id', true );
            if ( $vid ) $video_map[ $vid ] = $post_id;
        }

        foreach ( array_chunk( array_keys( $video_map ), 50 ) as $chunk ) {
            $batch = $this->api->get_videos_stats( $chunk );
            foreach ( $batch as $vid => $stats ) {
                $pid = $video_map[ $vid ] ?? null;
                if ( $pid ) $this->save_stats_meta( $pid, $stats );
            }
        }
    }

    // -------------------------------------------------------------------------
    // DÉTECTION LANGUE ET RUBRIQUE
    // -------------------------------------------------------------------------

    /**
     * Extrait la langue depuis le titre d'une playlist.
     * "Agri Actu (Fr)" → "fr"   |   "Agri Actu (En)" → "en"
     * Si pas de marqueur, retourne "fr" par défaut.
     */
    public static function detect_language( $title ) {
        if ( preg_match( '/\(\s*(fr|french|français)\s*\)/i', $title ) ) return 'fr';
        if ( preg_match( '/\(\s*(en|english|anglais)\s*\)/i', $title ) ) return 'en';
        // Détection dans le titre de la vidéo si pas de playlist
        if ( preg_match( '/\[FR\]|\bFR\b/i', $title ) ) return 'fr';
        if ( preg_match( '/\[EN\]|\bEN\b/i', $title ) ) return 'en';
        return 'fr';
    }

    /**
     * Extrait le nom de la rubrique sans le suffixe de langue.
     * "Agri Actu (Fr)" → "Agri Actu"
     */
    public static function extract_rubrique( $title ) {
        $rubrique = preg_replace( '/\s*\(\s*(fr|en|french|english|français|anglais)\s*\)\s*$/i', '', $title );
        return trim( $rubrique );
    }

    // -------------------------------------------------------------------------
    // IMPORT D'UNE VIDÉO
    // -------------------------------------------------------------------------

    private function import_video( $video, $video_id, $stats = [], $lang = 'fr', $rubrique = '' ) {
        $snippet = $video['snippet'];
        $title   = sanitize_text_field( $snippet['title'] );
        $desc    = wp_kses_post( $snippet['description'] );
        $date    = date( 'Y-m-d H:i:s', strtotime( $snippet['publishedAt'] ) );
        $thumb   = $snippet['thumbnails']['maxres']['url']
                   ?? $snippet['thumbnails']['high']['url']
                   ?? $snippet['thumbnails']['default']['url']
                   ?? '';

        $embed = '<div class="agri-video-embed" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;">'
               . '<iframe src="https://www.youtube.com/embed/' . esc_attr( $video_id ) . '" '
               . 'style="position:absolute;top:0;left:0;width:100%;height:100%;" '
               . 'frameborder="0" allowfullscreen></iframe>'
               . '</div>';

        $post_data = [
            'post_title'   => $title,
            'post_content' => $embed . "\n\n" . $desc,
            'post_status'  => $this->post_status,
            'post_type'    => $this->post_type,
            'post_date'    => $date,
        ];

        $post_id = wp_insert_post( $post_data );
        if ( is_wp_error( $post_id ) ) return false;

        // Meta internes
        update_post_meta( $post_id, '_agri_yt_video_id',  $video_id );
        update_post_meta( $post_id, '_agri_yt_video_url', 'https://www.youtube.com/watch?v=' . $video_id );
        update_post_meta( $post_id, '_agri_yt_lang',      $lang );
        update_post_meta( $post_id, '_agri_yt_rubrique',  sanitize_text_field( $rubrique ) );

        // StreamVid
        update_post_meta( $post_id, 'videos_url',  'https://www.youtube.com/watch?v=' . $video_id );
        update_post_meta( $post_id, 'videos_type', 'youtube' );

        // Stats YouTube
        if ( ! empty( $stats ) ) {
            $this->save_stats_meta( $post_id, $stats );
        }

        // Catégorie = rubrique (créée automatiquement si inexistante)
        if ( $rubrique ) {
            $this->assign_rubrique( $post_id, $rubrique );
        }

        // Tags YouTube → tags WordPress
        if ( ! empty( $stats['tags'] ) ) {
            wp_set_post_tags( $post_id, $stats['tags'], false );
        }

        // Langue Polylang (si installé)
        $this->set_language( $post_id, $lang );

        // Lier les traductions FR ↔ EN (si Polylang installé)
        $this->link_translation( $post_id, $video_id, $lang, $rubrique );

        if ( $thumb ) {
            $this->set_thumbnail( $post_id, $thumb, $title );
        }

        return $post_id;
    }

    // -------------------------------------------------------------------------
    // CATÉGORIES PAR RUBRIQUE
    // -------------------------------------------------------------------------

    /**
     * Assigne la catégorie correspondant à la rubrique.
     * Crée la catégorie automatiquement si elle n'existe pas encore.
     */
    private function assign_rubrique( $post_id, $rubrique ) {
        $taxonomies = get_object_taxonomies( $this->post_type );
        if ( empty( $taxonomies ) ) return;

        $taxonomy = $taxonomies[0];
        $term     = get_term_by( 'name', $rubrique, $taxonomy );

        if ( ! $term ) {
            $result = wp_insert_term( $rubrique, $taxonomy );
            if ( is_wp_error( $result ) ) return;
            $term_id = $result['term_id'];
        } else {
            $term_id = $term->term_id;
        }

        wp_set_object_terms( $post_id, $term_id, $taxonomy );
    }

    // -------------------------------------------------------------------------
    // POLYLANG — LANGUE ET TRADUCTIONS
    // -------------------------------------------------------------------------

    /**
     * Assigne la langue à un post via Polylang (si disponible).
     */
    private function set_language( $post_id, $lang ) {
        if ( ! function_exists( 'pll_set_post_language' ) ) return;
        // S'assurer que la langue existe dans Polylang
        $languages = pll_languages_list( [ 'fields' => 'slug' ] );
        if ( in_array( $lang, $languages, true ) ) {
            pll_set_post_language( $post_id, $lang );
        }
    }

    /**
     * Lie les traductions FR ↔ EN d'un même épisode via Polylang.
     * Cherche une vidéo existante de la même rubrique avec la langue opposée
     * et dont le titre de base (sans langue) est identique ou très proche.
     */
    private function link_translation( $post_id, $video_id, $lang, $rubrique ) {
        if ( ! function_exists( 'pll_save_post_translations' ) ) return;
        if ( ! $rubrique ) return;

        $other_lang = ( $lang === 'fr' ) ? 'en' : 'fr';

        // Cherche un post de même rubrique et langue opposée non encore lié
        $candidates = get_posts( [
            'post_type'      => $this->post_type,
            'posts_per_page' => 50,
            'meta_query'     => [
                [ 'key' => '_agri_yt_rubrique', 'value' => $rubrique ],
                [ 'key' => '_agri_yt_lang',     'value' => $other_lang ],
                [ 'key' => '_agri_yt_video_id', 'compare' => 'EXISTS' ],
            ],
            'fields' => 'ids',
        ]);

        if ( empty( $candidates ) ) return;

        // Prend le premier candidat non encore lié comme traduction
        foreach ( $candidates as $candidate_id ) {
            $existing_translations = pll_get_post_translations( $candidate_id );
            // Si déjà lié à une autre traduction dans cette langue, on passe
            if ( isset( $existing_translations[ $lang ] ) && $existing_translations[ $lang ] !== $post_id ) continue;

            $translations = [
                $lang       => $post_id,
                $other_lang => $candidate_id,
            ];
            pll_save_post_translations( $translations );
            break;
        }
    }

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    private function video_exists( $video_id ) {
        $posts = get_posts( [
            'post_type'   => $this->post_type,
            'meta_key'    => '_agri_yt_video_id',
            'meta_value'  => $video_id,
            'fields'      => 'ids',
            'numberposts' => 1,
        ]);
        return ! empty( $posts );
    }

    private function save_stats_meta( $post_id, $stats ) {
        update_post_meta( $post_id, '_agri_yt_views',        intval( $stats['views']        ?? 0 ) );
        update_post_meta( $post_id, '_agri_yt_likes',        intval( $stats['likes']        ?? 0 ) );
        update_post_meta( $post_id, '_agri_yt_comments',     intval( $stats['comments']     ?? 0 ) );
        update_post_meta( $post_id, '_agri_yt_duration_sec', intval( $stats['duration_sec'] ?? 0 ) );
        update_post_meta( $post_id, '_agri_yt_duration_fmt',         $stats['duration_fmt'] ?? '' );
        update_post_meta( $post_id, '_agri_yt_is_live',      (int)  ( $stats['is_live']     ?? false ) );
        update_post_meta( $post_id, '_agri_yt_stats_updated', current_time( 'mysql' ) );
    }

    private function set_thumbnail( $post_id, $thumb_url, $title ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $thumb_url );
        if ( is_wp_error( $tmp ) ) return;

        $file = [
            'name'     => sanitize_title( $title ) . '.jpg',
            'type'     => 'image/jpeg',
            'tmp_name' => $tmp,
            'size'     => filesize( $tmp ),
        ];

        $attach_id = media_handle_sideload( $file, $post_id );
        if ( ! is_wp_error( $attach_id ) ) {
            set_post_thumbnail( $post_id, $attach_id );
        }
    }
}
