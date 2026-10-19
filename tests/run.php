<?php
/**
 * Tests sans WHMCS des fonctions pures du module : php tests/run.php
 */

define('WHMCS', true);
require __DIR__ . '/../modules/addons/lwb_whmcs_wha/lib/bootstrap.php';

use LwbWha\Events;
use LwbWha\Phone;
use LwbWha\Settings;
use LwbWha\Time;

$failures = 0;
$count = 0;
function check($label, $actual, $expected)
{
    global $failures, $count;
    $count++;
    if ($actual !== $expected) {
        $failures++;
        echo "ÉCHEC : $label\n  attendu : " . var_export($expected, true) . "\n  obtenu  : " . var_export($actual, true) . "\n";
    }
}

Settings::inject(['default_country_code' => '243', 'timezone' => 'Africa/Kinshasa', 'quiet_hours' => '21-8']);

// --- Rendu
list($text) = Events::render("{greeting} {first_name}\n{early_discount}\nFin", ['greeting' => 'Bonjour', 'first_name' => 'Awa', 'early_discount' => '']);
check('variables + ligne vide supprimée', $text, "Bonjour Awa\n\nFin");
list($text) = Events::render('{Bonjour|Salut} {name}', ['name' => 'Ali']);
check('spintax', in_array($text, ['Bonjour Ali', 'Salut Ali'], true), true);
list($text, $ordered) = Events::render('{a} et {b}', ['a' => '1', 'b' => '2']);
check('valeurs ordonnées', $ordered, ['1', '2']);
list($text) = Events::render("Service *X* {domain_label}est actif", ['domain_label' => '']);
check('libellé optionnel', $text, 'Service *X* est actif');

// --- Emojis (base utf8 3 octets)
$s = "Merci 🎉 👋 été";
check('emoji encodé', Events::dbEncode($s), 'Merci &#x1F389; &#x1F44B; été');
check('emoji aller-retour', Events::dbDecode(Events::dbEncode($s)), $s);

// --- Numéros
check('format WHMCS', Phone::normalize('+243.816 123 456'), '243816123456');
check('local avec 0', Phone::normalize('0816123456'), '243816123456');
check('local sans 0', Phone::normalize('816123456'), '243816123456');
check('indicatif sans +', Phone::normalize('243816123456'), '243816123456');
check('préfixe 00', Phone::normalize('00243816123456'), '243816123456');
check('autre pays', Phone::normalize('+1 (567) 293-1610'), '15672931610');
check('trop court', Phone::normalize('12345'), '');
check('vide', Phone::normalize(''), '');

// --- Heures calmes
check('21-8 à 22 h', Time::inQuietHours('21-8', 22), true);
check('21-8 à 3 h', Time::inQuietHours('21-8', 3), true);
check('21-8 à 8 h', Time::inQuietHours('21-8', 8), false);
check('21-8 à 12 h', Time::inQuietHours('21-8', 12), false);
check('9-17 à 10 h', Time::inQuietHours('9-17', 10), true);
check('plage vide', Time::inQuietHours('', 3), false);

// --- Dates
check('aujourd\'hui', Time::when(0, '2026-11-18'), "aujourd'hui");
check('demain', Time::when(1, '2026-11-18'), 'demain');
check('dans 7 jours', Time::when(7, '2026-11-18'), 'dans 7 jours');
check('lointain', Time::when(30, '2026-11-18'), 'le 18/11/2026');
check('hier', Time::when(-1, '2026-11-18'), 'hier');
check('il y a 3 jours', Time::when(-3, '2026-11-18'), 'il y a 3 jours');
check('écart de jours', Time::daysUntil('2026-11-18', '2026-11-11'), 7);
check('écart négatif', Time::daysUntil('2026-11-08', '2026-11-11'), -3);
check('bonsoir', Time::greeting(19), 'Bonsoir');
check('bonjour', in_array(Time::greeting(10), ['Bonjour', 'Hello'], true), true);

// --- Catalogue
check('e-mail facture', Events::forEmail('Invoice Created', 'invoice'), 'invoice_created');
check('bienvenue produit', Events::forEmail('Hosting Account Welcome Email', 'product'), 'service_welcome');
check('bienvenue personnalisée', Events::forEmail('Lwb Maintenance Bienvenue', 'product'), 'service_welcome');
check('e-mail inconnu', Events::forEmail('Quote Accepted', 'general'), null);
$rels = ['invoice', 'service', 'domain', 'client', 'order', 'ticket'];
foreach (Events::defaults() as $key => $def) {
    foreach (['group', 'label', 'emails', 'rel', 'priority', 'ttl', 'message'] as $field) {
        check("$key.$field présent", array_key_exists($field, $def), true);
    }
    check("$key.rel valide", in_array($def['rel'], $rels, true), true);
    preg_match_all('/\{(\w+)\}/', $def['message'], $m);
    $unknown = array_values(array_diff(array_unique($m[1]), Events::vars($def['rel'], !empty($def['team']))));
    check("$key : variables connues", $unknown, []);
}

echo $count - $failures . " / $count tests réussis\n";
exit($failures ? 1 : 0);
