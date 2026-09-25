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
    'nav.brand' => 'Analyse de performance XHProf',
    'nav.home' => 'Accueil',
    'nav.runs' => 'Rapport d\'exécution',
    'nav.symbol' => 'Détails de la méthode',
    'search.placeholder' => 'Rechercher une fonction/méthode...',
    'search.button' => 'Rechercher',
    'run.col.method' => 'Méthode de la requête',
    'run.col.time' => 'Heure de la requête',
    'run.col.ip' => 'IP source',
    'run.col.totalCalls' => 'Nombre total d\'appels de fonctions/méthodes',
    'runs.title' => 'Journal des requêtes',
    'runs.col.method' => 'Méthode',
    'runs.col.url' => 'URL de la requête',
    'runs.col.time' => 'Heure de la requête',
    'runs.col.wt' => 'Durée (s)',
    'runs.col.mu' => 'Mémoire (Mo)',
    'runs.col.ip' => 'IP',
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
];
