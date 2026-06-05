# Agri YouTube Sync

**Auteur :** Mohamed KIEMDE  
**Email :** mkiemde00@gmail.com  
**Site :** [agribusinesstv.com](https://agribusinesstv.com)  
**Version :** 2.2.0  
**Licence :** GPL v2 or later  
**Compatibilité WordPress :** 6.0+  
**PHP minimum :** 7.4+

---

## Description

**Agri YouTube Sync** est un plugin WordPress développé pour [Agribusiness TV](https://agribusinesstv.com), un média en ligne spécialisé dans l'agriculture africaine.

Il synchronise automatiquement toutes les vidéos de la chaîne YouTube [@AgribusinessTV](https://youtube.com/@AgribusinessTV) vers WordPress en :

- Lisant automatiquement toutes les playlists YouTube
- Détectant la langue (FR/EN) depuis le nom de la playlist
- Créant les catégories WordPress correspondantes
- Assignant la langue via Polylang
- Liant les traductions FR ↔ EN entre elles
- Important les statistiques (vues, likes, durée, commentaires)
- Synchronisant en temps réel via WebSub + cron de secours toutes les 5 minutes

---

## Fonctionnalités

### Synchronisation automatique
- **WebSub (PubSubHubbub)** — notification instantanée dès qu'une vidéo est publiée sur YouTube
- **Cron WordPress toutes les 5 minutes** — filet de sécurité si WebSub échoue
- **Cron horaire** — mise à jour des statistiques (vues, likes) des vidéos déjà importées

### Gestion bilingue FR/EN
- Détection automatique de la langue depuis le nom de la playlist : `Agri Actu (Fr)` → `fr`, `Agri Actu (En)` → `en`
- Création automatique des catégories WordPress par rubrique (Agri Actu, Agri Doc, Agri Food…)
- Attribution de la langue via **Polylang** (gratuit)
- Liaison automatique des traductions FR ↔ EN d'un même épisode

### Statistiques YouTube
- Nombre de vues (format court : 1.2M, 45K)
- Nombre de likes
- Nombre de commentaires
- Durée de la vidéo (format lisible : 12:34 ou 1:02:03)
- Détection des livestreams en cours
- Mise à jour automatique toutes les heures

### Import intelligent
- Vérification des doublons avant import
- Import en lot (batch) — jusqu'à 50 vidéos par appel API
- Téléchargement et assignation automatique de la miniature YouTube
- Tags YouTube → tags WordPress
- Compatibilité thème StreamVid (meta `videos_url` et `videos_type`)

### Interface d'administration
- Page de configuration dans wp-admin
- Clé API YouTube configurable
- Handle de chaîne configurable
- Choix du type de post (movies, post, custom)
- Choix du statut (publié, brouillon)
- Lancement manuel de la synchronisation
- Affichage de la date et du résultat du dernier sync

---

## Installation

### Prérequis
- WordPress 6.0 ou supérieur
- PHP 7.4 ou supérieur
- Une clé API YouTube Data v3 (Google Cloud Console)
- Plugin **Polylang** (gratuit) pour la gestion bilingue

### Étapes

1. **Télécharger** le zip depuis [GitHub](https://github.com/MHDKIEMDE/YouTube-Sync)
2. Dans WordPress : **Extensions → Ajouter → Téléverser une extension**
3. Uploader le fichier zip et activer le plugin
4. Aller dans **Agri YouTube Sync** dans le menu wp-admin
5. Saisir ta **clé API YouTube**
6. Saisir le **handle de ta chaîne** (ex: `AgribusinessTV`)
7. Lancer une **synchronisation manuelle** pour importer les vidéos existantes

### Obtenir une clé API YouTube

1. Aller sur [Google Cloud Console](https://console.cloud.google.com)
2. Créer un projet ou en sélectionner un existant
3. Activer **YouTube Data API v3**
4. Aller dans **Identifiants → Créer des identifiants → Clé API**
5. Copier la clé et la coller dans les réglages du plugin

---

## Configuration Polylang

Pour que la gestion bilingue fonctionne :

1. Installer **Polylang** (gratuit sur wordpress.org)
2. Aller dans **Langues → Langues**
3. Ajouter **Français** (slug : `fr`)
4. Ajouter **English** (slug : `en`)
5. Définir le Français comme langue par défaut

Le plugin détectera automatiquement Polylang et assignera les langues à chaque vidéo importée.

---

## Structure des fichiers

```
agri-youtube-sync/
├── agri-youtube-sync.php              # Fichier principal, hooks d'activation
├── LICENSE                            # Licence GPL v2
├── README.md                          # Cette documentation
├── includes/
│   ├── class-agri-youtube-api.php     # Appels YouTube Data API v3
│   ├── class-agri-youtube-importer.php # Import, classification FR/EN, Polylang
│   ├── class-agri-youtube-cron.php    # Cron sync + cron stats horaire
│   └── class-agri-youtube-websub.php  # Abonnement WebSub temps réel
└── admin/
    └── class-agri-youtube-admin.php   # Interface wp-admin
```

---

## Meta données enregistrées

Chaque vidéo importée stocke les meta WordPress suivantes :

| Meta key | Contenu |
|---|---|
| `_agri_yt_video_id` | ID YouTube de la vidéo |
| `_agri_yt_video_url` | URL complète YouTube |
| `_agri_yt_lang` | Langue détectée (`fr` ou `en`) |
| `_agri_yt_rubrique` | Rubrique extraite de la playlist |
| `_agri_yt_playlist_id` | ID de la playlist YouTube source |
| `_agri_yt_playlist_title` | Titre de la playlist YouTube source |
| `_agri_yt_views` | Nombre de vues |
| `_agri_yt_likes` | Nombre de likes |
| `_agri_yt_comments` | Nombre de commentaires |
| `_agri_yt_duration_sec` | Durée en secondes |
| `_agri_yt_duration_fmt` | Durée formatée (ex: `12:34`) |
| `_agri_yt_is_live` | `1` si livestream en cours |
| `_agri_yt_stats_updated` | Date de la dernière mise à jour des stats |
| `videos_url` | URL YouTube (compatibilité StreamVid) |
| `videos_type` | `youtube` (compatibilité StreamVid) |

---

## Utiliser les statistiques dans les templates

```php
$views    = get_post_meta( get_the_ID(), '_agri_yt_views', true );
$likes    = get_post_meta( get_the_ID(), '_agri_yt_likes', true );
$duration = get_post_meta( get_the_ID(), '_agri_yt_duration_fmt', true );
$lang     = get_post_meta( get_the_ID(), '_agri_yt_lang', true );
$rubrique = get_post_meta( get_the_ID(), '_agri_yt_rubrique', true );

echo Agri_Youtube_API::format_views( $views ); // "1.2M"
echo $duration; // "12:34"
```

---

## Hooks disponibles

```php
// Déclenché après chaque sync réussi
do_action( 'agri_yt_after_sync', $imported, $skipped );

// Déclenché après import d'une vidéo
do_action( 'agri_yt_after_import_video', $post_id, $video_id, $lang );
```

---

## Changelog

### v2.2.0 — 2026-06-05
- Gestion bilingue FR/EN complète via playlists YouTube
- Création automatique des catégories WordPress par rubrique
- Intégration Polylang : assignation de langue + liaison des traductions
- Détection automatique de toutes les playlists sans configuration manuelle

### v2.1.0 — 2026-06-05
- Ajout des statistiques YouTube : vues, likes, commentaires, durée
- Mise à jour automatique des stats toutes les heures (cron horaire)
- Tags YouTube → tags WordPress
- Import en batch optimisé (50 vidéos par appel API)

### v2.0.0 — 2026-03-13
- Refonte complète du plugin
- Intégration WebSub (notifications instantanées YouTube)
- Cron de secours toutes les 5 minutes
- Compatibilité thème StreamVid

---

## Auteur

**Mohamed KIEMDE**  
Fondateur & Développeur — Agribusiness TV  
Email : mkiemde00@gmail.com  
Site : [agribusinesstv.com](https://agribusinesstv.com)  
GitHub : [github.com/MHDKIEMDE](https://github.com/MHDKIEMDE)

---

## Licence

Ce plugin est distribué sous licence **GPL v2 or later**.  
Voir le fichier [LICENSE](LICENSE) pour les détails complets.

Copyright (C) 2024-2026 Mohamed KIEMDE
