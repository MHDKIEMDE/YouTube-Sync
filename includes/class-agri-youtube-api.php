<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Agri_Youtube_API {

    private $api_key;
    private $channel_handle;

    public function __construct() {
        $this->api_key        = get_option( 'agri_yt_api_key', '' );
        $this->channel_handle = get_option( 'agri_yt_channel_handle', 'AgribusinessTV' );
    }

    public function get_channel_id() {
        $cached = get_transient( 'agri_yt_channel_id' );
        if ( $cached ) return $cached;

        $url = add_query_arg( [
            'part'      => 'id',
            'forHandle' => $this->channel_handle,
            'key'       => $this->api_key,
        ], 'https://www.googleapis.com/youtube/v3/channels' );

        $response = wp_remote_get( $url );
        if ( is_wp_error( $response ) ) return false;

        $data       = json_decode( wp_remote_retrieve_body( $response ), true );
        $channel_id = $data['items'][0]['id'] ?? false;

        if ( $channel_id ) {
            set_transient( 'agri_yt_channel_id', $channel_id, DAY_IN_SECONDS );
        }

        return $channel_id;
    }

    public function get_all_videos() {
        $channel_id  = $this->get_channel_id();
        if ( ! $channel_id ) return [];

        $videos     = [];
        $page_token = '';
        $max_pages  = 20;
        $page       = 0;

        do {
            $args = [
                'part'       => 'snippet',
                'channelId'  => $channel_id,
                'maxResults' => 50,
                'order'      => 'date',
                'type'       => 'video',
                'key'        => $this->api_key,
            ];

            if ( $page_token ) {
                $args['pageToken'] = $page_token;
            }

            $url      = add_query_arg( $args, 'https://www.googleapis.com/youtube/v3/search' );
            $response = wp_remote_get( $url );

            if ( is_wp_error( $response ) ) break;

            $data       = json_decode( wp_remote_retrieve_body( $response ), true );
            $items      = $data['items'] ?? [];
            $videos     = array_merge( $videos, $items );
            $page_token = $data['nextPageToken'] ?? '';
            $page++;

        } while ( $page_token && $page < $max_pages );

        return $videos;
    }

    public function get_videos_by_playlist( $playlist_id, $max_results = 50 ) {
        $videos     = [];
        $page_token = '';

        do {
            $args = [
                'part'       => 'snippet',
                'playlistId' => $playlist_id,
                'maxResults' => 50,
                'key'        => $this->api_key,
            ];

            if ( $page_token ) {
                $args['pageToken'] = $page_token;
            }

            $url      = add_query_arg( $args, 'https://www.googleapis.com/youtube/v3/playlistItems' );
            $response = wp_remote_get( $url );

            if ( is_wp_error( $response ) ) break;

            $data       = json_decode( wp_remote_retrieve_body( $response ), true );
            $items      = $data['items'] ?? [];
            $videos     = array_merge( $videos, $items );
            $page_token = $data['nextPageToken'] ?? '';

        } while ( $page_token && count( $videos ) < $max_results );

        return $videos;
    }

    public function get_playlists() {
        $channel_id = $this->get_channel_id();
        if ( ! $channel_id ) return [];

        $url = add_query_arg( [
            'part'       => 'snippet',
            'channelId'  => $channel_id,
            'maxResults' => 50,
            'key'        => $this->api_key,
        ], 'https://www.googleapis.com/youtube/v3/playlists' );

        $response = wp_remote_get( $url );
        if ( is_wp_error( $response ) ) return [];

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['items'] ?? [];
    }

    public function get_video_details( $video_id ) {
        $url = add_query_arg( [
            'part' => 'snippet,contentDetails,statistics',
            'id'   => $video_id,
            'key'  => $this->api_key,
        ], 'https://www.googleapis.com/youtube/v3/videos' );

        $response = wp_remote_get( $url );
        if ( is_wp_error( $response ) ) return null;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['items'][0] ?? null;
    }

    /**
     * Récupère les stats (vues, likes, durée, tags) pour un ou plusieurs IDs vidéo.
     * Accepte un string ou un tableau d'IDs (max 50 par appel).
     * Retourne un tableau indexé par video_id.
     */
    public function get_videos_stats( $video_ids ) {
        if ( empty( $video_ids ) ) return [];

        $ids = is_array( $video_ids ) ? implode( ',', $video_ids ) : $video_ids;

        $url = add_query_arg( [
            'part' => 'snippet,contentDetails,statistics',
            'id'   => $ids,
            'key'  => $this->api_key,
        ], 'https://www.googleapis.com/youtube/v3/videos' );

        $response = wp_remote_get( $url );
        if ( is_wp_error( $response ) ) return [];

        $data   = json_decode( wp_remote_retrieve_body( $response ), true );
        $items  = $data['items'] ?? [];
        $result = [];

        foreach ( $items as $item ) {
            $vid   = $item['id'];
            $stats = $item['statistics'] ?? [];
            $cd    = $item['contentDetails'] ?? [];
            $snip  = $item['snippet'] ?? [];

            $result[ $vid ] = [
                'views'        => intval( $stats['viewCount']    ?? 0 ),
                'likes'        => intval( $stats['likeCount']    ?? 0 ),
                'comments'     => intval( $stats['commentCount'] ?? 0 ),
                'duration_raw' => $cd['duration'] ?? '',
                'duration_sec' => self::iso8601_to_seconds( $cd['duration'] ?? '' ),
                'duration_fmt' => self::iso8601_to_human( $cd['duration'] ?? '' ),
                'tags'         => $snip['tags'] ?? [],
                'is_live'      => ( ( $snip['liveBroadcastContent'] ?? '' ) === 'live' ),
                'is_upcoming'  => ( ( $snip['liveBroadcastContent'] ?? '' ) === 'upcoming' ),
            ];
        }

        return $result;
    }

    /** Convertit une durée ISO 8601 (PT1H2M3S) en secondes. */
    public static function iso8601_to_seconds( $duration ) {
        if ( ! $duration ) return 0;
        preg_match( '/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $duration, $m );
        return intval( $m[1] ?? 0 ) * 3600
             + intval( $m[2] ?? 0 ) * 60
             + intval( $m[3] ?? 0 );
    }

    /** Convertit une durée ISO 8601 en format lisible (1:02:03 ou 12:34). */
    public static function iso8601_to_human( $duration ) {
        $s = self::iso8601_to_seconds( $duration );
        if ( $s <= 0 ) return '';
        $h = intdiv( $s, 3600 );
        $m = intdiv( $s % 3600, 60 );
        $s = $s % 60;
        if ( $h > 0 ) {
            return sprintf( '%d:%02d:%02d', $h, $m, $s );
        }
        return sprintf( '%d:%02d', $m, $s );
    }

    // -------------------------------------------------------------------------
    // PLAYLISTS SPÉCIALES — Shorts, Streams, Posts
    // -------------------------------------------------------------------------

    /**
     * Retourne l'ID de la playlist spéciale YouTube à partir du channel_id.
     * Shorts  → UUSH + channel_id[2:]
     * Streams → UULV + channel_id[2:]
     * Uploads → UU   + channel_id[2:]
     */
    private function special_playlist_id( $type = 'uploads' ) {
        $channel_id = $this->get_channel_id();
        if ( ! $channel_id ) return false;
        $suffix = substr( $channel_id, 2 );
        switch ( $type ) {
            case 'shorts':  return 'UUSH' . $suffix;
            case 'streams': return 'UULV' . $suffix;
            default:        return 'UU'   . $suffix;
        }
    }

    /**
     * Récupère les Shorts de la chaîne (durée ≤ 60s, format vertical).
     */
    public function get_shorts( $max = 50 ) {
        $playlist_id = $this->special_playlist_id( 'shorts' );
        if ( ! $playlist_id ) return [];
        return $this->get_videos_by_playlist( $playlist_id, $max );
    }

    /**
     * Récupère les Streams (lives passés + en cours) de la chaîne.
     */
    public function get_streams( $max = 50 ) {
        $playlist_id = $this->special_playlist_id( 'streams' );
        if ( ! $playlist_id ) return [];
        return $this->get_videos_by_playlist( $playlist_id, $max );
    }

    /**
     * Récupère les posts (community posts) de la chaîne via activities API.
     * Retourne un tableau de posts avec type, texte et date.
     */
    public function get_channel_posts( $max = 50 ) {
        $channel_id = $this->get_channel_id();
        if ( ! $channel_id ) return [];

        $url = add_query_arg( [
            'part'       => 'snippet,contentDetails',
            'channelId'  => $channel_id,
            'maxResults' => min( $max, 50 ),
            'key'        => $this->api_key,
        ], 'https://www.googleapis.com/youtube/v3/activities' );

        $response = wp_remote_get( $url );
        if ( is_wp_error( $response ) ) return [];

        $data  = json_decode( wp_remote_retrieve_body( $response ), true );
        $items = $data['items'] ?? [];

        // Filtrer uniquement les posts (bulletinPosted) et exclure les uploads déjà gérés
        return array_filter( $items, function( $item ) {
            return in_array( $item['snippet']['type'] ?? '', [ 'bulletinPosted', 'upload' ], true );
        });
    }

    /**
     * Détecte le format d'une vidéo depuis ses stats.
     * Retourne : 'short' | 'stream' | 'video'
     */
    public static function detect_format( $stats, $snippet = [] ) {
        $duration_sec = intval( $stats['duration_sec'] ?? 0 );
        $is_live      = ! empty( $stats['is_live'] ) || ! empty( $stats['is_upcoming'] );
        $live_content = $snippet['liveBroadcastContent'] ?? '';

        if ( $is_live || in_array( $live_content, [ 'live', 'upcoming' ], true ) ) {
            return 'stream';
        }
        if ( $duration_sec > 0 && $duration_sec <= 60 ) {
            return 'short';
        }
        return 'video';
    }

    /** Formate un nombre de vues en format court (1.2M, 45K, 320). */
    public static function format_views( $views ) {
        $views = intval( $views );
        if ( $views >= 1000000 ) return round( $views / 1000000, 1 ) . 'M';
        if ( $views >= 1000 )    return round( $views / 1000, 1 )    . 'K';
        return (string) $views;
    }
}
