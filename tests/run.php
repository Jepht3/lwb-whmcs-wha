<?php
/**
 * Tests sans WHMCS des fonctions pures du module : php tests/run.php
 */

define('WHMCS', true);
require __DIR__ . '/../modules/addons/lwb_whmcs_wha/lib/bootstrap.php';

use LwbWha\Admin;
use LwbWha\Events;
use LwbWha\Inbox;
use LwbWha\Phone;
use LwbWha\Queue;
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

// --- Offre paiement anticipé
use LwbWha\Context;
$o = Context::earlyOfferText(10, '04/11/2026', '72.20 USD', '3.80 USD', '76.00 USD', 5);
check('offre : prix remisé conditionnel', $o, "\n🎁 *Offre paiement anticipé* : réglez-la au plus tard le *04/11/2026* et ne payez que *72.20 USD* (-5 %, soit 3.80 USD d'économie). Passé cette date, le montant sera de 76.00 USD.\n");
check('offre : 2 jours', strpos(Context::earlyOfferText(2, '04/11/2026', 'a', 'b', 'c', 5), '⏳ *Plus que 2 jours*') === 1, true);
check('offre : 1 jour', strpos(Context::earlyOfferText(1, '04/11/2026', 'a', 'b', 'c', 5), '*Plus que 1 jour*') !== false, true);
check('offre : dernier jour', strpos(Context::earlyOfferText(0, '04/11/2026', 'a', 'b', 'c', 5), '⏳ *Dernier jour*') === 1, true);
check('offre : sans pourcentage', strpos(Context::earlyOfferText(9, 'x', '1', '2', '3'), '(2 d\'économie)') !== false, true);
check('offre : plus de « déjà déduits »', strpos($o, 'déduits'), false);

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

// --- Boîte de réception
$raw = ['id' => '3EB0C767D26A1D35E4D4', 'phone' => '243816123456', 'name' => ' Awa 🌸 ', 'text' => "  Bonjour,\r\nj'ai payé  ",
    'at' => 1760882400000, 'received' => 1760882401000, 'quoted' => 'BAE5F1A2'];
check('message reçu : champs', Inbox::fromGateway($raw), ['wa_id' => '3EB0C767D26A1D35E4D4', 'phone' => '243816123456', 'name' => 'Awa 🌸',
    'text' => "Bonjour,\nj'ai payé", 'quoted' => 'BAE5F1A2', 'at' => 1760882400]);
check('message sans texte ignoré', Inbox::fromGateway(['text' => '   '] + $raw), null);
check('numéro trop court ignoré', Inbox::fromGateway(['phone' => '12345'] + $raw), null);
check('identifiant invalide ignoré', Inbox::fromGateway(['id' => "x'; DROP"] + $raw), null);
check('pas un tableau', Inbox::fromGateway('texte'), null);
check('texte non scalaire ignoré', Inbox::fromGateway(['text' => ['a']] + $raw), null);
check('citation invalide écartée', Inbox::fromGateway(['quoted' => '<script>'] + $raw)['quoted'], '');
check('texte limité à 4000 caractères', mb_strlen(Inbox::fromGateway(['text' => str_repeat('é', 5000)] + $raw)['text']), 4000);
check('nom limité à 25 caractères', mb_strlen(Inbox::fromGateway(['name' => str_repeat('🌸', 40)] + $raw)['name']), 25);
check('nom encodé tient dans la colonne', strlen(Events::dbEncode(Inbox::fromGateway(['name' => str_repeat('🌸', 40)] + $raw)['name'])) <= 255, true);
check('date absente : maintenant', abs(Inbox::fromGateway(['at' => 0] + $raw)['at'] - time()) < 5, true);
check('ticket : sans emoji ni balise', Inbox::plain("Merci 🙏 <b>ok</b>  👍🏽"), 'Merci ‹b›ok‹/b›');
check('ticket : emoji seul', Inbox::plain('👍'), '(emoji)');
check('ticket : cœur gardé', Inbox::plain('❤️ merci'), '❤ merci');
check('extrait court', Inbox::excerpt("Bonjour\n  Awa", 40), 'Bonjour Awa');
check('extrait coupé', Inbox::excerpt('abcdef', 4), 'abc…');
check('sujet de ticket', Inbox::subject("J'ai payé la facture n°4280 hier soir par M-Pesa, merci de vérifier svp\nDeuxième ligne"),
    "WhatsApp : J'ai payé la facture n°4280 hier soir par M-Pesa, merci de…");
check('texte de ticket (un message)', Inbox::ticketText([['text' => 'Facture payée 🙏', 'time' => '14:31']], '243816123456', '19/10/2026'),
    "Facture payée\n\n— Message WhatsApp du +243816123456, le 19/10/2026 à 14:31.");
check('texte de ticket (plusieurs)', Inbox::ticketText([['text' => 'Bonjour', 'time' => '14:30'], ['text' => "J'ai payé", 'time' => '14:31']], '243816123456', '19/10/2026'),
    "[14:30] Bonjour\n\n[14:31] J'ai payé\n\n— Messages WhatsApp du +243816123456, le 19/10/2026.");
$rows = [['id' => 1, 'client_id' => '5'], ['id' => 2, 'client_id' => '7'], ['id' => 3, 'client_id' => '5']];
check('regroupement par client', Inbox::group($rows), [5 => [$rows[0], $rows[2]], 7 => [$rows[1]]]);

// --- Envoi manuel d'une notification de facture
check('facture impayée', Events::forInvoice('Unpaid', 5, '50.00'), ['invoice_created', 'invoice_reminder']);
check('facture à échéance aujourd\'hui', Events::forInvoice('Unpaid', 0, 50), ['invoice_created', 'invoice_reminder']);
check('facture échue : relances de retard', Events::forInvoice('Unpaid', -3, 50), ['invoice_created', 'invoice_overdue_1', 'invoice_overdue_2', 'invoice_overdue_3']);
check('facture payée', Events::forInvoice('Paid', -10, '50.00'), ['invoice_paid']);
check('facture impayée à 0', Events::forInvoice('Unpaid', 5, '0.00'), []);
check('facture payée à 0', Events::forInvoice('Paid', 0, 0), []);
foreach (['Draft', 'Cancelled', 'Refunded', 'Collections', 'Payment Pending'] as $status) {
    check("facture $status : rien à envoyer", Events::forInvoice($status, 5, 50), []);
}
foreach ([['Unpaid', 5], ['Unpaid', -3], ['Paid', 0]] as list($status, $days)) {
    foreach (Events::forInvoice($status, $days, 50) as $event) {
        $def = Events::get($event);
        check("$event : notification client d'une facture", $def && $def['rel'] === 'invoice' && empty($def['team']), true);
    }
}
check('empreinte inchangée (doublons sur 24 h)', Queue::hash('invoice_created', 'invoice', 12, '243816123456'), sha1('invoice_created|invoice|12|243816123456'));
check('empreinte : ID texte ou nombre', Queue::hash('invoice_paid', 'invoice', '12', '243816123456'), Queue::hash('invoice_paid', 'invoice', 12, '243816123456'));

// --- Compte rendu d'un envoi manuel
$html = Admin::sendResult(['ok' => true, 'phone' => '243816123456', 'text' => 'Bonjour <b>Awa</b>', 'status' => 'sent', 'error' => ''], 'Message envoyé');
check('envoi réussi', strpos($html, 'successbox') !== false && strpos($html, 'Message envoyé au +243816123456.') !== false, true);
check('texte envoyé échappé', strpos($html, 'Bonjour &lt;b&gt;Awa&lt;/b&gt;') !== false, true);
$html = Admin::sendResult(['ok' => false, 'phone' => '243816123456', 'text' => '', 'status' => 'pending', 'error' => 'Quota anti-bannissement atteint'], 'Message envoyé');
check('quota : reste en file', strpos($html, 'infobox') !== false && strpos($html, 'Quota anti-bannissement atteint') !== false, true);
$html = Admin::sendResult(['ok' => false, 'phone' => '243816123456', 'text' => '', 'status' => 'failed', 'error' => "<n'a pas de compte>"], 'Message envoyé');
check('échec signalé et échappé', strpos($html, 'errorbox') !== false && strpos($html, '&lt;n&#039;a pas de compte&gt;') !== false, true);
check('refus avant envoi', strpos(Admin::sendResult(['ok' => false, 'error' => 'Élément introuvable'], 'Message envoyé'), 'errorbox') !== false, true);
check('libellé échappé', strpos(Admin::sendResult(['ok' => true, 'phone' => '1', 'text' => ''], 'Notification « <x> » envoyée'), 'Notification « &lt;x&gt; » envoyée au +1.') !== false, true);

// --- Annonces
use LwbWha\Broadcast;
$a = Broadcast::audience([]);
check('annonce : tous les clients par défaut', $a, ['scope' => 'clients', 'servers' => [], 'groups' => [], 'products' => [], 'statuses' => ['Active']]);
$a = Broadcast::audience(['scope' => 'services', 'servers' => ['3', '3', 'x', '0'], 'groups' => [9], 'statuses' => ['Suspended', 'Hack']]);
check('annonce : filtres nettoyés', $a, ['scope' => 'services', 'servers' => [3], 'groups' => [9], 'products' => [], 'statuses' => ['Suspended']]);
check('annonce : statut actif si aucun valide', Broadcast::audience(['scope' => 'services', 'statuses' => ['Nope']])['statuses'], ['Active']);
check('annonce : description', Broadcast::describe(Broadcast::audience([])), 'Tous les clients actifs');
check('annonce : variables', in_array('domain', Broadcast::vars(), true) && in_array('domains', Broadcast::vars(), true), true);
$t = Broadcast::text("{greeting} {first_name}, votre site {domain} passe à PHP 8.3.", ['greeting' => 'Bonjour', 'first_name' => 'Awa', 'domain' => 'exemple.cd']);
check('annonce : texte + pied STOP', $t, "Bonjour Awa, votre site exemple.cd passe à PHP 8.3.\n\nRépondez STOP pour ne plus recevoir ces messages.");
check('annonce : variable absente vidée', Broadcast::text('Site : {domains}.', []), "Site : .\n\nRépondez STOP pour ne plus recevoir ces messages.");

echo $count - $failures . " / $count tests réussis\n";
exit($failures ? 1 : 0);
