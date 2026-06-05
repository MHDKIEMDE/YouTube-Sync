<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Agri_Youtube_Admin {

    public function __construct() {
        add_action( 'admin_menu',    [ $this, 'add_menu' ] );
        add_action( 'admin_init',    [ $this, 'register_settings' ] );
        add_action( 'admin_post_agri_yt_manual_sync',    [ $this, 'manual_sync' ] );
        add_action( 'admin_post_agri_yt_import_selected', [ $this, 'import_selected' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles' ] );
        add_action( 'admin_head-edit.php',   [ $this, 'add_youtube_button_to_movies' ] );
        add_action( 'update_option_agri_yt_api_key', [ $this, 'auto_websub_subscribe' ], 10, 2 );
        // Metabox import YouTube sur la page d'ajout de vidéo
        add_action( 'add_meta_boxes', [ $this, 'add_youtube_import_metabox' ] );
        add_action( 'wp_ajax_agri_yt_fetch_video', [ $this, 'ajax_fetch_video' ] );
    }

    public function auto_websub_subscribe( $old, $new ) {
        if ( empty( $new ) ) return;
        $websub = new Agri_Youtube_WebSub();
        $websub->subscribe();
    }

    public function add_youtube_button_to_movies() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== get_option( 'agri_yt_post_type', 'movies' ) ) return;
        $url = admin_url( 'options-general.php?page=agri-youtube-sync&tab=browse' );
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var btn = document.createElement('a');
            btn.href = '<?php echo esc_js( $url ); ?>';
            btn.className = 'page-title-action';
            btn.style.cssText = 'background:#7ED957;color:#000;border-color:#5db346;font-weight:600;';
            btn.innerHTML = '▶ Ajouter depuis YouTube';
            var addNew = document.querySelector('.page-title-action');
            if (addNew) addNew.parentNode.insertBefore(btn, addNew.nextSibling);
        });
        </script>
        <?php
    }

    public function enqueue_styles( $hook ) {
        if ( strpos( $hook, 'agri-youtube-sync' ) === false ) return;
        wp_add_inline_style( 'wp-admin', '
            .agri-video-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:16px; margin-top:20px; }
            .agri-video-card { background:#fff; border:2px solid #ddd; border-radius:8px; overflow:hidden; transition:border-color .2s; cursor:pointer; }
            .agri-video-card:hover { border-color:#7ED957; }
            .agri-video-card.selected { border-color:#7ED957; box-shadow:0 0 0 3px rgba(126,217,87,.3); }
            .agri-video-card.already-imported { opacity:.5; border-color:#ccc; cursor:not-allowed; }
            .agri-video-thumb { position:relative; padding-bottom:56.25%; background:#000; }
            .agri-video-thumb img { position:absolute; top:0; left:0; width:100%; height:100%; object-fit:cover; }
            .agri-video-thumb .badge { position:absolute; top:8px; right:8px; background:#7ED957; color:#000; font-size:11px; font-weight:700; padding:3px 8px; border-radius:4px; }
            .agri-video-thumb .badge.imported { background:#ccc; color:#555; }
            .agri-video-thumb .badge-live { position:absolute; top:8px; left:8px; background:#e00; color:#fff; font-size:11px; font-weight:700; padding:3px 8px; border-radius:4px; animation:agri-blink 1s step-start infinite; }
            @keyframes agri-blink { 50%{opacity:.4;} }
            .agri-video-info { padding:12px; }
            .agri-video-info h4 { margin:0 0 6px; font-size:13px; line-height:1.4; }
            .agri-video-info span { font-size:11px; color:#888; }
            .agri-select-bar { position:sticky; top:32px; z-index:99; background:#fff; border:1px solid #ddd; border-radius:8px; padding:12px 16px; margin-bottom:16px; display:flex; align-items:center; gap:12px; }
            .agri-toggle-wrap { display:flex; align-items:center; gap:10px; }
            .agri-toggle { position:relative; display:inline-block; width:46px; height:24px; }
            .agri-toggle input { opacity:0; width:0; height:0; }
            .agri-toggle-slider { position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#ccc; border-radius:24px; transition:.3s; }
            .agri-toggle-slider:before { position:absolute; content:""; height:18px; width:18px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.3s; }
            .agri-toggle input:checked + .agri-toggle-slider { background:#7ED957; }
            .agri-toggle input:checked + .agri-toggle-slider:before { transform:translateX(22px); }
        ' );
    }

    public function add_menu() {
        add_options_page(
            'Agri YouTube Sync',
            'YouTube Sync',
            'manage_options',
            'agri-youtube-sync',
            [ $this, 'settings_page' ]
        );
    }

    public function register_settings() {
        $fields = [
            'agri_yt_api_key', 'agri_yt_post_type', 'agri_yt_channel_handle',
            'agri_yt_post_status', 'agri_yt_category_id', 'agri_yt_import_source',
            'agri_yt_playlist_id', 'agri_yt_max_results',
            'agri_yt_email_enabled', 'agri_yt_email_address', 'agri_yt_email_subject',
        ];
        foreach ( $fields as $field ) {
            register_setting( 'agri_yt_settings', $field, 'sanitize_text_field' );
        }
        register_setting( 'agri_yt_settings', 'agri_yt_email_body', 'sanitize_textarea_field' );
    }

    public function manual_sync() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Non autorisé' );
        check_admin_referer( 'agri_yt_manual_sync' );

        $importer = new Agri_Youtube_Importer();
        $result   = $importer->sync( 'manual' );

        wp_redirect( add_query_arg( [
            'page'    => 'agri-youtube-sync',
            'synced'  => $result['imported'],
            'skipped' => $result['skipped'],
            'tab'     => 'browse',
        ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    public function import_selected() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Non autorisé' );
        check_admin_referer( 'agri_yt_import_selected' );

        $video_ids = isset( $_POST['video_ids'] ) ? (array) $_POST['video_ids'] : [];
        $imported  = 0;

        $api      = new Agri_Youtube_API();
        $importer = new Agri_Youtube_Importer();

        foreach ( $video_ids as $vid ) {
            $vid = sanitize_text_field( $vid );
            $details = $api->get_video_details( $vid );
            if ( ! $details ) continue;

            $video = [
                'id'      => [ 'videoId' => $vid ],
                'snippet' => $details['snippet'],
            ];

            $result = $importer->import_single( $video, $vid );
            if ( $result ) $imported++;
        }

        if ( $imported > 0 ) {
            Agri_Youtube_Logger::log( $imported, 0, 'manual' );
        }

        wp_redirect( add_query_arg( [
            'page'    => 'agri-youtube-sync',
            'synced'  => $imported,
            'skipped' => 0,
            'tab'     => 'browse',
        ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    private function get_imported_video_ids() {
        global $wpdb;
        $results = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_agri_yt_video_id'"
        );
        return $results ?: [];
    }

    public function settings_page() {
        $tab         = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'browse';
        $last_sync   = get_option( 'agri_yt_last_sync', 'Jamais' );
        $last_result = get_option( 'agri_yt_last_sync_result', [] );
        $post_types  = get_post_types( [ 'public' => true ], 'objects' );
        $current_pt  = get_option( 'agri_yt_post_type', 'movies' );
        $source      = get_option( 'agri_yt_import_source', 'channel' );

        $api        = new Agri_Youtube_API();
        $playlists  = get_option( 'agri_yt_api_key' ) ? $api->get_playlists() : [];
        $taxonomies = get_object_taxonomies( $current_pt, 'objects' );
        $first_tax  = ! empty( $taxonomies ) ? array_key_first( $taxonomies ) : 'category';
        $terms      = get_terms( [ 'taxonomy' => $first_tax, 'hide_empty' => false ] );

        $yt_videos    = [];
        $imported_ids = [];
        $total_yt     = 0;
        if ( get_option( 'agri_yt_api_key' ) && $tab === 'browse' ) {
            $all_videos   = $api->get_all_videos();
            $imported_ids = $this->get_imported_video_ids();
            $total_yt     = count( $all_videos );
            $yt_videos = array_filter( $all_videos, function( $video ) use ( $imported_ids ) {
                $vid_id = $video['id']['videoId'] ?? null;
                return $vid_id && ! in_array( $vid_id, $imported_ids );
            });
        }
        ?>
        <div class="wrap">
            <h1 style="color:#1d2327;">🎬 Agri YouTube Sync</h1>

            <?php if ( isset( $_GET['synced'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>✅ <strong><?php echo intval( $_GET['synced'] ); ?> vidéos importées</strong><?php if ( intval( $_GET['skipped'] ) ) echo ', ' . intval( $_GET['skipped'] ) . ' ignorées (déjà existantes)'; ?>.</p>
                </div>
            <?php endif; ?>

            <!-- ONGLETS -->
            <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
                <a href="?page=agri-youtube-sync&tab=browse"   class="nav-tab <?php echo $tab === 'browse'   ? 'nav-tab-active' : ''; ?>">📺 Parcourir les vidéos</a>
                <a href="?page=agri-youtube-sync&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">⚙️ Réglages</a>
                <a href="?page=agri-youtube-sync&tab=email"    class="nav-tab <?php echo $tab === 'email'    ? 'nav-tab-active' : ''; ?>">📧 Notifications email</a>
                <a href="?page=agri-youtube-sync&tab=logs"     class="nav-tab <?php echo $tab === 'logs'     ? 'nav-tab-active' : ''; ?>">📋 Logs</a>
            </nav>

            <?php if ( $tab === 'browse' ) : ?>

                <div style="background:#fff;padding:16px 20px;border-radius:8px;margin-bottom:20px;border:1px solid #ddd;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                    <div>
                        <strong>Dernière sync :</strong> <?php echo esc_html( $last_sync ); ?>
                        <?php if ( $last_result ) : ?>
                            &nbsp;|&nbsp; <strong><?php echo intval( $last_result['imported'] ?? 0 ); ?> importées</strong>, <?php echo intval( $last_result['skipped'] ?? 0 ); ?> ignorées
                        <?php endif; ?>
                    </div>
                    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                        <input type="hidden" name="action" value="agri_yt_manual_sync">
                        <?php wp_nonce_field( 'agri_yt_manual_sync' ); ?>
                        <button type="submit" class="button button-primary" style="background:#7ED957;border-color:#5db346;color:#000;">
                            ↻ Sync manuelle
                        </button>
                    </form>
                </div>

                <?php if ( $total_yt > 0 ) : ?>
                <div style="background:#f0faf0;border:1px solid #7ED957;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                    <div style="display:flex;gap:24px;">
                        <span>📺 <strong><?php echo $total_yt; ?></strong> vidéos sur YouTube</span>
                        <span>✅ <strong><?php echo count( $imported_ids ); ?></strong> déjà importées</span>
                        <span>⬇ <strong><?php echo count( $yt_videos ); ?></strong> disponibles à importer</span>
                    </div>
                    <input type="text" id="agri-search" placeholder="🔍 Rechercher une vidéo..."
                        style="padding:6px 12px;border:1px solid #ddd;border-radius:6px;width:280px;font-size:13px;"
                        oninput="agriSearch(this.value)" />
                </div>
                <?php endif; ?>

                <?php if ( empty( $yt_videos ) ) : ?>
                    <div class="notice notice-success"><p>✅ Toutes les vidéos de votre chaîne sont déjà importées !</p></div>
                <?php else : ?>

                    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" id="agri-import-form">
                        <input type="hidden" name="action" value="agri_yt_import_selected">
                        <?php wp_nonce_field( 'agri_yt_import_selected' ); ?>

                        <div class="agri-select-bar">
                            <button type="button" onclick="agriSelectAll()" class="button">✅ Tout sélectionner</button>
                            <button type="button" onclick="agriSelectNone()" class="button">✖ Tout désélectionner</button>
                            <button type="submit" class="button button-primary" style="background:#7ED957;border-color:#5db346;color:#000;">
                                ⬇ Importer les vidéos sélectionnées
                            </button>
                            <span id="agri-count" style="color:#666;font-size:13px;">0 vidéo(s) sélectionnée(s)</span>
                        </div>

                        <div class="agri-video-grid">
                            <?php foreach ( $yt_videos as $video ) :
                                $vid_id  = $video['id']['videoId'] ?? null;
                                if ( ! $vid_id ) continue;
                                $snippet = $video['snippet'];
                                $title   = esc_html( $snippet['title'] );
                                $date    = date( 'd/m/Y', strtotime( $snippet['publishedAt'] ) );
                                $thumb   = $snippet['thumbnails']['high']['url'] ?? $snippet['thumbnails']['default']['url'] ?? '';
                                $is_live = ! empty( $snippet['liveBroadcastContent'] ) && $snippet['liveBroadcastContent'] === 'live';
                            ?>
                                <div class="agri-video-card" onclick="agriToggle(this, '<?php echo esc_js( $vid_id ); ?>')">
                                    <div class="agri-video-thumb">
                                        <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo $title; ?>" loading="lazy" />
                                        <span class="badge">YouTube</span>
                                        <?php if ( $is_live ) : ?>
                                            <span class="badge-live">🔴 EN DIRECT</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="agri-video-info">
                                        <h4><?php echo $title; ?></h4>
                                        <span><?php echo $date; ?></span>
                                        <input type="checkbox" name="video_ids[]"
                                            value="<?php echo esc_attr( $vid_id ); ?>"
                                            class="agri-checkbox"
                                            style="display:none;" />
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </form>

                    <script>
                    function agriToggle(card, id) {
                        card.classList.toggle('selected');
                        var cb = card.querySelector('.agri-checkbox');
                        if (cb) cb.checked = card.classList.contains('selected');
                        agriUpdateCount();
                    }
                    function agriSelectAll() {
                        document.querySelectorAll('.agri-video-card:not([style*="display: none"])').forEach(function(c) {
                            c.classList.add('selected');
                            var cb = c.querySelector('.agri-checkbox');
                            if (cb) cb.checked = true;
                        });
                        agriUpdateCount();
                    }
                    function agriSelectNone() {
                        document.querySelectorAll('.agri-video-card').forEach(function(c) {
                            c.classList.remove('selected');
                            var cb = c.querySelector('.agri-checkbox');
                            if (cb) cb.checked = false;
                        });
                        agriUpdateCount();
                    }
                    function agriUpdateCount() {
                        var n = document.querySelectorAll('.agri-checkbox:checked').length;
                        document.getElementById('agri-count').textContent = n + ' vidéo(s) sélectionnée(s)';
                    }
                    function agriSearch(q) {
                        q = q.toLowerCase();
                        document.querySelectorAll('.agri-video-card').forEach(function(c) {
                            var title = c.querySelector('h4').textContent.toLowerCase();
                            c.style.display = title.includes(q) ? '' : 'none';
                        });
                    }
                    </script>

                <?php endif; ?>

            <?php elseif ( $tab === 'settings' ) : ?>

                <form method="post" action="options.php">
                    <?php settings_fields( 'agri_yt_settings' ); ?>

                    <div style="background:#fff;padding:20px;border-radius:8px;margin-bottom:20px;border:1px solid #ddd;">
                        <h2>🔑 Connexion YouTube</h2>
                        <table class="form-table">
                            <tr>
                                <th>Clé API YouTube</th>
                                <td>
                                    <input type="text" name="agri_yt_api_key"
                                        value="<?php echo esc_attr( get_option( 'agri_yt_api_key' ) ); ?>"
                                        class="regular-text" placeholder="AIzaSy..." />
                                </td>
                            </tr>
                            <tr>
                                <th>Handle YouTube</th>
                                <td>
                                    <input type="text" name="agri_yt_channel_handle"
                                        value="<?php echo esc_attr( get_option( 'agri_yt_channel_handle', 'AgribusinessTV' ) ); ?>"
                                        class="regular-text" placeholder="AgribusinessTV" />
                                    <p class="description">Sans le @ — ex: AgribusinessTV</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div style="background:#fff;padding:20px;border-radius:8px;margin-bottom:20px;border:1px solid #ddd;">
                        <h2>📥 Source d'import</h2>
                        <table class="form-table">
                            <tr>
                                <th>Importer depuis</th>
                                <td>
                                    <label><input type="radio" name="agri_yt_import_source" value="channel" <?php checked( $source, 'channel' ); ?> /> Toute la chaîne</label>
                                    &nbsp;&nbsp;
                                    <label><input type="radio" name="agri_yt_import_source" value="playlist" <?php checked( $source, 'playlist' ); ?> /> Une playlist spécifique</label>
                                </td>
                            </tr>
                            <?php if ( ! empty( $playlists ) ) : ?>
                            <tr>
                                <th>Playlist</th>
                                <td>
                                    <select name="agri_yt_playlist_id">
                                        <option value="">— Choisir une playlist —</option>
                                        <?php foreach ( $playlists as $pl ) : ?>
                                            <option value="<?php echo esc_attr( $pl['id'] ); ?>"
                                                <?php selected( get_option( 'agri_yt_playlist_id' ), $pl['id'] ); ?>>
                                                <?php echo esc_html( $pl['snippet']['title'] ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Nombre de vidéos max</th>
                                <td>
                                    <select name="agri_yt_max_results">
                                        <?php foreach ( [ 10, 25, 50, 100, 200, 500 ] as $n ) : ?>
                                            <option value="<?php echo $n; ?>" <?php selected( get_option( 'agri_yt_max_results', 50 ), $n ); ?>><?php echo $n; ?> vidéos</option>
                                        <?php endforeach; ?>
                                        <option value="9999" <?php selected( get_option( 'agri_yt_max_results', 50 ), 9999 ); ?>>Toutes les vidéos</option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div style="background:#fff;padding:20px;border-radius:8px;margin-bottom:20px;border:1px solid #ddd;">
                        <h2>📝 Publication</h2>
                        <table class="form-table">
                            <tr>
                                <th>Type de contenu</th>
                                <td>
                                    <select name="agri_yt_post_type">
                                        <?php foreach ( $post_types as $pt ) : ?>
                                            <option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $current_pt, $pt->name ); ?>><?php echo esc_html( $pt->label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Statut à l'import</th>
                                <td>
                                    <select name="agri_yt_post_status">
                                        <option value="publish" <?php selected( get_option( 'agri_yt_post_status', 'publish' ), 'publish' ); ?>>Publié</option>
                                        <option value="draft"   <?php selected( get_option( 'agri_yt_post_status', 'publish' ), 'draft' ); ?>>Brouillon</option>
                                        <option value="pending" <?php selected( get_option( 'agri_yt_post_status', 'publish' ), 'pending' ); ?>>En attente de relecture</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Catégorie automatique</th>
                                <td>
                                    <select name="agri_yt_category_id">
                                        <option value="">— Aucune —</option>
                                        <?php if ( ! is_wp_error( $terms ) ) foreach ( $terms as $term ) : ?>
                                            <option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( get_option( 'agri_yt_category_id' ), $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <?php submit_button( 'Enregistrer les réglages' ); ?>
                </form>

            <?php elseif ( $tab === 'email' ) : ?>

                <form method="post" action="options.php">
                    <?php settings_fields( 'agri_yt_settings' ); ?>

                    <div style="background:#fff;padding:20px;border-radius:8px;margin-bottom:20px;border:1px solid #ddd;">
                        <h2>📧 Notifications email</h2>
                        <p style="color:#555;">Recevez un email automatiquement à chaque fois que des vidéos sont importées.</p>
                        <table class="form-table">
                            <tr>
                                <th>Activer les notifications</th>
                                <td>
                                    <div class="agri-toggle-wrap">
                                        <label class="agri-toggle">
                                            <input type="checkbox" name="agri_yt_email_enabled" value="1"
                                                <?php checked( get_option( 'agri_yt_email_enabled', 0 ), 1 ); ?> />
                                            <span class="agri-toggle-slider"></span>
                                        </label>
                                        <span style="font-size:13px;color:#555;">
                                            <?php echo get_option( 'agri_yt_email_enabled' ) ? '<strong style="color:#2e7d32;">Activées</strong>' : '<span style="color:#888;">Désactivées</span>'; ?>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th>Adresse email destinataire</th>
                                <td>
                                    <input type="email" name="agri_yt_email_address"
                                        value="<?php echo esc_attr( get_option( 'agri_yt_email_address', get_option( 'admin_email' ) ) ); ?>"
                                        class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
                                    <p class="description">Par défaut : adresse administrateur WordPress.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Objet de l'email</th>
                                <td>
                                    <input type="text" name="agri_yt_email_subject"
                                        value="<?php echo esc_attr( get_option( 'agri_yt_email_subject', '[AgriTV] {count} nouvelle(s) vidéo(s) importée(s)' ) ); ?>"
                                        class="regular-text" />
                                    <p class="description">Variable disponible : <code>{count}</code> — nombre de vidéos importées.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Corps de l'email</th>
                                <td>
                                    <textarea name="agri_yt_email_body" rows="6" class="large-text"><?php
                                        echo esc_textarea( get_option( 'agri_yt_email_body',
                                            "Bonjour,\n\n{count} nouvelle(s) vidéo(s) viennent d'être importées sur votre site.\n\nConsultez les vidéos : {site_url}"
                                        ) );
                                    ?></textarea>
                                    <p class="description">Variables : <code>{count}</code>, <code>{site_url}</code></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <?php submit_button( 'Enregistrer les réglages email' ); ?>
                </form>

            <?php elseif ( $tab === 'logs' ) : ?>

                <?php Agri_Youtube_Logger::render_tab(); ?>

            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // METABOX — Import YouTube sur la page d'ajout de vidéo
    // -------------------------------------------------------------------------

    public function add_youtube_import_metabox() {
        $post_type = get_option( 'agri_yt_post_type', 'movies' );
        add_meta_box(
            'agri_yt_import_metabox',
            '▶ Importer depuis YouTube',
            [ $this, 'render_youtube_import_metabox' ],
            [ $post_type, 'videos' ],
            'side',
            'high'
        );
    }

    public function render_youtube_import_metabox( $post ) {
        ?>
        <div id="agri-yt-metabox">
            <p style="font-size:12px;color:#666;margin-top:0;">Collez une URL YouTube pour pré-remplir automatiquement tous les champs.</p>
            <input type="text" id="agri-yt-url-input"
                placeholder="https://www.youtube.com/watch?v=..."
                style="width:100%;margin-bottom:8px;padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px;" />
            <button type="button" id="agri-yt-fetch-btn"
                style="width:100%;background:#7ED957;border:1px solid #5db346;color:#000;font-weight:600;padding:7px;border-radius:4px;cursor:pointer;font-size:13px;">
                ⬇ Récupérer les infos YouTube
            </button>
            <div id="agri-yt-status" style="margin-top:8px;font-size:12px;"></div>
            <div id="agri-yt-preview" style="display:none;margin-top:10px;">
                <img id="agri-yt-thumb-preview" src="" style="width:100%;border-radius:4px;" />
                <p id="agri-yt-title-preview" style="font-size:12px;font-weight:600;margin:6px 0 2px;"></p>
                <p id="agri-yt-stats-preview" style="font-size:11px;color:#888;margin:0;"></p>
            </div>
        </div>
        <script>
        document.getElementById('agri-yt-fetch-btn').addEventListener('click', function() {
            var url   = document.getElementById('agri-yt-url-input').value.trim();
            var status = document.getElementById('agri-yt-status');
            if ( ! url ) { status.innerHTML = '<span style="color:#c00;">Collez une URL YouTube.</span>'; return; }

            // Extraire l'ID vidéo
            var match = url.match(/(?:v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
            if ( ! match ) { status.innerHTML = '<span style="color:#c00;">URL YouTube invalide.</span>'; return; }
            var videoId = match[1];

            status.innerHTML = '<span style="color:#888;">Chargement...</span>';
            this.disabled = true;
            var btn = this;

            fetch( ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=agri_yt_fetch_video&video_id=' + encodeURIComponent(videoId) + '&nonce=<?php echo wp_create_nonce('agri_yt_fetch_video'); ?>'
            })
            .then(r => r.json())
            .then(function(data) {
                btn.disabled = false;
                if ( ! data.success ) {
                    status.innerHTML = '<span style="color:#c00;">' + data.data + '</span>';
                    return;
                }
                var d = data.data;
                status.innerHTML = '<span style="color:#2e7d32;">✅ Informations récupérées !</span>';

                // Remplir le titre
                var titleInput = document.querySelector('#title');
                if (titleInput) titleInput.value = d.title;

                // Remplir le contenu (iframe embed)
                if (typeof wp !== 'undefined' && wp.data) {
                    wp.data.dispatch('core/editor').editPost({ title: d.title, content: d.embed + '\n\n' + d.description });
                } else {
                    var content = document.querySelector('#content');
                    if (content) content.value = d.embed + '\n\n' + d.description;
                }

                // Aperçu
                document.getElementById('agri-yt-preview').style.display = 'block';
                document.getElementById('agri-yt-thumb-preview').src = d.thumb;
                document.getElementById('agri-yt-title-preview').textContent = d.title;
                document.getElementById('agri-yt-stats-preview').textContent = '👁 ' + d.views + '  ·  ⏱ ' + d.duration + '  ·  ' + d.lang.toUpperCase();

                // Stocker l'ID pour la sauvegarde
                var hidden = document.createElement('input');
                hidden.type  = 'hidden';
                hidden.name  = 'agri_yt_video_id_import';
                hidden.value = d.video_id;
                document.getElementById('agri-yt-metabox').appendChild(hidden);
            })
            .catch(function() {
                btn.disabled = false;
                status.innerHTML = '<span style="color:#c00;">Erreur réseau.</span>';
            });
        });
        </script>
        <?php
    }

    public function ajax_fetch_video() {
        check_ajax_referer( 'agri_yt_fetch_video', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Non autorisé' );

        $video_id = sanitize_text_field( $_POST['video_id'] ?? '' );
        if ( ! $video_id ) wp_send_json_error( 'ID vidéo manquant' );

        $api     = new Agri_Youtube_API();
        $details = $api->get_video_details( $video_id );
        if ( ! $details ) wp_send_json_error( 'Vidéo introuvable ou clé API invalide' );

        $stats   = $api->get_videos_stats( [ $video_id ] )[ $video_id ] ?? [];
        $snippet = $details['snippet'];
        $lang    = Agri_Youtube_Importer::detect_language( $snippet['title'] );
        $thumb   = $snippet['thumbnails']['maxres']['url']
                   ?? $snippet['thumbnails']['high']['url']
                   ?? $snippet['thumbnails']['default']['url']
                   ?? '';

        $embed = '<div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;">'
               . '<iframe src="https://www.youtube.com/embed/' . esc_attr( $video_id ) . '" '
               . 'style="position:absolute;top:0;left:0;width:100%;height:100%;" '
               . 'frameborder="0" allowfullscreen></iframe>'
               . '</div>';

        wp_send_json_success( [
            'video_id'    => $video_id,
            'title'       => sanitize_text_field( $snippet['title'] ),
            'description' => wp_strip_all_tags( $snippet['description'] ),
            'thumb'       => esc_url( $thumb ),
            'embed'       => $embed,
            'views'       => number_format_i18n( intval( $stats['views'] ?? 0 ) ),
            'duration'    => $stats['duration_fmt'] ?? '',
            'lang'        => $lang,
        ] );
    }
}
