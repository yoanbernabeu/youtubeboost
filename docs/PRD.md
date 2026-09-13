# YoutubeBoost — Product Requirements Document

> Outil open source (MIT), self-hosted, mono-utilisateur, qui aide un créateur YouTube à donner une seconde vie aux vidéos de son back catalogue en identifiant celles qui déclinent et en générant de nouvelles miniatures avec Gemini.

| Champ | Valeur |
|---|---|
| Nom | YoutubeBoost |
| Licence | MIT |
| Langue de l'interface | Français |
| Cible | Un créateur qui sait lancer `docker compose up` |
| Stack | Symfony full stack, Symfony UX, Symfony AI (Gemini), Tailwind, UX Toolkit kit shadcn, PostgreSQL, Messenger, FrankenPHP, Docker |
| Statut | V1 en cadrage |

---

## 1. Vision

Une vidéo YouTube qui date voit ses vues journalières baisser. L'expérience montre qu'une nouvelle miniature peut relancer une vidéo que YouTube propose encore mais sur laquelle les spectateurs ne cliquent plus.

YoutubeBoost automatise la partie fastidieuse de ce travail :

1. **Repérer** les vidéos du catalogue qui méritent une relance, via un scoring automatique et pondérable basé sur les données de YouTube Analytics.
2. **Comprendre** le contenu de la vidéo grâce à son transcript.
3. **Proposer** cinq miniatures générées par Gemini, avec le visage du créateur à partir de photos de référence, et permettre de les itérer par instruction.
4. **Appliquer** la miniature choisie directement sur YouTube, en gardant l'ancienne pour pouvoir revenir en arrière.
5. **Mesurer** l'effet à 14 et 28 jours et donner un verdict tranché.

L'outil est conçu pour un seul créateur, sur sa propre chaîne, avec ses propres clés API. Il tourne en continu dans Docker, mais toutes les opérations coûteuses sont déclenchées à la main pour rester dans les quotas gratuits de Google.

## 2. Périmètre

### 2.1 Dans la V1

- Connexion OAuth à une chaîne YouTube (compte propriétaire).
- Synchronisation manuelle du catalogue : métadonnées, miniature actuelle, historique complet des vues, impressions et CTR par vidéo depuis sa publication.
- Exclusion automatique des Shorts et des lives.
- Scoring automatique de relance, critères pondérés, pondérations modifiables dans les réglages.
- Classement des vidéos par score avec filtres et tri.
- Analyse à la demande d'une vidéo : téléchargement et mise en cache du transcript, compréhension du contenu par Gemini.
- Génération de 5 miniatures par analyse, avec le texte en français incrusté, en utilisant les photos de référence du créateur (face, profil droit, profil gauche).
- Itération sur une proposition par instruction en langage naturel, l'image servant de référence.
- Comparaison avec la miniature actuelle et prévisualisation façon flux YouTube mobile et desktop.
- Stockage de toutes les images générées dans l'application, export PNG 1280×720.
- Application de la miniature choisie sur YouTube en un clic, sauvegarde de l'ancienne, retour arrière en un clic.
- Suivi après changement à 14 et 28 jours, verdict tranché, suggestion de retour arrière si le résultat est négatif.
- Compteur de quota YouTube Data API consommé dans la journée.
- Réglages : pondérations, consignes de style de la chaîne, photos de référence, modèles Gemini.
- Authentification par mot de passe unique défini en variable d'environnement.
- Déploiement Docker Compose avec FrankenPHP servant directement en HTTPS.

### 2.2 Hors périmètre V1

- Propositions de titres (retiré du périmètre lors du cadrage : un seul levier, la miniature, pour un suivi lisible).
- Modification de la description ou des tags.
- Multi-utilisateurs, multi-chaînes, instance hébergée publique.
- Notifications (email, Discord, Slack).
- Synchronisation automatique planifiée.
- Test A/B natif YouTube (non exposé par l'API).
- Autres fournisseurs d'IA que Gemini.
- Reverse proxy externe : l'outil gère lui-même son HTTPS.

## 3. Personas et parcours

### 3.1 Persona

Créateur YouTube avec un catalogue conséquent (ordre de grandeur : 300 vidéos), à l'aise avec Docker, qui possède un projet Google Cloud et une clé Gemini. Il veut consacrer une session d'une heure de temps en temps à relancer deux ou trois vidéos, pas surveiller un tableau de bord en permanence.

### 3.2 Parcours principal

```
Onboarding ──> Synchro ──> Classement ──> Analyse ──> Génération ──> Application ──> Suivi
   (1 fois)    (manuel)     (auto)       (manuel)     (Gemini)        (1 clic)     (14 / 28 j)
```

1. **Onboarding** (première ouverture)
   - Saisie du mot de passe de l'application.
   - Connexion Google OAuth, sélection de la chaîne.
   - Upload des photos de référence : au minimum face, profil droit, profil gauche. D'autres angles ou expressions sont acceptés.
   - Saisie des consignes de style de la chaîne (texte libre : palette, ton, éléments récurrents, mots à éviter).
2. **Synchro** : bouton « Synchroniser le catalogue ». Un job Messenger récupère la liste des vidéos, exclut Shorts et lives, rapatrie l'historique Analytics, puis recalcule les scores. Barre de progression et compteur de quota.
3. **Classement** : tableau des vidéos triées par score décroissant, avec les signaux détaillés (déclin, CTR, impressions, potentiel), la miniature actuelle et l'âge de la vidéo. Filtres par score, âge, statut de relance.
4. **Analyse** : sur une vidéo, bouton « Analyser ». Téléchargement du transcript (200 unités), résumé du contenu et angles de miniature proposés par Gemini.
5. **Génération** : 5 miniatures produites en arrière-plan. Chaque proposition peut être régénérée avec une instruction. Comparaison côte à côte avec l'actuelle, prévisualisation dans un faux flux YouTube.
6. **Application** : bouton « Appliquer sur YouTube ». L'ancienne miniature est téléchargée et archivée, la nouvelle est poussée via l'API. La date du changement est enregistrée.
7. **Suivi** : à chaque synchro suivante, les vues, impressions et CTR sont comparés avant et après. À 14 puis 28 jours, verdict affiché sur la vidéo et dans un onglet « Relances ».

## 4. Fonctionnalités détaillées

### 4.1 Connexion YouTube

- OAuth 2.0 Google, flux « application web » avec identifiants fournis par le créateur (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`).
- Scopes demandés :
  - `https://www.googleapis.com/auth/youtube.readonly` : liste des vidéos, métadonnées.
  - `https://www.googleapis.com/auth/yt-analytics.readonly` : stats journalières, impressions, CTR.
  - `https://www.googleapis.com/auth/youtube.force-ssl` : téléchargement des sous-titres et mise à jour de la miniature.
- Le refresh token est chiffré et stocké en base. Rafraîchissement automatique de l'access token.
- **Point d'attention documenté** : une application OAuth Google en statut « Test » fait expirer les refresh tokens au bout de 7 jours. Le README doit expliquer qu'il faut passer l'application en statut « En production » dans la console Google Cloud, même sans validation Google. L'écran d'avertissement « application non vérifiée » est acceptable pour un usage personnel.

### 4.2 Synchronisation du catalogue

Déclenchée manuellement. Deux passes :

**Passe 1 — Data API v3** (≈ 10 à 20 unités pour 300 vidéos)
- `channels.list` pour obtenir la playlist « uploads ».
- `playlistItems.list` paginé (50 par page, 1 unité par page).
- `videos.list` par lots de 50 (1 unité par lot) avec `snippet`, `contentDetails`, `statistics`, `liveStreamingDetails`, `status`.
- Exclusions :
  - Shorts : durée ≤ 3 minutes et format vertical (à défaut d'un champ explicite, heuristique durée + ratio de la miniature, ou vérification via la présence du terme `#shorts`). La règle est documentée et ajustable.
  - Lives : présence de `liveStreamingDetails`.
  - Vidéos privées : exclues. Non répertoriées : incluses mais signalées.

**Passe 2 — Analytics API** (pas de quota en unités, limite de requêtes par seconde)
- Pour chaque vidéo, requête `reports.query` avec `dimensions=day`, métriques `views,estimatedMinutesWatched,averageViewDuration,averageViewPercentage,impressions,impressionClickThroughRate`, de la date de publication à hier.
- Les impressions et le CTR ne sont disponibles que depuis une certaine date et uniquement pour le propriétaire ; les valeurs manquantes sont tolérées.
- Stockage incrémental : seules les journées absentes sont demandées lors des synchros suivantes.
- Requêtes espacées par un délai configurable pour respecter le rate limit.

**Transcripts** : jamais téléchargés lors de la synchro. Uniquement à l'analyse d'une vidéo, puis mis en cache définitivement.

**Compteur de quota** : chaque appel Data API est journalisé avec son coût. Le total du jour (fuseau Pacifique, comme le reset Google) est affiché en permanence dans l'interface, avec un avertissement à 80 % des 10 000 unités.

### 4.3 Scoring de relance

Objectif : un score de 0 à 100 par vidéo, recalculé après chaque synchro, indiquant l'intérêt de changer sa miniature.

**Éligibilité** (une vidéo non éligible a un score nul et est masquée par défaut)
- Publiée depuis au moins `min_age_days` (défaut 60).
- Ni Short, ni live, ni privée.
- Pas de relance en cours de suivi (moins de 28 jours depuis un changement de miniature).

**Signaux** (chacun normalisé entre 0 et 1)

| Signal | Définition | Ce qu'il capture |
|---|---|---|
| Déclin | 1 − (vues/jour sur les 28 derniers jours ÷ meilleure moyenne mobile 28 j de la vidéo, hors 30 premiers jours) | La vidéo faisait mieux avant |
| CTR faible | Position du CTR 28 j de la vidéo par rapport à la médiane de la chaîne (1 si nettement sous, 0 si au-dessus) | YouTube la montre, on ne clique pas |
| Impressions encore présentes | Impressions 28 j rapportées à la médiane de la chaîne, plafonné à 1 | Changer la miniature n'a d'effet que si YouTube la propose encore |
| Potentiel | Combinaison du log des vues cumulées et de la rétention moyenne relative à la chaîne | Une vidéo qui a prouvé qu'elle intéresse |

**Pondérations par défaut** (modifiables dans les réglages, somme libre, normalisée au calcul)

| Signal | Poids |
|---|---|
| Déclin | 30 |
| CTR faible | 30 |
| Impressions encore présentes | 15 |
| Potentiel | 25 |

**Score** = 100 × Σ(poids_i × signal_i) ÷ Σ(poids_i).

Le détail des signaux est visible sur chaque vidéo pour que le créateur comprenne pourquoi elle est classée là. Les seuils `min_age_days`, la fenêtre de 28 jours et les pondérations sont dans les réglages.

### 4.4 Analyse d'une vidéo

Déclenchée manuellement, exécutée en arrière-plan.

1. `captions.list` (50 unités) pour trouver la piste, priorité à une piste manuelle dans la langue de la chaîne, sinon la piste automatique (ASR).
2. `captions.download` (200 unités) au format SRT ou VTT, converti en texte brut, stocké en base.
3. Appel Gemini texte avec : transcript, titre, description, consignes de style de la chaîne, stats résumées. Sortie structurée (JSON) :
   - résumé en trois phrases,
   - promesse principale de la vidéo,
   - cinq angles de miniature distincts, chacun avec : texte court à incruster (2 à 4 mots, en français), description de la scène, expression du visage souhaitée, angle de référence à utiliser (face / droite / gauche), prompt image complet.
4. Les cinq prompts sont envoyés à la génération d'images.

Le créateur peut éditer un prompt avant de relancer la génération de la proposition correspondante.

### 4.5 Génération de miniatures

- Modèle Gemini image configurable (`GEMINI_IMAGE_MODEL`). Un modèle acceptant plusieurs images de référence est requis.
- Entrées : prompt de l'angle, les photos de référence du créateur (toutes, le prompt précise l'angle voulu), les consignes de style, et pour une itération l'image précédente.
- Sortie : image 16:9, redimensionnée et encodée en PNG 1280×720 côté serveur, poids vérifié sous 2 Mo (limite YouTube).
- 5 propositions par analyse, générées en parallèle par des messages Messenger distincts, affichées au fur et à mesure via Turbo Streams ou Live Component en polling.
- **Itération** : sur une proposition, champ « Modifier cette miniature » avec instruction libre. Nouvelle génération avec l'image comme référence, conservée en tant que version enfant. L'historique des versions est conservé.
- **Comparaison** : vue côte à côte avec la miniature actuelle, et prévisualisation dans un faux flux YouTube (carte mobile, carte desktop, suggestion latérale) pour juger la lisibilité du texte à petite taille.
- **Export** : téléchargement du PNG.
- **Stockage** : toutes les images générées, les photos de référence et les miniatures archivées sont stockées sur un volume Docker, référencées en base.

### 4.6 Application et retour arrière

- Bouton « Appliquer sur YouTube » sur une proposition.
- Avant l'écriture : téléchargement de la miniature actuelle en meilleure résolution disponible (`maxres`, sinon `high`) et archivage.
- `thumbnails.set` (50 unités) avec le PNG généré.
- Enregistrement d'une entité « Relance » : vidéo, miniature précédente, miniature appliquée, date, snapshot des stats des 28 jours précédents.
- Bouton « Revenir à l'ancienne miniature » sur une relance : `thumbnails.set` avec l'image archivée, relance marquée « annulée ».
- Le titre n'est jamais modifié par l'outil.

### 4.7 Suivi et verdict

Pour chaque relance, à chaque synchro :

- Comparaison des moyennes journalières avant (28 jours précédant le changement) et après (jours écoulés depuis) pour : vues, impressions, CTR, durée moyenne de visionnage.
- Jalons à 14 et 28 jours. Un jalon est validé quand les données Analytics couvrent la période (compter 2 jours de latence).

**Verdict** (tranché, affiché en badge, seuils ajustables)

| Verdict | Règle par défaut |
|---|---|
| Relance réussie | Vues/jour ≥ 1,5 × avant et CTR ≥ avant |
| Effet neutre | Vues/jour entre 0,8 et 1,5 × avant |
| Relance négative | Vues/jour < 0,8 × avant ou CTR < 0,8 × avant |

- Verdict à 14 jours = provisoire, à 28 jours = définitif.
- En cas de verdict négatif, suggestion explicite de revenir à l'ancienne miniature.
- Onglet « Relances » listant toutes les relances avec leur verdict, pour capitaliser sur ce qui fonctionne.

### 4.8 Réglages

- Pondérations et seuils du scoring.
- Consignes de style de la chaîne (texte libre injecté dans les prompts).
- Photos de référence : ajout, suppression, étiquetage de l'angle.
- Modèles Gemini texte et image.
- Délai entre requêtes Analytics.
- Déconnexion et reconnexion de la chaîne YouTube.

### 4.9 Authentification

- Un seul mot de passe, `APP_PASSWORD` en variable d'environnement, comparé via hash.
- Session Symfony classique, formulaire de connexion, protection CSRF, limitation de tentatives (rate limiter Symfony).
- Toutes les routes sauf le login sont protégées.

## 5. Contraintes des API Google

| API | Coût | Remarques |
|---|---|---|
| Data API `playlistItems.list` | 1 unité / page de 50 | Synchro |
| Data API `videos.list` | 1 unité / lot de 50 | Synchro |
| Data API `captions.list` | 50 unités | Analyse |
| Data API `captions.download` | 200 unités | Analyse, mis en cache |
| Data API `thumbnails.set` | 50 unités | Application, retour arrière |
| Analytics API `reports.query` | Pas d'unités, rate limit | Une requête par vidéo et par synchro |
| Gemini | Facturé par Google selon le modèle | Hors quota YouTube, mais suivi du nombre d'appels affiché |

Ordre de grandeur pour une session : une synchro (≈ 20 unités), trois analyses (≈ 750 unités), trois applications (150 unités). Le quota gratuit de 10 000 unités par jour n'est pas une contrainte en usage normal ; le compteur sert surtout à éviter les surprises lors d'une première synchro ou d'analyses en rafale.

Contrainte structurelle : les données Analytics ont 48 à 72 heures de retard. L'interface indique toujours la date de la dernière donnée disponible.

## 6. Architecture technique

### 6.1 Stack

| Couche | Choix |
|---|---|
| Framework | Symfony (dernière LTS ou stable au démarrage du projet), PHP 8.3+ |
| Interface | Twig, Symfony UX : Live Components, Turbo, Stimulus |
| Composants UI | UX Toolkit, kit shadcn (`ux:toolkit:install-kit shadcn`) |
| CSS | Tailwind via `symfonycasts/tailwind-bundle` |
| IA | Symfony AI (Platform + Agent) avec le bridge Gemini pour le texte ; génération d'images via le même bridge si supporté, sinon client HTTP dédié sur l'API Gemini REST (à valider en phase de spike) |
| Base de données | PostgreSQL 16, Doctrine ORM, migrations |
| Asynchrone | Symfony Messenger, transport Doctrine sur PostgreSQL (LISTEN/NOTIFY) |
| Serveur | FrankenPHP, mode worker, HTTPS automatique via Caddy intégré |
| Conteneurs | Docker Compose : `app` (FrankenPHP), `worker` (même image, commande `messenger:consume`), `database` (PostgreSQL) |
| Stockage fichiers | Volume Docker monté sur `var/storage`, accès via Flysystem local |
| Google | `google/apiclient` ou client HTTP Symfony avec les endpoints REST, à trancher en spike |

### 6.2 Découpage applicatif

```
src/
  YouTube/        connexion OAuth, clients Data API et Analytics API, compteur de quota
  Catalog/        entités Video, DailyStat, synchro, exclusions
  Scoring/        calcul des signaux, pondérations, score
  Analysis/       transcript, appel Gemini texte, angles de miniature
  Thumbnail/      génération d'images, versions, export, prévisualisation
  Relaunch/       application, archivage, retour arrière, suivi, verdict
  Settings/       réglages, photos de référence
  Security/       mot de passe unique
```

Chaque opération longue est un message Messenger : `SyncCatalog`, `AnalyzeVideo`, `GenerateThumbnail`, `IterateThumbnail`, `ApplyThumbnail`, `RevertThumbnail`. L'interface suit leur avancement via une entité `Job` mise à jour par les handlers et lue par un Live Component.

### 6.3 Modèle de données (principales entités)

- **Channel** : id YouTube, titre, refresh token chiffré, date de dernière synchro.
- **Video** : id YouTube, titre, description, date de publication, durée, type (standard / short / live), visibilité, url miniature actuelle, vues cumulées, score, détail des signaux, date de dernière analyse.
- **DailyStat** : vidéo, date, vues, minutes visionnées, durée moyenne, pourcentage moyen, impressions, CTR.
- **Transcript** : vidéo, langue, source (manuel / auto), texte, date.
- **Analysis** : vidéo, résumé, promesse, JSON des cinq angles, date, modèle utilisé.
- **ThumbnailProposal** : analyse, index d'angle, prompt, image, parent (pour les itérations), instruction d'itération, date, modèle utilisé.
- **ReferencePhoto** : fichier, angle (face / droite / gauche / autre), étiquette.
- **Relaunch** : vidéo, proposition appliquée, miniature archivée, date d'application, snapshot avant, statut (en cours / annulée / terminée), verdicts à 14 et 28 jours.
- **QuotaUsage** : date, endpoint, coût.
- **Job** : type, statut, progression, message d'erreur, dates.
- **Setting** : clé, valeur (pondérations, consignes, modèles, délais).

### 6.4 Configuration (variables d'environnement)

```
APP_PASSWORD=...
APP_SECRET=...
SERVER_NAME=youtubeboost.example.com     # FrankenPHP / Caddy, HTTPS automatique
DATABASE_URL=postgresql://...
MESSENGER_TRANSPORT_DSN=doctrine://default
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://youtubeboost.example.com/oauth/callback
GEMINI_API_KEY=...
GEMINI_TEXT_MODEL=...                    # valeur par défaut fournie dans .env
GEMINI_IMAGE_MODEL=...                   # modèle acceptant plusieurs images de référence
```

Pour un usage sans nom de domaine, `SERVER_NAME=localhost` fait générer par Caddy un certificat local auto-signé.

### 6.5 Déploiement

```
git clone ... && cd YoutubeBoost
cp .env.example .env      # renseigner les variables
docker compose up -d
```

Le conteneur `app` exécute les migrations au démarrage. Le README couvre : création du projet Google Cloud, activation des deux API YouTube, création des identifiants OAuth avec l'URI de redirection, passage de l'application en statut « En production », obtention de la clé Gemini.

## 7. Interface

Pages :

1. **Connexion** : mot de passe.
2. **Onboarding** : assistant en trois étapes (Google, photos de référence, consignes de style), affiché tant qu'il n'est pas complété.
3. **Catalogue** : tableau des vidéos classées par score, miniature, âge, signaux, statut ; bouton de synchro ; compteur de quota ; filtres.
4. **Vidéo** : stats et courbe de vues sur toute la vie de la vidéo, détail du score, transcript replié, analyse, grille des cinq propositions avec itération, comparaison et prévisualisation flux, bouton d'application.
5. **Relances** : liste des relances avec courbes avant / après et verdicts.
6. **Réglages** : pondérations, consignes, photos, modèles, connexion YouTube.

Principes : interface en français, composants shadcn du UX Toolkit, mises à jour en temps réel des jobs via Live Components, aucune action coûteuse sans clic explicite, coût en unités affiché sur chaque bouton qui consomme du quota.

## 8. Critères de succès de la V1

- Un créateur installe l'outil et connecte sa chaîne en moins de 30 minutes en suivant le README.
- Une synchro complète de 300 vidéos aboutit sans dépasser 100 unités de quota.
- L'analyse d'une vidéo produit cinq miniatures exploitables en moins de 3 minutes.
- Le visage du créateur est reconnaissable sur les miniatures générées.
- Une relance appliquée depuis l'outil est visible sur YouTube immédiatement et un verdict est produit à 14 jours sans intervention.
- Le retour arrière restaure exactement l'ancienne miniature.

## 9. Risques et points à valider en spike

| Risque | Mitigation |
|---|---|
| Symfony AI ne couvre pas la génération d'images Gemini avec références multiples | Client HTTP dédié sur l'API REST Gemini, isolé derrière une interface `ImageGenerator` |
| Détection des Shorts approximative via l'API | Heuristique documentée, possibilité de forcer le type d'une vidéo à la main |
| Impressions et CTR absents sur les vidéos très anciennes | Signaux manquants neutralisés dans le score, indiqué dans l'interface |
| Refresh token expiré (application OAuth en test) | Avertissement dans le README et détection dans l'interface avec bouton de reconnexion |
| Piste de sous-titres automatique non téléchargeable pour certaines vidéos | Analyse possible sans transcript, à partir du titre et de la description, avec avertissement |
| Rendu du texte français par le modèle image | Texte court (2 à 4 mots) et itération facile ; le créateur garde l'export pour retoucher |

## 10. Évolutions envisagées après la V1

- Propositions de titres, avec suivi séparé.
- Notifications quand un verdict tombe ou qu'une vidéo dépasse un seuil de score.
- Synchro planifiée avec Symfony Scheduler.
- Multi-chaînes.
- Autres fournisseurs d'IA derrière les interfaces `TextAnalyzer` et `ImageGenerator`.
- Apprentissage à partir des relances réussies pour orienter les prompts.

## 11. Décisions prises pendant le cadrage

| Sujet | Décision |
|---|---|
| Déploiement | Self-hosted, mono-utilisateur, Docker Compose, en continu sur un serveur |
| Scoring | Automatique, critères pondérés, pondérations modifiables |
| Fonctionnalités V1 | Miniatures uniquement ; titres retirés du périmètre |
| Photos de référence | Plusieurs angles : face, droite, gauche |
| IA | Gemini pour le texte et l'image, aucune autre option |
| Base de données | PostgreSQL (SQLite écarté au profit du transport Messenger sur Postgres) |
| Serveur | FrankenPHP, HTTPS direct, pas de reverse proxy |
| Sécurité | Mot de passe unique en variable d'environnement |
| Notifications | Aucune |
| Catalogue | Environ 300 vidéos, Shorts et lives exclus, historique complet |
| Synchronisation | Manuelle, en deux niveaux : stats de tout le catalogue à bas coût, analyse lourde ciblée |
| Génération | 5 miniatures, texte en français incrusté, itération par instruction, comparaison, export, stockage interne |
| Application | Miniature seule, ancienne sauvegardée, retour arrière en un clic |
| Suivi | 14 et 28 jours, verdict tranché |
| Identité | YoutubeBoost, MIT, interface en français, README pour un créateur qui sait lancer Docker Compose |
