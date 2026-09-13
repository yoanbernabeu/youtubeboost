# Décisions techniques

Notes issues du cadrage technique (recherche documentaire, septembre 2026). Elles
complètent le PRD et documentent les écarts assumés.

## Versions retenues

| Brique | Version |
|---|---|
| Symfony | 8.1 (dernière stable, PHP >= 8.4.1) |
| Doctrine ORM / DBAL | 3.7 / 4.4 |
| PHPUnit | 13 |
| Symfony UX | 3.4 |
| UX Toolkit | 3.4 (expérimental) |
| Tailwind | 4.x via `symfonycasts/tailwind-bundle` 1.0 |
| PostgreSQL | 16 |
| FrankenPHP | image `dunglas/frankenphp` PHP 8.4 |

## Écart n°1 — impressions et CTR : API Reporting, pas API Analytics

Le PRD prévoyait de récupérer `impressions` et `impressionClickThroughRate` via
`reports.query` de l'API YouTube Analytics. **Ces métriques n'existent pas dans
cette API** :

- `impressions` a été renommée `adImpressions` et désigne les impressions
  publicitaires (métrique monétaire, scope `yt-analytics-monetary.readonly`).
- `impressionClickThroughRate` n'existe nulle part dans la référence des métriques.
- Les impressions de miniature et leur CTR, visibles dans YouTube Studio, sont
  exposées depuis janvier 2026 par l'**API YouTube Reporting** via les rapports
  `channel_reach_basic_a1` et `channel_reach_combined_a1`, avec les métriques
  `video_thumbnail_impressions` et `video_thumbnail_impressions_ctr`.

Conséquences assumées :

1. Deux sources de données au lieu d'une. L'API Analytics fournit les vues, le
   temps de visionnage et la rétention. L'API Reporting fournit les impressions
   et le CTR, sous forme de rapports CSV quotidiens à télécharger.
2. Un **job de reporting doit être créé le plus tôt possible** : les rapports ne
   commencent à s'accumuler qu'à partir de sa création, et aucune donnée
   n'existe avant janvier 2026. L'onboarding crée donc le job immédiatement.
3. L'historique d'impressions est nécessairement court. Les signaux « CTR faible »
   et « impressions encore présentes » sont donc neutralisés (valeur nulle, poids
   retiré du dénominateur) tant que les données manquent, ce que le PRD prévoyait
   déjà comme tolérance.

## Écart n°2 — détection des Shorts

Aucun champ de l'API Data n'identifie un Short. La source fiable est la dimension
`creatorContentType` de l'API Analytics (valeurs `SHORTS`, `VIDEO_ON_DEMAND`,
`LIVE_STREAM`), disponible depuis 2019. La détection se fait donc en cascade :

1. `creatorContentType` via l'API Analytics (source `analytics`) ;
2. heuristique structurelle pour les vidéos sans données (durée <= 180 s, absence
   de `liveStreamingDetails`, `#shorts` dans le titre ou la description), source
   `heuristic` ;
3. forçage manuel depuis l'interface, source `manual`, qui prime sur tout.

La durée maximale d'un Short est de **180 secondes** depuis octobre 2024, pas 60.

## Écart n°3 — transcript automatique : hypothèse démentie par la mesure

Cette section affirmait auparavant que `captions.download` refusait les pistes
`trackKind=ASR` à une application tierce, même avec le jeton du propriétaire.
C'était une hypothèse reprise du folklore, jamais vérifiée sur la chaîne — et
elle est **fausse**.

Mesuré le 13 septembre 2026 sur deux vidéos publiques dont le
`contentDetails.caption` valait `false` : `captions.list` renvoie bien la piste
`asr`, et `captions.download` renvoie le WebVTT complet (762 ko pour 80 minutes,
290 ko pour 33 minutes). La doc de `captions.download` ne documente d'ailleurs
aucune restriction propre à l'ASR : son seul critère est le droit d'édition sur
la vidéo, et sa table d'erreurs ne contient que `forbidden` (403),
`couldNotConvert` (400) et `captionNotFound` (404).

La cascade reste la bonne architecture, mais pour une raison de **qualité** et
non de permission : une piste manuelle est ponctuée et correctement orthographiée,
une piste automatique ne l'est pas. Ordre de préférence : manuelle dans la langue
de la chaîne, manuelle dans une autre langue, automatique dans la langue, puis
automatique. Le repli sur titre + description seuls ne concerne plus que les
vidéos sans aucune piste.

## `contentDetails.caption` n'est pas un signal d'absence

Sur les 382 vidéos de la chaîne, ce champ vaut `true` pour **4** — exactement
celles où des sous-titres ont été déposés à la main en 2023. Il est `false` sur
les 378 autres, qui ont toutes une piste automatique téléchargeable.

Le code s'en servait comme veto pour éviter de payer `captions.list` :
conséquence, l'application renonçait sans rien demander sur 99 % du catalogue et
affichait « Cette vidéo n'a aucune piste de sous-titres », ce qui était faux. Le
veto est supprimé. Ce champ reste synchronisé mais **aucun code ne le lit** : au
mieux il signale une piste manuelle, ce que le classement des pistes découvre de
toute façon.

## Longueur du transcript envoyé à Gemini

Le plafond de `TranscriptDownloader::MAX_CHARACTERS` valait 40 000 caractères
depuis le premier commit, sans justification : ni le PRD ni aucune contrainte de
l'API ne l'imposaient. Mesure faite sur la chaîne : une piste automatique produit
**environ 1 000 caractères par minute** de parole. Le plafond coupait donc au-delà
de 40 minutes, et **69 vidéos publiques** perdaient silencieusement leur fin —
c'est-à-dire la conclusion et l'appel à l'action, précisément ce qui sert à
conseiller une relance.

Le plafond est porté à **120 000 caractères**, soit près de deux heures de parole
et environ 30 000 tokens : négligeable pour un modèle dont le contexte est d'un
million, et payé une seule fois par vidéo grâce au cache.

Quand un transcript dépasse malgré tout ce budget, `SubtitleParser` élide son
**milieu** et non sa fin : deux tiers du budget pour l'ouverture, un tiers pour la
conclusion, avec un marqueur explicite entre les deux.

## Coûts de quota et conséquences

`captions.list` coûte 50 unités et `captions.download` 200, soit **250 unités par
vidéo transcrite**. La synchronisation ne liste donc **jamais** les pistes : elles
ne sont demandées qu'au moment d'analyser une vidéo précise, et `TranscriptProvider`
met le résultat en cache — les échecs compris — pour ne payer qu'une fois.

Transcrire les 373 vidéos publiques coûterait 93 250 unités, soit plus de neuf
jours de quota. C'est la raison pour laquelle l'analyse reste déclenchée vidéo par
vidéo, jamais en masse.

`search.list` (100 unités) n'est jamais utilisé : le catalogue est parcouru via la
playlist « uploads » (1 unité par page de 50).

## Autres points retenus

- Les scopes OAuth minimaux sont `youtube.force-ssl` et `yt-analytics.readonly` ;
  `youtube.readonly` est redondant mais demandé par le PRD, il reste dans la liste.
- Les jours sans activité sont absents des réponses Analytics : la série
  quotidienne est densifiée côté application (jour manquant = zéro vue).
- `endDate` est silencieusement tronquée à la dernière journée entièrement
  traitée. La date réellement couverte est lue dans la réponse, jamais supposée.
- Les colonnes d'une réponse Analytics sont lues par `columnHeaders[].name`,
  jamais par position.
- Les sept derniers jours sont redemandés à chaque synchronisation, pour capter
  les révisions tardives.
- Symfony Turbo ne diffuse des streams asynchrones qu'avec un hub Mercure, hors
  périmètre. Le suivi des jobs utilise donc des Live Components en polling.
- Symfony 8.1 gère nativement le mode worker FrankenPHP : ni `APP_RUNTIME` ni
  `runtime/frankenphp-symfony` ne sont nécessaires.

## Écart n°4 — syntaxe du ratio d'image chez Gemini

Deux orthographes circulent pour le format de l'image :
`generationConfig.imageConfig` et `generationConfig.responseFormat.image`. Plutôt
que de parier sur l'une des deux, le générateur les essaie dans l'ordre et
**mémorise dans les réglages celle que l'API du créateur accepte**. Une erreur de
quota ou de facturation n'est jamais confondue avec une erreur de syntaxe : elle
remonte telle quelle.

Les deux champs existent sur `v1beta`, mais **ils n'attendent pas le même
vocabulaire**, ce qui a été vérifié en sondant l'API :

| Champ | Valeurs attendues |
| --- | --- |
| `generationConfig.imageConfig` | `"16:9"`, `"1K"` |
| `generationConfig.responseFormat.image` | `"ASPECT_RATIO_SIXTEEN_BY_NINE"`, `"IMAGE_SIZE_ONE_K"` |

`responseFormat.image` type son ratio et sa taille en énumérations protobuf
(`ImageResponseFormat.AspectRatio`, `ImageResponseFormat.ImageSize`) dont les
membres écrivent les chiffres **en lettres**. Envoyer `"16:9"` dans ce champ est
refusé sèchement, et c'était le défaut : les deux branches recevaient les deux
mêmes chaînes, donc la branche `responseFormat` ne pouvait jamais aboutir. Les deux
orthographes voyagent désormais ensemble dans `ImageShape`, ce qui rend
structurellement impossible d'envoyer à un champ le vocabulaire de l'autre.

La documentation publique de Google n'aide pas ici : la page « Image generation »
ne documente plus `generateContent` mais la nouvelle API Interactions, dont la
forme `{"type": "image", "aspect_ratio": "16:9"}` est une troisième syntaxe encore
absente des documents de découverte `v1`, `v1beta` et `v1alpha`. Les valeurs
`"16:9"` qu'on y lit sont réelles, mais pour une autre surface — d'où le piège.
La référence utilisée est donc le document de découverte lui-même
(`https://generativelanguage.googleapis.com/$discovery/rest?version=v1beta`),
recoupé avec les SDK `js-genai` et `python-genai`, puis confirmé par sondage.

`imageConfig` reste essayé en premier : c'est la syntaxe la plus simple et celle
qui répond aujourd'hui.

Conséquence sur la détection : le refus ne porte pas sur le **nom** du champ mais
sur sa **valeur**, avec le libellé `Invalid value at 'generation_config…'`. Ne
traiter comme « mauvaise syntaxe » que les erreurs de champ inconnu laissait donc
ce cas remonter à l'écran sans jamais essayer l'orthographe suivante. Une valeur
refusée est désormais considérée comme une erreur de syntaxe **à condition qu'elle
désigne un champ de la configuration d'image** : un `responseModalities` refusé
reste un bug du code et remonte immédiatement, plutôt que d'être masqué par trois
tentatives inutiles.

Le modèle rend une image légèrement plus large que 16:9 — 1376×768 observé pour
`imageSize: "1K"` —, que `ImageNormalizer` ramène à 1280×720 par recadrage centré.
Attention au modèle choisi : `gemini-3.1-flash-lite-image` n'accepte que `1K`,
là où `gemini-3.1-flash-image` accepte aussi `2K` et `4K`.

## Ce que la première connexion réelle a appris

Une synchronisation complète contre la vraie chaîne (93 vidéos) a validé la
chaîne de bout en bout et corrigé trois hypothèses :

- Les identifiants YouTube sont en **base64url** : ils contiennent des
  underscores et des tirets. `Requirement::ASCII_SLUG` les refuse. Le motif est
  désormais porté par `Video::ID_PATTERN`, et les fixtures de test utilisent un
  identifiant réaliste plutôt qu'un slug, faute de quoi le bug reste invisible.
- La détection des Shorts par `creatorContentType` fonctionne : 30 vidéos
  longues, 60 Shorts et 3 lives correctement séparés sans intervention.
- Le coût réel d'une synchronisation de 93 vidéos est de **10 unités de quota**,
  très en dessous des 100 unités visées par le PRD pour 300 vidéos.

Un score n'est marqué fiable que sur la présence du **déclin**, et non plus du
déclin et du CTR. Les rapports d'impressions n'existent qu'à partir de la
création du job de reporting : exiger le CTR marquait 100 % des vidéos d'une
instance neuve comme incomplètes pendant des semaines, ce qui transformait
l'avertissement en bruit permanent. L'attente est désormais expliquée une fois,
sur le catalogue, avec la date à partir de laquelle les données s'accumulent.

## Choix d'implémentation notables

**Aucun nom propre dans un prompt d'image.** Gemini abandonne l'image entière —
`finishReason: IMAGE_OTHER`, pas une seule partie dans la réponse — lorsque le
prompt nomme la personne, y compris quand cette personne est le créateur qui
génère son propre visage à partir de ses propres photos. Mesuré : le même prompt
échoue trois fois sur trois avec le nom, et rend une image sans lui, sur deux
prompts différents dont un qui avait « réussi » par chance. L'identité passe donc
uniquement par les photos de référence, ce qui est le mécanisme prévu pour cela.
C'est aussi pourquoi des miniatures échouaient par deux ou trois sur cinq sans
raison apparente : le filtre est à la limite, et le nom fait basculer.

**Catalogue de modèles tolérant.** `symfony/ai-gemini-platform` embarque une liste
de modèles codée en dur, et `Provider::supports()` interprète un modèle absent de
cette liste comme « cette plateforme ne sait pas le servir » — ce qui remonte sous
la forme `No provider found for model "…"`. Google publie des modèles plus vite que
la bibliothèque ne sort des versions : `gemini-3.8-flash` répondait parfaitement à
l'API alors que la v0.13.0, la plus récente publiée, s'arrête à `gemini-3.7-flash`.
`TolerantGeminiModelCatalog` décore le catalogue du bundle et suppose existant tout
nom commençant par `gemini-`. Si le modèle n'existe pas, c'est Google qui le dit, et
c'est lui l'autorité sur la question. La tolérance s'arrête à la famille Gemini :
un nom d'un autre fournisseur reste refusé, puisque c'est une erreur de saisie.

**Un job doit toujours finir.** L'étape affichée (`step`) cite souvent la raison
d'un échec, et un fournisseur peut être très bavard : 392 caractères observés pour
une colonne de 255. Le dépassement fait échouer le flush, ferme le gestionnaire
d'entités, et le job ne peut alors même plus être marqué en échec — il reste
« en cours » pour toujours et l'interface interroge une ligne qui ne bougera
jamais. L'entité borne donc `step` elle-même, à chaque transition, comme elle
bornait déjà le message d'erreur.

**Mot de passe unique.** Le PRD demande `APP_PASSWORD` en variable
d'environnement, comparé via hash. L'application utilise le fournisseur
`memory` de Symfony avec le hasher `plaintext`, dont la comparaison passe par
`hash_equals` : constante en temps, et sans payer un bcrypt à chaque requête
d'un worker de longue durée, ce qui serait le cas avec un hash calculé à la
volée.

**Images hors de `public/`.** Miniatures générées, photos de référence et
miniatures archivées sont servies par un contrôleur, donc protégées par le
pare-feu comme le reste. Elles vivent dans le volume monté sur
`%kernel.share_dir%`, c'est-à-dire `var/share/<env>`.

**Normalisation des images en GD.** Le mode worker de FrankenPHP documente
ImageMagick comme instable (ses threads OpenMP entrent en conflit avec ceux du
serveur) et php-vips passe par FFI, pire encore. Pour un simple recadrage vers
1280 × 720, les trois donnent le même résultat visuel.

**Recadrage plutôt que redimensionnement.** Gemini rend du 1344 × 768 en 16:9,
qui n'est pas exactement le ratio de 1280 × 720 : un redimensionnement simple
déformerait de 1,6 %. L'image est donc mise à l'échelle pour couvrir la cible,
puis le centre est découpé.

**Deux passes pour le score.** Les médianes de la chaîne ne peuvent être connues
qu'après avoir regardé toutes les vidéos. Les historiques sont donc lus vidéo par
vidéo, en flux, pour qu'un catalogue de plusieurs centaines de milliers de lignes
quotidiennes ne tienne jamais entièrement en mémoire.

**Écritures en masse en DBAL.** Les statistiques quotidiennes sont écrites par
lots avec `INSERT ... ON CONFLICT DO UPDATE` sur la clé primaire composite
`(video_id, stat_date)`, sans hydrater d'entités. Les métriques Analytics et les
métriques de portée mettent à jour des colonnes disjointes : l'un n'écrase jamais
l'autre, quel que soit l'ordre d'arrivée.

**Suivi des jobs en polling.** Les Turbo Streams asynchrones exigent un hub
Mercure, hors périmètre. Les Live Components interrogent le serveur tant qu'un
job tourne, et le rendu qui le marque terminé retire l'attribut de polling, ce
qui coupe le trafic.

## Internationalisation

**La langue est un réglage, pas un préfixe d'URL.** Les routes localisées
(`/fr/reglages`, `/en/settings`) servent à faire indexer deux versions d'un site
par un moteur de recherche. Ici il y a un seul utilisateur, derrière un mot de
passe, sur sa propre machine : le préfixe n'aurait rien apporté et aurait doublé
la table de routage. Le choix vit dans `SettingKey::Locale`, un
`LocaleListener` le pose sur chaque requête, et le sélecteur de la barre latérale
écrit le réglage puis renvoie sur la page d'où l'on vient.

**L'anglais est la langue par défaut, pas la langue de repli d'un projet
français.** `framework.default_locale` vaut `en` et `fallbacks` vaut `[en]` : une
clé oubliée en français s'affiche en anglais plutôt que de montrer son
identifiant à l'écran. Les deux fichiers `translations/messages.{en,fr}.yaml`
sont des traductions complètes l'une de l'autre ; le script de vérification de la
suite échoue si une clé n'existe que d'un côté.

**Le worker a besoin qu'on lui donne la langue.** Un handler Messenger n'a pas de
requête d'où tirer une locale, et il écrit pourtant du texte que le créateur lit :
l'étape d'un job en cours, le message d'un échec. `WorkerLocaleListener` lit le
réglage à la réception de chaque message et le passe au `LocaleSwitcher`.

**Les étapes et les erreurs de job sont traduites à l'écriture, pas à
l'affichage.** La colonne stocke la phrase finie, dans la langue en vigueur au
moment où le travail a tourné. Stocker la clé et ses paramètres aurait permis de
relire un vieux job dans une autre langue — au prix d'une colonne JSON et d'une
migration, pour un historique que personne ne relit dans l'autre langue. Les
exceptions qui remontent à l'écran implémentent `TranslatableThrowable` : leur
`getMessage()` reste en anglais pour le journal, et `translatableMessage()`
fournit la version destinée au créateur.

**Les prompts Gemini suivent la langue de l'interface.** Les instructions
adressées au modèle restent en anglais, parce que c'est ce qu'il suit le mieux,
mais le texte destiné à l'audience — les deux à quatre mots incrustés sur la
miniature — est demandé dans la langue de l'application. `ContentLanguage` en
donne le nom anglais (« French »), qui est la forme qu'un modèle comprend.
Conséquence assumée : changer la langue de l'interface change la langue des
miniatures générées ensuite, pas celle des propositions déjà produites.
