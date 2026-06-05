<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Agri_Youtube_Cron {

    public function __construct() {
        add_action( 'agri_yt_sync_event',         [ $this, 'run_sync' ] );
        add_action( 'agri_yt_update_stats_event', [ $this, 'run_update_stats' ] );
    }

    public function run_sync() {
        $importer = new Agri_Youtube_Importer();
        $importer->sync( 'cron_5min' );
    }

    public function run_update_stats() {
        $importer = new Agri_Youtube_Importer();
        $importer->update_stats();
    }
}
