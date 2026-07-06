# Plan d'implémentation — Système Équipe / Membres (v2.4.0)

> Validé le 6 juillet 2026. Couvre le plugin **Agri YouTube Sync v2.4.0** et le thème enfant **streamvid-child**.
> Objectif : chaque membre de l'équipe (journaliste, monteur, cadreur…) a sa fiche publique avec ses vidéos, ses playlists, ses statistiques — alimentée automatiquement par la synchronisation YouTube.

---

## Principe directeur

La **source de vérité** est le champ répéteur ACF `crew` (personne + job) déjà présent sur
`movies` / `tv_shows` / `videos` dans StreamVid. Tout le reste s'en déduit :

- la fiche membre du thème liste déjà les vidéos via `crew_$_person` (aucune requête à inventer) ;
- le compteur de projets = comptage de cette même requête ;
- les playlists du membre = termes `movies_playlist` de ses vidéos.

Le plugin ne crée **aucun champ nouveau** : il remplit `crew` automatiquement à l'import.

---

## Phase 1 — Plugin Agri YouTube Sync v2.4.0

### 1.1 Mapping playlist → membre
- Nouvel onglet de réglages « Équipe » : table de correspondance
  **playlist YouTube → fiche `person` + job** (ex. « Chroniques de Fatou » → Fatou / journaliste).
- À l'import, toute vidéo issue d'une playlist mappée reçoit le membre dans son répéteur `crew`
  (sans doublon si déjà présent).
- Source stable et sans faute de frappe — prioritaire sur les hashtags.

### 1.2 Alias / hashtags en complément
- Table **alias → membre + job** (ex. `#MohamedK` ou `Montage: Mohamed` → Mohamed / monteur).
- À l'import, scan de la description YouTube et des tags ; utile pour les vidéos hors playlist
  et les rôles secondaires (montage, cadrage).

### 1.3 Bilingue FR/EN (Polylang)
- Le mapping pointe vers la fiche `person` de **la langue de la vidéo** : si la vidéo est EN
  et que la fiche a une traduction EN, c'est la traduction qui est attribuée.
- Fallback : fiche FR si pas de traduction.

### 1.4 Traçabilité et notifications
- Chaque attribution automatique est tracée dans les logs existants du plugin :
  « vidéo X attribuée à Y via playlist Z » ou « via alias #… ».
- Option : notification email au membre quand une nouvelle vidéo lui est attribuée
  (réutilise le système d'emails existant).

### 1.5 Suivi des vidéos orphelines
- Colonne « Équipe » dans la liste admin des vidéos (membres attribués).
- Filtre « Sans attribution » pour voir d'un coup d'œil le travail manuel restant.

---

## Phase 2 — Thème enfant streamvid-child

### 2.1 Fiche membre enrichie (override `single-person.php` + template-parts)
- **Compteur « X projets réalisés »** (requête cast/crew existante).
- **« X vues cumulées »** : somme des vues YouTube importées (`_agri_yt_views`) de ses vidéos.
- **Grille paginée** des dernières vidéos (AJAX « Charger plus ») en remplacement du carrousel
  « Known for » qui charge tout ; filtres par type et par rubrique conservés.
- **Section « Ses playlists / émissions »** : cartes des termes `movies_playlist` de ses vidéos
  (visuel + nombre de vidéos), lien vers l'archive playlist native (paginée).
- **Réseaux sociaux + contact** : nouveaux champs ACF sur `person`
  (Facebook, Instagram, LinkedIn, YouTube, site web, email pro) — affichés sur la fiche.
- **SEO** : schema.org `Person` sur la fiche (JSON-LD) ; `contributor` sur les vidéos.

### 2.2 Page Équipe
- Catégories `person_cat` : « Équipe » et « Entrepreneurs » (séparation nette).
- Page Équipe = grille des membres (widget Elementor `person_advanced` ou archive `person_cat`) :
  photo, nom, métier, nombre de projets.

---

## Phase 3 — Extensions (après validation des phases 1–2)

- **Entrepreneurs** : même mécanique via le répéteur `cast` (fiche, vidéos liées, compteur) ;
  détection semi-automatique par hashtag/nom, saisie manuelle en complément.
- **Filtre public par membre** sur la grille de vidéos du site (dropdown « par journaliste »).
- **Articles liés** sur la fiche membre (par étiquette), si le blog est activé.

---

## Ordre de réalisation

1. Plugin 1.1 → 1.5 (c'est lui qui alimente tout le reste) → release **v2.4.0**.
2. Thème enfant 2.1 puis 2.2.
3. Phase 3 selon priorités.

## Décisions actées

- Pas de champs dédiés séparés (journaliste, monteur…) : le sous-champ `job` du répéteur
  `crew` couvre ce besoin et alimente déjà les templates du thème.
- L'attribution par playlist prime sur les hashtags ; les hashtags complètent.
- Ce qui n'est pas détecté automatiquement reste éditable à la main dans la vidéo.
