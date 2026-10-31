<?php

declare(strict_types=1);

/*
| Site public (cncms.tech) : accueil, à propos, contact, confidentialité, conditions.
| Ne jamais nommer ici la technologie ni les prestataires du service.
*/

return [
    'brand_by' => 'par OneTech',
    'meta_description' => 'CNCMS est le logiciel des câblodistributeurs : abonnés, manuscrits mensuels, paiements, agents de terrain et contrôle de caisse au même endroit. Démo gratuite de 4 mois.',
    'skip' => 'Aller au contenu',

    'nav' => [
        'features' => 'Fonctionnalités',
        'app' => 'Appli agents',
        'how' => 'Comment ça marche',
        'security' => 'Sécurité',
        'contact' => 'Contact',
        'about' => 'À propos',
        'login' => 'Se connecter',
        'demo' => 'Démo gratuite',
        'dashboard' => 'Ouvrir votre espace',
        'language' => 'Langue',
        'menu' => 'Menu',
    ],

    'hero' => [
        'eyebrow' => 'Logiciel pour câblodistributeurs',
        'title' => 'Gérez votre réseau câblé <em>sans papier.</em>',
        'lede' => 'Abonnés, manuscrits mensuels, paiements, agents de terrain et chaque franc en caisse, au même endroit. Conçu à Kumba avec des câblodistributeurs qui l\'utilisent chaque jour.',
        'demo' => 'Démo gratuite de 4 mois',
        'fine' => 'Aucune carte requise. Vos données restent à vous, même après la démo.',
        'shot_label' => 'Exemple du tableau de bord CNCMS',
        'shot_title' => 'Manuscrit · novembre 2026',
        'shot_tag' => 'Publié',
        'collected' => 'Encaissé',
        'customers' => 'Abonnés',
        'paid' => 'Déjà payé',
        'phone_title' => 'Paiement enregistré',
        'phone_line' => 'Reçu 000412 · Fiango',
        'phone_wa' => 'Envoyer sur WhatsApp',
        'sample' => 'Chiffres d\'exemple',
    ],

    'clients' => [
        'title' => 'Câblodistributeurs qui utilisent CNCMS',
        'slot_title' => 'Votre entreprise',
        'slot_text' => 'Apparaît ici quand vous nous rejoignez',
    ],

    'pains' => [
        'eyebrow' => 'Ça vous parle ?',
        'title' => 'Les problèmes que tout câblodistributeur connaît',
        'items' => [
            ['L\'argent ne tombe pas juste', 'Encaissements, versements et caisse ne concordent jamais, et personne ne sait où est l\'écart.'],
            ['Des manuscrits sur papier', 'Les cahiers se perdent, sont mal recopiés ou réécrits à la main chaque mois.'],
            ['L\'argent des agents, introuvable', 'Vous découvrez les manquants des semaines plus tard, quand vous les découvrez.'],
            ['Quelle zone paie ?', 'Aucune vue claire de qui a payé, qui doit, et quelle zone recule.'],
        ],
    ],

    'features' => [
        'eyebrow' => 'Ce que fait CNCMS',
        'title' => 'Tout ce qui fait tourner une entreprise de câble, dans un seul système',
        'items' => [
            'customers' => ['Abonnés et zones', 'Chaque abonné, ses services, son prix et sa zone. Archivez sans perdre l\'historique.'],
            'manuscripts' => ['Manuscrits et factures', 'Générez le mois en quelques minutes, reportez dettes et avances, imprimez les factures par zone.'],
            'payments' => ['Paiements et reçus', 'Un reçu numéroté pour chaque paiement, partagé sur WhatsApp en un geste.'],
            'app' => ['Application des agents', 'Les agents enregistrent les paiements sur le terrain, même sans réseau. Tout se synchronise au retour du signal.'],
            'cash' => ['Contrôle de caisse', 'Caisses, versements, manquants et un bilan mensuel qui montre chaque franc.'],
            'zones' => ['Performance des zones', 'Voyez quelles zones paient et recevez une alerte quand l\'une baisse.'],
            'expenses' => ['Dépenses et validations', 'Bons, avances et salaires, validés par les bonnes personnes avant que l\'argent ne sorte.'],
            'complaints' => ['Plaintes et coupures', 'Enregistrez les pannes, planifiez coupures et rebranchements, facturez les bons frais.'],
            'roles' => ['Rôles et agences', 'Décidez qui voit et fait quoi, agence par agence. Chaque action est enregistrée.'],
        ],
    ],

    'app' => [
        'eyebrow' => 'L\'application des agents',
        'title' => 'Votre bureau, dans la poche de chaque agent',
        'lede' => 'Une application Android rapide pour vos agents de terrain. Elle fonctionne sans réseau et envoie tout au bureau dès que le signal revient.',
        'label' => 'Exemple d\'écran de l\'application des agents',
        'zone' => 'Fiango · 214 abonnés',
        'offline' => 'Hors ligne · 3 en attente',
        'search' => 'Nom, téléphone ou n° de décodeur',
        'owes_1' => 'Doit 5 000 frs',
        'paid' => 'Payé · novembre',
        'owes_2' => 'Doit 2 500 frs',
        'cut' => 'À couper',
        'pay' => 'Payer',
        'tabs' => ['Accueil', 'Abonnés', 'Payer', 'Plus'],
        'items' => [
            ['Fonctionne hors ligne', 'Enregistrez les paiements dans n\'importe quel village, synchronisez plus tard. Rien ne se perd.'],
            ['Un paiement en quelques secondes', 'Trouvez l\'abonné, saisissez le montant, et le reçu part sur WhatsApp.'],
            ['Leur zone, leur liste', 'Qui a payé, qui doit, et qui est à couper.'],
            ['Travail de terrain', 'Nouveaux abonnés, coupures, rebranchements, plaintes et dépenses.'],
            ['Remise de caisse', 'Les agents remettent l\'argent au bureau dans l\'application : chaque franc laisse une trace.'],
            ['Aussi pour les gérants', 'Le manuscrit du mois, la performance des zones, les rapports et les alertes sur le téléphone.'],
        ],
    ],

    'local' => [
        ['Réseau faible ? Pas de souci.', 'Des pages légères et une application qui marche hors ligne.'],
        ['Anglais et français', 'Chacun choisit sa langue.'],
        ['Pensé pour le FCFA', 'Factures mensuelles, dettes et avances, comme le câble fonctionne ici.'],
        ['Reçus WhatsApp', 'Les abonnés reçoivent la preuve de paiement sur le téléphone qu\'ils utilisent déjà.'],
    ],

    'how' => [
        'eyebrow' => 'Comment ça marche',
        'title' => 'Configurez votre entreprise vous-même, en quatre étapes',
        'lede' => 'Un guide de configuration vous montre chaque étape lors de votre première connexion. En cas de blocage, notre équipe est à un message WhatsApp.',
        'steps' => [
            ['Inscrivez-vous à la démo', 'Remplissez le formulaire d\'inscription. Une fois votre espace approuvé, vous vous connectez comme administrateur de l\'entreprise.'],
            ['Ajoutez vos zones', 'Téléchargez le modèle de zones, remplissez vos zones et importez-le. Ou ajoutez-les une à une à l\'écran.'],
            ['Ajoutez vos abonnés', 'Téléchargez le modèle d\'abonnés, remplissez-le depuis vos cahiers et importez-le. Chaque abonné arrive dans la bonne zone.'],
            ['Faites venir vos agents', 'Créez leurs comptes et montrez-leur comment télécharger l\'application. Puis lancez votre premier mois.'],
        ],
        'guide_label' => 'Exemple du guide de configuration',
        'guide_who' => 'Guide de configuration',
        'guide_msg' => 'Bienvenue sur CNCMS ! Vous avez 0 zone pour l\'instant. Étape 2 sur 4 : téléchargez le modèle de zones, une zone par ligne, puis importez-le ici.',
        'guide_b1' => 'Télécharger le modèle de zones',
        'guide_b2' => 'Ajouter les zones à la main',
        'guide_title' => 'Un guide à l\'écran, pas un manuel',
        'guide_text' => 'Dès votre première connexion, le guide vérifie ce qui est fait et vous montre l\'étape suivante : zones, abonnés, employés, application des agents, premier mois.',
    ],

    'security' => [
        'eyebrow' => 'Sécurité',
        'title' => 'Les données de votre entreprise, protégées',
        'items' => [
            'Connexion en deux étapes pour les gérants et propriétaires',
            'Chaque paiement, modification et validation est enregistré, avec qui et quand',
            'Sauvegardes chiffrées chaque nuit, gardées à deux endroits sûrs et séparés',
            'Chaque entreprise ne voit que ses données. Nous ne les vendons ni ne les partageons jamais.',
        ],
        'log_title' => 'Journal d\'activité · aujourd\'hui',
        'log' => [
            ['08:12', 'L\'agent Brenda a ouvert la caisse de Fiango'],
            ['10:47', 'Reçu 000412 émis · 2 500 frs'],
            ['13:05', 'Bon V-118 validé par le gérant'],
            ['17:30', 'Caisse fermée · le compte est juste'],
        ],
    ],

    'quote' => [
        'text' => '« Nous passions la première semaine de chaque mois à réécrire les cahiers. Aujourd\'hui, le mois est prêt avant de commencer. »',
        'by' => 'MDCN — Multimedia Digital Cable Network, Kumba',
    ],

    'faq' => [
        'eyebrow' => 'Questions',
        'title' => 'Questions fréquentes',
        'items' => [
            ['Que se passe-t-il après les 4 mois de démo ?', 'Vous choisissez. Continuer avec un abonnement payant, ou arrêter. Si vous arrêtez, vous pouvez toujours vous connecter et exporter vos données. Nous ne les supprimons jamais sans vous demander.'],
            ['Puis-je importer les abonnés de mes cahiers ?', 'Oui. Téléchargez le modèle d\'abonnés, remplissez-le et importez-le. Les zones fonctionnent de la même façon.'],
            ['Mes agents ont-ils besoin d\'internet en permanence ?', 'Non. L\'application enregistre les paiements hors ligne et les envoie au retour du réseau.'],
            ['Les données de mon entreprise sont-elles en sécurité ?', 'Chaque entreprise ne voit que ses données. Les gérants peuvent activer la connexion en deux étapes, chaque action est enregistrée, et des sauvegardes chiffrées sont faites chaque nuit et gardées à deux endroits différents.'],
            ['Nous sommes dans plusieurs villes. Ça marche ?', 'Oui. Gérez plusieurs agences dans une seule entreprise, chacune avec ses employés, ses zones et ses prix, et voyez-les ensemble.'],
            ['Combien ça coûte après la démo ?', 'Cela dépend de la taille de votre réseau. Appelez-nous ou écrivez sur WhatsApp au :phone pour un prix.'],
        ],
    ],

    'demo' => [
        'eyebrow' => 'Gratuit pendant 4 mois',
        'title' => 'Essayez CNCMS avec vos propres abonnés',
        'lede' => 'Quatre mois, le système complet, sans paiement. À la fin, vous choisissez : continuer, ou partir avec vos données.',
        'cta' => 'S\'inscrire à la démo gratuite',
        'call' => 'Vous préférez parler d\'abord ? Appelez-nous ou écrivez sur WhatsApp.',
    ],

    'contact' => [
        'title' => 'Contactez-nous',
        'lede' => 'Une question sur CNCMS, la démo ou votre compte ? Notre équipe répond par téléphone, WhatsApp et e-mail.',
        'phone' => 'Téléphone',
        'whatsapp' => 'WhatsApp',
        'email' => 'E-mail',
        'office' => 'Bureau',
        'office_value' => 'Kumba, Cameroun',
        'chat' => 'Écrivez-nous',
        'support_title' => 'Vous utilisez déjà CNCMS ?',
        'support_text' => 'Indiquez le nom de votre entreprise quand vous écrivez, pour que nous puissions vous aider plus vite.',
    ],

    'about' => [
        'title' => 'À propos de OneTech',
        'lede' => 'OneTech conçoit CNCMS, le logiciel des câblodistributeurs au Cameroun.',
        'body' => [
            'CNCMS est né à Kumba, au cœur d\'une entreprise de câble en activité. Chaque mois, les mêmes problèmes revenaient : des manuscrits réécrits à la main, des encaissements qui ne correspondaient pas à la caisse, et aucune vue claire des zones qui payaient.',
            'Nous avons créé CNCMS pour régler ces problèmes une fois pour toutes : un seul endroit pour les abonnés, les manuscrits mensuels, les paiements, les agents de terrain et la caisse, avec une application qui continue de marcher quand le réseau ne marche pas.',
            'Aujourd\'hui, CNCMS fait tourner de vrais réseaux câblés chaque jour. Nous l\'améliorons sans cesse avec les câblodistributeurs qui l\'utilisent, et notre équipe est à un appel ou un message WhatsApp.',
        ],
        'values_title' => 'Ce qui compte pour nous',
        'values' => [
            ['Des comptes qui tombent juste', 'Chaque franc encaissé se suit de l\'abonné jusqu\'à la banque.'],
            ['Pensé pour ici', 'FCFA, anglais et français, WhatsApp, et un réseau qui va et vient.'],
            ['Vos données sont à vous', 'Chaque entreprise ne voit que ses données, et peut les emporter à tout moment.'],
        ],
    ],

    'legal' => [
        'privacy' => 'Politique de confidentialité',
        'terms' => 'Conditions d\'utilisation',
        'contract' => 'Contrat de service',
        'version' => 'Version :version · en vigueur depuis le :date',
        'questions' => 'Une question sur ce document ? Écrivez à :email.',
    ],

    'footer' => [
        'rights' => '© :year OneTech · Kumba, Cameroun',
        'privacy' => 'Confidentialité',
        'terms' => 'Conditions',
    ],

    'whatsapp_float' => 'Écrivez-nous',

    // App\Support\DemoReadOnly: a write refused after the free demo.
    'demo_read_only' => 'Votre démo gratuite est terminée : plus rien de nouveau ne peut être enregistré. Vous pouvez toujours tout consulter et exporter. Contactez :company sur WhatsApp au :phone pour activer votre compte.',
];
