# CLAUDE.md — Lettie

Lettie est un logiciel web de gestion du courrier entrant et sortant.

## Stack et contraintes (strictes)

- **PHP 8.2+**, sans framework. `declare(strict_types=1);` dans chaque fichier PHP.
- **MariaDB 10.11**, jeu de caractères `utf8mb4` / collation `utf8mb4_unicode_ci`.
- **PDO** uniquement, **requêtes préparées obligatoires** (jamais de concaténation de valeurs dans le SQL). `PDO::ATTR_ERRMODE => ERRMODE_EXCEPTION`, `ATTR_EMULATE_PREPARES => false`.
- **Pas d'ORM**, pas de query builder : SQL écrit à la main dans les Repositories.
- **Front-end** : JavaScript classique + **jQuery 3.7.1** (fichier local dans `public/`). Pas de TypeScript, pas de framework JS.
- **Aucun outil de build** (pas de Webpack, Vite, Sass, etc.). Les fichiers CSS/JS servis sont ceux écrits.
- **Aucune dépendance Composer ni npm en production.** Composer sert **uniquement** à l'autoload PSR-4 `App\` → `app/`. Ne jamais ajouter de paquet dans `require`. En développement seulement : PHPUnit (`require-dev`) et Playwright (`devDependencies` de `package.json`, jamais déployé).

## Commandes

| Commande | Effet |
|---|---|
| `composer test` | Suites PHPUnit `unit`, `integration` puis `functional` (`composer test:unit` etc. pour une seule) |
| `composer migrate` / `composer migrate:status` | Applique / liste les migrations (base du `.env`) |
| `composer demo:seed` (`:medium`, `:large`) | Recrée la base `lettie_demo` (petite : ~10 mois, moyenne : 2 ans, grande : ~100 000 courriers) et lance les contrôles ; options après `--` : `--seed=N`, `--skip-checks` |
| `npm test` | Tests `node:test` des modules JS purs (`tests/js/*.test.js`) |
| `npm run screenshots` | Base `lettie_demo` régénérée (`tools/demo/seed.php`), serveur PHP intégré, captures dans `docs/guide/screenshots/` |
| `npm run guide` | Captures + `docs/guide/index.html` + `docs/guide/Lettie-guide-utilisateur.pdf` (source : `tools/guide/guide.html`) |

- **Suites PHPUnit** : `unit` (sans base : `tests/Core`, `Domain`, `Middleware`, `Architecture`), `integration` (repositories et services, `tests/Integration`), `functional` (requêtes HTTP via le Kernel, `tests/Functional`, classe de base `Tests\Support\FunctionalTestCase`). Les deux dernières **recréent** la base `TEST_DB_NAME` (doit finir par `_test`).
- **Règles vérifiées par les tests** : la matrice d'accès (`tests/Functional/AccessMatrixTest.php`) doit lister toute nouvelle route GET ; `tests/Integration/RepositoryScopeTest.php` doit couvrir tout nouveau repository lié à un site.
- **Données de démo** : uniquement dans `lettie_demo` (nom obligatoirement en `_demo`), jamais dans la base du `.env` (le journal d'activité n'accepte aucune suppression). Proportions dans `tools/demo/profile.php` (à modifier là, pas dans le code). Simulation chronologique par les vrais services (`Tools\Demo\Generator`), cas limites planifiés et vérifiés (`EdgeCases`), historique en masse pour la grande taille (`BulkWriter`, même forme que les services), contrôles de fin (`Checks` : invariants bloquants, proportions et durées indicatives). Même graine, mêmes données.
- Les `<th>` des tableaux DataTables utilisent `data-lt-*` : DataTables lit les `data-*` simples comme options de colonne.

## Architecture en couches

```
Controllers → Services → Repositories → (base de données)
                  ↓
               Domain
```

| Couche | Rôle | Interdit |
|---|---|---|
| **Controllers** (`app/Controllers`) | Lire la requête HTTP, valider le format des entrées, appeler un Service, rendre une vue ou du JSON. | SQL, logique métier, transactions. |
| **Services** (`app/Services`) | Orchestrer un cas d'usage ; **seule couche qui ouvre/valide/annule les transactions**. | SQL direct, accès à `$_GET`/`$_POST`/`$_SESSION`, HTML. |
| **Repositories** (`app/Repositories`) | Tout le SQL (requêtes préparées), mapping lignes ↔ objets Domain. | Logique métier, transactions, appels à d'autres Repositories. |
| **Domain** (`app/Domain`) | Entités, value objects, énumérations, règles métier **pures** (statuts, transitions, validations). | Toute E/S : PDO, HTTP, session, fichiers, date système implicite. |

Règles :
- Les dépendances ne vont que vers le bas ; une couche n'appelle jamais une couche supérieure.
- Injection des dépendances par constructeur (pas de singletons ni de `global`, sauf le conteneur/bootstrap dans `app/Core`).
- Les erreurs métier sont des exceptions typées du Domain ; les Controllers les traduisent en messages utilisateur.

## Arborescence

```
app/
  Core/          # Noyau : routeur, requête/réponse, conteneur, PDO, transactions, session, CSRF, validateur, vues, i18n, migrations, helpers (e(), __())
  container.php  # Câblage des services applicatifs
  routes.php     # Déclaration des routes et de leurs middlewares
  Controllers/   # Contrôleurs HTTP
  Middleware/    # Middlewares de route (auth, guest, can:permission)
  Services/      # Cas d'usage + transactions
  Repositories/  # Accès SQL
  Domain/        # Entités, value objects, enums, règles métier pures
views/           # Templates PHP (layouts/, partials/, un dossier par écran)
lang/            # Traductions : fr.php, en.php (tableaux clé => texte)
database/
  migrations/    # Scripts SQL versionnés : NNN_description.sql (001_, 002_…), appliqués par bin/migrate.php
bin/             # Scripts CLI (migrations, maintenance)
tools/           # Outils de développement : demo/ (base de démo, routeur du serveur PHP), playwright/ (captures, guide), guide/ (source du guide)
docs/            # Documentation : déploiement Plesk, guide utilisateur généré (docs/guide/)
public/          # Racine web : index.php (front controller), assets/css, assets/js, assets/vendor/<lib>-<version>/ (figé, avec licence)
tests/           # PHPUnit 11.5 : Core, Domain, Middleware, Architecture, Integration (base *_test recréée)
```

Seul `public/` est exposé par le serveur web.

## Conventions

- **Code en anglais** : classes, méthodes, variables, tables, colonnes, commentaires, messages de commit.
- **Interface en français et anglais** : aucun texte en dur dans les vues ou contrôleurs ; toutes les chaînes passent par `lang/fr.php` et `lang/en.php` (mêmes clés dans les deux fichiers).
- **Échappement des sorties** : toute donnée affichée dans une vue passe par `e()` (`htmlspecialchars` ENT_QUOTES, UTF-8). Pas d'`echo` brut de données variables.
- **Sécurité** : jeton CSRF sur tout formulaire/requête modifiant des données ; mots de passe via `password_hash`/`password_verify` ; uploads stockés hors de `public/`.
- **Nommage** : classes en `PascalCase`, méthodes/variables en `camelCase`, tables et colonnes en `snake_case` ; suffixes `…Controller`, `…Service`, `…Repository`.
- **Style** : PSR-12, types déclarés pour paramètres, retours et propriétés ; `readonly` quand pertinent ; enums PHP natives pour les statuts.
- **JS** : pas de script inline (CSP). Modules purs UMD sans DOM (`lt-core.js`, `lt-tables.js`) testés avec `node --test "tests/js/*.test.js"` ; `app.js` relie DOM, jQuery et bibliothèques via des attributs `data-lt-*`. AJAX uniquement via `LT.api` (jeton CSRF, PUT/PATCH/DELETE tunnelisés en POST + `_method`). Textes JS dans la section `js` de `lang/*.php`.
- **Vendor** : versions figées dans `public/assets/vendor/<lib>-<version>/` (jQuery 3.7.1, DataTables 2.1.8, Chart.js 4.4.7, SweetAlert2 11.14.5 sans le build `.all`, Lucide 0.460.0, ExcelJS 4.4.0, pdfmake 0.2.14, IBM Plex Sans 5.1.0). Aucun CDN. Chart.js, ExcelJS et pdfmake ne sont chargés que par les pages qui en ont besoin (section `scripts`).
- **Tables serveur** : `GET ?page=&per_page=&sort=&dir=asc|desc&q=&<filtres>` → `{"data": [...], "meta": {"total": n, "filtered": n}}` ; `sort` doit être validé contre une liste blanche côté serveur.
- **CSS** : variables `--lt-*` dans `:root` (clair/sombre), mode terrain = classe `lt-field-mode` sur `<html>`.
- **Base de données** : ne jamais modifier une migration déjà appliquée ; en créer une nouvelle.

## Sécurité et accès

- **Rôles** (`App\Domain\Auth\Role`) : `admin`, `secretariat`, `head_of_department`, `agent`, `management`. Les permissions sont définies dans le code (`Role::permissions()`), pas en base.
- **Autorisation** : chaque route protégée déclare ses middlewares (`auth`, `can:mail.view,…`) dans `app/routes.php`. Un contrôleur ne vérifie jamais un rôle directement.
- **SiteScope obligatoire** : tout repository étend `App\Repositories\Repository` (constructeur imposé) et filtre les tables liées à un site avec `scopeSql()` en lecture et en écriture, ou `assertInScope()` à la création. `SiteScope::system()` est réservé au code technique (authentification, CLI) et doit être explicite. Vérifié par `tests/Architecture`.
- **Connexion** : `password_hash`/`password_verify`, verrouillage après 5 échecs sur 15 minutes par compte (20 par IP), régénération de l'ID de session et du jeton CSRF à la connexion.
- **CSP stricte** : `script-src 'self'`, aucun script ni style inline, aucun attribut `on…=` ni `style=` dans les vues (vérifié par test). Tout le JS va dans `public/js/`.
- **Cookies** : Secure (selon `SESSION_SECURE`), HttpOnly, SameSite=Lax par défaut.
- **Journal d'activité** : toute création/modification passe par `AuditTrail` dans la transaction du Service (valeurs avant/après, uniquement les champs modifiés). `activity_log` est en ajout seul (triggers qui refusent UPDATE/DELETE).
- **Fichiers** : stockés via `FileStorage` hors de `public/` (`STORAGE_PATH`), nom aléatoire, extension déduite du type MIME détecté (`finfo`), jamais du nom ni du type envoyé par le navigateur. Téléchargement uniquement par contrôleur (contrôle SiteScope).
- **SQL strict** : chaque connexion force un `sql_mode` strict (pas de troncature silencieuse).

## Métier courrier

- Référence `ENT-AAAA-NNNNN` / `SOR-AAAA-NNNNN`, compteur par site, sens et année d'enregistrement (fuseau `APP_TIMEZONE`), réservé dans la transaction (`mail_sequences`) : ni doublon ni trou.
- Dates-heures stockées en UTC, dates simples (`Y-m-d`) sans conversion ; affichage avec `local_datetime()` / `local_date()`.
- Les erreurs métier sont des `RuleViolation` (clés de traduction `rules.*`), les erreurs de format des `ValidationException` ; un élément hors périmètre donne 404 (`NotFoundException`), jamais 403.
- **Statut** : jamais modifié par le formulaire d'édition. Toute transition passe par une action de `App\Domain\Mail\MailWorkflow` (matrice complète testée dans `tests/Domain/MailWorkflowTest.php`) via `WorkflowService`. Qui peut agir : `MailAccess` (dispatcheurs `mail.assign` sur tout le périmètre, agents seulement sur les courriers qui leur sont affectés ou à un absent qu'ils remplacent).
- **Affectation** : un seul responsable « pour traitement » actif à la fois (sinon réaffectation), copies « pour information » illimitées. Clôture et réponse terminent les affectations actives.
- **Absences** : pendant une délégation, les nouvelles affectations vont au délégué (`DelegationResolver`, chaînes suivies, cycles arrêtés) et le délégué peut traiter les courriers de l'absent.
- **Réponse** : lier un sortant (`reply_to`) à un entrant clôture l'entrant, dans la même transaction.
- **Confirmations** : actions sensibles via `data-lt-confirm*` sur le formulaire (SweetAlert2, options construites par `LT.confirmOptions`), jamais de `confirm()` inline.

## Échéances, notifications, tâche quotidienne

- **Échéance par défaut** d'un courrier entrant sans date : `DueDatePolicy` (jours ouvrés, jours fériés français inclus, délai selon la priorité).
- **Notifications** : table `notifications`, une par utilisateur, dédoublonnées par `dedupe_key`. Toute lecture ou modification est filtrée par l'utilisateur courant. Compteur de la cloche mis à jour par `app.js` (`/notifications/count`), jamais calculé dans le layout.
- **Tâche quotidienne** `bin/send-reminders.php` → `DailyTaskService` (portée `SiteScope::system()`) : rappels, conservation, nettoyage. Idempotente, verrou exclusif, trace dans `scheduled_runs`. Configuration Plesk : `docs/scheduled-task-plesk.md`.
- **Conservation** : jamais de destruction automatique d'un courrier. Les actions automatiques sont archiver, supprimer les fichiers (métadonnées et SHA-256 conservées) ou demander une revue. Les changements faits par la tâche ont `user_id` NULL et `user_agent = task:<nom>` dans `activity_log`.

## Statistiques, exports, documents

- **Statistiques** : `StatsRepository` fournit les données brutes (bornes en UTC) ; `StatsCalculator` (Domain) les regroupe par jour local, semaine ISO ou mois, et calcule délais, retards et tranches. Page `/statistics` (`reports.view`), JSON consommé par `public/assets/js/pages/dashboard.js`. Les fonctions qui construisent les graphiques Chart.js sont pures et testées ; le code qui manipule la page ne s'exécute que dans le navigateur.
- **Exports** : `lt-export.js` est pur. Il reçoit ExcelJS/pdfmake en paramètre et utilise les jeux de colonnes `COLUMN_SETS`. Les bibliothèques sont chargées à la demande (`LT.loadScript`, même origine) par les boutons `data-lt-export`. Limites côté serveur : 5 000 lignes (liste), 10 000 (registre).
- **Documents qui quittent l'application** (exports, registre, bordereau) : l'objet d'un courrier `secret` n'y figure jamais (`Confidentiality::masksSubjectInDocuments`).
- **Bordereau** : `/mails/{id}/slip`, layout `layouts/print`, code-barres Code 39 en SVG généré côté serveur (`App\Core\Code39`).
- **Traductions** : les paramètres `:nom` sont remplacés mot entier (`:page` ne touche pas `:pages`), en PHP comme en JS.
