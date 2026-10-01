# Tâche quotidienne Lettie dans Plesk

Lettie a besoin d'une tâche planifiée qui s'exécute **une fois par jour**. Elle lance `bin/send-reminders.php`, qui :

1. **Rappels d'échéance** : crée des notifications dans l'application.
   - Courriers en retard : une notification par jour, jusqu'à ce que le courrier soit traité.
   - Échéance dans les 3 jours : une seule notification.
   - Destinataires : le responsable du courrier, les membres du service affecté, ou à défaut le secrétariat et les chefs de service. Pendant une absence, le délégué est prévenu aussi.
2. **Règles de conservation** : applique les règles définies dans *Conservation* (menu réservé aux administrateurs).
   - Archivage des courriers clos.
   - Suppression des fichiers joints des courriers archivés. Le nom, la taille et l'empreinte SHA-256 de chaque fichier restent enregistrés.
   - Notification « à examiner » aux administrateurs.
   - Aucun courrier n'est jamais détruit automatiquement.
3. **Nettoyage** : supprime les notifications lues et les tentatives de connexion de plus de 90 jours.

Sans cette tâche, l'application fonctionne, mais **aucun rappel n'est envoyé et aucune règle de conservation n'est appliquée**.

Relancer le script ne crée pas de doublons : on peut l'exécuter une seconde fois le même jour sans risque.

---

## 1. Prérequis

| Point | Où le vérifier dans Plesk |
|---|---|
| **PHP 8.2 ou plus** avec les extensions `pdo_mysql`, `fileinfo`, `mbstring` | *Sites Web & Domaines* → domaine → *Paramètres PHP* |
| Les **migrations** ont été appliquées (`bin/migrate.php`) | — |
| Le fichier `.env` existe à la racine de l'application et est lisible par l'utilisateur système de l'abonnement | *Gestionnaire de fichiers* → `.env` → *Modifier les permissions* |
| Le dossier `storage/` (ou `STORAGE_PATH`) est **accessible en écriture** par cet utilisateur : le script y pose un verrou | *Gestionnaire de fichiers* |
| La **racine du document** du site pointe sur `…/public`, pas sur la racine de l'application | *Paramètres d'hébergement* → *Racine du document* |

Le dernier point est indispensable pour la sécurité. Si la racine du document est la racine de l'application, le navigateur donne accès à `.env` (mots de passe), `vendor/`, `storage/` (pièces jointes), etc.

Exemple d'arborescence avec l'abonnement `example.org` :

```
/var/www/vhosts/example.org/
└── lettie/                  ← application (hors racine web)
    ├── .env
    ├── bin/send-reminders.php
    ├── public/              ← racine du document du site
    └── storage/             ← pièces jointes, verrous (hors racine web)
```

---

## 2. Créer la tâche

1. Ouvrir *Sites Web & Domaines* → **Tâches planifiées** (dans l'onglet *Tableau de bord* ou *Outils de développement*, selon la version de Plesk).
2. Cliquer sur **Ajouter une tâche**.
3. Remplir le formulaire :

| Champ | Valeur |
|---|---|
| **Tâche active** | cochée |
| **Type de tâche** | **Exécuter un script PHP** |
| **Chemin du script** | `lettie/bin/send-reminders.php` (le bouton parcourir part du dossier de l'abonnement) |
| **Avec les arguments** | *(vide)*. Voir [Options](#4-options) pour les cas particuliers. |
| **Utiliser la version de PHP** | la même version que le site, **8.2 ou plus** |
| **Exécuter** | **Quotidiennement**, à **06:30** |
| **Description** | `Lettie – rappels d'échéance et conservation` |
| **Notifier** | **En cas d'erreur** |
| **Adresse e-mail** | l'adresse de l'administrateur technique |

4. Enregistrer avec **OK**.

### Pourquoi 06:30 ?

- Les rappels sont là quand les agents arrivent, et la tâche tourne en dehors des heures de travail.
- Le script calcule « aujourd'hui » dans le fuseau `APP_TIMEZONE` du `.env` (par défaut `Europe/Paris`). L'heure de déclenchement, elle, dépend du fuseau du serveur.
  - Si le serveur est réglé en UTC, 06:30 UTC correspond à 07:30 ou 08:30 à Paris : c'est acceptable.
  - Évitez de programmer la tâche entre 22:00 et 02:00 UTC : la date de Paris et celle du serveur peuvent alors différer.

### Si « Exécuter un script PHP » n'est pas disponible

Choisissez **Exécuter une commande** et indiquez le chemin complet du PHP de Plesk :

```bash
# Linux
/opt/plesk/php/8.3/bin/php /var/www/vhosts/example.org/lettie/bin/send-reminders.php
```

```bat
:: Windows (le chemin dépend de la version de PHP installée)
"C:\Program Files (x86)\Plesk\Additional\PleskPHP83\php.exe" "C:\Inetpub\vhosts\example.org\lettie\bin\send-reminders.php"
```

N'utilisez pas simplement `php` : la version par défaut du système est souvent plus ancienne que celle du site.

---

## 3. Tester avant de laisser tourner

1. Dans la liste des tâches, ouvrir la tâche et mettre temporairement **`--dry-run`** dans *Avec les arguments*.
2. Cliquer sur **Exécuter maintenant**. Plesk affiche la sortie, par exemple :

   ```
   [2026-09-30 06:30:02] send-reminders 2026-09-30 (dry run: nothing changed)
     reminders: 12 mail(s) due, 4 overdue, 8 due soon; 15 notification(s) created, 0 already sent
     retention: 3 archived, 0 file(s) purged on 0 mail(s), 0 review notification(s), 0 error(s)
     housekeeping: 0 old notification(s), 0 old login attempt(s) deleted
   ```

   En simulation, rien n'est modifié : les chiffres indiquent ce qui *serait* fait.
3. Vérifier ces chiffres, surtout les archivages et les suppressions de fichiers si des règles de conservation existent.
4. **Retirer `--dry-run`** et enregistrer.
5. Le lendemain, vérifier dans Lettie, menu **Conservation** → encadré **Tâche quotidienne** : chaque exécution y apparaît avec son statut (*Réussie* / *Échec*) et un résumé. Tant que la tâche n'a jamais tourné, un avertissement s'affiche à cet endroit.

---

## 4. Options

| Argument | Effet |
|---|---|
| *(aucun)* | Fonctionnement normal : rappels, conservation, nettoyage |
| `--dry-run` | Simulation : affiche ce qui serait fait, ne change rien |
| `--date=AAAA-MM-JJ` | Exécute comme si l'on était à cette date. Sert à rattraper une journée manquée après une panne. |
| `--only=reminders` | Seulement les rappels. Les valeurs se combinent : `--only=reminders,housekeeping` |
| `--only=retention` | Seulement la conservation |
| `--help` | Aide |

Exemple de rattrapage après deux jours d'arrêt du serveur (à lancer à la main avec *Exécuter maintenant*, puis retirer l'argument) :

```
--date=2026-10-01
```

---

## 5. Codes de sortie et notifications

Plesk envoie un e-mail **« en cas d'erreur »** quand le script se termine avec un code différent de 0 ou écrit dans la sortie d'erreur.

| Code | Signification | Que faire |
|---|---|---|
| `0` | Succès | — |
| `1` | Échec : base inaccessible, règle de conservation en erreur… Le détail est dans l'e-mail. | Lire le message. Vérifier `.env` et l'accès à la base, puis relancer avec *Exécuter maintenant*. |
| `2` | Une exécution précédente est encore en cours | Normalement rien : l'exécution en cours termine le travail. Si cela se répète chaque jour, la tâche est trop longue (voir ci-dessous). |
| `64` | Argument invalide | Corriger le champ *Avec les arguments*. |

La conservation traite au plus 500 courriers par règle et par exécution. La première fois qu'une règle s'applique à un gros historique, la sortie l'indique : `limit reached: the rest is done on the next run`. Le reste est traité les nuits suivantes, sans intervention.

---

## 6. Dépannage

| Symptôme | Cause probable |
|---|---|
| `could not find driver` | La version de PHP choisie pour la tâche n'a pas `pdo_mysql`. Choisissez la même version que le site. |
| `Environment file not readable` ou `Access denied for user` | `.env` absent, illisible par l'utilisateur de l'abonnement, ou identifiants de base erronés. |
| `Cannot create lock directory` | `storage/` n'est pas accessible en écriture par l'utilisateur de l'abonnement. |
| Les rappels arrivent avec un jour de décalage | La tâche tourne près de minuit et `APP_TIMEZONE` diffère du fuseau du serveur. Programmez-la à 06:30. |
| Aucune exécution visible dans *Conservation → Tâche quotidienne* | Tâche désactivée, mauvais chemin de script, ou erreur avant la connexion à la base. Lancez *Exécuter maintenant* et lisez la sortie. |

Le script refuse de s'exécuter depuis un navigateur. Il se trouve dans `bin/`, hors de la racine web : il ne doit jamais être rendu accessible par une URL.
