<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Agri_Youtube_Logger {

    const OPTION_KEY = 'agri_yt_sync_logs';
    const MAX_LOGS   = 100;

    /**
     * Enregistre une entrée de log.
     *
     * @param int    $imported  Nombre de vidéos importées.
     * @param int    $skipped   Nombre ignorées.
     * @param string $source    'cron_5min' | 'cron_hourly' | 'manual' | 'websub'
     * @param string $error     Message d'erreur optionnel.
     */
    public static function log( $imported, $skipped, $source = 'cron', $error = '' ) {
        $logs = get_option( self::OPTION_KEY, [] );

        array_unshift( $logs, [
            'date'     => current_time( 'mysql' ),
            'imported' => intval( $imported ),
            'skipped'  => intval( $skipped ),
            'source'   => sanitize_text_field( $source ),
            'error'    => sanitize_text_field( $error ),
        ] );

        // Garder seulement les MAX_LOGS dernières entrées
        $logs = array_slice( $logs, 0, self::MAX_LOGS );
        update_option( self::OPTION_KEY, $logs );
    }

    public static function get_logs() {
        return get_option( self::OPTION_KEY, [] );
    }

    public static function clear_logs() {
        delete_option( self::OPTION_KEY );
    }

    /**
     * Rendu de l'onglet Logs dans wp-admin.
     */
    public static function render_tab() {
        // Vider les logs si demandé
        if (
            isset( $_POST['agri_yt_clear_logs'] )
            && check_admin_referer( 'agri_yt_clear_logs' )
            && current_user_can( 'manage_options' )
        ) {
            self::clear_logs();
            echo '<div class="notice notice-success is-dismissible"><p>Logs effacés.</p></div>';
        }

        $logs = self::get_logs();

        $source_labels = [
            'manual'       => '🖱 Manuel',
            'cron_5min'    => '⏱ Cron 5 min',
            'cron_hourly'  => '⏰ Cron horaire',
            'websub'       => '⚡ WebSub',
            'cron'         => '⏱ Cron',
        ];
        ?>
        <div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #ddd;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <h2 style="margin:0;">📋 Historique des synchronisations</h2>
                <form method="post">
                    <?php wp_nonce_field( 'agri_yt_clear_logs' ); ?>
                    <button type="submit" name="agri_yt_clear_logs" value="1" class="button button-secondary"
                        onclick="return confirm('Vider tous les logs ?');">
                        🗑 Vider les logs
                    </button>
                </form>
            </div>

            <?php if ( empty( $logs ) ) : ?>
                <p style="color:#888;">Aucun log enregistré pour le moment.</p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped" style="border-radius:6px;overflow:hidden;">
                    <thead>
                        <tr>
                            <th style="width:180px;">Date</th>
                            <th style="width:130px;">Source</th>
                            <th style="width:120px;">Importées</th>
                            <th style="width:120px;">Ignorées</th>
                            <th>Erreur</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $logs as $log ) : ?>
                            <tr>
                                <td><?php echo esc_html( $log['date'] ); ?></td>
                                <td><?php echo esc_html( $source_labels[ $log['source'] ] ?? $log['source'] ); ?></td>
                                <td>
                                    <?php if ( $log['imported'] > 0 ) : ?>
                                        <span style="color:#2e7d32;font-weight:600;">+<?php echo $log['imported']; ?></span>
                                    <?php else : ?>
                                        <span style="color:#888;">0</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:#888;"><?php echo intval( $log['skipped'] ); ?></td>
                                <td style="color:#c00;font-size:12px;"><?php echo esc_html( $log['error'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="color:#888;font-size:12px;margin-top:8px;">
                    <?php echo count( $logs ); ?> entrée(s) — les <?php echo self::MAX_LOGS; ?> plus récentes sont conservées.
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}
