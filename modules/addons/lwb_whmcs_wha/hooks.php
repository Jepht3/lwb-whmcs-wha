<?php
/**
 * lwb-whmcs-wha — hooks WHMCS.
 *
 * - Notifications client : suivent les e-mails envoyés par WHMCS (EmailPreSend),
 *   y compris ceux de vos propres hooks (SendEmail).
 * - Alertes équipe : paiement, commande, ticket, réponse client, résiliation.
 * - Cron : maintien de la passerelle, synchronisations (désinscriptions, statuts,
 *   messages reçus des clients), alertes, envoi de la file.
 * - Administration : widget du tableau de bord, encart et lien dans la fiche client.
 * - Espace client : bouton WhatsApp (optionnel).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/bootstrap.php';

use LwbWha\Admin;
use LwbWha\Alerts;
use LwbWha\Events;
use LwbWha\Gateway;
use LwbWha\Inbox;
use LwbWha\Queue;
use LwbWha\Settings;
use LwbWha\Team;
use WHMCS\Database\Capsule;

add_hook('EmailPreSend', 100, function ($vars) {
    try {
        $name = (string) ($vars['messagename'] ?? '');
        $relid = (int) ($vars['relid'] ?? 0);
        if ($name === '' || !$relid) {
            return [];
        }
        $type = (string) Capsule::table('tblemailtemplates')->where('name', $name)->value('type');
        $event = Events::forEmail($name, $type);
        if (!$event) {
            return [];
        }
        $def = Events::get($event);
        $relId = $relid;
        if ($def['rel'] === 'order') {
            // Les e-mails « general » portent l'ID client : on retrouve la commande.
            $merge = is_array($vars['mergefields'] ?? null) ? $vars['mergefields'] : [];
            $relId = (int) ($merge['order_id'] ?? 0)
                ?: (int) Capsule::table('tblorders')->where('userid', $relid)->orderBy('id', 'desc')->value('id');
        }
        if ($relId) {
            Queue::notify($event, $def['rel'], $relId);
        }
    } catch (\Throwable $e) {
        logActivity('LWB WhatsApp (EmailPreSend) : ' . $e->getMessage());
    }
    // Ne modifie jamais l'e-mail.
    return [];
});

add_hook('AfterCronJob', 1, function ($vars) {
    try {
        Gateway::keepAlive();
        if (Gateway::isOpen()) {
            // Pour le bouton WhatsApp de l'espace client.
            \LwbWha\State::set('connected_number', Gateway::number());
        }
        Queue::syncOptouts();
        Queue::syncStatuses();
        Inbox::sync();
        Alerts::check();
        Queue::process();
        Queue::purge();
    } catch (\Throwable $e) {
        logActivity('LWB WhatsApp (cron) : ' . $e->getMessage());
    }
});

// ------------------------------------------------------------------ alertes équipe

add_hook('InvoicePaid', 100, function ($vars) {
    Team::notify('team_paid', 'invoice', (int) ($vars['invoiceid'] ?? 0));
});

add_hook('AfterShoppingCartCheckout', 100, function ($vars) {
    Team::notify('team_order', 'order', (int) ($vars['OrderID'] ?? 0));
});

add_hook('TicketOpen', 100, function ($vars) {
    Team::notify('team_ticket_new', 'ticket', (int) ($vars['ticketid'] ?? 0));
});

add_hook('TicketUserReply', 100, function ($vars) {
    Team::notify('team_ticket_reply', 'ticket', (int) ($vars['ticketid'] ?? 0));
});

add_hook('CancellationRequest', 100, function ($vars) {
    if (($vars['type'] ?? '') !== '' && isset($vars['relid'])) {
        Team::notify('team_cancel', 'service', (int) $vars['relid']);
    }
});

// ------------------------------------------------------------------ administration

add_hook('AdminAreaClientSummaryActionLinks', 1, function ($vars) {
    return ['<a href="addonmodules.php?module=' . Settings::MODULE . '&tab=send&userid=' . (int) $vars['userid'] . '">'
        . '<i class="fab fa-whatsapp"></i> Envoyer un WhatsApp</a>'];
});

add_hook('AdminAreaClientSummaryPage', 1, function ($vars) {
    try {
        return Admin::clientPanel((int) ($vars['userid'] ?? 0));
    } catch (\Throwable $e) {
        return '';
    }
});

if (class_exists('\WHMCS\Module\AbstractWidget')) {
    require_once __DIR__ . '/lib/Widget.php';
    add_hook('AdminHomeWidgets', 1, function () {
        return new \LwbWha\Widget();
    });
}

// ------------------------------------------------------------------ espace client

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    try {
        if (!Settings::bool('client_button', false)) {
            return '';
        }
        $number = (string) \LwbWha\State::get('connected_number', '');
        if ($number === '') {
            return '';
        }
        $text = 'Bonjour ' . \LwbWha\Context::config('CompanyName') . ' 👋 ';
        if (!empty($vars['clientsdetails']['id'])) {
            $text .= '(client n°' . (int) $vars['clientsdetails']['id'] . ') ';
        }
        $url = 'https://wa.me/' . $number . '?text=' . rawurlencode($text);
        return '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener" aria-label="WhatsApp" '
            . 'style="position:fixed;right:18px;bottom:18px;z-index:9999;width:56px;height:56px;border-radius:50%;background:#25D366;'
            . 'box-shadow:0 4px 12px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center">'
            . '<svg width="30" height="30" viewBox="0 0 32 32" fill="#fff" aria-hidden="true"><path d="M16 3C9 3 3.3 8.6 3.3 15.6c0 2.5.7 4.9 2 7L3 29l6.6-2.2c2 1.1 4.2 1.7 6.4 1.7 7 0 12.7-5.7 12.7-12.7S23 3 16 3zm0 23.2c-2 0-4-.6-5.7-1.6l-.4-.2-3.9 1.3 1.3-3.8-.3-.4c-1.2-1.8-1.8-3.8-1.8-5.9C5.2 9.7 10 5 16 5s10.8 4.8 10.8 10.7S22 26.2 16 26.2zm5.9-8c-.3-.2-1.9-1-2.2-1.1-.3-.1-.5-.2-.7.2-.2.3-.8 1-1 1.2-.2.2-.4.2-.7.1-.3-.2-1.4-.5-2.6-1.6-1-.9-1.6-1.9-1.8-2.2-.2-.3 0-.5.1-.7l.5-.6c.2-.2.2-.4.3-.6.1-.2 0-.4 0-.6l-1-2.4c-.3-.6-.5-.5-.7-.5h-.6c-.2 0-.6.1-.9.4-.3.3-1.2 1.1-1.2 2.8s1.2 3.2 1.4 3.5c.2.2 2.4 3.6 5.7 5 .8.3 1.4.5 1.9.7.8.3 1.5.2 2.1.1.6-.1 1.9-.8 2.2-1.5.3-.7.3-1.4.2-1.5-.1-.2-.3-.3-.6-.4z"/></svg></a>';
    } catch (\Throwable $e) {
        return '';
    }
});
