<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Prévient les administrateurs par e-mail quand WhatsApp est déconnecté depuis
 * plus de 15 minutes (QR code à rescanner, numéro banni, passerelle arrêtée),
 * une seule fois par incident, puis quand la connexion est rétablie.
 */
class Alerts
{
    const DELAY = 900;

    public static function check()
    {
        if (!Settings::isGateway()) {
            return;
        }
        $s = Gateway::status();
        $st = (string) ($s['status'] ?? 'unreachable');
        if ($st === 'open') {
            if (State::get('alert_sent')) {
                self::mail('✅ WhatsApp de nouveau connecté', '<p>La connexion WhatsApp est rétablie. Les messages en attente partent progressivement.</p>');
            }
            State::delete('down_since');
            State::delete('alert_sent');
            return;
        }
        if ($st === 'connecting') {
            return;
        }
        $since = (int) State::get('down_since', 0);
        if (!$since) {
            State::set('down_since', time());
            return;
        }
        if (time() - $since < self::DELAY || State::get('alert_sent') || !Settings::bool('alert_email', true)) {
            return;
        }
        $pending = Capsule::table(Queue::TABLE)->where('status', 'pending')->count();
        $url = Context::adminUrl('addonmodules.php?module=' . Settings::MODULE);
        $texts = [
            'qr' => 'WhatsApp n\'est plus relié à WHMCS : il faut rescanner le QR code (ou utiliser un code de liaison).',
            'logged_out' => 'L\'appareil a été délié depuis le téléphone (ou WhatsApp l\'a déconnecté) : il faut relier le numéro.',
            'banned' => 'WhatsApp refuse la connexion (erreur 403) : le numéro est peut-être banni ou restreint. Ouvrez WhatsApp sur le téléphone pour vérifier.',
            'replaced' => 'La session WhatsApp a été ouverte ailleurs : vérifiez qu\'une seule passerelle utilise ce numéro.',
            'closed' => 'La connexion à WhatsApp est interrompue ; la passerelle réessaie automatiquement.',
            'unreachable' => 'La passerelle ne répond pas. Le cron WHMCS tente de la redémarrer automatiquement.',
        ];
        $body = '<p><strong>' . htmlspecialchars($texts[$st] ?? ('Statut : ' . $st)) . '</strong></p>'
            . '<p>' . $pending . ' message(s) WhatsApp en attente : ils partiront dès le retour de la connexion (rien n\'est perdu).</p>'
            . '<p><a href="' . htmlspecialchars($url) . '">Ouvrir LWB WhatsApp dans WHMCS</a></p>';
        self::mail('⚠️ WhatsApp déconnecté (' . $st . ')', $body);
        State::set('alert_sent', time());
    }

    private static function mail($subject, $html)
    {
        try {
            localAPI('SendAdminEmail', [
                'customsubject' => $subject . ' — ' . Context::config('CompanyName'),
                'custommessage' => $html,
                'type' => 'system',
            ]);
        } catch (\Throwable $e) {
            logActivity('LWB WhatsApp : alerte e-mail impossible : ' . $e->getMessage());
        }
        logActivity('LWB WhatsApp : ' . $subject);
    }
}
