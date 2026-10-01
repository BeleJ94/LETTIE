<?php

declare(strict_types=1);

/**
 * Demo data profile: every proportion used by tools/demo/seed.php.
 * Weights are relative (they need not add up to 1). Edit here, not in the code.
 */
return [
    // ------------------------------------------------------------------ sizes
    // window_days: simulated through the services (real numbering, workflow, audit).
    // bulk_days / bulk_mails: older history written in bulk SQL before the window (large only).
    'sizes' => [
        'small' => ['window_days' => 300, 'daily_mean' => 0.8, 'bulk_days' => 0, 'bulk_mails' => 0],
        'medium' => ['window_days' => 730, 'daily_mean' => 4.5, 'bulk_days' => 0, 'bulk_mails' => 0],
        'large' => ['window_days' => 60, 'daily_mean' => 6.0, 'bulk_days' => 1095, 'bulk_mails' => 100000],
    ],

    // ------------------------------------------------------------- calendar
    // Incoming mail per working day = daily_mean × weekday × month (Poisson). Weekends and holidays: none.
    'weekday' => [1 => 1.30, 2 => 1.15, 3 => 1.00, 4 => 1.00, 5 => 0.85, 6 => 0.0, 7 => 0.0],
    'month' => [1 => 1.20, 2 => 1.00, 3 => 1.05, 4 => 1.00, 5 => 0.90, 6 => 1.00, 7 => 0.80, 8 => 0.45, 9 => 1.25, 10 => 1.10, 11 => 1.00, 12 => 0.85],
    // Registration time of day (local): uniform between these hours.
    'office_hours' => [8.0, 17.5],

    // ------------------------------------------------------------ mail mix
    'sites' => ['SIEGE' => 0.85, 'ANNEXE' => 0.15],
    // Outgoing mail registered on its own (replies come on top, from the outcome below).
    'outgoing_share' => 0.12,
    'priority' => ['low' => 0.15, 'normal' => 0.65, 'high' => 0.15, 'urgent' => 0.05],
    'channel' => ['postal' => 0.45, 'email' => 0.35, 'registered' => 0.08, 'hand_delivered' => 0.07, 'fax' => 0.02, 'other' => 0.03],
    'confidentiality' => ['internal' => 0.80, 'public' => 0.10, 'confidential' => 0.08, 'secret' => 0.02],
    // Correspondent popularity: Zipf exponent (a few organisations send most of the mail).
    'correspondent_zipf' => 1.1,
    'external_reference_share' => 0.25,
    'summary_share' => 0.35,

    // ------------------------------------------------------------ processing
    'department_only_assignment' => 0.15,      // assigned to a department, not a person
    'information_copy' => 0.10,                // extra "for information" assignment
    'reassignment' => 0.05,
    'start_delay_days' => ['median' => 0.8, 'sigma' => 0.8],      // assignment → "take charge"
    'processing_days' => ['median' => 6.0, 'sigma' => 0.9],       // reception → outcome (log-normal: long tail)
    'outcome' => ['close' => 0.55, 'reply' => 0.35, 'forgotten' => 0.10],
    'annotation' => 0.30,
    'private_annotation' => 0.10,
    'outgoing_closed' => 0.80,
    'attachments' => [0 => 0.10, 1 => 0.70, 2 => 0.20],
    'image_attachment' => 0.20,

    // ---------------------------------------------------------- organisation
    'organisation' => [
        'SIEGE' => [
            'name' => 'Communauté de communes du Val d\'Azergues',
            'address' => '12 place de la Mairie, 69480 Anse',
            'departments' => [
                'DG' => ['Direction générale', 0.12],
                'RH' => ['Ressources humaines', 0.20],
                'FIN' => ['Finances', 0.22],
                'TECH' => ['Services techniques', 0.30],
                'JUR' => ['Affaires juridiques', 0.16],
            ],
        ],
        'ANNEXE' => [
            'name' => 'Antenne de Villefranche',
            'address' => '5 rue Nationale, 69400 Villefranche-sur-Saône',
            'departments' => [
                'ACC' => ['Accueil et état civil', 0.60],
                'URB' => ['Urbanisme', 0.40],
            ],
        ],
    ],
    // key => [login, role, first name, last name, site, department]. Keys used by screenshots: admin, secretariat, head, agent, agent2, management.
    'users' => [
        'admin' => ['claire.martin', 'admin', 'Claire', 'Martin', 'SIEGE', null],
        'secretariat' => ['sophie.bernard', 'secretariat', 'Sophie', 'Bernard', 'SIEGE', 'DG'],
        'secretariat2' => ['nadia.haddad', 'secretariat', 'Nadia', 'Haddad', 'SIEGE', 'DG'],
        'head' => ['marc.dubois', 'head_of_department', 'Marc', 'Dubois', 'SIEGE', 'FIN'],
        'agent' => ['julie.petit', 'agent', 'Julie', 'Petit', 'SIEGE', 'RH'],
        'agent2' => ['thomas.moreau', 'agent', 'Thomas', 'Moreau', 'SIEGE', 'TECH'],
        'agent3' => ['karim.benali', 'agent', 'Karim', 'Benali', 'SIEGE', 'TECH'],
        'agent4' => ['lea.fontaine', 'agent', 'Léa', 'Fontaine', 'SIEGE', 'JUR'],
        'agent5' => ['paul.garnier', 'agent', 'Paul', 'Garnier', 'SIEGE', 'DG'],
        'agent6' => ['ines.roux', 'agent', 'Inès', 'Roux', 'SIEGE', 'FIN'],
        'management' => ['helene.laurent', 'management', 'Hélène', 'Laurent', 'SIEGE', 'DG'],
        'annexe_secretariat' => ['bruno.lefevre', 'secretariat', 'Bruno', 'Lefèvre', 'ANNEXE', 'ACC'],
        'annexe_agent' => ['chloe.gauthier', 'agent', 'Chloé', 'Gauthier', 'ANNEXE', 'URB'],
    ],
    'password' => 'Demo-Lettie-2026',
    'email_domain' => 'demo.lettie.fr',

    // [name, type, organisation, address, postal code, city, site]
    'correspondents' => [
        ['Préfecture du Rhône', 'organization', null, '106 rue Pierre Corneille', '69003', 'Lyon', 'SIEGE'],
        ['Conseil départemental du Rhône', 'organization', null, '29-31 cours de la Liberté', '69003', 'Lyon', 'SIEGE'],
        ['URSSAF Rhône-Alpes', 'organization', null, '6 rue du 19 Mars 1962', '69691', 'Vénissieux', 'SIEGE'],
        ['Trésorerie de Villefranche', 'organization', null, '72 rue de Thizy', '69400', 'Villefranche-sur-Saône', 'SIEGE'],
        ['Cabinet Legrand Avocats', 'organization', null, '4 quai Jules Courmont', '69002', 'Lyon', 'SIEGE'],
        ['Syndicat des eaux du Beaujolais', 'organization', null, '1 route de Frontenas', '69620', 'Theizé', 'SIEGE'],
        ['Caisse d\'allocations familiales', 'organization', null, '67 boulevard Vivier Merle', '69003', 'Lyon', 'SIEGE'],
        ['Mairie de Lucenay', 'organization', null, 'Place de la Mairie', '69480', 'Lucenay', 'SIEGE'],
        ['Entreprise Bâti-Rhône', 'organization', null, 'ZA des Bruyères', '69400', 'Arnas', 'SIEGE'],
        ['Office de tourisme Beaujolais', 'organization', null, '96 rue de la Sous-Préfecture', '69400', 'Villefranche-sur-Saône', 'SIEGE'],
        ['Centre de gestion du Rhône', 'organization', null, '9 allée Alban Vistel', '69110', 'Sainte-Foy-lès-Lyon', 'SIEGE'],
        ['Région Auvergne-Rhône-Alpes', 'organization', null, '1 esplanade François Mitterrand', '69002', 'Lyon', 'SIEGE'],
        ['Jean Dupont', 'person', 'Association Les Amis du Patrimoine', '8 chemin des Vignes', '69480', 'Anse', 'SIEGE'],
        ['Amélie Rousseau', 'person', null, '15 rue de la Gare', '69480', 'Anse', 'SIEGE'],
        ['Mohamed Saïdi', 'person', null, '3 impasse des Lilas', '69480', 'Ambérieux', 'SIEGE'],
        ['Françoise Œillet', 'person', 'Comité des fêtes', '21 grande rue', '69480', 'Pommiers', 'SIEGE'],
        ['Sous-préfecture de Villefranche', 'organization', null, '206 rue Nationale', '69400', 'Villefranche-sur-Saône', 'ANNEXE'],
        ['Notaires associés Beaujolais', 'organization', null, '14 rue de la République', '69400', 'Villefranche-sur-Saône', 'ANNEXE'],
        ['Lucas Bernardin', 'person', null, '9 rue des Fossés', '69400', 'Villefranche-sur-Saône', 'ANNEXE'],
    ],

    'subjects' => [
        'Demande de subvention pour la fête du village', 'Convocation à la commission d\'appel d\'offres', 'Relevé annuel des cotisations',
        'Réclamation concernant l\'éclairage public', 'Demande de permis de stationnement', 'Transmission du budget primitif',
        'Avis sur le plan local d\'urbanisme', 'Demande de mise à disposition de la salle des fêtes', 'Contestation d\'une facture d\'eau',
        'Candidature spontanée — poste d\'agent technique', 'Rapport annuel sur le prix et la qualité du service', 'Invitation à la réunion des maires',
        'Demande de travaux de voirie', 'Notification d\'une dotation', 'Signalement d\'un arbre dangereux', 'Proposition de partenariat culturel',
        'Mise en demeure — marché de travaux', 'Demande de copie d\'acte', 'Questionnaire sur l\'accueil périscolaire', 'Déclaration de sinistre',
        'Demande de raccordement au réseau d\'assainissement', 'Arrêté préfectoral — restrictions d\'eau', 'Demande de congé parental',
        'Facture — entretien des espaces verts', 'Recours gracieux contre une décision', 'Demande d\'autorisation d\'occupation du domaine public',
    ],
    'summaries' => [
        'Courrier reçu en deux exemplaires. Pièces justificatives jointes.',
        'Le demandeur souhaite une réponse avant la prochaine séance du conseil.',
        'Relance d\'un premier courrier resté sans réponse.',
        'Dossier incomplet : il manque le plan de situation.',
    ],
    'annotations' => [
        'Dossier complet, en attente de validation du chef de service.',
        'Appel du demandeur : il souhaite un rendez-vous.',
        'Vu avec les services techniques, intervention planifiée.',
        'Attention, délai réglementaire de deux mois.',
        'Pièce manquante demandée par e-mail.',
    ],
];
