# FIORI_MIGRATION_PLAN.md — Migration de l'interface vers SAP Fiori (Horizon)

Référence : [FIORI_DESIGN.md](FIORI_DESIGN.md). Seules les vues, `app.js`, `app.css` et les modules qui manipulent la page sont concernés. Controllers, Services, Repositories, Domain et les modules purs (`lt-core`, `lt-export`) ne changent pas, sauf pour fournir des données nouvelles à un écran.

Statuts : **fait** (composants UI5, floorplan déclaré, vérifié dans le navigateur) · **en cours** (migré en partie) · **à faire** (composants maison, habillés aux couleurs du thème).

## 1. Écrans existants

Rôles : ADM admin · SEC secrétariat · CS chef de service · AG agent · DIR direction.

| # | Écran | Route | Vue | Floorplan | Rôles | Statut |
|---|---|---|---|---|---|---|
| 1 | Coque (ShellBar, navigation, menu du profil, messages) | toutes | `layouts/main.php` | cadre commun à tous les floorplans | tous | **fait** |
| 2 | Connexion | `/login` | `auth/login.php` | hors floorplan (page de connexion) | invité | **en cours** — ShellBar faite ; formulaire encore maison |
| 3 | Accueil | `/` | `home/index.php` | Launchpad | tous, tuiles selon le rôle | **fait** |
| 4 | Liste du courrier | `/mails` | `mails/index.php` | List Report | tous ; Créer (SEC, ADM), Affecter (SEC, CS, ADM) | **fait** — filtres UI5, tableau DataTables compact (décision du 2026-10-02) ; écran de référence du gabarit (FIORI_DESIGN.md §12) |
| 5 | Fiche courrier (workflow, annotations, pièces jointes, historique, réponse) | `/mails/{id}` | `mails/show.php` | Object Page | tous ; actions selon le rôle | **fait** |
| 6 | Enregistrer un courrier entrant | `/mails/new` | `mails/wizard.php` | Wizard (5 étapes, scan facultatif) | SEC, ADM | **fait** |
| 6 bis | Créer un courrier sortant, répondre | `/mails/new?direction=outgoing`, `?reply_to=` | `mails/form.php` | Object Page en création (formulaire simple) | SEC, ADM | **fait** |
| 7 | Modifier un courrier | `/mails/{id}/edit` | `mails/show.php` (mode édition) | Object Page en mode édition | SEC, CS, AG*, ADM | **fait** |
| 8 | Bordereau imprimable | `/mails/{id}/slip` | `mails/slip.php` + `layouts/print.php` | hors floorplan (document imprimé) — seule la barre d'outils passe en UI5 | tous | à faire |
| 9 | Registre du courrier | `/register` | `register/index.php` | List Report | tous | **fait** — le tableau montre le registre de la période, les exports produisent le document |
| 10 | Correspondants (liste) | `/correspondents` | `correspondents/index.php` | List Report | SEC, ADM | **fait** |
| 11 | Correspondant (création/édition) | `/correspondents/new`, `/{id}/edit` | `correspondents/form.php` | Object Page en mode édition | SEC, ADM | **fait** |
| 12 | Notifications | `/notifications` | `notifications/index.php` | Worklist | tous | à faire — la cloche de la ShellBar est faite |
| 13 | Absences / délégations | `/delegations` | `delegations/index.php` | Worklist (création dans un `ui5-dialog`) | tous | **en cours** — formulaire en UI5 (ComboBox, validation inline) ; liste et page à faire |
| 14 | Statistiques | `/statistics` | `statistics/index.php` | Analytical List Page | CS, DIR, ADM | à faire |
| 14 bis | Vue d'ensemble (nouvel écran) | `/overview` | `overview/index.php` | Overview Page | CS, DIR, ADM ; cartes d'affectation pour CS et ADM | **fait** |
| 15 | Règles de conservation | `/retention-rules` | `retention/index.php` | List Report (création dans un `ui5-dialog`) | ADM | **fait** — liste de paramétrage (FIORI_DESIGN.md §12) |
| 16 | Page d'erreur (403/404/500) | — | `errors/error.php` | hors floorplan (`ui5-illustrated-message`) | tous | à faire — thème appliqué |

\* Incohérence connue, pas encore tranchée : un agent peut ouvrir le formulaire d'édition de n'importe quel courrier de son site, alors que `MailAccess` limite ses actions aux courriers qui lui sont affectés.

Manquant : la permission `users.manage` existe, mais aucun écran ne l'utilise (les utilisateurs sont créés par `bin/create-user.php`). Une future application « Utilisateurs » serait un List Report + une Object Page, avec sa tuile dans le groupe Administration.

## 2. Composants maison → UI5

| Composant maison (classes / attributs) | Où | Remplacement UI5 | Statut |
|---|---|---|---|
| `lt-topbar`, `lt-brand`, `lt-user`, `lt-locale`, `lt-nav-toggle` | layout | `ui5-shellbar`, `ui5-shellbar-branding`, `ui5-user-menu` | **fait** |
| `lt-sidebar`, `lt-nav`, `lt-backdrop` | layout | `ui5-navigation-layout` + `ui5-side-navigation` | **fait** |
| `lt-bell`, `data-lt-notifications-count` | layout | `ui5-shellbar` `show-notifications` + `notifications-count` | **fait** (la cloche ouvre la page ; popover à faire avec l'écran Notifications) |
| `lt-field-mode` | layout | densité compact/cozy automatique + thèmes contraste élevé | **fait** |
| `lt-offline-banner`, `lt-alert` du layout (messages flash) | layout | `ui5-message-strip` | **fait** |
| `lt-stat(s)` de l'accueil | accueil | tuiles `ui5-card` + `ui5-card-header` + `ui5-tag` | **fait** |
| `lt-list` de l'accueil (prochaines échéances) | accueil | `ui5-list` + `ui5-li`, `ui5-illustrated-message` si vide | **fait** |
| `app.css` (`--lt-*`, IBM Plex, mode sombre maison) | global | variables `--sap*` uniquement | **fait** |
| `lt-skip`, `lt-sr-only` | layout | conservés (accessibilité, aucun équivalent UI5) | — |
| `lt-alert(--*)` dans les formulaires, `partials/field-errors.php` | formulaires | `value-state` sur les champs (`Ui5::state`) + popover de messages (`partials/object-page/messages.php`) | en cours — fait pour les formulaires de saisie (`partials/field-errors.php` supprimé) ; reste l'alerte de la page de connexion |
| `lt-btn(--primary/--ghost/--danger/--icon)` | partout | `ui5-button design="Emphasized/Transparent/Negative"` + `icon` | à faire |
| `lt-card`, `lt-page-header`, `lt-columns` | partout | `ui5-dynamic-page` + `ui5-dynamic-page-title`, `ui5-card` | à faire |
| `lt-field`, `lt-form`, `<input>/<select>/<textarea>`, `lt-check` | formulaires | `ui5-form` + `ui5-label`, `ui5-input`, `ui5-select`, `ui5-combobox`, `ui5-step-input`, `ui5-textarea`, `ui5-date-picker`, `ui5-datetime-picker`, `ui5-checkbox` (FIORI_DESIGN.md §14) | en cours — faits : courrier, correspondant, absence, règle de conservation ; restent : connexion, filtres des statistiques |
| `lt-filters` | listes, registre, stats | en-tête dépliable de `ui5-dynamic-page` (structure FilterBar), bouton « Exécuter », vues enregistrées | **fait** pour le courrier, le registre, les correspondants et la conservation ; restent les absences et les statistiques |
| `lt-table`, DataTables (`lt-tables.js`, `data-lt-table/render/name/class/sortable`) | courrier, correspondants, conservation | DataTables **conservé** comme tableau des listes, en version compacte (`.lt-dt`, `pages/list-report.js`) avec sélection multiple et statuts en `ui5-tag` ; `/…/data` conservé | **fait** pour le courrier, le registre, les correspondants et la conservation ; restent les absences et les statistiques |
| `lt-table-toolbar`, `data-lt-export` | listes, registre | `ui5-toolbar` + `ui5-toolbar-button` (ExcelJS/pdfmake conservés), actions de masse | **fait** pour le courrier, le registre, les correspondants et la conservation ; restent les absences et les statistiques |
| `lt-badge(--*)`, `lt-due(--*)` | fiche, listes, absences, conservation | `ui5-tag` avec un `design` sémantique (correspondance centralisée : `LT.listReport.tagDesign`) | **fait** pour le courrier, le registre, les correspondants et la conservation ; restent les absences et les statistiques |
| `lt-picker`, `data-lt-picker*` (correspondant) | formulaire courrier | `ui5-input show-suggestions` + `ui5-suggestion-item` (`data-lt-suggest`) | **fait** |
| `lt-workflow`, `lt-inline-form` (assigner, clôturer…) | fiche | pied de page de l'Object Page (`ui5-bar`) + `ui5-dialog` | **fait** |
| `data-lt-confirm*` + SweetAlert2 | fiche, absences, conservation | `ui5-dialog` (`state` Critical/Negative, `ui5-textarea` pour le motif) : `partials/object-page/confirm.php` sur une Object Page, `LT.ui.confirmForm` ailleurs | **fait** — SweetAlert2 retiré du dépôt |
| `lt-timeline`, `lt-notes` | fiche | `ui5-timeline`, `ui5-list` + `ui5-li-custom` | **fait** |
| `lt-files`, `lt-upload` | fiche | `ui5-list` + `ui5-file-uploader` dans un dialogue | **fait** |
| `lt-dl`, `lt-details`, `<details>` | fiche, stats | `ui5-form` en lecture seule, dialogues ; `ui5-panel` pour les stats | en cours (fiche courrier faite) |
| `lt-stats--kpi` (indicateurs), `lt-chart*` | stats | `ui5-card` ; Chart.js conservé, couleurs `--sapChart_*` | en cours — couleurs lues dans le thème et redessinées au changement de thème ; cartes et filtres à faire |
| `lt-list`, `lt-notification` | notifications, absences | `ui5-list`, `ui5-li-notification` | à faire |
| Lucide | écrans non migrés | `ui5-icon` (`@ui5/webcomponents-icons`) | en cours (coque et accueil faits) |
| `lt-login-*` + `login-hero.svg` | connexion | `ui5-input`, `ui5-button` ; illustration conservée | à faire |
| `lt-slip`, `lt-print-*` | bordereau | conservés (CSS d'impression) ; barre d'outils en `ui5-toolbar` | à faire |

## 3. Applications par rôle

Le détail des tuiles et la correspondance rôles → groupes sont dans [FIORI_DESIGN.md §11](FIORI_DESIGN.md) (source : `App\Domain\Launchpad\Launchpad`). La navigation latérale reprend les mêmes groupes ; l'accès reste contrôlé côté serveur (`can:` dans `app/routes.php`).

| Application | Floorplan | Permission | ADM | SEC | CS | AG | DIR |
|---|---|---|---|---|---|---|---|
| Accueil | Launchpad | `mail.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| Enregistrer un courrier | Wizard | `mail.create` | ✓ | ✓ | — | — | — |
| Courriers (dont « Mes courriers », par filtre) | List Report → Object Page | `mail.view` | ✓ | ✓ | ✓ | ✓ | ✓ (tous sites) |
| Registre | List Report | `mail.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| Correspondants | List Report → Object Page | `correspondents.manage` | ✓ | ✓ | — | — | — |
| Vue d'ensemble | Overview Page | `reports.view` | ✓ | — | ✓ | — | ✓ |
| Statistiques | Analytical List Page | `reports.view` | ✓ | — | ✓ | — | ✓ |
| Mes absences | Worklist | `mail.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| Notifications | Worklist | (connecté) | ✓ | ✓ | ✓ | ✓ | ✓ |
| Conservation | List Report | `settings.manage` | ✓ | — | — | — | — |
| Utilisateurs (futur) | List Report → Object Page | `users.manage` | ✓ | — | — | — | — |

## 4. Plan de migration (du plus structurant au plus fin)

Chaque étape se termine avec `composer test`, `npm test`, `npm run ui5:check` et `npm run guide` au vert. Une vue migrée déclare son floorplan (`/** @floorplan … */`).

| Étape | Contenu | Statut |
|---|---|---|
| **0. Socle technique** | Bundle UI5 figé (`tools/ui5/`), CSP inchangée, configuration par attributs de `<html>` | **fait** |
| **1. Thème et jetons** | `app.css` sur les variables `--sap*`, police 72, clair/sombre/contraste élevé, densité automatique, fin du mode terrain | **fait** |
| **2. Coque et navigation** | `ui5-navigation-layout`, ShellBar (recherche, cloche, profil : thème, langue, déconnexion), navigation par groupes, messages flash en `ui5-message-strip` | **fait** |
| **2 bis. Partials communs** | `field-errors` → `value-state` (`Ui5::state`) — **fait** ; helper de statut → `ui5-tag` (`Ui5::tag`) — **fait** ; `data-lt-confirm` + SweetAlert2 → `ui5-dialog` générique, même contrat d'attributs (`LT.dialogSpec`) — **fait** ; messages et erreurs en `ui5-toast` / `ui5-dialog` — **fait** ; page d'erreur → `ui5-illustrated-message` — à faire | en cours |
| **3. Écran pilote : liste du courrier** (List Report) | `ui5-dynamic-page`, barre de filtres UI5 avec « Exécuter », vues enregistrées par appareil, barre d'outils (Créer, Affecter et Clôturer en lot, export de la liste ou de la sélection), tableau **DataTables compact** : tri au clic sur l'en-tête, pagination, colonnes au choix, sélection multiple, statuts en `ui5-tag`, clic sur une ligne, état vide. Logique dans `lt-listreport.js` (pur, testé) et `pages/list-report.js` (générique, piloté par `data-lt-*`). | **fait** |
| **3 bis. Gabarit List Report** | Gabarit extrait dans `views/partials/list-report/`, section « Modèle List Report » de FIORI_DESIGN.md (§12), appliqué au registre, aux correspondants et aux règles de conservation | **fait** |
| **4. Fiche courrier** (Object Page) | En-tête dynamique (titre, statut, 4 chiffres clés), barre d'ancres et sections, lecture par défaut et mode édition, actions de workflow dans le pied de page, saisies et confirmations en `ui5-dialog`, erreurs dans un popover de messages, fil d'Ariane. Modèle documenté dans FIORI_DESIGN.md §13. Reste à trancher : l'accès des agents à l'édition. | **fait** |
| **5. Saisie** | Wizard pour le courrier entrant ; formulaires simples en `ui5-form` pour le courrier sortant, la modification, le correspondant, l'absence et la règle de conservation ; aide à la saisie (suggestions, ComboBox, sélecteurs de date, StepInput) ; validation dans les champs. Règles dans FIORI_DESIGN.md §14. | **fait** |
| **6. Autres List Reports et Worklists** | Registre, Correspondants, Conservation — **fait** (étape 3 bis). Restent : Notifications (`ui5-li-notification`, popover de la cloche), Mes absences | en cours |
| **7. Accueil, Vue d'ensemble et Statistiques** | Accueil : Launchpad par rôle, gestion par exception — **fait**. Vue d'ensemble (Overview Page) pour le pilotage : cartes KPI, listes, graphiques aux couleurs du thème, reliée au Launchpad — **fait**. Statistiques (Analytical List Page) : filtres, indicateurs en `ui5-card`, Chart.js recoloré avec `--sapChart_*` — à faire | en cours |
| **8. Connexion, bordereau, finitions** | Formulaire de connexion en UI5, barre d'outils du bordereau, états vides, `ui5-busy-indicator`, `ui5-toast` | à faire |
| **9. Nettoyage et conformité** | SweetAlert2 retiré, CSS mort supprimé, DataTables chargé par le seul écran qui s'en sert, CLAUDE.md remis à jour — **fait**. Retirer Lucide quand les derniers écrans seront migrés (DataTables et `lt-tables.js` restent : tableau des listes) ; analyse axe-core dans les captures (WCAG 2.1 AA) — à faire | en cours |

Dépendances : 3 précède 6. 4 et 5 peuvent avancer en parallèle après 3 et 2 bis. Les statistiques (7) et l'étape 8 sont indépendantes. 9 vient en dernier.

## 5. Journal

| Date | Étapes | Notes |
|---|---|---|
| 2026-10-01 | 0, 1 | UI5 Web Components 2.27.2 installé, thème et densité, `app.css` sur les variables du thème. |
| 2026-10-01 | 2, 7 (accueil) | Coque UI5 et page d'accueil Launchpad. Écrans restants affichés dans la nouvelle coque avec leurs composants maison. |
| 2026-10-01 | 3 | Liste du courrier en List Report (écran pilote). Nouvelles routes `POST /mails/bulk/assign` et `/mails/bulk/close`, filtre `ids` sur `/mails/export`. En attente de validation avant l'extraction du gabarit et son application au registre, aux correspondants et à la conservation. |
| 2026-10-01 | 4, 5 (en partie) | Fiche courrier en Object Page, avec son mode édition ; création d'un courrier et fiche correspondant sur le même modèle. `correspondent-picker.js` supprimé. L'incohérence d'accès des agents à l'édition n'est toujours pas tranchée. |
| 2026-10-01 | 5 | Enregistrement d'un courrier entrant en Wizard (le scan peut être joint dès l'enregistrement) ; formulaires d'absence et de règle de conservation en `ui5-form`. Section « Formulaires » dans FIORI_DESIGN.md. Les écrans Absences et Conservation gardent leur liste et leur page d'origine. |
| 2026-10-01 | 7 (en partie) | Overview Page `/overview` pour le chef de service, la direction et l'administrateur ; tuile « Vue d'ensemble » dans le groupe Pilotage du Launchpad. Les graphiques de la page Statistiques gardent encore leur palette en dur. |
| 2026-10-02 | revue de conformité | Règles précisées dans FIORI_DESIGN.md (écrans hors floorplan, ordre des boutons, table des statuts, navigation, palettes fixes, espacements, place d'« Enregistrer »). Une seule action mise en avant sur la fiche courrier ; messages, erreurs et confirmations en composants UI5 partout ; graphiques des statistiques aux couleurs du thème ; nettoyage (CSS mort, textes en dur, chargement des bibliothèques). Restent les six écrans de `NOT_MIGRATED_YET` (test d'architecture) et la page de connexion. |
| 2026-10-02 | 3 | À la demande du client, le tableau de la liste du courrier redevient un DataTable, compact (lignes de 32 px, tri au clic, pagination) ; filtres, vues, barre d'outils et dialogues restent en UI5. Exception inscrite dans FIORI_DESIGN.md §2. |
| 2026-10-02 | 3 bis | Gabarit List Report extrait (`views/partials/list-report/`) et documenté (FIORI_DESIGN.md §12). Registre, correspondants et règles de conservation convertis. Le registre affiche maintenant les courriers de la période avant l'export. Restent dans `NOT_MIGRATED_YET` : absences, notifications, statistiques. |
| 2026-10-02 | nouveau module | Gestion des utilisateurs (`/users`) : List Report et Object Page construits directement sur les gabarits (§12, §13), entrée de menu et tuile « Utilisateurs » pour l'administrateur. |
| 2026-10-05 | module utilisateurs complet | Mon profil (mot de passe, double authentification), changement obligatoire du mot de passe donné par un administrateur, fermeture des autres sessions, règles de mot de passe renforcées, mot de passe oublié par e-mail, sites et services, transfert des courriers à la désactivation, déblocage, historique et connexions du compte, export, rôles et droits, import CSV. |
