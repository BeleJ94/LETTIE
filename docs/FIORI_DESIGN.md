# FIORI_DESIGN.md — Règles de design et d'UX de Lettie

Lettie suit SAP Fiori (langage visuel **Horizon**), réalisé avec **UI5 Web Components** dans la stack du projet : vues PHP, JavaScript classique, sans framework ni build au déploiement.
En cas de conflit sur un sujet d'interface, ce document prévaut sur CLAUDE.md.

## 1. Les 5 principes Fiori, traduits en règles

| Principe | Règles concrètes dans Lettie |
|---|---|
| **Role-based** | Chaque écran sert une tâche d'un rôle (`secretariat`, `agent`, `head_of_department`, `management`, `admin`). Navigation et actions filtrées par permission côté serveur (`can:` dans `app/routes.php`, `MailAccess`) : on n'affiche jamais une action que l'utilisateur ne peut pas faire. Page d'accueil = ce que le rôle doit traiter (échéances, « Mes courriers »). |
| **Adaptive** | Une même vue de 360 px à 1920 px. Densité automatique (§6). Mise en page par les conteneurs UI5 (`ui5-dynamic-page`, `ui5-flexible-column-layout`), pas par des grilles maison. |
| **Simple** | Un écran = une tâche = un floorplan (§4). Au plus une action principale (`design="Emphasized"`) visible à la fois. Champs avancés repliés (`ui5-panel collapsed`). Pas plus de 3 niveaux de navigation. |
| **Coherent** | Uniquement des composants UI5 et des variables du thème. Mêmes libellés pour les mêmes actions (clés `lang/*` partagées : `common.save`, `common.cancel`…). Même place pour les mêmes choses : actions de l'objet dans le pied de page, filtres dans l'en-tête de la page. |
| **Delightful** | Retour immédiat : `ui5-busy-indicator` pendant les appels, `ui5-toast` après une action réussie, états vides illustrés (`ui5-illustrated-message`). Animations UI5 uniquement, désactivées si l'utilisateur le demande (`prefers-reduced-motion`). |

## 2. Stack UI

- **Composants** : `@ui5/webcomponents`, `@ui5/webcomponents-fiori`, `@ui5/webcomponents-icons` (icônes SAP, `ui5-icon name="…"`). Pas de `@ui5/webcomponents-react` : Lettie n'utilise pas React.
- **Livraison** (compatible avec « aucun build au déploiement ») : un script de **développement** `tools/ui5/build-bundle.mjs` (esbuild, `devDependencies`) produit un bundle ES module, avec ses polices et assets de thème, dans `public/assets/vendor/ui5-webcomponents-<version>/`. Ce dossier est versionné et figé comme les autres bibliothèques ; le serveur n'exécute jamais npm. Le bundle n'importe que les composants listés dans `tools/ui5/components.mjs`.
- **Chargement** : `<script type="module" src="…/ui5.js">` dans le layout, après `app.js`. Aucun script inline dans les vues : la configuration (thème, langue, densité) passe par des attributs de `<html>`, écrits par `lt-theme.js` et `lang`.
- **Exception : le tableau des listes est un DataTable.** Par décision du projet (2026-10-02), le tableau d'un List Report est un DataTables serveur compact : lignes sur une seule hauteur (2rem), tri par clic sur l'en-tête, pagination et choix du nombre de lignes. Le `ui5-table`, essayé d'abord, donnait des lignes trop hautes et pas de tri au clic. Tout le reste de l'écran reste en UI5 : barre de filtres, vues enregistrées, barre d'outils, dialogues ; dans les cellules, les statuts sont des `ui5-tag`. Le tableau n'utilise que les variables du thème (`.lt-dt` dans `app.css`) et suit le clair, le sombre et le contraste élevé. C'est la seule exception à « pas de composant non UI5 » avec Chart.js.
- **Pas de tuile Fiori dans UI5 Web Components** (`GenericTile` n'existe que dans SAPUI5) : une tuile du Launchpad est un `ui5-card` dont l'en-tête `ui5-card-header interactive` porte le titre et ouvre l'écran (§11).
- **Interdit** : un composant visuel maison quand un équivalent UI5 existe. Restent autorisés, sans équivalent UI5 : Chart.js (graphiques, couleurs issues des variables du thème), ExcelJS et pdfmake (exports, rien de visible), le code-barres SVG du bordereau.

| Besoin | Composant UI5 | Remplace |
|---|---|---|
| Coque | `ui5-navigation-layout` (ShellBar dans `header`, navigation dans `sideContent`) | grille maison |
| Barre supérieure, cloche, recherche | `ui5-shellbar` (`notifications-count`, `show-notifications`), `ui5-shellbar-branding`, `ui5-shellbar-search` | en-tête maison |
| Menu du profil | `ui5-avatar` (slot `profile`) + `ui5-user-menu` | boutons maison |
| Navigation | `ui5-side-navigation` + `ui5-side-navigation-group` | menu maison |
| Tuile du Launchpad | `ui5-card` + `ui5-card-header interactive` + `ui5-title` (chiffre) + `ui5-tag` (état) | compteurs maison |
| Liste avec pagination serveur | **DataTables** (exception décidée, voir ci-dessous), habillé aux couleurs du thème | — |
| Champs | `ui5-input`, `ui5-select`, `ui5-date-picker`, `ui5-datetime-picker`, `ui5-textarea`, `ui5-checkbox`, `ui5-label` | champs natifs stylés |
| Autocomplétion du correspondant | `ui5-input show-suggestions` + `ui5-suggestion-item` | picker maison |
| Statut / priorité | `ui5-tag` avec `design` sémantique (§5) | badges maison |
| Messages | `ui5-message-strip`, `ui5-toast` | alertes maison |
| Confirmation | `ui5-dialog` (en-tête et pied normalisés) | SweetAlert2 |
| Historique | `ui5-timeline` | timeline maison |
| Pièces jointes | `ui5-list` + `ui5-file-uploader` (dans un dialogue) | liste et formulaire maison |
| Cartes du tableau de bord | `ui5-card` + `ui5-card-header` | cartes maison |
| Assistant (enregistrement guidé) | `ui5-wizard` | — |
| États vides | `ui5-illustrated-message` | textes « Aucun… » |

## 3. Thème

- **Thème par défaut : `sap_horizon`**, même si le système est en mode sombre. L'utilisateur bascule en **`sap_horizon_dark`** par le bouton de la barre supérieure (`data-lt-theme-toggle`), choix mémorisé par appareil. Si le système demande plus de contraste (`prefers-contrast: more` ou `forced-colors: active`), Lettie applique **`sap_horizon_hcw`** (ou **`sap_horizon_hcb`** si le thème choisi ou le système est sombre).
- **Aucune valeur codée en dur** dans `app.css` : ni couleur, ni police, ni taille de texte, ni espacement, ni rayon, ni ombre. Uniquement les variables du thème :
  - fonds et textes : `--sapBackgroundColor`, `--sapBaseColor`, `--sapGroup_ContentBackground`, `--sapNeutralBackground`, `--sapTextColor`, `--sapContent_LabelColor`, `--sapList_BorderColor` ;
  - marque et liens : `--sapBrandColor`, `--sapLinkColor`, `--sapButton_Emphasized_*` ;
  - états : `--sapPositiveColor`, `--sapCriticalColor`, `--sapNegativeColor`, `--sapInformativeColor` (et les variantes `…ElementColor`, `…TextColor`), fonds `--sapSuccessBackground`, `--sapWarningBackground`, `--sapErrorBackground`, `--sapInformationBackground` ;
  - typographie : `--sapFontFamily` (police « 72 »), `--sapFontSize`, `--sapFontSmallSize`, `--sapFontHeader2Size` à `--sapFontHeader5Size`, `--sapContent_LineHeight` ;
  - forme : `--sapContent_Space_Tiny/Small/Medium`, `--sapButton_BorderCornerRadius`, `--sapElement_BorderCornerRadius`, `--sapContent_Shadow0..3`, focus `--sapContent_FocusColor/Width/Style`.
- **Le CSS maison est réduit à la mise en page.** Les variables `--lt-*`, IBM Plex Sans et le mode sombre maison ont disparu.
- **Espacements : ce qui est permis.** Marges, espacements internes et écarts (`margin`, `padding`, `gap`) entre des contenus utilisent `--sapContent_Space_*` (ou un `calc()` sur ces variables). Restent écrits en clair, parce que ce sont des dimensions de mise en page et non des valeurs du thème : largeurs et hauteurs de colonnes, de cartes et de graphiques (`minmax(13rem, 1fr)`, `height: 16rem`), bordures et traits de 1 ou 2 px, valeurs nulles, dimensions du document imprimé (en mm).
- **Palettes fixes : deux exceptions, chacune définie à un seul endroit.** Elles concernent ce qui ne peut pas lire le thème :
  - les **documents produits** par l'application (export PDF et Excel de `lt-export.js`) : ils sortent de l'écran, s'impriment sur papier blanc et ne suivent pas le thème de l'utilisateur. Leur palette est une constante en tête de `lt-export.js` ;
  - les **illustrations en image** (`public/assets/img/*.svg` : logo, illustration de connexion) : une image chargée par `<img>` n'a pas accès aux variables CSS. Leurs couleurs sont celles de Horizon clair.
  Le bordereau imprimable, lui, est une page : il utilise les variables du thème, verrouillé sur `sap_horizon`. Aucune autre couleur en dur n'est admise, y compris dans le JavaScript des graphiques.
- **Graphiques** : `dashboard.js` lit les couleurs dans les variables du thème (`--sapChart_OrderedColor_1..11`, `--sapChart_Bad/Critical/Good`) et redessine au changement de thème (`lt:theme-change`). C'est aussi le cas de `pages/overview.js`. Un script de page attend `lt:ui5-ready` avant de lire ces variables : avant, le thème n'est pas encore appliqué.

## 4. Floorplans

**Toute vue d'écran déclare son floorplan** en tête de fichier (`/** @floorplan ListReport */`). Floorplans autorisés : **Launchpad, List Report, Object Page, Worklist, Overview Page, Analytical List Page, Wizard**.

Trois écrans ne relèvent d'aucun floorplan Fiori ; ils le déclarent explicitement, avec la raison : `/** @floorplan None — page de connexion */`. Ce sont la **connexion** (avant l'entrée dans l'application), la **page d'erreur** (403, 404, 500) et le **bordereau** (document imprimable). `None` n'est pas une échappatoire : tout nouvel écran de l'application prend un des sept floorplans.

Un test d'architecture vérifie que chaque vue déclare une valeur autorisée. Les écrans pas encore migrés sont nommés dans ce test (`NOT_MIGRATED_YET`) ; la liste ne peut que raccourcir, et un écran en sort quand il déclare son floorplan. Les partiels (`views/partials/`, fichiers `_*.php`) et les layouts ne déclarent rien.

| Écran | Floorplan | Composition UI5 Web Components |
|---|---|---|
| Accueil (`/`) | **Launchpad** | groupes de tuiles `ui5-card` par rôle (§11) + liste `ui5-list` des prochaines échéances |
| Liste du courrier (`/mails`) | **List Report** | `ui5-dynamic-page` (filtres dans l'en-tête dépliable) + `ui5-table` |
| Fiche courrier (`/mails/{id}`) | **Object Page** | modèle Object Page (§13) |
| Enregistrer un courrier entrant | **Wizard** | `ui5-wizard` : Courrier → Expéditeur → Traitement → Pièce jointe → Récapitulatif (§14) |
| Créer un courrier sortant, répondre | **Object Page** en création | modèle Object Page (§13), formulaire simple (§14) |
| Notifications, Mes absences | **Worklist** | `ui5-dynamic-page` + `ui5-table` / `ui5-list`, sans filtres avancés |
| Connexion, page d'erreur, bordereau | **None** (hors floorplan) | composants UI5 quand il y en a d'utiles ; raison indiquée dans la déclaration |
| Vue d'ensemble (`/overview`) | **Overview Page** | grille de `ui5-card` : KPI, listes courtes, graphiques simples (§15) |
| Statistiques (`/statistics`) | **Analytical List Page** | en-tête de filtres + `ui5-card` (Chart.js) + tableau de détail |
| Registre, Correspondants, Conservation | **List Report** | idem liste du courrier |
| Fiche correspondant | **Object Page** | idem fiche courrier |

Les floorplans sont des compositions Fiori : UI5 Web Components n'a pas de composant « List Report » prêt à l'emploi. On respecte leur structure (en-tête, zone de contenu, pied de page), pas une reproduction au pixel près.

## 5. Interactions

- **Actions principales** de l'Object Page dans le **pied de page** (`ui5-dynamic-page` > `slot="footerArea"` > `ui5-bar`), alignées à droite. Une seule `Emphasized`, les autres `Transparent`. Les actions de section (Affecter, Ajouter une pièce jointe) vont dans la barre d'outils de la section.
- **Statuts** : `ui5-tag` avec un état sémantique, jamais une couleur choisie à la main.

  | Statut / valeur | `design` |
  |---|---|
  | Statut : Enregistré, Affecté, En cours | `Information` |
  | Statut : En attente de réponse | `Critical` |
  | Statut : Répondu, Clos | `Positive` |
  | Statut : Archivé | `Neutral` |
  | Priorité : Urgente | `Negative` |
  | Priorité : Haute | `Critical` |
  | Priorité : Normale, Basse | `Neutral` |
  | Échéance dépassée | `Negative` |
  | Échéance aujourd'hui ou dans les 2 jours | `Critical` |
  | Sens : Entrant, Sortant | `Set2` (catégorie, pas un état : schémas de couleur 6 et 9) |

  Un courrier terminé (répondu, clos, archivé) n'affiche plus d'état d'échéance. La correspondance est définie à deux endroits tenus identiques par un test : `App\Core\Ui5::TAG_DESIGNS` (vues PHP) et `LT.listReport.TAG_DESIGNS` (listes). Aucune vue ne choisit un `design` elle-même.
- **Messages** :
  - erreurs de validation : `value-state="Negative"` et `slot="valueStateMessage"` sur le champ concerné ;
  - message de page (succès, échec, avertissement) : `ui5-message-strip` en haut du contenu ;
  - succès d'une action rapide : `ui5-toast` ;
  - plusieurs messages à la fois : bouton dans le pied de page qui ouvre un `ui5-popover` contenant un `ui5-list` (équivalent du MessagePopover, absent des Web Components).
- **Actions destructives ou irréversibles** (clôturer, archiver, réaffecter, annuler une absence, désactiver une règle) : confirmation par `ui5-dialog` (`state="Critical"` ou `"Negative"`). Dans le pied d'un dialogue, les boutons sont alignés à droite, **l'action d'abord, « Annuler » en dernier** (ordre Fiori) ; l'action porte le verbe de ce qu'elle fait (« Clôturer », jamais « OK »). Pour une action irréversible, le focus initial est sur « Annuler ». Commentaire facultatif via un `ui5-textarea` dans le dialogue. Jamais `window.confirm()`.
- **Navigation** : une ligne de tableau ouvre l'Object Page (`ui5-table-row` `interactive`). Le retour se fait par le **fil d'Ariane** (`ui5-breadcrumbs`), présent sur tout écran de détail, de création ou de modification, dont le premier élément est la liste d'origine ; le logo de la ShellBar ramène à l'accueil. Il n'y a pas de bouton « Retour » dans la ShellBar ni dans les pages.
- **Une seule action mise en avant par écran** (`design="Emphasized"`) : celle qui fait avancer la tâche. Barre de filtres : « Exécuter ». Object Page en lecture : la première action du pied de page (« Modifier » est une action secondaire de la barre de titre, en `Transparent`). Édition, création, Wizard : « Enregistrer » ou « Étape suivante ». Un dialogue ouvert compte à part : son action est mise en avant.
- **Créer et exporter** se trouvent toujours dans la barre d'outils du tableau d'un List Report (à droite du titre et du compteur), jamais dans la barre de titre de la page.
- **Chargement** : `ui5-busy-indicator` sur la zone concernée, jamais sur toute la page pour un appel local.

## 6. Densité

- **Compact** sur poste de travail (pointeur précis), **cozy** sur écran tactile : `lt-theme.js` ajoute la classe `ui5-content-density-compact` sur `<html>` si `matchMedia('(pointer: fine)')`, sinon rien (cozy par défaut). La densité suit les changements (tablette avec clavier détachable, etc.).
- La hauteur minimale des éléments interactifs du CSS maison (`--lt-tap`) suit la densité : 2.75rem en cozy, 2rem en compact.
- Ce réglage remplace l'ancien « mode terrain » ; le contraste élevé passe par les thèmes `hcb`/`hcw` (§3).

## 7. Accessibilité (WCAG 2.1 AA)

- Chaque champ a un `ui5-label for="…"` (ou `accessible-name`), et `required` quand c'est le cas. Les icônes seules ont un `accessible-name` ou un `tooltip`.
- **Navigation clavier complète** : ordre de tabulation logique, aucun `tabindex` positif, raccourcis UI5 natifs conservés (F4 sur les sélecteurs, Échap pour fermer un dialogue). Le focus revient à l'élément déclencheur après un dialogue.
- Les contrastes sont garantis par les thèmes Horizon et contraste élevé : ne pas les contourner par des couleurs maison.
- Statuts jamais portés par la seule couleur : `ui5-tag` contient toujours le libellé.
- Graphiques accompagnés de leur tableau de données (déjà en place).
- Vérifié par les captures Playwright avec une analyse axe-core (à ajouter à `npm run screenshots`).

## 8. Langues

- Aucun texte en dur, ni dans les vues, ni dans `app.js`, ni dans les attributs (`placeholder`, `accessible-name`, `header-text`…) : tout vient de `lang/fr.php` / `lang/en.php` (section `js` pour le navigateur).
- **Français par défaut** (`APP_LOCALE=fr`). La langue d'UI5 est celle de `<html lang>` (`setLanguage`), pour ses textes internes (« Aucune donnée », boutons des sélecteurs de date) et ses formats (CLDR) : seules les données fr et en sont dans le bundle.
- Dates, nombres et heures formatés selon la langue (`lt-core.js`, `local_date()`), dans le fuseau `APP_TIMEZONE`.

## 9. Ce qui ne change pas

Tout ce qui n'est pas de l'interface reste régi par CLAUDE.md : architecture en couches, SiteScope, CSP (UI5 doit fonctionner avec `script-src 'self'`), CSRF, échappement par `e()`, journal d'activité, modules JS purs et leurs tests.

## 10. Décisions techniques

| Sujet | Décision |
|---|---|
| **Versions** | `@ui5/webcomponents`, `-fiori`, `-icons`, `-base` **2.27.2** (versions exactes, sans `^`), `esbuild` **0.28.2**, polices de `@sap-theming/theming-base-content` **11.36.5**. Toutes en `devDependencies` de `package.json` : rien n'est installé en production. |
| **Construction** | `npm run ui5:build` → `tools/ui5/build-bundle.mjs`. Résultat versionné dans `public/assets/vendor/ui5-webcomponents-2.27.2/` : `ui5.js` (point d'entrée), `chunks/` (thèmes et textes chargés à la demande), `fonts/` + `ui5-fonts.css`, `LICENSE.txt`, `licenses/` (une licence par paquet embarqué), `BUILD.json` (versions, thèmes, langues, composants). Ajouter un composant ou une icône : `tools/ui5/components.mjs`, puis reconstruire et committer. Monter de version : changer les versions exactes, `npm install`, reconstruire, mettre à jour le chemin dans les deux layouts et dans `tests/Architecture`. |
| **Contenu** | Thèmes `sap_horizon`, `sap_horizon_dark`, `sap_horizon_hcb`, `sap_horizon_hcw` et langues `fr`, `en` uniquement (filtrés à la construction). |
| **Thème et densité** | `public/assets/js/lt-theme.js`, chargé dans `<head>` avant le CSS : choisit le thème (préférence `localStorage` `lt.theme` = `light`/`dark`, contraste système) et la densité (pointeur), et les écrit sur `<html>` (`data-lt-theme`, `data-lt-density`, classe `ui5-content-density-compact`). Fonctions de décision pures, testées (`tests/js/lt-theme.test.js`). Événement `lt:theme-change` à chaque changement. |
| **Documents imprimables** | `<html data-lt-theme-lock="sap_horizon">` (layout `print`) : le bordereau reste en thème clair, quelle que soit la préférence. |
| **Police 72** | Servie localement : `ui5-fonts.css` (mêmes `@font-face` qu'UI5, chemins vers `fonts/`) dans le `<head>`, chargement par défaut d'UI5 désactivé (`defaultFontLoading: false`, UI5 irait sinon sur jsDelivr). |
| **Configuration initiale** | Le premier module du bundle (`tools/ui5/prelude.js`) crée par le DOM le bloc de configuration d'UI5 (`data-ui5-config` : langue de `<html lang>`, thème de `data-lt-theme`, police par défaut désactivée, paramètres d'URL `sap-ui-*` ignorés). Ne jamais appeler `setLanguage()` au chargement : un changement de langue pendant la définition des composants les fait se redessiner avant le chargement de leurs textes (erreurs de rendu, page blanche). |
| **CLDR** | Le prélude enregistre les chargeurs CLDR locaux fr/en : le chargeur « en » intégré à UI5 télécharge depuis jsDelivr (bloqué par la CSP). |
| **CSP** | Inchangée (`script-src 'self'; style-src 'self'; connect-src 'self'; font-src 'self'`). UI5 injecte ses styles par feuilles construites (`adoptedStyleSheets`), non soumises à `style-src`. Aucune requête externe : vérifié par `npm run ui5:check`. |
| **Affichage initial** | La page reste masquée jusqu'à l'application des variables du thème (classe `lt-ui5-ready` posée après `boot()`), avec un filet de sécurité CSS : elle s'affiche après 1,5 s même si le bundle ne se charge pas. |
| **Variables locales** | Seules des dimensions de mise en page restent en `--lt-*` (`--lt-content-max`, `--lt-tap`, `--lt-transition`). Les nuances sont dérivées des variables du thème par `color-mix()`, jamais écrites. Vérifié par `tests/Architecture/ArchitectureTest.php` (`testAppCssUsesOnlyThemeVariables`). |
| **API navigateur** | `window.LT_UI5` (`getTheme`, `setTheme`, `getLanguage`, `setLanguage`, `themes`) pour `app.js` et `lt-theme.js` ; événement `lt:ui5-ready` sur `document` une fois UI5 démarré. |
| **Vérification** | `npm run ui5:check` (base de démo + serveur intégré + Edge) : aucune violation CSP ni erreur JS, variables appliquées sur chaque page, police 72, bascule clair/sombre mémorisée, compact/cozy, contraste élevé, textes UI5 en français, bordereau en thème clair ; coque et Launchpad : tuiles par rôle, exceptions en premier, ouverture à la souris et au clavier, recherche, langue, déconnexion, réorganisation tablette/téléphone. |
| **Événements UI5** | Les composants UI5 émettent leurs propres événements (`click` d'un en-tête de carte, `item-click`, `profile-click`, `search`…) : `app.js` écoute sur l'élément (`onUi5`), pas par délégation jQuery. Les éléments qui ouvrent une page portent `data-lt-href`. |
| **Liens vers une liste filtrée** | `LT.initTable` recopie la chaîne de requête dans le formulaire de filtres (`/mails?status=registered&mine=1`) et le terme `?q=` dans la recherche du tableau : c'est ce qu'utilisent les tuiles et la recherche de la ShellBar. |

## 11. Coque et page d'accueil (Launchpad)

**Coque** (`views/layouts/main.php`), identique sur tous les écrans :

- `ui5-shellbar` : bouton de menu (replie la navigation), logo et titre (retour à l'accueil), **recherche globale** (ouvre la liste du courrier filtrée par `?q=`), **cloche** avec le nombre de notifications non lues (rafraîchi chaque minute), **avatar** aux initiales de l'utilisateur.
- `ui5-user-menu` (clic sur l'avatar) : nom, rôle et adresse ; **thème** clair/sombre ; **langue** ; **déconnexion**. Un invité (page de connexion) n'a pas de profil : thème et langue sont deux actions directes de la ShellBar.
- `ui5-side-navigation`, filtrée par permission ; étendue sur poste de travail, repliée sur tablette, en panneau sur téléphone (`ui5-navigation-layout`). Règles :
  - **Ordre** : le travail quotidien en haut, sans titre de groupe (Accueil, Enregistrer un courrier, Courrier et ses raccourcis, Registre, Correspondants) ; puis le groupe « Pilotage » ; en bas, dans la zone fixe (`slot="fixedItems"`), les réglages personnels et d'administration (Absences, Conservation).
  - **Action de création** : « Enregistrer un courrier » est une entrée `design="Action"`, visible avec `mail.create`. C'est la seule action du menu.
  - **Raccourcis** : « Courrier » ne fait qu'ouvrir et refermer ses raccourcis (pas d'adresse propre : cliquer pour replier ne doit pas changer d'écran). Dessous, des `ui5-side-navigation-sub-item` ouvrent la liste entière (Tous les courriers) ou déjà filtrée (Mes courriers, Mes retards, À affecter), avec les mêmes adresses que les tuiles. Le raccourci dont les paramètres correspondent à l'adresse est sélectionné.
  - **Compteurs** : « Mes retards (3) », « À affecter (5) », lus sur `/navigation/counts` au chargement puis chaque minute ; rien entre parenthèses quand le compte est à zéro. Un utilisateur ne reçoit que les compteurs de ses permissions.
  - **Pas de doublon avec la ShellBar** : les notifications s'ouvrent par la cloche, pas par le menu.
  - **Replié ou déplié** : le choix fait avec le bouton de menu est mémorisé par appareil (`localStorage` `lt.nav`).

**Page d'accueil** (`views/home/index.php`, floorplan Launchpad). Les règles sont dans `App\Domain\Launchpad\Launchpad` (classe pure, testée) ; les chiffres sont réunis par `LaunchpadService`, qui ne calcule que ceux que le rôle verra.

- **Gestion par exception** : le premier groupe, « À traiter », réunit les compteurs qui appellent une action. Ses tuiles sont triées par gravité : `Negative` (retards), puis `Critical` (à affecter, échéances du jour), puis `Information` (notifications), puis les compteurs à zéro. Une tuile en alerte affiche son chiffre dans la couleur sémantique **et** un `ui5-tag` « À traiter » (jamais la couleur seule). Les autres groupes gardent un ordre fixe.
- **Tuile dynamique** : un compteur ou un indicateur issu des données, avec son unité. **Tuile de lancement** : titre et sous-titre seulement.
- **Une tuile ouvre l'écran déjà filtré** (`/mails?mine=1&overdue=1`), à la souris comme au clavier (Entrée ou Espace).
- **Réorganisation** : grille fluide (`auto-fill`) sur poste de travail, tuiles plus étroites sous 1024 px, deux colonnes sous 600 px, une tuile par ligne sous 480 px.

### Groupes et tuiles

| Groupe | Tuile | Chiffre affiché | Alerte si > 0 | Permission (au moins une) | Ouvre |
|---|---|---|---|---|---|
| **À traiter** | Mes courriers en retard | mes courriers dont l'échéance est dépassée | Negative | `mail.update` | `/mails?mine=1&overdue=1` |
| | Courriers à affecter | entrants au statut « enregistré » | Critical | `mail.assign` | `/mails?direction=incoming&status=registered` |
| | Retards du périmètre | courriers en retard, tous services | Negative | `mail.assign`, `reports.view` | `/mails?overdue=1` |
| | À traiter aujourd'hui | mes échéances du jour | Critical | `mail.update` | `/mails?mine=1` |
| | Notifications | non lues | Information | `mail.view` | `/notifications` |
| **Courrier** | Enregistrer un courrier entrant | enregistrés aujourd'hui | — | `mail.create` | `/mails/new` |
| | Créer un courrier sortant | — | — | `mail.create` | `/mails/new?direction=outgoing` |
| | Courriers | en cours de traitement | — | `mail.view` | `/mails` |
| | Mes échéances | mes échéances sous 7 jours | — | `mail.update` | `/mails?mine=1` |
| | Registre | — | — | `mail.view` | `/register` |
| | Correspondants | — | — | `correspondents.manage` | `/correspondents` |
| **Pilotage** | Vue d'ensemble | — (tuile de lancement de l'Overview Page, §15) | — | `reports.view` | `/overview` |
| | Délai de traitement | délai moyen en jours, 30 derniers jours | — | `reports.view` | `/statistics` |
| | Clôtures en retard | % clos après l'échéance, 30 derniers jours | — | `reports.view` | `/statistics` |
| | Échéances à venir | échéances sous 7 jours, tous services | — | `reports.view` | `/mails` |
| **Mon espace** | Mes absences | délégations en cours ou à venir | — | `mail.view` | `/delegations` |
| **Administration** | Conservation | règles actives | — | `settings.manage` | `/retention-rules` |

« Mes courriers » = ceux qui me sont affectés pour traitement, et ceux d'un collègue absent que je remplace.

### Rôles → groupes

| Rôle | À traiter | Courrier | Pilotage | Mon espace | Administration |
|---|---|---|---|---|---|
| **Agent** | Mes retards · Aujourd'hui · Notifications | Courriers · Mes échéances · Registre | — | Mes absences | — |
| **Secrétariat** | Mes retards · À affecter · Retards du périmètre · Aujourd'hui · Notifications | Enregistrer entrant · Créer sortant · Courriers · Mes échéances · Registre · Correspondants | — | Mes absences | — |
| **Chef de service** | Mes retards · À affecter · Retards du périmètre · Aujourd'hui · Notifications | Courriers · Mes échéances · Registre | Vue d'ensemble · Délai · Clôtures en retard · Échéances à venir | Mes absences | — |
| **Direction** (tous sites) | Retards du périmètre · Notifications | Courriers · Registre | Vue d'ensemble · Délai · Clôtures en retard · Échéances à venir | Mes absences | — |
| **Administrateur** | comme le secrétariat | comme le secrétariat | comme le chef de service | Mes absences | Conservation |

Ce tableau est vérifié par `tests/Domain/LaunchpadTest.php`. Ajouter une tuile : une ligne dans `Launchpad::TILES`, ses libellés dans `lang/*.php` (`launchpad.tiles.<clé>`), son chiffre dans `LaunchpadService`, son icône dans `tools/ui5/components.mjs` si elle n'y est pas, puis ces deux tableaux et le test.

## 12. Modèle List Report

Un écran de liste se construit à partir du gabarit, jamais en copiant un autre écran. Écrans qui l'utilisent : liste du courrier (`views/mails/index.php`, la plus complète), correspondants, registre.

### Structure de la page

De haut en bas, dans un `ui5-dynamic-page data-lt-list-report` :

1. **Titre** : nom de l'écran et, sur la même ligne, bouton des vues enregistrées ; résumé des filtres actifs quand l'en-tête est replié.
2. **Barre de filtres** (en-tête dépliable) : recherche à gauche, filtres, puis « Exécuter » (seul bouton `Emphasized` de l'écran) et « Réinitialiser ». Rien n'est rechargé avant « Exécuter » ou Entrée. Sur téléphone, la barre s'ouvre repliée.
3. **En-tête du tableau** : titre avec le nombre de lignes, « Courriers (128) », puis la barre d'outils dans cet ordre : créer, actions de masse, exports, réglages du tableau.
4. **Tableau** DataTables compact (§2) : une ligne par objet, tri au clic sur l'en-tête, pagination en bas. Un clic ou Entrée sur une ligne ouvre l'objet.

### Fichiers

| Fichier | Rôle |
|---|---|
| `views/partials/list-report/assets.php` | Charge DataTables et les scripts de la liste (sections `styles`, `libraries`, `scripts`) |
| `views/partials/list-report/title.php` | Titre et bouton des vues (`title`) |
| `views/partials/list-report/toolbar-end.php` | Fin de la barre d'outils : exports (`export => true`) et réglages |
| `views/partials/list-report/table.php` | Tableau (`label`, `columns`, `selectable`) |
| `views/partials/list-report/dialogs.php` | Vues enregistrées et réglages du tableau (`id`) |
| `public/assets/js/lt-listreport.js` | Logique pure, testée par `tests/js/` : requête, filtres, vues, couleurs des statuts |
| `public/assets/js/pages/list-report.js` | Branchement dans la page ; le contrat des attributs est décrit en tête du fichier |

La vue de l'écran écrit elle-même sa barre de filtres et ses boutons de création et d'actions de masse : c'est ce qui change d'un écran à l'autre.

### Ce que la vue déclare

Sur `ui5-dynamic-page` :

| Attribut | Rôle |
|---|---|
| `data-key` | Nom de l'écran : clé des vues et des colonnes enregistrées sur l'appareil |
| `data-url` | Source des lignes, au format des tables serveur (CLAUDE.md) |
| `data-sort`, `data-dir`, `data-per-page` | Tri et taille de page de la vue Standard |
| `data-title` | Titre du tableau, complété par le compteur |
| `data-row-href`, `data-row-label` | Adresse ouverte par une ligne (`{id}`) et colonne qui nomme la ligne. Sans `data-row-href`, les lignes ne sont pas cliquables |
| `data-default-filters` | Filtres de la vue Standard, en JSON. À utiliser quand un filtre est obligatoire (sens et période du registre) |
| `data-export-source`, `data-export-set`, `data-export-title`, `data-export-subtitle` | Export : source, jeu de colonnes de `lt-export.js`, titres du document |
| `data-export-map` | Quand le document a ses propres paramètres : filtres transmis et leur nom côté serveur (`date_from:from`) |

Colonnes : une liste `[nom, clé du libellé, rendu, triable, obligatoire]` passée à `table.php`. Rendus disponibles : `text`, `strong` (la colonne qui nomme l'objet), `tag:<enum>`, `due`, `date`, `datetime`, `number`. Une colonne n'est triable que si le serveur l'accepte dans sa liste blanche.

Filtres : tout champ UI5 avec `data-lt-filter="<paramètre>"` ; la recherche porte `data-lt-search`. Un lien `?statut=…` vers l'écran applique ces filtres à l'ouverture.

### Règles

- **Vues enregistrées** : par appareil (`localStorage`). Une vue garde les filtres, la recherche, le tri et les colonnes. « Réinitialiser » revient à Standard.
- **Statuts** : toujours en `ui5-tag`, couleurs de la table du §5. Une énumération absente de cette table s'affiche en `Neutral`.
- **Sélection et actions de masse** : seulement si l'écran a une action de masse ou un export de sélection (`selectable => true`). Les boutons restent désactivés sans sélection. Une action de masse s'exécute dans un `ui5-dialog`, répond `{done, failed}` et affiche le détail des refus au-dessus du tableau.
- **Exports** : la liste filtrée, ou la sélection s'il y en a une. Limites côté serveur (CLAUDE.md).
- **Liste vide** : `ui5-illustrated-message`, jamais un tableau vide sans explication.
- **Barre compacte** : chaque filtre prend la largeur utile à son contenu, les cases à cocher sont sur une ligne et les boutons à droite ; filtres et boutons tiennent sur une ligne quand la place suffit. Pas de marge ajoutée autour du titre ni des filtres.
- **Texte long** : coupé sur une ligne, texte complet dans l'infobulle.
- **Texte d'aide de la recherche** : propre à l'écran, il nomme les champs réellement cherchés.

### Liste de paramétrage

Une liste courte, sans filtre ni pagination (règles de conservation), est rendue par le serveur dans la même mise en page : `ui5-dynamic-page`, en-tête de tableau avec « Créer », tableau `class="lt-table lt-dt lt-dt--static"`. Elle n'utilise ni `list-report.js` ni DataTables. La création se fait dans un `ui5-dialog` ouvert depuis la barre d'outils (`data-lt-open-dialog`, `data-lt-dialog-submit` de `pages/object-page.js`), rouvert par le serveur si la saisie est refusée. L'action d'une ligne est un bouton `Transparent` en fin de ligne, confirmé par `data-lt-confirm`.

## 13. Modèle Object Page

Écran de détail d'un objet (un courrier, un correspondant). UI5 Web Components n'a pas d'`ObjectPageLayout` : le modèle le compose à partir de `ui5-dynamic-page`. Écrans : fiche courrier (`views/mails/show.php`, lecture et édition), création d'un courrier (`views/mails/form.php`), correspondant (`views/correspondents/form.php`).

**Fichiers du modèle**

| Fichier | Rôle |
|---|---|
| `public/assets/js/pages/object-page.js` | Comportement, piloté par des attributs `data-lt-*` (ancres, pied de page, messages, dialogues, suggestions). Aucun code propre à un écran. |
| `views/partials/object-page/messages.php` | Bouton et popover des erreurs de validation (équivalent du MessagePopover). |
| `views/partials/object-page/confirm.php` | Dialogue de confirmation partagé par les actions de la page. |
| `App\Core\Ui5` | Assistant de vue : `tag()` (statut → `ui5-tag` sémantique), `state()` et `stateMessage()` (champ en erreur), `options()`, `enumOptions()`, `fileSize()`. |
| `app.css` (`.lt-object-page`, `.lt-kpis`, `.lt-anchor-bar`, `.lt-op-section`, `.lt-item`) | Mise en page uniquement. |

**Structure d'une page**

```html
<ui5-dynamic-page class="lt-object-page" data-lt-object-page [data-editing] [show-footer]>
  <ui5-dynamic-page-title slot="titleArea">
    <ui5-breadcrumbs slot="breadcrumbs">           liste d'origine → objet (→ « Modification »)
    <ui5-title slot="heading">                     référence — objet
    <div slot="subheading" class="lt-tags">        statut et autres états : Ui5::tag()
    <ui5-toolbar slot="actionsBar">                Modifier, actions secondaires (lecture seule)
  </ui5-dynamic-page-title>
  <ui5-dynamic-page-header slot="headerArea">
    <div class="lt-kpis"> 3 ou 4 <div class="lt-kpi">   libellé + valeur (+ ui5-tag si un état est à signaler)
  </ui5-dynamic-page-header>
  <div class="lt-object-page__content">
    <ui5-tabcontainer class="lt-anchor-bar" collapsed data-lt-anchor-bar>
      <ui5-tab text="…" data-target="id-de-section">
    <section class="lt-op-section" id="…">         titre ui5-title + contenu ; actions de section à droite du titre
  </div>
  <ui5-bar slot="footerArea" design="FloatingFooter">   actions principales, à droite
</ui5-dynamic-page>
```

**Règles**

- **En-tête dynamique** : titre, statut en `ui5-tag`, puis 3 ou 4 chiffres clés. Il se réduit de lui-même au défilement (comportement de `ui5-dynamic-page`) ; l'en-tête réduit garde le titre et le statut. Fiche courrier : échéance (avec son état), responsable (« Non affecté » signalé quand le courrier attend une affectation), correspondant, nombre de pièces jointes.
- **Sections ancrées** : une barre d'onglets sans contenu sert de barre d'ancres ; un onglet fait défiler jusqu'à sa section et l'onglet de la section visible est sélectionné pendant le défilement. Le nombre d'éléments d'une section figure dans le libellé de l'onglet (« Affectations (2) »). Une redirection après action revient sur sa section (`/mails/12#assignments`).
- **Lecture par défaut, édition par « Modifier »** : même écran, deux modes rendus par le serveur. `/mails/{id}` affiche un `ui5-form` en lecture ; `/mails/{id}/edit` rend la section principale en champs UI5 dans un `<form>`. En mode édition, les autres actions de la page (affecter, annoter, joindre…) sont retirées : une seule tâche à la fois. Les champs de création et d'édition sont un même partiel (`mails/_fields.php`).
- **Champs** : composants UI5 avec leur `name` (ils participent au formulaire HTML). L'`id` d'un champ est le nom du champ côté serveur : c'est ce qui relie une erreur à son champ. Un `ui5-label for` par champ, `required` sur le libellé et sur le champ.
- **Actions principales dans le pied de page fixe** : en édition, « Enregistrer » (`Emphasized`, `data-lt-submit="id-du-formulaire"`) et « Annuler ». En lecture, les actions de l'objet (pour un courrier : Prendre en charge, En attente de réponse, Clôturer, Rouvrir, Archiver — ce sont nos « Approuver / Rejeter »), la première en `Emphasized`. Une action est un bouton `data-lt-action="id"` qui soumet un formulaire caché ; les droits restent vérifiés par le serveur (`MailAccess`, `MailWorkflow`).
- **Confirmation** : une action irréversible porte `data-confirm`, `data-confirm-title`, `data-confirm-state` (`Critical` ou `Negative`) et, si un commentaire est utile, `data-confirm-input`. Le dialogue partagé s'ouvre avec le focus sur Annuler.
- **Actions de section** : un bouton `data-lt-open-dialog` à droite du titre de la section ouvre un `ui5-dialog data-lt-form-dialog` qui contient le formulaire (affecter, réaffecter, annoter, joindre un fichier, lier une réponse). Les champs obligatoires vides sont signalés dans le dialogue avant l'envoi.
- **Erreurs de validation** : le serveur répond 422 et rend la page en mode édition. Chaque champ en erreur est marqué (`value-state="Negative"` + message sous le champ) ; un bouton rouge du pied de page affiche le nombre d'erreurs et ouvre le popover des messages, ouvert d'office au chargement. Un clic sur un message ferme le popover, fait défiler jusqu'au champ et lui donne le focus.
- **Fil d'Ariane** : toujours présent, premier élément = la liste d'origine.
- **Listes dans les sections** : `ui5-list` (`ui5-li-custom` pour un contenu riche, `ui5-li type="Navigation"` avec `data-lt-href` pour ouvrir un autre objet), `ui5-timeline` pour l'historique (« Champ : ancienne valeur → nouvelle valeur »).

**Créer un nouvel écran de détail** : copier la structure ci-dessus, déclarer `@floorplan ObjectPage`, charger `pages/object-page.js` dans la section `scripts`, nommer les champs comme côté serveur, inclure `partials/object-page/messages` dans le pied de page avec la table champ → libellé, et `partials/object-page/confirm` si une action demande confirmation. Aucun JavaScript propre à l'écran n'est nécessaire.

**Vérifié par** : `tests/Functional` (modes, 422, messages, droits par rôle), `tests/Core/Ui5Test.php` (assistant de vue, cohérence des états avec la table JavaScript des listes), `npm run ui5:check` (en-tête réduit au défilement, ancres, dialogues, téléversement, confirmation, lien message → champ).

## 14. Formulaires

Toute saisie passe par les composants de formulaire UI5 (`ui5-form`, `ui5-form-item`, et `ui5-form-group` quand un formulaire a plusieurs groupes), jamais par des champs HTML stylés. Deux formes seulement : le **formulaire simple** et le **Wizard**.

### Wizard ou formulaire simple ?

| Utiliser un **Wizard** si… | Utiliser un **formulaire simple** si… |
|---|---|
| c'est une **création** (jamais une modification) ; | c'est une modification, ou une création courte ; |
| la saisie se découpe en **au moins 3 étapes** qui ont un sens pour l'utilisateur ; | tous les champs tiennent sur un écran, sans défilement notable (environ 8 champs) ; |
| les étapes se suivent dans un ordre naturel, ou une étape dépend de la précédente ; | les champs sont indépendants ; |
| la tâche est occasionnelle ou faite par des personnes peu habituées : on guide. | la tâche est répétée par des habitués : on ne ralentit pas. |

En cas de doute : formulaire simple. Un Wizard ne sert jamais à modifier un objet existant : la modification se fait sur l'Object Page en mode édition (§13).

| Écran | Forme | Fichier |
|---|---|---|
| Enregistrer un courrier entrant | **Wizard** : Courrier → Expéditeur → Traitement → Pièce jointe → Récapitulatif | `views/mails/wizard.php` |
| Créer un courrier sortant, répondre | formulaire simple (Object Page en création) | `views/mails/form.php` |
| Modifier un courrier, un correspondant | formulaire simple (Object Page en édition) | `views/mails/show.php`, `views/correspondents/form.php` |
| Déclarer une absence, créer une règle de conservation | formulaire simple | `views/delegations/index.php`, `views/retention/index.php` |
| Affecter, annoter, joindre, lier une réponse | formulaire simple dans un `ui5-dialog` | `views/mails/show.php` |

### Règles du Wizard

- Floorplan déclaré (`@floorplan Wizard`), `ui5-wizard content-layout="SingleStep"` dans `ui5-dynamic-page`, un seul `<form>` autour du Wizard : les étapes découpent la saisie, pas la soumission.
- **Étapes numérotées**, 3 à 6, titre court ; chaque étape commence par son titre (« 2. Expéditeur ») et une phrase d'aide.
- **Validation étape par étape** : « Étape suivante » ne s'ouvre que si l'étape est valide (champs obligatoires remplis, choix fait dans une liste de suggestions). Les étapes suivantes sont verrouillées (`disabled`) tant que la précédente n'est pas franchie ; une étape déjà franchie reste accessible depuis l'en-tête du Wizard.
- **Dernière étape = récapitulatif** en lecture seule, qui montre les valeurs telles que l'utilisateur les a vues (libellé d'une liste, date au format local, nom du fichier). C'est la seule étape qui affiche « Enregistrer ».
- **Pied de page** : « Étape précédente », « Étape suivante » (`Emphasized`), puis « Enregistrer » sur le récapitulatif, et « Annuler ».
- **Le serveur valide tout à la soumission**, exactement comme pour un formulaire simple : la validation par étape n'est qu'un confort. En cas de refus (422), le Wizard se rouvre sur la première étape en erreur, toutes les étapes déverrouillées, les valeurs conservées, et le popover de messages mène au champ, quelle que soit son étape.
- Un fichier choisi ne survit pas à un refus du serveur : l'étape le dit. Une pièce jointe refusée n'annule pas l'enregistrement de l'objet : elle est signalée.
- Comportement : `pages/wizard.js` (générique, piloté par `data-lt-wizard`, `data-lt-wizard-next`, `data-lt-wizard-previous`, `data-lt-summary`, `data-lt-requires`), chargé avec `pages/object-page.js`.

### Règles du formulaire simple

- **Structure** : `ui5-form` (un `layout` qui passe à une colonne sur téléphone), un `ui5-form-item` par champ, `ui5-form-group` avec un titre si le formulaire a plusieurs groupes (au-delà de 6 champs environ). Un seul bouton `Emphasized` (« Enregistrer » ou le verbe de l'action), à droite.
- **Où placer « Enregistrer »** : dans le **pied de page fixe** quand le formulaire est l'objet de l'écran (Object Page en création ou en édition, Wizard) ; **sous le formulaire, à droite, dans son bloc** quand il n'est qu'une partie d'un écran (déclarer une absence à côté de la liste des absences) ; **dans le pied du dialogue** quand il est dans un `ui5-dialog`. « Annuler » suit toujours l'action, jamais l'inverse ; un formulaire intégré à une page n'a pas de bouton « Annuler ».
- **Libellé obligatoire** : chaque champ a un `ui5-label slot="labelContent" for="id-du-champ" show-colon`. Pas de champ sans libellé, pas de `placeholder` en guise de libellé (le `placeholder` montre un exemple de format : `jj/mm/aaaa`).
- **Champ obligatoire** : `required` sur le libellé (astérisque) **et** sur le champ. Pas de mention « (obligatoire) » dans le texte, pas de légende « * obligatoire ». Un champ facultatif n'est pas marqué.
- **L'`id` d'un champ est son nom côté serveur** (`subject`, `due_date`) : c'est ce qui relie une erreur à son champ.
- **Aide à la saisie** — choisir le composant selon la liste :

  | Valeurs possibles | Composant |
  |---|---|
  | liste fermée courte (jusqu'à 10 valeurs environ : sens, priorité, canal, rôle) | `ui5-select` |
  | liste fermée longue (personnes, services d'un grand site) | `ui5-combobox` + `ui5-cb-item text value` : filtre à la frappe, c'est la `value` (l'identifiant) qui est envoyée |
  | recherche dans une grande table (correspondants) | `ui5-input show-suggestions data-lt-suggest` : aide à la valeur par suggestions du serveur, l'identifiant choisi va dans un champ caché (`data-target`) |
  | date, date et heure | `ui5-date-picker`, `ui5-datetime-picker` avec `value-format` ISO et `display-format` local |
  | nombre borné | `ui5-step-input` avec `min` et `max` |
  | oui / non | `ui5-checkbox` (ou `ui5-switch` pour un réglage à effet immédiat) |
  | texte long | `ui5-textarea growing` avec `maxlength` |
  | fichier | `ui5-file-uploader` avec `accept` |

  Une aide sous le champ (`ui5-label wrapping-type="Normal"` dans `.lt-field-stack`) quand la règle n'est pas évidente (échéance calculée, formats de fichier acceptés).
- **Validation inline** : au clic sur le bouton, les champs obligatoires vides sont signalés dans le champ, sans aller-retour (`data-lt-validate` sur le `<form>`), et le focus va au premier. Le serveur revalide toujours ; ses erreurs reviennent dans les mêmes champs (`Ui5::state()` et `Ui5::stateMessage()`), avec les valeurs saisies conservées.

### Messages d'erreur

- **Où** : dans le champ (`value-state="Negative"` + message sous le champ). Sur une Object Page ou un Wizard, s'y ajoute le popover de messages du pied de page (§13). Jamais de bandeau générique « Certains champs sont invalides » seul.
- **Quand** : au clic sur l'action (Enregistrer, Étape suivante), pas à chaque frappe. Le message disparaît dès que le champ est corrigé.
- **Format** : une phrase complète, en français, terminée par un point, qui nomme le champ et dit quoi faire.

  | Cas | Modèle | Exemple |
  |---|---|---|
  | champ obligatoire vide (serveur) | « Le champ *Libellé* est obligatoire. » | Le champ Objet est obligatoire. |
  | champ obligatoire vide (dans le champ, avant envoi) | « Ce champ est obligatoire. » | — |
  | choix à faire dans une liste | « Choisissez … » | Choisissez un élément dans la liste proposée. |
  | format | « Le champ *Libellé* doit être … » | Le champ E-mail doit être une adresse e-mail valide. |
  | règle métier | la règle, et la sortie quand il y en a une | Le courrier a déjà un responsable : utilisez la réaffectation. |

- Pas de code technique, pas de nom de colonne, pas de « invalide » sans explication. Les textes viennent de `lang/*.php` (`validation.*`, `rules.*`, `js.form.*`), jamais du code.
- **États** : `Negative` pour une erreur bloquante ; `Critical` pour un avertissement qui n'empêche pas d'enregistrer ; `Information` pour une précision. Un état n'est jamais porté par la couleur seule : le message l'accompagne.

**Vérifié par** : `tests/Functional/FormsHttpTest.php` (étapes verrouillées, réouverture sur l'étape en erreur, scan joint, formulaire simple avec erreurs dans les champs) et `npm run ui5:check` (validation par étape, suggestions, récapitulatif, validation inline, ComboBox).

## 15. Overview Page et types de cartes

L'Overview Page donne à un rôle de pilotage une vue d'ensemble en un écran : ce qui demande une décision, puis les tendances. Elle ne remplace ni le Launchpad (point d'entrée, tuiles) ni les statistiques (analyse détaillée) : **chaque carte mène à l'application détaillée**.

- **Écran** : `/overview` (`views/overview/index.php`, `@floorplan OverviewPage`), permission `reports.view` : chef de service, direction (tous sites), administrateur. Relié au Launchpad par la tuile « Vue d'ensemble » du groupe Pilotage, et à la navigation latérale.
- **Données** : `OverviewService` (aucune requête dans la vue). **États** : `App\Domain\Overview\Indicator` (classe pure, testée) ; la vue n'attribue jamais une couleur.
- **Grille** : 12 colonnes (`.lt-ov-grid`). Les cartes KPI sur une rangée, puis les cartes liste, puis les cartes graphique. Sous 1280 px : deux KPI par rangée ; sous 900 px : listes et graphiques en pleine largeur ; sous 480 px : une carte par rangée.

### Les trois types de cartes

Toutes sont des `ui5-card` avec un `ui5-card-header interactive data-lt-href="…"` : cliquer l'en-tête (ou Entrée) ouvre l'application. Titre = ce qui est mesuré ; sous-titre = le périmètre ou la période.

| Type | Contenu | Quand l'utiliser | Règles |
|---|---|---|---|
| **Carte KPI** (`data-card="kpi"`) | un chiffre, son unité, son état en `ui5-tag` | un indicateur que l'on juge d'un coup d'œil | Un seul chiffre par carte. L'état vient d'`Indicator` et s'affiche en toutes lettres (« Conforme », « À surveiller », « À traiter », « Sans donnée ») en plus de la couleur du chiffre. Sans donnée : « — » et l'état `Neutral`, jamais 0. Quatre cartes KPI au plus. |
| **Carte liste** (`data-card="list"`) | les 5 premières lignes d'une liste à traiter, et « 5 sur 22 » dans l'en-tête | montrer par quoi commencer | 5 lignes au maximum, triées par urgence (le plus ancien d'abord). Une ligne ouvre l'objet (`ui5-li type="Navigation"`) ; l'en-tête ouvre la liste complète, avec le même filtre. Liste vide : `ui5-illustrated-message` avec une phrase positive (« Aucun courrier en retard. »). |
| **Carte graphique** (`data-card="chart"`) | un graphique simple (Chart.js) et ses données en texte (`ui5-panel` repliable) | une tendance ou une répartition | Un seul graphique, barres uniquement, 2 séries et 8 catégories au plus, pas d'interaction dans la carte. Les données sont toujours disponibles en texte. Sans donnée : `ui5-illustrated-message`, pas de graphique vide. |

Pas d'autre type de carte sans l'ajouter d'abord à ce tableau. Une carte ne contient ni formulaire ni action : elle informe et renvoie.

### États des indicateurs

| Indicateur | Positive (« Conforme ») | Critical (« À surveiller ») | Negative (« À traiter ») | Ouvre |
|---|---|---|---|---|
| Courriers en retard | 0 | — | 1 ou plus | `/mails?overdue=1` |
| Courriers à affecter (`mail.assign`) | 0 | 1 ou plus | — | `/mails?direction=incoming&status=registered` |
| Délai de traitement (moyenne sur 30 jours) | ≤ 14 jours | ≤ 21 jours | > 21 jours | `/statistics` |
| Clôtures en retard (30 jours) | < 10 % | < 25 % | ≥ 25 % | `/statistics` |

`Neutral` (« Sans donnée ») quand rien n'a été clos sur la période. Les seuils sont des constantes d'`Indicator` (objectif de 14 jours = le délai par défaut d'un courrier de priorité normale).

### Couleurs

- **Jamais de couleur en dur**, ni dans la vue, ni dans `app.css`, ni dans le JavaScript.
- Chiffres et étiquettes : états sémantiques (`ui5-tag design`, `--sapPositiveTextColor`, `--sapCriticalTextColor`, `--sapNegativeTextColor`).
- Graphiques : `pages/overview.js` lit les variables du thème au moment de dessiner — séries `--sapChart_OrderedColor_1` et `_2`, valeurs négatives `--sapChart_Bad`, textes `--sapContent_LabelColor`, grille `--sapList_BorderColor` — et **redessine au changement de thème** (`lt:theme-change`). Une série qui compte des échecs (retards) prend la couleur négative ; une série neutre (volumes) prend la palette ordonnée.

**Cartes selon le rôle** : les cartes d'affectation (KPI et liste « à affecter ») ne sont rendues que pour `mail.assign` ; la direction voit donc 3 KPI et 1 liste, et la grille s'adapte (`data-kpis`, `data-lists`).

**Vérifié par** : `tests/Domain/OverviewIndicatorTest.php` (seuils), `tests/Functional/OverviewHttpTest.php` (cartes par rôle, états, liens, aucune couleur dans la page), `tests/js/overview.test.js` (couleurs lues dans le thème, aucune couleur dans le source), `npm run ui5:check` (graphiques dessinés et redessinés au changement de thème, navigation depuis les cartes et depuis la tuile).
