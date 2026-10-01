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
- **Chargement** : `<script type="module" src="…/ui5.js">` dans le layout, après `app.js`. Aucun script inline : la configuration (thème, langue, densité) passe par des attributs de `<html>`, écrits par `lt-theme.js` et `lang`.
- **Interdit** : un composant visuel maison quand un équivalent UI5 existe. Restent autorisés, sans équivalent UI5 : Chart.js (graphiques, couleurs issues des variables du thème), ExcelJS et pdfmake (exports, rien de visible), le code-barres SVG du bordereau.

| Besoin | Composant UI5 | Remplace |
|---|---|---|
| Barre supérieure, cloche, profil | `ui5-shellbar` (`notifications-count`, `show-notifications`) | en-tête maison |
| Navigation | `ui5-side-navigation` | menu maison |
| Liste avec pagination serveur | `ui5-table` + `ui5-table-growing` (mode bouton « Plus ») | DataTables |
| Champs | `ui5-input`, `ui5-select`, `ui5-date-picker`, `ui5-datetime-picker`, `ui5-textarea`, `ui5-checkbox`, `ui5-label` | champs natifs stylés |
| Autocomplétion du correspondant | `ui5-input show-suggestions` + `ui5-suggestion-item` | picker maison |
| Statut / priorité | `ui5-tag` avec `design` sémantique (§5) | badges maison |
| Messages | `ui5-message-strip`, `ui5-toast` | alertes maison |
| Confirmation | `ui5-dialog` (en-tête et pied normalisés) | SweetAlert2 |
| Historique | `ui5-timeline` | timeline maison |
| Pièces jointes | `ui5-upload-collection` | liste et formulaire maison |
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
- **Graphiques** : `dashboard.js` lit les couleurs dans les variables du thème (`--sapChart_OrderedColor_1..11`, `--sapChart_Bad/Critical/Good`) et redessine au changement de thème (`lt:theme-change`). *Pas encore fait : `dashboard.js` garde sa palette en dur jusqu'à la migration de l'écran Statistiques.*

## 4. Floorplans

Chaque vue déclare son floorplan en tête de fichier (`/** @floorplan ListReport */`) ; un test d'architecture vérifie que la valeur fait partie de la liste ci-dessous. Floorplans autorisés : **Launchpad, List Report, Object Page, Worklist, Overview Page, Analytical List Page, Wizard**.

| Écran | Floorplan | Composition UI5 Web Components |
|---|---|---|
| Accueil (`/`) | **Overview Page** | `ui5-dynamic-page` + `ui5-card` (Mes échéances, À traiter, Notifications) |
| Liste du courrier (`/mails`) | **List Report** | `ui5-dynamic-page` (filtres dans l'en-tête dépliable) + `ui5-table` |
| Fiche courrier (`/mails/{id}`) | **Object Page** | `ui5-dynamic-page` (titre = référence, `ui5-tag` statut) + sections + pied de page `ui5-bar` |
| Enregistrer un courrier | **Wizard** (entrant) / **Object Page** en création (sortant) | `ui5-wizard` : Identification → Correspondant → Pièces jointes → Récapitulatif |
| Mes courriers, Notifications | **Worklist** | `ui5-dynamic-page` + `ui5-table` / `ui5-list`, sans filtres avancés |
| Statistiques (`/statistics`) | **Analytical List Page** | en-tête de filtres + `ui5-card` (Chart.js) + tableau de détail |
| Registre, Correspondants, Absences, Conservation | **List Report** | idem liste du courrier |
| Fiche correspondant | **Object Page** | idem fiche courrier |
| Menu des applications (évolution) | **Launchpad** | `ui5-shellbar` + `ui5-card` par application |

Les floorplans sont des compositions Fiori : UI5 Web Components n'a pas de composant « List Report » prêt à l'emploi. On respecte leur structure (en-tête, zone de contenu, pied de page), pas une reproduction au pixel près.

## 5. Interactions

- **Actions principales** de l'Object Page dans le **pied de page** (`ui5-dynamic-page` > `slot="footerArea"` > `ui5-bar`), alignées à droite. Une seule `Emphasized`, les autres `Transparent`. Les actions de section (Affecter, Ajouter une pièce jointe) vont dans la barre d'outils de la section.
- **Statuts** : `ui5-tag` avec un état sémantique, jamais une couleur choisie à la main.

  | Statut / valeur | `design` |
  |---|---|
  | Enregistré | `Information` |
  | Affecté, En cours | `Set2` / `Information` |
  | En attente de réponse | `Critical` |
  | Répondu, Clos | `Positive` |
  | Archivé | `Neutral` |
  | En retard | `Negative` |
  | Priorité urgente / haute | `Negative` / `Critical` |

  La correspondance est centralisée dans un seul module JS et dans un helper de vue PHP.
- **Messages** :
  - erreurs de validation : `value-state="Negative"` et `slot="valueStateMessage"` sur le champ concerné ;
  - message de page (succès, échec, avertissement) : `ui5-message-strip` en haut du contenu ;
  - succès d'une action rapide : `ui5-toast` ;
  - plusieurs messages à la fois : bouton dans le pied de page qui ouvre un `ui5-popover` contenant un `ui5-list` (équivalent du MessagePopover, absent des Web Components).
- **Actions destructives ou irréversibles** (clôturer, archiver, réaffecter, annuler une absence, désactiver une règle) : confirmation par `ui5-dialog` (`state="Critical"` ou `"Negative"`), bouton de confirmation à droite, focus initial sur Annuler. Commentaire facultatif via un `ui5-textarea` dans le dialogue. Jamais `window.confirm()`.
- **Navigation** : une ligne de tableau ouvre l'Object Page (`ui5-table-row` `interactive`) ; retour par le bouton de la shellbar ; fil d'Ariane `ui5-breadcrumbs` au-delà d'un niveau.
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
| **Thème et densité** | `public/assets/js/lt-theme.js`, chargé dans `<head>` avant le CSS : choisit le thème (préférence `localStorage` `lt.theme` = `light`/`dark`, contraste système) et la densité (pointeur), et les écrit sur `<html>` (`data-lt-theme`, `data-lt-density`, classe `ui5-content-density-compact`). Le premier module du bundle (`tools/ui5/prelude.js`) lit `data-lt-theme` avant la définition des composants. Fonctions de décision pures, testées (`tests/js/lt-theme.test.js`). Événement `lt:theme-change` à chaque changement. |
| **Documents imprimables** | `<html data-lt-theme-lock="sap_horizon">` (layout `print`) : le bordereau reste en thème clair, quelle que soit la préférence. |
| **Police 72** | Servie localement : `ui5-fonts.css` (mêmes `@font-face` qu'UI5, chemins vers `fonts/`) dans le `<head>`, chargement par défaut d'UI5 désactivé (`setDefaultFontLoading(false)`, UI5 irait sinon sur jsDelivr). |
| **Langue et CLDR** | `setLanguage(<html lang>)` est appelé en fin de point d'entrée, après l'enregistrement explicite des chargeurs CLDR locaux fr/en : sinon UI5 se rabat sur son chargeur « en » qui télécharge depuis jsDelivr (bloqué par la CSP). |
| **CSP** | Inchangée (`script-src 'self'; style-src 'self'; connect-src 'self'; font-src 'self'`). UI5 injecte ses styles par feuilles construites (`adoptedStyleSheets`), non soumises à `style-src`. Aucune requête externe : vérifié par `npm run ui5:check`. |
| **Affichage initial** | La page reste masquée jusqu'à l'application des variables du thème (classe `lt-ui5-ready` posée après `boot()`), avec un filet de sécurité CSS : elle s'affiche après 1,5 s même si le bundle ne se charge pas. |
| **Variables locales** | Seules des dimensions de mise en page restent en `--lt-*` (`--lt-topbar-h`, `--lt-sidebar-w`, `--lt-content-max`, `--lt-tap`, `--lt-transition`). Les nuances sont dérivées des variables du thème par `color-mix()`, jamais écrites. Vérifié par `tests/Architecture/ArchitectureTest.php` (`testAppCssUsesOnlyThemeVariables`). |
| **API navigateur** | `window.LT_UI5` (`getTheme`, `setTheme`, `getLanguage`, `setLanguage`, `themes`) pour `app.js` et `lt-theme.js` ; événement `lt:ui5-ready` sur `document` une fois UI5 démarré. |
| **Vérification** | `npm run ui5:check` (base de démo + serveur intégré + Edge) : aucune violation CSP ni erreur JS, variables appliquées sur chaque page, police 72, bascule clair/sombre mémorisée, compact/cozy, contraste élevé, textes UI5 en français, bordereau en thème clair. |
