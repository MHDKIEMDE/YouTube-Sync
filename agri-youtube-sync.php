<?php
/*
Plugin Name: Agri YouTube Sync
Plugin URI: https://github.com/MHDKIEMDE/YouTube-Sync
Description: Synchronise automatiquement les vidéos YouTube vers WordPress via WebSub (instantané) et cron (5 min). Gestion bilingue FR/EN, statistiques (vues, likes, durée), catégories automatiques, intégration Polylang, shortcode [agri_videos], widget sidebar, logs de synchronisation, notifications email et badge EN DIRECT.
Version: 2.3.0
Author: Mohamed KIEMDE
Author URI: https://agribusinesstv.com
Text Domain: agri-youtube-sync
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Copyright (C) 2024-2026 Mohamed KIEMDE <mkiemde00@gmail.com>

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AGRI_YT_VERSION', '2.3.0' );
define( 'AGRI_YT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGRI_YT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Chargement des fichiers
require_once AGRI_YT_PLUGIN_DIR . 'includes/class-agri-youtube-api.php';
require_once AGRI_YT_PLUGIN_DIR . 'includes/class-agri-youtube-logger.php';
require_once AGRI_YT_PLUGIN_DIR . 'includes/class-agri-youtube-importer.php';
require_once AGRI_YT_PLUGIN_DIR . 'includes/class-agri-youtube-cron.php';
require_once AGRI_YT_PLUGIN_DIR . 'includes/class-agri-youtube-websub.php';
require_once AGRI_YT_PLUGIN_DIR . 'includes/class-agri-youtube-shortcode.php';
require_once AGRI_YT_PLUGIN_DIR . 'includes/class-agri-youtube-widget.php';
require_once AGRI_YT_PLUGIN_DIR . 'admin/class-agri-youtube-admin.php';

// Ajouter l'intervalle de 5 minutes au cron WordPress
add_filter( 'cron_schedules', 'agri_yt_add_cron_interval' );
function agri_yt_add_cron_interval( $schedules ) {
    $schedules['every_5_minutes'] = [
        'interval' => 300,
        'display'  => 'Toutes les 5 minutes',
    ];
    return $schedules;
}

// Initialisation
function agri_yt_init() {
    new Agri_Youtube_Admin();
    new Agri_Youtube_Cron();
    new Agri_Youtube_WebSub();
    new Agri_Youtube_Shortcode();
}
add_action( 'plugins_loaded', 'agri_yt_init' );

// S'abonner WebSub automatiquement si pas encore actif et clé API présente
add_action( 'init', 'agri_yt_auto_websub', 99 );
function agri_yt_auto_websub() {
    $api_key    = get_option( 'agri_yt_api_key', '' );
    $subscribed = get_option( 'agri_yt_websub_subscribed', [] );
    if ( $api_key && empty( $subscribed ) ) {
        $websub = new Agri_Youtube_WebSub();
        $websub->subscribe();
    }
}

// Activation
register_activation_hook( __FILE__, 'agri_yt_activate' );
function agri_yt_activate() {
    wp_clear_scheduled_hook( 'agri_yt_sync_event' );
    wp_schedule_event( time(), 'every_5_minutes', 'agri_yt_sync_event' );

    wp_clear_scheduled_hook( 'agri_yt_update_stats_event' );
    wp_schedule_event( time(), 'hourly', 'agri_yt_update_stats_event' );

    add_action( 'init', function() {
        $websub = new Agri_Youtube_WebSub();
        $websub->subscribe();
    }, 99 );
}

// Désactivation
register_deactivation_hook( __FILE__, 'agri_yt_deactivate' );
function agri_yt_deactivate() {
    wp_clear_scheduled_hook( 'agri_yt_sync_event' );
    wp_clear_scheduled_hook( 'agri_yt_update_stats_event' );
    wp_clear_scheduled_hook( 'agri_yt_websub_resubscribe' );
    $websub = new Agri_Youtube_WebSub();
    $websub->unsubscribe();
}
