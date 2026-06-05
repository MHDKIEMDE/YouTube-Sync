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

    /** Formate un nombre de vues en format court (1.2M, 45K, 320). */
    public static function format_views( $views ) {
        $views = intval( $views );
        if ( $views >= 1000000 ) return round( $views / 1000000, 1 ) . 'M';
        if ( $views >= 1000 )    return round( $views / 1000, 1 )    . 'K';
        return (string) $views;
    }
}
