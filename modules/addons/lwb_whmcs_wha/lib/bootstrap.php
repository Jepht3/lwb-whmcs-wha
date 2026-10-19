<?php
/**
 * lwb-whmcs-wha — chargement des classes du module (espace de noms LwbWha).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

foreach ([
    'Settings', 'State', 'Time', 'Events', 'Phone', 'Links', 'Context',
    'Gateway', 'Sender', 'Queue', 'Team', 'Alerts', 'Stats', 'Schema', 'Admin',
] as $lwbWhaClass) {
    require_once __DIR__ . '/' . $lwbWhaClass . '.php';
}
unset($lwbWhaClass);
