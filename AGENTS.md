# AGENTS.md

This is a Symfony project. Check `composer.json` for the exact Symfony/PHP version
in use, and read `symfony.lock` to see which recipes ran. Don't assume Doctrine,
Twig, API Platform, Messenger, or Lock are installed unless one of those says so.

## Ask before generating

If the task doesn't specify, ask rather than guess:

- Persistence: Doctrine ORM, Doctrine ODM, or none?
- Interface: server-rendered (Twig), API (Serializer, maybe API Platform), or both?
- Auth: SecurityBundle, and which authenticator?

If you can't ask (no interactive channel), state the assumption you're making and
pick the smallest option (e.g. no persistence layer) rather than scaffolding a
full stack nobody asked for.

## Adding features: Flex, not hand-wiring

Install new capabilities with `composer require <package>` (e.g. `symfony/lock`,
`symfony/messenger`, `orm-pack`) and let the Flex recipe register the bundle and
generate its config. Don't hand-edit `config/bundles.php` or hand-write a bundle's
base config; that's what the recipe is for. Don't skip a good-fit component just
because it isn't installed yet; installing it is one command.

## Conventions

Follow https://symfony.com/doc/current/best_practices.html to write idiomatic
Symfony:

- Use PHP attributes for framework metadata, and not only on controllers:
  `#[Route]`, `#[MapRequestPayload]`, `#[IsGranted]` on actions, `#[Assert\...]`
  on properties, `#[AsCommand]`, `#[AsEventListener]`, `#[AsMessageHandler]`, and
  `#[AsAlias]` / `#[AsTaggedItem]` / `#[Autoconfigure]` on services. No YAML or
  XML routing.
- Rely on autowiring and autoconfiguration. Type-hint constructor arguments and
  let the container resolve them. Where a type-hint can't express it, stay in the
  class with `#[Autowire]` (parameters, env vars, expressions) or `#[Target]` (one
  of several implementations of an interface). A YAML service definition is the
  last resort, not the first.
- Controllers extend `AbstractController`, stay thin, and delegate to services.
- Use the framework for what it already does: Form for server-rendered forms,
  Validator for validation, Serializer for JSON, Messenger for async work,
  Security (voters, authenticators) for access control, Twig `path()`/`url()`
  instead of hardcoded URLs.
- Before hand-writing infrastructure (locks, queues, caches, HTTP clients,
  mailers, schedulers) or reaching for a third-party library, check whether a
  Symfony component covers it. It usually does.

Three specifics worth spelling out, because they are easy to get wrong:

- Bind request data with `#[MapRequestPayload]` / `#[MapQueryString]` on action
  arguments, which wires up Serializer and Validator for you, instead of calling
  `json_decode()` or `SerializerInterface` by hand. If neither package is
  installed yet, `composer require` them rather than falling back to manual
  parsing.
- Use constructor property promotion, and `readonly` for DTOs and value objects.
  Don't mark a service `readonly` if it might become `lazy: true`: a lazy proxy
  can't extend a `readonly` class.
- Use `symfony/lock` (`LockFactory`) for mutual exclusion. A hand-built flag or
  lock file looks fine in review and is usually wrong under concurrency.

## Everyday workflow

- Run the app with `symfony serve -d`, and commands with `symfony console ...`
  (or `bin/console` when the Symfony CLI isn't available).
- When something fails, read `var/log/dev.log` and the web profiler
  (`/_profiler`) before changing code.
- If `maker-bundle` is installed, prefer `bin/console make:*` with every argument
  passed up front and `--no-interaction` where supported: makers prompt on a
  terminal by default, which hangs a non-interactive shell. If a maker still
  needs interactive input, hand-write the code instead.
- If Doctrine ORM is installed, schema changes go through migrations
  (`bin/console make:migration`, then `doctrine:migrations:migrate`), never
  `doctrine:schema:update` or hand-written SQL.
- `.env` is committed and holds defaults only. Real secrets belong in `.env.local`
  (git-ignored) or the secrets vault (`bin/console secrets:set`), read via
  `%env(...)%`.

## Testing

Install `symfony/test-pack` if it isn't already. Functional/HTTP tests extend
`WebTestCase`; service-level tests extend `KernelTestCase`. Run
`php bin/phpunit` (falls back to `vendor/bin/phpunit`). A feature isn't done
until it has a test that exercises it the way a caller would, an HTTP request for
a controller or a service call for a service, not just "it didn't throw."

## Code style

Symfony's coding standard, the `@Symfony` php-cs-fixer ruleset (a PSR-12-derived
superset). Run `vendor/bin/php-cs-fixer fix` if `friendsofphp/php-cs-fixer` is
installed; it isn't part of the skeleton by default.

## Discover, don't guess

Framework APIs change between versions and your training data may be stale. Look
things up in the project instead of relying on memory:

- `bin/console about`: versions, environment, paths.
- `bin/console debug:router`, `debug:container`, `debug:autowiring <name>`,
  `debug:config <bundle>`, `config:dump-reference <bundle>`: what exists and how
  it is configured.
- `bin/console lint:container`, plus `lint:twig templates/` and
  `lint:yaml config/` where those packages are installed: validate before running.
- Read the installed source and docblocks under `vendor/`.
- Docs: https://symfony.com/doc/current/ (switch to the version matching
  `composer.json` if it differs).

---

# YoutubeBoost

Ce fichier complète les conventions Symfony ci-dessus avec ce qui est propre à ce
projet.

## Boucle de retour : `make check`

Une seule commande dit si le travail tient :

```bash
make check
```

Elle enchaîne, dans cet ordre, en s'arrêtant au premier échec :

| Étape | Ce qu'elle prouve |
|---|---|
| `make cs` | Le style `@Symfony` est respecté. |
| `make stan` | PHPStan au niveau maximum ne trouve ni erreur de type ni erreur de logique. |
| `make lint` | Gabarits, YAML et conteneur de services sont valides. |
| `make lsp` | Symfony Language Tools résout chaque route, service, paramètre, gabarit, composant Twig et **clé de traduction**. |
| `make test` | Toute la suite passe : unitaire, intégration, fonctionnel. |

**Un travail n'est pas fini tant que `make check` n'est pas vert.** Ne dis pas
qu'une tâche est terminée sur la foi d'une relecture : lance la commande, lis la
sortie, corrige, relance. `make cs-fix` règle le style tout seul.

Si tu n'as touché qu'à de la logique pure, `make test-unit` donne un retour en
deux secondes — mais il ne remplace pas `make check` avant de conclure.

`make check` ne touche jamais la base de développement. L'environnement de test
ajoute `_test` au nom de la base (`dbname_suffix` dans
`config/packages/doctrine.yaml`), et `make db-test` refuse de supprimer un schéma
dont la base ne finit pas par `_test`. Ne contourne ni l'un ni l'autre : la base
de développement contient le vrai catalogue du créateur et son historique de
relances.

## Langues

Le code, les noms de classes et les commentaires sont en **anglais**.

L'interface est traduite avec `symfony/translation`, domaine `messages`,
fichiers `translations/messages.{en,fr}.yaml`. L'anglais est la langue par
défaut, et le repli est l'anglais : une clé oubliée en français s'affiche en
anglais plutôt que de montrer son identifiant à l'écran.

Toute chaîne visible par l'utilisateur passe par une clé de traduction : jamais
de texte en dur dans un gabarit ou un contrôleur. En PHP, utilise
`TranslatableMessage` plutôt que d'injecter le traducteur dans un service métier.
Ajouter une chaîne, c'est l'ajouter dans les **deux** fichiers de langue :
`TranslationCatalogueTest` échoue si les deux fichiers divergent, et `make lsp`
échoue si un gabarit utilise une clé qui n'existe pas.

Le README est en anglais. `docs/PRD.md` et `docs/decisions-techniques.md` restent
en français : ce sont des documents de cadrage internes.

## Découpage

`src/` est découpé par domaine, pas par couche : `Analysis`, `Catalog`, `Job`,
`Relaunch`, `Scoring`, `Security`, `Settings`, `Shared`, `Thumbnail`, `YouTube`.
Les entités vivent dans le module qui les possède ; Doctrine scanne tout `src/`.

Les dépendances vont dans un seul sens : les modules métier dépendent de
`Settings` et de `Shared`, jamais l'inverse. Quand deux modules ont besoin de se
parler, c'est par une interface taguée (`PostSyncStepInterface`,
`SignalCalculatorInterface`) plutôt que par un appel direct.

## Règles de conception

- **Rien de coûteux dans une requête web.** Toute opération qui consomme du quota
  ou de l'argent passe par un message Messenger et un `Job` suivi à l'écran. Le
  coût en unités est affiché sur le bouton.
- **Un signal sans donnée est neutralisé, jamais compté comme zéro.** C'est ce qui
  permet à une vidéo ancienne, sans données d'impression, d'être classée
  équitablement.
- **Les valeurs venant de l'extérieur passent par `Shared\Type\Scalar`.** Les
  payloads JSON, les lignes SQL brutes et les corps de requête arrivent en
  `mixed` : la garde est explicite, pas un cast qui masque une hypothèse fausse.
- **Les fournisseurs externes sont derrière une interface.**
  `VideoAnalyzerInterface` et `ImageGeneratorInterface` isolent Gemini.
- **Les images ne sont jamais servies depuis `public/`.** Elles passent par
  `ImageController`, donc par le pare-feu.

## Tests

- `tests/Unit` : logique pure, aucune base de données, aucun réseau. Les clients
  HTTP sont testés avec `MockHttpClient`.
- `tests/Integration` : vrai PostgreSQL. C'est le seul endroit où le `ON CONFLICT`
  des écritures en masse et le fuseau du compteur de quota se vérifient.
- `tests/Functional` : requêtes HTTP réelles sur les six écrans.

Une fonctionnalité n'est pas finie tant qu'un test ne l'exerce pas comme le ferait
un appelant.

Les identifiants et les modèles sur lesquels les tests s'appuient sont ceux de
`.env.test`, et `tests/bootstrap.php` les réimpose par-dessus l'environnement :
Compose passe `.env.local` aux conteneurs via `env_file`, donc ses lignes deviennent
de vraies variables d'environnement, qui battent tout fichier `.env*`. Sans cela la
suite testerait la configuration personnelle du créateur et son résultat changerait
d'une machine à l'autre.

## Écarts assumés par rapport au PRD

Ils sont documentés dans `docs/decisions-techniques.md` : les impressions et le
CTR viennent de l'API Reporting et non de l'API Analytics, la détection des
Shorts s'appuie sur `creatorContentType`, et `contentDetails.caption` ne signale
que les sous-titres déposés à la main, jamais les pistes automatiques.
