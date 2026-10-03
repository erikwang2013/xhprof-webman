<?php

declare(strict_types=1);

/**
 * Report-page strings — French (Français).
 *
 * Key set must match src/Core/I18n/lang/zh_CN.php key for key: no key added,
 * removed or renamed (tests/Unit/Core/I18nTest.php fails otherwise). To add a
 * string to the report page, add the key to zh_CN.php first, then fill it in
 * here.
 *
 * The value is **raw text**: a literal `<br>` is allowed (narrow table headers
 * wrap by hand), every other character is escaped on output by I18n::html() /
 * I18n::plain(). Line breaks are placed where French reads best; the Chinese
 * break positions were deliberately not copied, and the 3-line shape used for
 * the User/Sys/Memory headers is there to keep the columns narrow, not to
 * mirror the source.
 *
 * Units follow French usage: µs (not "microsec") and octets (not "bytes").
 * Inclusive/exclusive is rendered incl./excl., the wall clock as « Temps réel »,
 * peak memory as « Mémoire de pointe », samples as « Échantillons ».
 * _meta is not to be touched: lang drives <html lang>, dir=rtl would flip the page.
 */
return [
    '_meta' => [
        'lang' => 'fr',
        'name' => 'Français',
        'dir' => 'ltr',
    ],
    'report.title' => 'Rapport d\'analyse de performance XHProf',
    'report.noData' => 'Les données de profilage de cette exécution n\'existent plus (expirées ou supprimées) ; le rapport ne peut pas être généré.',
    'nav.brand' => 'Analyse de performance XHProf',
    'nav.home' => 'Accueil',
    'nav.runs' => 'Rapport d\'exécution',
    'nav.symbol' => 'Détails de la méthode',
    'nav.language' => 'Langue',
    'search.placeholder' => 'Rechercher une fonction/méthode...',
    'search.button' => 'Rechercher',
    'run.col.method' => 'Méthode de la requête',
    'run.col.time' => 'Heure de la requête',
    'run.col.ip' => 'IP source',
    'run.col.totalCalls' => 'Nombre total d\'appels de fonctions/méthodes',
    'run.desc' => 'Exécution XHProf (espace de noms=%s)',
    'runs.title' => 'Journal des requêtes',
    'runs.col.method' => 'Méthode',
    'runs.col.url' => 'URL de la requête',
    'runs.col.time' => 'Heure de la requête',
    'runs.col.wt' => 'Durée (s)',
    'runs.col.mu' => 'Mémoire (Mo)',
    'runs.col.ip' => 'IP',
    'runs.compare' => 'Comparer la sélection',
    'runs.selectAll' => 'Tout sélectionner',
    'runs.selectRow' => 'Sélectionner cette ligne',
    'runs.compareHint' => 'Sélectionnez exactement deux exécutions à comparer',
    'flat.title.top' => 'Affichage des %s fonctions principales : tri par %s',
    'flat.title.sorted' => 'Tri par %s',
    'flat.title.diff' => 'Top 100 des régressions/améliorations : tri par différence de %s',
    'flat.title.diffAll' => 'Rapport complet des différences : tri par valeur absolue de la régression/amélioration sur %s',
    'flat.displayAll' => 'tout afficher',
    'symbol.notFound' => 'Symbole %s introuvable dans l\'exécution XHProf.',
    'pc.current' => 'Fonction courante',
    'pc.exclusive' => 'Métriques excl.%s de la fonction courante',
    'pc.report' => 'Rapport parent/enfant pour %s',
    'pc.child' => 'Fonction enfant',
    'pc.childMany' => 'Fonctions enfants',
    'pc.parent' => 'Fonction parente',
    'pc.parentMany' => 'Fonctions parentes',
    'diff.run' => 'Exécution #%s : %s',
    'agg.title' => 'Rapport agrégé de %s exécutions : %s %s',
    'agg.titleOne' => 'Rapport agrégé d\'une exécution : %s %s',
    'agg.ratio' => 'au prorata (%s)',
    'common.diff' => 'Diff',
    'diff.summary' => 'Synthèse globale des différences',
    'diff.runShort' => 'Exécution #%s',
    'diff.diffPct' => 'Diff %',
    'diff.callCount' => 'Nombre d\'appels de fonctions',
    'pc.reportDiff' => 'Rapport parent/enfant %s pour %s',
    'diag.title' => 'Diagnostic',
    'diag.whySlow' => 'Pourquoi c\'est lent',
    'diag.otherFindings' => 'Autres constats',
    'diag.view' => 'voir',
    'diag.empty' => 'Aucun goulot d\'étranglement évident n\'a été détecté (seuils : temps réel excl. ≥ %s%%, nombre d\'appels ≥ %s, appels d\'arête ≥ %s, temps réel excl. de la fonction appelée ≥ %s%%, mémoire de pointe ≥ %s%%)',
    'diag.r1.title' => '%s : temps réel excl. %s, soit %s de cette requête',
    'diag.r1.detail' => 'Le temps réel excl. ne compte pas les appels enfants : c\'est uniquement le coût du corps de la fonction',
    'diag.r2.title' => '%s appelée %s fois',
    'diag.r2.detail' => 'Nombre d\'appels élevé : à vérifier, c\'est peut-être attendu',
    'diag.r3.title' => '%s → %s appelée %s fois, %s au total',
    'diag.r3.detail' => 'Cette relation d\'appel représente une part notable du temps',
    'diag.r4.title' => 'Récursion détectée dans %s(), profondeur maximale %d',
    'diag.r4.detail' => 'Une profondeur de récursion excessive peut provoquer un débordement de pile ou une croissance exponentielle du temps',
    'diag.r5.title' => '%s : mémoire de pointe %s, soit %s du total',
    'diag.r5.detail' => 'La mémoire de pointe est concentrée sur une seule fonction : vérifier en priorité ses structures de données',
    'diag.r6.title' => '%s : temps réel excl. %s dépasse son temps réel incl. %s de %s',
    'diag.r6.detail' => 'Le temps réel excl. ne devrait jamais dépasser le temps réel incl. : les données d\'échantillonnage ou le calcul statistique peuvent être erronés',
    'unit.microsecs' => 'µs',
    'unit.bytes' => 'octets',
    'unit.samples' => 'échantillons',
    'agg.invalidInput' => 'Entrée invalide.',
    'common.run' => 'Exécution',
    'diff.invert' => 'Inverser le rapport %s',
    'diff.viewRun' => 'Voir l\'exécution #%s',
    'common.regression' => 'Régression',
    'common.improvement' => 'Amélioration',
    'pc.summary' => 'Synthèse %s pour %s',
    'runs.dt.processing' => 'Traitement en cours...',
    'runs.dt.loadingRecords' => 'Chargement en cours...',
    'runs.dt.lengthMenu' => 'Afficher _MENU_ éléments',
    'runs.dt.zeroRecords' => 'Aucun résultat correspondant',
    'runs.dt.emptyTable' => 'Aucune donnée disponible dans le tableau',
    'runs.dt.info' => 'Affichage de _START_ à _END_ sur _TOTAL_ éléments',
    'runs.dt.infoEmpty' => 'Affichage de 0 à 0 sur 0 élément',
    'runs.dt.infoFiltered' => '(filtré à partir de _MAX_ éléments au total)',
    'runs.dt.search' => 'Rechercher :',
    'runs.dt.first' => 'Premier',
    'runs.dt.previous' => 'Précédent',
    'runs.dt.next' => 'Suivant',
    'runs.dt.last' => 'Dernier',
    'runs.dt.sortAsc' => ': activer pour trier la colonne par ordre croissant',
    'runs.dt.sortDesc' => ': activer pour trier la colonne par ordre décroissant',
    'col.fn' => 'Fonction/Méthode',
    'col.ct' => 'Appels',
    'col.Calls%' => 'Appels<br>%',
    'col.wt' => 'Temps réel<br>incl. (µs)',
    'col.IWall%' => 'Temps réel<br>incl. %',
    'col.excl_wt' => 'Temps réel<br>excl. (µs)',
    'col.EWall%' => 'Temps réel<br>excl. %',
    'col.ut' => 'Temps<br>utilisateur incl.<br>(µs)',
    'col.IUser%' => 'IUser%',
    'col.excl_ut' => 'Temps<br>utilisateur excl.<br>(µs)',
    'col.EUser%' => 'EUser%',
    'col.st' => 'Temps<br>système incl.<br>(µs)',
    'col.ISys%' => 'ISys%',
    'col.excl_st' => 'Temps<br>système excl.<br>(µs)',
    'col.ESys%' => 'ESys%',
    'col.cpu' => 'Temps CPU<br>incl. (µs)',
    'col.ICpu%' => 'Temps CPU<br>incl. %',
    'col.excl_cpu' => 'Temps CPU<br>excl. (µs)',
    'col.ECpu%' => 'Temps CPU<br>excl. %',
    'col.mu' => 'Mémoire<br>utilisée incl.<br>(octets)',
    'col.IMUse%' => 'Mémoire<br>utilisée incl.<br>%',
    'col.excl_mu' => 'Mémoire<br>utilisée excl.<br>(octets)',
    'col.EMUse%' => 'Mémoire<br>utilisée excl.<br>%',
    'col.pmu' => 'Mémoire<br>de pointe incl.<br>(octets)',
    'col.IPMUse%' => 'Mémoire<br>de pointe incl.<br>%',
    'col.excl_pmu' => 'Mémoire<br>de pointe excl.<br>(octets)',
    'col.EPMUse%' => 'Mémoire<br>de pointe excl.<br>%',
    'col.samples' => 'Échantillons incl.',
    'col.ISamples%' => 'ISamples%',
    'col.excl_samples' => 'Échantillons excl.',
    'col.ESamples%' => 'ESamples%',
    // ——— 2026-10 : chaînes encore codées en dur dans la vue diff et la page de détail d'un
    // symbole (l'ordre des clés fait partie du contrat : insérées après col.ESamples%).
    // Dans les en-têtes diff, l'écart est marqué par « Δ » (d'où « Appels Δ », « Δ (µs) »…) ;
    // le reste reprend la découpe et le vocabulaire des col.* ci-dessus.
    'common.na' => 'N/A',
    'pc.perCall' => 'Par appel : %s',
    'sym.source' => 'Code source',
    'diffcol.fn' => 'Fonction/Méthode',
    'diffcol.ct' => 'Appels<br>Δ',
    'diffcol.Calls%' => 'Appels<br>Δ %',
    'diffcol.wt' => 'Temps réel<br>incl. Δ<br>(µs)',
    'diffcol.IWall%' => 'Temps réel<br>incl. Δ %',
    'diffcol.excl_wt' => 'Temps réel<br>excl. Δ<br>(µs)',
    'diffcol.EWall%' => 'Temps réel<br>excl. Δ %',
    'diffcol.ut' => 'Temps<br>utilisateur incl.<br>Δ (µs)',
    'diffcol.IUser%' => 'IUser<br>Δ %',
    'diffcol.excl_ut' => 'Temps<br>utilisateur excl.<br>Δ (µs)',
    'diffcol.EUser%' => 'EUser<br>Δ %',
    'diffcol.cpu' => 'Temps CPU<br>incl. Δ<br>(µs)',
    'diffcol.ICpu%' => 'Temps CPU<br>incl. Δ %',
    'diffcol.excl_cpu' => 'Temps CPU<br>excl. Δ<br>(µs)',
    'diffcol.ECpu%' => 'Temps CPU<br>excl. Δ %',
    'diffcol.st' => 'Temps<br>système incl.<br>Δ (µs)',
    'diffcol.ISys%' => 'ISys<br>Δ %',
    'diffcol.excl_st' => 'Temps<br>système excl.<br>Δ (µs)',
    'diffcol.ESys%' => 'ESys<br>Δ %',
    'diffcol.mu' => 'Mémoire<br>utilisée incl.<br>Δ (octets)',
    'diffcol.IMUse%' => 'Mémoire<br>utilisée incl.<br>Δ %',
    'diffcol.excl_mu' => 'Mémoire<br>utilisée excl.<br>Δ (octets)',
    'diffcol.EMUse%' => 'Mémoire<br>utilisée excl.<br>Δ %',
    'diffcol.pmu' => 'Mémoire<br>de pointe incl.<br>Δ (octets)',
    'diffcol.IPMUse%' => 'Mémoire<br>de pointe incl.<br>Δ %',
    'diffcol.excl_pmu' => 'Mémoire<br>de pointe excl.<br>Δ (octets)',
    'diffcol.EPMUse%' => 'Mémoire<br>de pointe excl.<br>Δ %',
    'diffcol.samples' => 'Échantillons incl.<br>Δ',
    'diffcol.ISamples%' => 'ISamples<br>Δ %',
    'diffcol.excl_samples' => 'Échantillons excl.<br>Δ',
    'diffcol.ESamples%' => 'ESamples<br>Δ %',
    // ——— 2026-10 : entrée d'agrégation multi-exécutions / carte du chemin critique /
    // recherche par sous-chaîne. L'ordre des clés reste le contrat : ces clés viennent
    // après diffcol.ESamples%, dans le même ordre que les 13 autres catalogues.
    // runs.aggregate : libellé que le bouton de comparaison adopte au-delà de deux
    // exécutions sélectionnées (HTML statique côté Utils, remplacé en JS selon le
    // nombre coché ; injecté via window.xpI18n).
    'runs.aggregate' => 'Agréger la sélection',
    'path.title' => 'Chemin critique',
    'path.empty' => 'Aucune chaîne d\'appels à afficher (les données ne contiennent aucune arête d\'appel depuis main()).',
    // %s = la chaîne recherchée ; « 30 » doit rester le même nombre que le plafond appliqué dans XhprofDisplay
    'search.matches' => 'Fonctions contenant « %s » (30 premières affichées) :',
    // Graphe de flammes (le module FlameGraph ne porte aucun texte ; le titre et la
    // note de la carte sont rendus côté Display).
    // Les deux %s de flame.note = trames réellement dessinées, part non dessinée ; un pourcent littéral s'écrit %%
    'flame.title' => 'Graphe de flammes',
    'flame.note' => '%s trames affichées ; pour limiter la taille de la page, %s%% du temps total n\'est pas dessiné',
    // 与上次运行对比（run 详情页里指向同 URI 的上一条 run 的 diff 链接）。
    'run.previous' => 'Comparer avec l\'exécution précédente',
    // 火焰图 metric 选中 mu/pmu 时的口径说明，跟在指标名后面。
    'flame.muInclusive' => '(mémoire incluant les appels enfants)',
    // Séparateurs de milliers / décimales des nombres de la page de rapport (affichage HTML uniquement ; exports et liste des exécutions ne les utilisent pas) : valeurs mesurées via CLDR.
    'num.thousands' => ' ',
    'num.decimal' => ',',
    // Autres exécutions de la même URL : mène à la liste avec `requrl`, que son JS lit pour préremplir la recherche.
    'run.otherRuns' => 'Autres exécutions de cette URL',
    // Pied de page. Marque et URL sont indépendantes de la langue : les 13 catalogues portent la même valeur au mot près (le garde-fou du test I18nTest ne voit que les valeurs sources contenant des han) ; render_footer() en fait un lien.
    'footer.credit' => '© erik · https://erik.xyz',

    // Barre d'état de la liste des exécutions : nombre stocké / limite / jours de conservation / dates la plus ancienne et la plus récente.
    'runs.status' => 'Exécutions stockées : %s / limite %s · conservation %s jours · la plus ancienne %s · la plus récente %s',

];
