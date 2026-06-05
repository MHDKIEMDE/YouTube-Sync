<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WebSub (PubSubHubbub) — YouTube notifie le site instantanément
 * dès qu'une nouvelle vidéo est publiée sur la chaîne.
 *
 * Fonctionnement :
 * 1. On s'abonne au feed Atom YouTube de la chaîne via le hub Google
 * 2. YouTube envoie un ping HTTP POST sur notre endpoint dès qu'une vidéo est publiée
 * 3. On parse la notification et on importe la vidéo immédiatement
 */
class Agri_Youtube_WebSub {

    const HUB_URL      = 'https://pubsubhubbub.appspot.com/subscribe';
    const YOUTUBE_FEED = 'https://www.youtube.com/xml/feeds/videos.xml?channel_id=';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_endpoint' ] );
        add_action( 'agri_yt_websub_resubscribe', [ $this, 'subscribe' ] );
    }

    /**
     * Enregistre l'endpoint REST qui reçoit les notifications YouTube
     * URL : /wp-json/agri-youtube/v1/websub
     */
    public function register_endpoint() {
        register_rest_route( 'agri-youtube/v1', '/websub', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'verify_subscription' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_notification' ],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    /**
     * Vérification de l'abonnement par Google (challenge)
     */
    public function verify_subscription( WP_REST_Request $request ) {
        $challenge = $request->get_param( 'hub_challenge' );
        $mode      = $request->get_param( 'hub_mode' );
        $topic     = $request->get_param( 'hub_topic' );

        update_option( 'agri_yt_websub_status', [
            'mode'  => $mode,
            'topic' => $topic,
            'time'  => current_time( 'mysql' ),
        ]);

        // Renvoyer le challenge pour confirmer l'abonnement
        return new WP_REST_Response( $challenge, 200, [ 'Content-Type' => 'text/plain' ] );
    }

    /**
     * Reçoit la notification YouTube et importe la vidéo immédiatement
     */
    public function handle_notification( WP_REST_Request $request ) {
        $body = $request->get_body();
        if ( empty( $body ) ) return new WP_REST_Response( 'ok', 200 );

        // Parser le XML Atom envoyé par YouTube
        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $body );
        if ( ! $xml ) return new WP_REST_Response( 'ok', 200 );

        $namespaces = $xml->getNamespaces( true );
        $yt         = isset( $namespaces['yt'] ) ? $xml->children( $namespaces['yt'] ) : null;

        foreach ( $xml->entry as $entry ) {
            $yt_ns    = $entry->children( 'http://www.youtube.com/xml/schemas/2015' );
            $video_id = (string) ( $yt_ns->videoId ?? '' );

            if ( ! $video_id ) {
                // Extraire l'ID depuis l'URL
                $link = (string) $entry->link->attributes()->href;
                parse_str( parse_url( $link, PHP_URL_QUERY ), $params );
                $video_id = $params['v'] ?? '';
            }

            if ( ! $video_id ) continue;

            // Récupérer les détails complets via l'API YouTube
            $api     = new Agri_Youtube_API();
            $details = $api->get_video_details( $video_id );

            if ( ! $details ) continue;

            $video = [
                'id'      => [ 'videoId' => $video_id ],
                'snippet' => $details['snippet'],
            ];

            $importer = new Agri_Youtube_Importer();
            $result   = $importer->import_single( $video, $video_id );

            update_option( 'agri_yt_last_websub_notification', [
                'video_id' => $video_id,
                'title'    => $details['snippet']['title'] ?? '?',
                'imported' => $result ? true : false,
                'time'     => current_time( 'mysql' ),
            ]);
        }

        return new WP_REST_Response( 'ok', 200 );
    }

    /**
     * S'abonner au feed YouTube de la chaîne via WebSub
     */
    public function subscribe() {
        $api        = new Agri_Youtube_API();
        $channel_id = $api->get_channel_id();

        if ( ! $channel_id ) {
            update_option( 'agri_yt_websub_error', 'Channel ID introuvable' );
            return false;
        }

        $callback_url = rest_url( 'agri-youtube/v1/websub' );
        $topic_url    = self::YOUTUBE_FEED . $channel_id;

        $response = wp_remote_post( self::HUB_URL, [
            'body' => [
                'hub.callback'      => $callback_url,
                'hub.mode'          => 'subscribe',
                'hub.topic'         => $topic_url,
                'hub.verify'        => 'async',
                'hub.lease_seconds' => 864000, // 10 jours
            ],
        ]);

        if ( is_wp_error( $response ) ) {
            update_option( 'agri_yt_websub_error', $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );

        update_option( 'agri_yt_websub_subscribed', [
            'channel_id'   => $channel_id,
            'callback_url' => $callback_url,
            'topic_url'    => $topic_url,
            'http_code'    => $code,
            'time'         => current_time( 'mysql' ),
            'expires'      => date( 'Y-m-d H:i:s', time() + 864000 ),
        ]);

        // Replanifier le renouvellement dans 9 jours
        wp_clear_scheduled_hook( 'agri_yt_websub_resubscribe' );
        wp_schedule_single_event( time() + ( 9 * DAY_IN_SECONDS ), 'agri_yt_websub_resubscribe' );

        return $code === 202 || $code === 204;
    }

    /**
     * Se désabonner du feed YouTube
     */
    public function unsubscribe() {
        $info = get_option( 'agri_yt_websub_subscribed', [] );
        if ( empty( $info['topic_url'] ) ) return;

        wp_remote_post( self::HUB_URL, [
            'body' => [
                'hub.callback' => $info['callback_url'],
                'hub.mode'     => 'unsubscribe',
                'hub.topic'    => $info['topic_url'],
                'hub.verify'   => 'async',
            ],
        ]);

        delete_option( 'agri_yt_websub_subscribed' );
        wp_clear_scheduled_hook( 'agri_yt_websub_resubscribe' );
    }
}
