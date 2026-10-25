<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/** Interface d'administration (Addons > LWB WhatsApp). */
class Admin
{
    private static $link;
    private static $token;
    /** Le dernier envoi manuel est parti (ou attend en file) : le formulaire est vidé. */
    private static $done = false;

    const TABS = [
        'dashboard' => 'Tableau de bord',
        'inbox' => 'Boîte de réception',
        'send' => 'Envoyer',
        'broadcast' => 'Annonces',
        'templates' => 'Modèles',
        'messages' => 'Messages',
        'stats' => 'Statistiques',
        'optouts' => 'Désinscriptions',
        'test' => 'Aperçu',
        'help' => 'Astuces',
    ];

    const STATUS_LABELS = [
        'pending' => ['En attente', 'default'], 'sending' => ['Envoi…', 'info'], 'sent' => ['Envoyé', 'success'],
        'failed' => ['Échec', 'danger'], 'skipped' => ['Ignoré', 'warning'], 'expired' => ['Expiré', 'warning'],
        'optout' => ['Désinscrit', 'warning'],
    ];

    const INVOICE_STATUS = [
        'Unpaid' => 'impayée', 'Paid' => 'payée', 'Cancelled' => 'annulée', 'Refunded' => 'remboursée',
        'Draft' => 'en brouillon', 'Collections' => 'en recouvrement', 'Payment Pending' => 'en attente de paiement',
    ];

    private static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private static function url($params = [])
    {
        return self::$link . ($params ? '&' . http_build_query($params) : '');
    }

    /** @param string|array $tab onglet, ou paramètres complets de l'adresse (filtres conservés) */
    private static function form($action, $tab, $inner, $attrs = '')
    {
        return '<form method="post" action="' . self::h(self::url(is_array($tab) ? $tab : ['tab' => $tab])) . '" ' . $attrs . '>'
            . '<input type="hidden" name="token" value="' . self::h(self::$token) . '">'
            . '<input type="hidden" name="action" value="' . self::h($action) . '">' . $inner . '</form>';
    }

    private static function box($type, $html)
    {
        $class = ['success' => 'successbox', 'error' => 'errorbox', 'info' => 'infobox'][$type] ?? 'infobox';
        return '<div class="' . $class . '">' . $html . '</div>';
    }

    private static function label($event)
    {
        $def = Events::get($event);
        if ($def) {
            return $def['label'];
        }
        return ['manual' => 'Message manuel', Broadcast::EVENT => 'Annonce'][$event] ?? $event;
    }

    /**
     * Compte rendu d'un envoi immédiat. Refusé par les quotas de la passerelle ou
     * pendant une déconnexion, le message reste en file et part plus tard.
     *
     * @param array  $res  résultat de Queue::sendNow, avec 'phone'
     * @param string $done début de la phrase, ex. « Message envoyé »
     */
    public static function sendResult(array $res, $done)
    {
        if (!empty($res['ok'])) {
            $text = (string) ($res['text'] ?? '');
            return self::box('success', self::h($done) . (empty($res['phone']) ? '' : ' au +' . self::h($res['phone'])) . '.'
                . ($text === '' ? '' : '<details style="margin-top:4px"><summary style="cursor:pointer">Texte envoyé</summary>'
                    . '<pre style="white-space:pre-wrap;font-size:12px;margin:4px 0 0">' . self::h($text) . '</pre></details>'));
        }
        if (in_array($res['status'] ?? '', ['pending', 'sending'], true)) {
            return self::box('info', 'Pas encore envoyé : ' . self::h($res['error'] ?? '') . '<br>Le message reste dans la file d\'attente et partira '
                . 'automatiquement au prochain passage du cron, hors heures calmes (onglet Messages pour l\'annuler).');
        }
        return self::box('error', 'Échec : ' . self::h($res['error'] ?? ''));
    }

    /** Compte rendu d'un envoi manuel ; une réponse partie traite les messages reçus de ce numéro. */
    private static function sent(array $res, $done)
    {
        self::$done = !empty($res['ok']) || in_array($res['status'] ?? '', ['pending', 'sending'], true);
        if (!empty($res['ok']) && !empty($res['phone'])) {
            Inbox::markReplied($res['phone']);
        }
        return self::sendResult($res, $done);
    }

    /** Libellé d'une facture dans les listes : numéro, montant, statut, échéance. */
    private static function invoiceLabel($inv)
    {
        $num = (string) $inv->invoicenum;
        $num = $num !== '' && $num !== (string) $inv->id ? $num . ' (ID ' . $inv->id . ')' : (string) $inv->id;
        return 'n°' . $num . ' · ' . Context::money($inv->total, $inv->userid) . ' · ' . (self::INVOICE_STATUS[$inv->status] ?? $inv->status)
            . ' · échéance ' . Time::date($inv->duedate);
    }

    public static function render(array $vars)
    {
        self::$link = $vars['modulelink'];
        self::$token = generate_token('plain');
        $tab = isset(self::TABS[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'dashboard';
        $flash = $_SERVER['REQUEST_METHOD'] === 'POST' ? self::handle() : '';
        Settings::all(true);
        if ($tab === 'inbox') {
            // Messages arrivés depuis le dernier cron.
            Inbox::sync();
        }
        $unanswered = Inbox::unanswered();

        echo '<style>.lwb-card{border:1px solid #e3e6ea;border-radius:6px;padding:14px 16px;margin-bottom:14px;background:#fff}'
            . '.lwb-card h4{margin-top:0}.lwb-kpi{display:inline-block;min-width:150px;margin:0 18px 10px 0}.lwb-kpi b{display:block;font-size:22px}'
            . '.lwb-var{display:inline-block;background:#f3f5f7;border-radius:3px;padding:1px 5px;margin:2px;font-family:monospace;font-size:11px}'
            . '.lwb-read{color:#34B7F1;font-weight:bold}.lwb-msg{white-space:pre-wrap;max-width:520px;font-size:12px;margin:4px 0 0}'
            . '.lwb-pair{font-size:30px;letter-spacing:4px;font-family:monospace;font-weight:bold}</style>';
        echo $flash;
        echo '<ul class="nav nav-tabs admin-tabs" style="margin-bottom:15px">';
        foreach (self::TABS as $key => $title) {
            $badge = $key === 'inbox' && $unanswered ? ' <span class="badge" title="Reçus ces dernières 24 h, sans réponse depuis WHMCS">' . $unanswered . '</span>' : '';
            echo '<li class="' . ($tab === $key ? 'active' : '') . '"><a href="' . self::h(self::url(['tab' => $key])) . '">' . $title . $badge . '</a></li>';
        }
        echo '</ul>';
        $method = 'page' . ucfirst($tab);
        self::$method();
    }

    // ------------------------------------------------------------------ actions

    private static function handle()
    {
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        switch ($action) {
            case 'save_templates':
                foreach (Events::defaults() as $event => $def) {
                    $reset = isset($_POST['reset'][$event]);
                    Capsule::table('mod_lwbwha_templates')->where('event', $event)->update([
                        'enabled' => isset($_POST['enabled'][$event]) ? 1 : 0,
                        'message' => Events::dbEncode($reset ? $def['message'] : (string) ($_POST['message'][$event] ?? $def['message'])),
                        'days' => preg_replace('/[^\d,\-]/', '', (string) ($_POST['days'][$event] ?? ($def['days'] ?? ''))),
                        'attach_pdf' => isset($_POST['attach_pdf'][$event]) ? 1 : 0,
                        'cloud_template' => trim((string) ($_POST['cloud_template'][$event] ?? '')),
                        'cloud_lang' => trim((string) ($_POST['cloud_lang'][$event] ?? 'fr')) ?: 'fr',
                    ]);
                }
                return self::box('success', 'Modèles enregistrés.');

            case 'send_manual':
                $clientId = (int) ($_POST['client_id'] ?? 0);
                $phone = Phone::normalize((string) ($_POST['phone'] ?? ''));
                $client = $clientId ? Capsule::table('tblclients')->where('id', $clientId)->first() : null;
                if ($clientId && !$client) {
                    return self::box('error', 'Client #' . $clientId . ' introuvable.');
                }
                if (($_POST['mode'] ?? '') === 'event') {
                    $event = (string) ($_POST['event'] ?? '');
                    $def = Events::get($event);
                    $relId = (int) ($_POST['relid'] ?? 0);
                    if ($def && $def['rel'] === 'client' && !$relId) {
                        $relId = $clientId;
                    }
                    if (!$relId) {
                        return self::box('error', 'Indiquez l\'ID de l\'élément (facture, service, domaine…).');
                    }
                    $res = Queue::sendNotification($event, $relId, $phone, $clientId);
                    return self::sent($res, 'Notification « ' . ($def ? $def['label'] : $event) . ' » envoyée');
                }
                $text = trim((string) ($_POST['message'] ?? ''));
                $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
                if ($text === '') {
                    return self::box('error', 'Le message est vide.');
                }
                if ($invoiceId && !$client) {
                    return self::box('error', 'Choisissez le client pour joindre une de ses factures.');
                }
                if ($invoiceId && !Capsule::table('tblinvoices')->where('id', $invoiceId)->where('userid', $clientId)->exists()) {
                    return self::box('error', 'La facture #' . $invoiceId . ' n\'appartient pas à ce client.');
                }
                if ($client) {
                    if ($phone === '') {
                        $who = Phone::forClient($client);
                        if ($who['blocked'] !== '' && $who['phone'] === '') {
                            return self::box('error', 'Envoi impossible : ' . self::h($who['blocked']) . '.');
                        }
                        $phone = $who['phone'];
                    }
                    $ctx = Context::build('client', $clientId);
                    list($text) = Events::render($text, $ctx['vars']);
                }
                if ($phone === '') {
                    return self::box('error', 'Aucun numéro de téléphone.');
                }
                $res = Queue::sendManual($clientId, $phone, $text, $invoiceId);
                return self::sent($res + ['phone' => $phone], 'Message envoyé');

            case 'send_invoice':
                // Bouton de la page d'une facture : le compte rendu s'affiche au retour sur la facture.
                $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
                $flash = self::sendInvoice($invoiceId, (string) ($_POST['event'] ?? ''));
                $back = 'invoices.php?action=edit&id=' . $invoiceId;
                if ($invoiceId && !headers_sent()) {
                    $_SESSION['lwbwha_flash'] = ['invoice' => $invoiceId, 'html' => $flash];
                    header('Location: ' . $back);
                    exit;
                }
                return $flash . '<p><a href="' . self::h($back) . '">&larr; Retour à la facture</a></p>';

            case 'broadcast':
                return self::broadcastAction();

            case 'broadcast_cancel':
                $n = Broadcast::cancel($id);
                return self::box('success', 'Annonce #' . $id . ' annulée : ' . $n . ' message(s) en attente retiré(s) de la file.');

            case 'inbox_reply':
                $row = Inbox::ready() ? Capsule::table(Inbox::TABLE)->where('id', $id)->first() : null;
                $text = trim((string) ($_POST['message'] ?? ''));
                if (!$row) {
                    return self::box('error', 'Message introuvable.');
                }
                if ($text === '') {
                    return self::box('error', 'La réponse est vide.');
                }
                if ($row->client_id) {
                    $ctx = Context::build('client', (int) $row->client_id);
                    list($text) = Events::render($text, $ctx['vars']);
                }
                // Même chemin que l'onglet Envoyer : quotas de la passerelle, STOP, simulation de frappe.
                $res = Queue::sendManual((int) $row->client_id, $row->phone, $text);
                if (!$res['ok']) {
                    return self::box('error', 'Échec : ' . self::h($res['error']));
                }
                Inbox::markReplied($row->phone);
                return self::box('success', 'Réponse envoyée au +' . self::h($row->phone) . '.');

            case 'test':
                $phone = Phone::normalize((string) ($_POST['test_phone'] ?? ''));
                if ($phone === '') {
                    return self::box('error', 'Saisissez votre numéro.');
                }
                $res = Queue::sendTest((string) ($_POST['event'] ?? ''), (int) ($_POST['relid'] ?? 0), $phone);
                return $res['ok']
                    ? self::box('success', 'Aperçu envoyé au +' . self::h($phone) . '.') . '<pre class="lwb-msg" style="max-width:none">' . self::h($res['text']) . '</pre>'
                    : self::box('error', 'Échec : ' . self::h($res['error']));

            case 'pair':
                $res = Gateway::request('POST', '/pair', ['phone' => Phone::normalize((string) ($_POST['phone'] ?? ''))], 30);
                return $res['ok']
                    ? self::box('success', 'Sur le téléphone : WhatsApp &gt; Appareils connectés &gt; Connecter un appareil &gt; <em>Lier avec le numéro de téléphone</em>, puis saisissez :<br><span class="lwb-pair">' . self::h($res['data']['code'] ?? '') . '</span>')
                    : self::box('error', 'Code impossible : ' . self::h($res['error']));

            case 'reconnect':
                $res = Gateway::request('POST', '/reconnect', null, 15);
                return $res['ok'] ? self::box('success', 'Reconnexion lancée.') : self::box('error', 'Échec : ' . self::h($res['error']));

            case 'logout':
                $res = Gateway::request('POST', '/logout', null, 20);
                return $res['ok'] ? self::box('success', 'Appareil délié. Un nouveau QR code va apparaître.') : self::box('error', 'Échec : ' . self::h($res['error']));

            case 'msg_send_now':
                $res = Queue::sendNow($id);
                return $res['ok'] ? self::box('success', 'Message #' . $id . ' envoyé.') : self::box('error', 'Message #' . $id . ' : ' . self::h($res['error'] ?: $res['status'] ?? ''));

            case 'msg_cancel':
                Capsule::table(Queue::TABLE)->where('id', $id)->where('status', 'pending')->update(['status' => 'skipped', 'error' => 'Annulé par un administrateur']);
                return self::box('success', 'Message #' . $id . ' annulé.');

            case 'msg_retry':
                Capsule::table(Queue::TABLE)->where('id', $id)->whereIn('status', ['failed', 'expired'])
                    ->update(['status' => 'pending', 'attempts' => 0, 'next_try' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]);
                return self::box('success', 'Message #' . $id . ' remis en file.');

            case 'queue_clear':
                $n = Capsule::table(Queue::TABLE)->where('status', 'pending')->update(['status' => 'skipped', 'error' => 'File vidée par un administrateur']);
                return self::box('success', $n . ' message(s) retiré(s) de la file.');

            case 'optout_add':
                $phone = Phone::normalize((string) ($_POST['phone'] ?? ''));
                if ($phone === '') {
                    return self::box('error', 'Numéro invalide.');
                }
                Capsule::table('mod_lwbwha_optout')->updateOrInsert(['phone' => $phone], ['client_id' => Queue::clientByPhone($phone), 'source' => 'admin', 'created_at' => date('Y-m-d H:i:s')]);
                return self::box('success', '+' . self::h($phone) . ' ne recevra plus de messages automatiques.');

            case 'optout_remove':
                $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? ''));
                Capsule::table('mod_lwbwha_optout')->where('phone', $phone)->delete();
                if (Settings::isGateway()) {
                    Gateway::request('POST', '/optouts', ['phone' => $phone, 'action' => 'remove'], 10);
                }
                return self::box('success', '+' . self::h($phone) . ' peut de nouveau recevoir des messages.');
        }
        return '';
    }

    /** Notification choisie sur la page d'une facture, contrôlée d'après l'état actuel de la facture. */
    private static function sendInvoice($invoiceId, $event)
    {
        $inv = Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->first();
        if (!$inv) {
            return self::box('error', 'Facture #' . (int) $invoiceId . ' introuvable.');
        }
        if (!in_array($event, Events::forInvoice($inv->status, Time::daysUntil($inv->duedate), $inv->total), true)) {
            return self::box('error', 'Cette notification ne correspond pas à une facture ' . self::h(self::INVOICE_STATUS[$inv->status] ?? $inv->status) . ' : rechargez la page.');
        }
        $res = Queue::sendNotification($event, (int) $inv->id);
        return self::sent($res, 'Notification « ' . Events::get($event)['label'] . ' » envoyée');
    }

    // ------------------------------------------------------------------ pages

    private static function pageDashboard()
    {
        $active = explode(',', Context::config('ActiveAddonModules'));
        if (in_array('whatsapp_notify', $active, true)) {
            echo self::box('error', 'L\'ancien module <strong>WhatsApp Notify</strong> est encore actif : désactivez-le (Configuration &gt; Modules addon) pour éviter les messages en double.');
        }
        if (!Settings::isGateway()) {
            echo self::box('info', 'Fournisseur actuel : <strong>' . self::h(Settings::get('provider')) . '</strong>. Les fonctions de la passerelle (QR code, statuts de lecture, PDF) ne s\'appliquent qu\'au mode « Passerelle WhatsApp Web ».');
        }
        $s = Gateway::status(true);
        $st = (string) ($s['status'] ?? 'unreachable');

        echo '<div class="row"><div class="col-md-6"><div class="lwb-card"><h4>Connexion WhatsApp</h4>';
        if ($st === 'open') {
            $number = Gateway::number();
            echo '<p><span class="label label-success">Connecté</span> &nbsp;<strong>+' . self::h($number) . '</strong>'
                . (!empty($s['me']['name']) ? ' (' . self::h($s['me']['name']) . ')' : '')
                . (!empty($s['connectedAt']) ? '<br><small class="text-muted">depuis le ' . self::h(Time::show(date('Y-m-d H:i:s', strtotime($s['connectedAt'])))) . '</small>' : '') . '</p>';
            $local = preg_replace('/\D+/', '', Settings::get('default_country_code', '243'));
            if ($local !== '' && strpos($number, $local) !== 0) {
                echo '<div class="alert alert-warning" style="padding:8px 10px">💡 Ce numéro n\'est pas un numéro local (+' . self::h($local) . '). Vos clients font davantage confiance à un numéro local '
                    . 'et le signalent moins comme spam : si possible, utilisez une carte SIM locale dédiée.</div>';
            }
            echo '<div style="display:flex;gap:8px">' . self::form('reconnect', 'dashboard', '<button class="btn btn-default btn-sm">Reconnecter</button>')
                . self::form('logout', 'dashboard', '<button class="btn btn-danger btn-sm">Délier l\'appareil</button>', 'onsubmit="return confirm(\'Délier ce numéro WhatsApp ?\')"') . '</div>';
        } elseif ($st === 'qr') {
            echo '<p><span class="label label-warning">À relier</span> Sur le téléphone : WhatsApp &gt; Paramètres &gt; Appareils connectés &gt; Connecter un appareil, puis scannez :</p>';
            if (!empty($s['qr']) && strpos($s['qr'], 'data:image/png;base64,') === 0) {
                echo '<img src="' . self::h($s['qr']) . '" width="260" height="260" alt="QR WhatsApp" style="border:1px solid #ddd">';
            }
            echo '<p style="margin-top:10px"><strong>WHMCS est ouvert sur le même téléphone ?</strong> Demandez plutôt un code de liaison :</p>'
                . self::form('pair', 'dashboard', '<input type="text" name="phone" placeholder="Numéro WhatsApp à relier, ex. +243…" class="form-control" style="width:260px;display:inline-block"> '
                    . '<button class="btn btn-primary">Obtenir un code</button>');
            if (($_POST['action'] ?? '') !== 'pair') {
                echo '<script>setTimeout(function(){location.reload();},10000);</script>';
            }
        } elseif ($st === 'unreachable') {
            echo '<p><span class="label label-danger">Passerelle injoignable</span></p><p>' . self::h($s['error'] ?? '') . '</p>'
                . '<p class="text-muted">Le cron WHMCS la redémarre automatiquement si le dossier et le binaire Node.js sont renseignés dans la configuration du module.</p>';
            $tail = Gateway::logTail(15);
            if ($tail !== '') {
                echo '<pre style="max-height:220px;overflow:auto;font-size:11px">' . self::h($tail) . '</pre>';
            }
        } else {
            $texts = [
                'connecting' => ['Connexion…', 'info', 'La passerelle se connecte à WhatsApp.'],
                'closed' => ['Déconnecté', 'warning', 'Connexion interrompue : la passerelle réessaie automatiquement.'],
                'logged_out' => ['Délié', 'danger', 'L\'appareil a été délié : un nouveau QR code va apparaître.'],
                'banned' => ['Refusé (403)', 'danger', 'WhatsApp refuse la connexion : le numéro est peut-être banni ou restreint. Vérifiez WhatsApp sur le téléphone. Nouvelle tentative toutes les 30 min.'],
                'replaced' => ['Ouvert ailleurs', 'warning', 'La session a été ouverte par une autre passerelle : une seule doit utiliser ce numéro.'],
            ];
            $t = $texts[$st] ?? [$st, 'default', ''];
            echo '<p><span class="label label-' . $t[1] . '">' . self::h($t[0]) . '</span> ' . self::h($t[2]) . '</p>';
            if (!empty($s['lastError'])) {
                echo '<p class="text-muted"><small>Dernière erreur : ' . self::h($s['lastError']) . (isset($s['lastCode']) ? ' (' . self::h($s['lastCode']) . ')' : '') . '</small></p>';
            }
            echo self::form('reconnect', 'dashboard', '<button class="btn btn-default btn-sm">Reconnecter maintenant</button>');
            echo '<script>setTimeout(function(){location.reload();},15000);</script>';
        }
        echo '</div></div>';

        // Santé / anti-bannissement
        echo '<div class="col-md-6"><div class="lwb-card"><h4>Anti-bannissement</h4>';
        if (!empty($s['quota'])) {
            $q = $s['quota'];
            echo '<div class="lwb-kpi"><b>' . (int) $q['sent_last_hour'] . ' / ' . (int) $q['max_per_hour'] . '</b>envois cette heure</div>'
                . '<div class="lwb-kpi"><b>' . (int) $q['sent_last_24h'] . ' / ' . (int) $q['max_per_day'] . '</b>envois sur 24 h</div>'
                . '<div class="lwb-kpi"><b>' . (int) ($q['new_last_24h'] ?? 0) . ' / ' . (int) ($q['max_new_per_day'] ?? 0) . '</b>nouveaux contacts sur 24 h</div>';
            if (!empty($q['warmup'])) {
                echo '<p><span class="label label-warning">Rodage du numéro</span> quotas réduits pendant les premiers jours.</p>';
            }
        }
        if (!empty($s['newChatCap']['total'])) {
            $c = $s['newChatCap'];
            echo '<p>Limite WhatsApp de nouvelles discussions : <strong>' . (int) $c['used'] . ' / ' . (int) $c['total'] . '</strong>'
                . (!empty($c['cycleEnd']) ? ' (jusqu\'au ' . self::h(Time::show(date('Y-m-d H:i:s', strtotime($c['cycleEnd'])))) . ')' : '') . '</p>';
        }
        if (!empty($s['reachout']['active'])) {
            echo '<div class="alert alert-danger" style="padding:8px 10px">WhatsApp limite actuellement les messages vers de <strong>nouveaux</strong> contacts'
                . (!empty($s['reachout']['until']) ? ' jusqu\'au ' . self::h(Time::show(date('Y-m-d H:i:s', strtotime($s['reachout']['until'])))) : '')
                . '. Seuls vos contacts existants reçoivent des messages en attendant.</div>';
        }
        if (isset($s['version'])) {
            $up = (int) $s['uptime'];
            $uptime = $up >= 86400 ? floor($up / 86400) . ' j ' . gmdate('G', $up % 86400) . ' h' : gmdate('G \h i \m\i\n', $up);
            echo '<p class="text-muted"><small>Passerelle v' . self::h($s['version']) . ' · en ligne depuis ' . self::h($uptime) . ' · '
                . (int) ($s['memory_mb'] ?? 0) . ' Mo · ' . (int) ($s['contacts_known'] ?? 0) . ' contacts connus · '
                . (int) ($s['optout_count'] ?? 0) . ' désinscrits</small></p>';
        }
        echo '</div></div></div>';

        // Activité
        $t = Stats::today();
        $paid = Stats::paidAfter(date('Y-m-d H:i:s', time() - 30 * 86400));
        echo '<div class="lwb-card"><h4>Activité</h4>'
            . '<div class="lwb-kpi"><b>' . $t['sent_today'] . '</b>envoyés aujourd\'hui</div>'
            . '<div class="lwb-kpi"><b>' . $t['pending'] . '</b>en attente</div>'
            . '<div class="lwb-kpi"><b>' . ($t['read_rate'] === null ? '–' : $t['read_rate'] . ' %') . '</b>lus (7 jours)</div>'
            . '<div class="lwb-kpi"><b>' . $paid['count'] . '</b>factures payées après un WhatsApp (30 j, ' . self::h($paid['amount']) . ')</div>'
            . '<div class="lwb-kpi"><b>' . $t['received_today'] . '</b><a href="' . self::h(self::url(['tab' => 'inbox'])) . '">messages reçus aujourd\'hui</a></div>';
        if (Time::inQuietHours()) {
            echo '<p><span class="label label-info">Heures calmes</span> aucun envoi automatique jusqu\'à ' . self::h(Time::quietEnd()) . ' (heure ' . self::h(Time::tz()->getName()) . ').</p>';
        } elseif ($t['pending'] && $t['next']) {
            echo '<p class="text-muted">Prochain envoi prévu : ' . self::h(Time::show($t['next'])) . ' (au passage du cron WHMCS).</p>';
        }
        if ($t['failed_today']) {
            echo '<p><a href="' . self::h(self::url(['tab' => 'messages', 'status' => 'failed'])) . '">' . $t['failed_today'] . ' échec(s) aujourd\'hui</a></p>';
        }
        echo '</div>';
    }

    private static function pageInbox()
    {
        if (!Settings::isGateway()) {
            echo self::box('info', 'La boîte de réception fonctionne avec la passerelle WhatsApp Web (fournisseur actuel : <strong>' . self::h(Settings::get('provider')) . '</strong>).');
        } else {
            $version = (string) (Gateway::status()['version'] ?? '');
            if ($version !== '' && version_compare($version, '3.1.0', '<')) {
                echo self::box('error', 'La passerelle (v' . self::h($version) . ') ne transmet pas encore les messages reçus : mettez à jour ses fichiers '
                    . '<code>server.js</code>, <code>package.json</code> et <code>package-lock.json</code> (v3.1.0 ou plus), puis redémarrez-la.');
            }
        }
        if (!Inbox::ready()) {
            echo self::box('info', 'La boîte de réception sera prête au prochain passage du cron WHMCS.');
            return;
        }
        $clientId = (int) ($_GET['userid'] ?? 0);
        $q = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $query = Capsule::table(Inbox::TABLE);
        if ($clientId) {
            $query->where('client_id', $clientId);
        } elseif ($q !== '') {
            $digits = ltrim(preg_replace('/\D+/', '', $q), '0');
            if (ctype_digit($q) && strlen($q) < 7) {
                $query->where('client_id', (int) $q);
            } elseif (strlen($digits) >= 6) {
                $query->where('phone', 'like', '%' . $digits . '%');
            } else {
                $like = '%' . $q . '%';
                $ids = Capsule::table('tblclients')->where(function ($w) use ($like) {
                    $w->where('firstname', 'like', $like)->orWhere('lastname', 'like', $like)->orWhere('companyname', 'like', $like)
                        ->orWhere(Capsule::raw("CONCAT(firstname, ' ', lastname)"), 'like', $like);
                })->limit(200)->pluck('id')->all();
                $query->where(function ($w) use ($ids, $q) {
                    $w->whereIn('client_id', $ids ?: [-1])->orWhere('name', 'like', '%' . Events::dbEncode($q) . '%');
                });
            }
        }
        $here = array_filter(['tab' => 'inbox', 'userid' => $clientId ?: null, 'q' => $clientId ? null : ($q !== '' ? $q : null), 'p' => $page > 1 ? $page : null]);
        $total = (clone $query)->count();
        $rows = $query->orderBy('received_at', 'desc')->orderBy('id', 'desc')->offset(($page - 1) * 50)->limit(50)->get();
        $names = Capsule::table('tblclients')->whereIn('id', $rows->pluck('client_id')->unique()->all())->get(['id', 'firstname', 'lastname', 'companyname'])->keyBy('id');
        $tickets = Capsule::table('tbltickets')->whereIn('id', $rows->pluck('ticket_id')->filter()->unique()->all())->pluck('tid', 'id')->all();
        $quoted = Capsule::table(Queue::TABLE)->whereIn('id', $rows->pluck('reply_to')->filter()->unique()->all())->get(['id', 'event', 'sent_at', 'created_at'])->keyBy('id');
        $team = Settings::teamPhones();

        $dept = Settings::bool('inbox_tickets', false) ? (string) Capsule::table('tblticketdepartments')->where('id', Inbox::department())->value('name') : '';
        echo '<div class="lwb-card"><p style="margin:0">Messages WhatsApp reçus de vos clients (réponses aux notifications, questions…), gardés 30 jours par la passerelle et rapatriés à chaque cron. '
            . 'Une réponse envoyée d\'ici part de votre numéro WhatsApp, avec les protections anti-bannissement habituelles.</p>'
            . '<p class="text-muted" style="margin:6px 0 0"><small>Tickets : ' . (Settings::bool('inbox_tickets', false)
                ? 'les messages d\'un client sont ajoutés à son ticket ouvert, sinon un ticket est ouvert dans le département « ' . self::h($dept) . ' »'
                : 'désactivé (Configuration &gt; Modules addon &gt; LWB WhatsApp &gt; « Boîte de réception : tickets »)') . '.</small></p></div>';
        echo '<form method="get" class="form-inline" style="margin-bottom:12px"><input type="hidden" name="module" value="' . Settings::MODULE . '"><input type="hidden" name="tab" value="inbox">'
            . '<input type="text" name="q" value="' . self::h($clientId ?: $q) . '" placeholder="ID client, nom ou numéro" class="form-control" style="width:240px"> <button class="btn btn-default">Filtrer</button>'
            . ($clientId || $q !== '' ? ' &nbsp;<a href="' . self::h(self::url(['tab' => 'inbox'])) . '">Tous les messages</a>' : '') . '</form>';
        $c = $clientId ? Capsule::table('tblclients')->where('id', $clientId)->first(['firstname', 'lastname']) : null;
        if ($c) {
            echo '<p>Client : <a href="clientssummary.php?userid=' . $clientId . '"><strong>' . self::h(trim($c->firstname . ' ' . $c->lastname)) . '</strong></a>'
                . ' · <a href="' . self::h(self::url(['tab' => 'send', 'userid' => $clientId])) . '">Envoyer un message</a>'
                . ' · <a href="' . self::h(self::url(['tab' => 'messages', 'q' => $clientId])) . '">Messages envoyés</a></p>';
        }

        echo '<table class="datatable" width="100%"><tr><th>Reçu le</th><th>De</th><th>Message</th><th>Ticket</th><th></th></tr>';
        if (!count($rows)) {
            echo '<tr><td colspan="5" class="text-muted">Aucun message reçu' . ($clientId || $q !== '' ? ' pour ce filtre' : ' pour le moment') . '.</td></tr>';
        }
        foreach ($rows as $r) {
            $client = $names[$r->client_id] ?? null;
            if ($client) {
                $who = '<a href="clientssummary.php?userid=' . (int) $r->client_id . '">' . self::h(trim($client->firstname . ' ' . $client->lastname)) . '</a>'
                    . ($client->companyname ? '<br><small>' . self::h($client->companyname) . '</small>' : '');
            } else {
                $who = in_array($r->phone, $team, true) ? '<span class="label label-default">équipe</span>' : '<span class="text-muted">Numéro inconnu</span>';
            }
            $name = Events::dbDecode($r->name);
            $who .= '<br><small><a href="' . self::h(self::url(['tab' => 'inbox', 'q' => $r->phone])) . '" title="Tous les messages de ce numéro">+' . self::h($r->phone) . '</a>'
                . ($name !== '' ? ' · ' . self::h($name) : '') . '</small>';
            $context = '';
            if ($r->reply_to && isset($quoted[$r->reply_to])) {
                $m = $quoted[$r->reply_to];
                $context = '<small class="text-muted">En réponse à : ' . self::h(self::label($m->event)) . ' (' . self::h(Time::show($m->sent_at ?: $m->created_at, 'd/m H:i')) . ')</small>';
            }
            $ticket = $r->ticket_id ? '<a href="supporttickets.php?action=view&id=' . (int) $r->ticket_id . '">#' . self::h($tickets[$r->ticket_id] ?? $r->ticket_id) . '</a>' : '';
            $state = $r->replied_at ? '<span class="label label-success" title="' . self::h(Time::show($r->replied_at)) . '">Répondu</span> ' : '';
            $reply = '<details style="display:inline-block"><summary class="btn btn-default btn-xs">Répondre</summary>'
                . self::form('inbox_reply', $here, '<input type="hidden" name="id" value="' . (int) $r->id . '">'
                    . '<textarea name="message" rows="3" class="form-control" style="min-width:280px;margin:6px 0" required></textarea>'
                    . '<button class="btn btn-primary btn-xs">Envoyer sur WhatsApp</button>')
                . '</details>';
            echo '<tr><td style="white-space:nowrap">' . self::h(Time::show($r->received_at)) . '</td><td>' . $who . '</td>'
                . '<td>' . $context . '<div class="lwb-msg">' . self::h(Events::dbDecode($r->message)) . '</div></td>'
                . '<td>' . $ticket . '</td><td>' . $state . $reply . '</td></tr>';
        }
        echo '</table>';
        if ($total > 50) {
            echo '<p>';
            for ($i = 1; $i <= (int) ceil($total / 50) && $i <= 20; $i++) {
                echo $i === $page ? ' <strong>' . $i . '</strong> ' : ' <a href="' . self::h(self::url(array_merge($here, ['p' => $i]))) . '">' . $i . '</a> ';
            }
            echo '</p>';
        }
    }

    private static function pageSend()
    {
        $clientId = (int) ($_GET['userid'] ?? $_POST['client_id'] ?? 0);
        $client = $clientId ? Capsule::table('tblclients')->where('id', $clientId)->first() : null;
        // Après un refus, la saisie est conservée ; après un envoi, seuls le mode et la notification restent.
        $old = ($_POST['action'] ?? '') === 'send_manual' && !self::$done ? $_POST : [];
        $mode = ($_POST['mode'] ?? '') === 'event' ? 'event' : 'text';
        $event = (string) ($_POST['event'] ?? '');

        $info = '<span class="text-muted">ou laissez vide pour écrire à un numéro</span>';
        if ($client) {
            $who = Phone::forClient($client);
            $info = '<strong>' . self::h(trim($client->firstname . ' ' . $client->lastname)) . '</strong> · '
                . ($who['phone'] !== '' ? 'WhatsApp : +' . self::h($who['phone']) : '<span class="text-danger">aucun numéro</span>')
                . ($who['blocked'] !== '' && $who['phone'] !== '' ? ' · <span class="text-warning">' . self::h($who['blocked']) . '</span>' : '');
        } elseif ($clientId) {
            $info = '<span class="text-danger">Client #' . $clientId . ' introuvable</span>';
        }
        echo '<div class="lwb-card"><form method="get" class="form-inline" style="margin:0"><input type="hidden" name="module" value="' . Settings::MODULE . '">'
            . '<input type="hidden" name="tab" value="send">Client : <input type="number" name="userid" value="' . ($clientId ?: '') . '" placeholder="ID client" class="form-control" style="width:120px"> '
            . '<button class="btn btn-default">Choisir</button> &nbsp;' . $info . '</form></div>';
        $clientId = $client ? (int) $client->id : 0;

        // Notifications client, par rubrique ; data-rel oriente les suggestions d'ID.
        $events = '';
        $group = null;
        foreach (Events::defaults() as $key => $def) {
            if (!empty($def['team'])) {
                continue;
            }
            if ($def['group'] !== $group) {
                $events .= ($group === null ? '' : '</optgroup>') . '<optgroup label="' . self::h($def['group']) . '">';
                $group = $def['group'];
            }
            $events .= '<option value="' . $key . '" data-rel="' . $def['rel'] . '"' . ($event === $key ? ' selected' : '') . '>' . self::h($def['label']) . '</option>';
        }
        $events .= '</optgroup>';

        $attach = '<p class="text-muted"><small>Choisissez un client pour joindre une de ses factures en PDF.</small></p>';
        $lists = '';
        if ($clientId) {
            $invoices = Capsule::table('tblinvoices')->where('userid', $clientId)->where('status', '!=', 'Draft')
                ->orderByRaw("status = 'Unpaid' desc")->orderBy('id', 'desc')->limit(15)->get(['id', 'userid', 'invoicenum', 'total', 'status', 'duedate']);
            $options = '<option value="">Aucune</option>';
            foreach ($invoices as $inv) {
                $options .= '<option value="' . (int) $inv->id . '"' . ((int) ($old['invoice_id'] ?? 0) === (int) $inv->id ? ' selected' : '') . '>' . self::h(self::invoiceLabel($inv)) . '</option>';
            }
            $attach = '<p>Joindre une facture (PDF) : <select name="invoice_id" class="form-control" style="display:inline-block;width:auto;max-width:100%">' . $options . '</select></p>';
            $lists = self::suggestions($clientId, $invoices);
        }

        $quiet = Time::inQuietHours() ? 'onsubmit="return confirm(\'Heures calmes en cours chez vos clients : envoyer quand même maintenant ?\')"' : '';
        echo '<div class="lwb-card">'
            . self::form('send_manual', array_filter(['tab' => 'send', 'userid' => $clientId ?: null]),
                '<input type="hidden" name="client_id" value="' . ($clientId ?: '') . '">'
                . '<p><label style="font-weight:normal;margin-right:18px"><input type="radio" name="mode" value="text"' . ($mode === 'text' ? ' checked' : '') . '> Message libre</label>'
                . '<label style="font-weight:normal"><input type="radio" name="mode" value="event"' . ($mode === 'event' ? ' checked' : '') . '> Notification WHMCS (facture, service, domaine…)</label></p>'
                . '<div id="lwb-send-text"><p><textarea name="message" rows="6" class="form-control" placeholder="{greeting} {first_name}, …">' . self::h($old['message'] ?? '') . '</textarea>'
                . '<small>Avec un client : ' . implode(' ', array_map(function ($v) {
                    return '<span class="lwb-var">{' . $v . '}</span>';
                }, ['greeting', 'first_name', 'client_name', 'company', 'client_area'])) . ' et les variantes {Bonjour|Hello}.</small></p>' . $attach . '</div>'
                . '<div id="lwb-send-event"><p>Notification : <select name="event" class="form-control" style="display:inline-block;width:auto;max-width:100%">' . $events . '</select></p>'
                . '<p>ID de l\'élément : <input type="number" name="relid" value="' . ((int) ($old['relid'] ?? 0) ?: '') . '" class="form-control" style="width:140px;display:inline-block" autocomplete="off"> '
                . '<small class="text-muted">' . ($clientId ? 'les éléments du client sont proposés · ' : '') . 'facture → ID facture · service → ID service · domaine → ID domaine · '
                . 'bienvenue/marketing → ID client · commande → ID commande · ticket → ID ticket</small></p>'
                . '<p class="text-muted"><small>Même texte, mêmes liens et même PDF que la notification automatique (onglet Modèles), avec les données à jour, '
                . 'vers le client de l\'élément. Refusée si la situation ne s\'y prête plus (facture déjà payée…) ou si le client s\'est désinscrit.</small></p></div>'
                . '<p>Numéro : <input type="text" name="phone" value="' . self::h($old['phone'] ?? '') . '" placeholder="+243…" class="form-control" style="width:200px;display:inline-block"> '
                . '<small class="text-muted">facultatif pour un client : son numéro WhatsApp est utilisé</small></p>'
                . '<button class="btn btn-primary">Envoyer maintenant</button>' . $lists,
                'id="lwb-send" ' . $quiet)
            . '</div><p class="text-muted">Les envois manuels partent tout de suite (sans attendre le cron ni la fin des heures calmes), mais respectent les quotas anti-bannissement de la passerelle et le STOP. '
            . 'Pour une facture, le bouton « Envoyer sur WhatsApp » de sa page fait la même chose en un clic.</p>'
            . '<script>(function () {'
            . 'var f = document.getElementById("lwb-send"), e = f.elements["event"], r = f.elements["relid"];'
            . 'function show() {'
            . 'var ev = f.querySelector("input[name=mode]:checked").value === "event", o = e.options[e.selectedIndex], rel = o ? o.getAttribute("data-rel") : "";'
            . 'document.getElementById("lwb-send-text").style.display = ev ? "none" : "";'
            . 'document.getElementById("lwb-send-event").style.display = ev ? "" : "none";'
            . 'r.setAttribute("list", "lwb-rel-" + rel);'
            . 'if (ev && rel === "client" && !r.value && f.elements["client_id"].value) { r.value = f.elements["client_id"].value; } }'
            . 'f.addEventListener("change", show); show(); })();</script>';
    }

    // ------------------------------------------------------------------ annonces

    /** Estimation lisible de la durée d'envoi d'une annonce. */
    private static function broadcastEta($n)
    {
        $perHour = Settings::int('broadcast_per_run', 3, 1, 10) * 12;
        $hours = $n / $perHour;
        $eta = $hours < 1 ? 'moins d\'une heure' : 'environ ' . (int) ceil($hours) . ' h d\'envoi';
        return $eta . ' (≈ ' . $perHour . ' messages par heure avec un cron WHMCS toutes les 5 minutes, sans compter les heures calmes '
            . self::h(Settings::get('quiet_hours', '21-8')) . ' h ni les quotas de la passerelle)';
    }

    /** Aperçu, envoi de test et lancement d'une annonce (onglet Annonces). */
    private static function broadcastAction()
    {
        Broadcast::ready();
        $step = (string) ($_POST['step'] ?? 'preview');
        $message = trim((string) ($_POST['message'] ?? ''));
        $audience = Broadcast::audience($_POST);
        if ($message === '') {
            return self::box('error', 'Le message est vide.');
        }
        $r = Broadcast::recipients($audience);
        $first = $r['ok'] ? array_key_first($r['ok']) : 0;
        $sample = $first ? Broadcast::text($message, Broadcast::clientVars($first, $r['targets'][$first])) : '';

        if ($step === 'test') {
            $phone = Phone::normalize((string) ($_POST['test_phone'] ?? ''));
            if ($phone === '') {
                return self::box('error', 'Saisissez votre numéro pour recevoir l\'aperçu.');
            }
            if (!$first) {
                return self::box('error', 'Aucun destinataire joignable pour ce ciblage : impossible de remplir l\'aperçu.');
            }
            $id = Queue::enqueue([
                'client_id' => $first, 'event' => Broadcast::EVENT, 'phone' => $phone, 'message' => $sample, 'priority' => 9, 'source' => 'test',
            ]);
            $res = Queue::sendNow($id) + ['phone' => $phone];
            return self::sendResult($res, 'Aperçu envoyé (rempli avec les données du client #' . $first . ')');
        }

        if ($step === 'launch') {
            $res = Broadcast::create((string) ($_POST['title'] ?? ''), $message, $audience, (int) ($_POST['days'] ?? 3));
            if (!$res['ok']) {
                return self::box('error', self::h($res['error']));
            }
            self::$done = true;
            return self::box('success', 'Annonce #' . $res['id'] . ' lancée : <strong>' . $res['queued'] . '</strong> message(s) en file'
                . ($res['blocked'] ? ', ' . $res['blocked'] . ' client(s) exclu(s)' : '') . '. ' . self::broadcastEta($res['queued']) . '. '
                . '<a href="' . self::h(self::url(['tab' => 'messages', 'bid' => $res['id']])) . '">Suivre les envois</a>');
        }

        // Aperçu : nombre de destinataires, exclusions, exemple de texte.
        $html = '<strong>' . count($r['ok']) . '</strong> destinataire(s) · ' . self::h(Broadcast::describe($audience));
        if ($r['ok']) {
            $html .= '<br>' . self::broadcastEta(count($r['ok'])) . '.';
        }
        if ($r['blocked']) {
            $names = Capsule::table('tblclients')->whereIn('id', array_keys($r['blocked']))->get(['id', 'firstname', 'lastname', 'companyname'])->keyBy('id');
            $list = '';
            foreach (array_slice($r['blocked'], 0, 60, true) as $id => $why) {
                $c = $names[$id] ?? null;
                $list .= '<li><a href="clientssummary.php?userid=' . (int) $id . '">' . self::h($c ? trim($c->firstname . ' ' . $c->lastname) : '#' . $id) . '</a> : ' . self::h($why) . '</li>';
            }
            $html .= '<details style="margin-top:4px"><summary style="cursor:pointer">' . count($r['blocked']) . ' client(s) exclu(s)</summary><ul style="margin:4px 0 0">' . $list
                . (count($r['blocked']) > 60 ? '<li>…</li>' : '') . '</ul></details>';
        }
        if ($sample !== '') {
            $c = Capsule::table('tblclients')->where('id', $first)->first(['firstname', 'lastname']);
            $html .= '<div style="margin-top:6px">Exemple pour <strong>' . self::h(trim($c->firstname . ' ' . $c->lastname)) . '</strong> :</div>'
                . '<pre class="lwb-msg" style="max-width:none;background:#fff">' . self::h($sample) . '</pre>';
        }
        return self::box($r['ok'] ? 'info' : 'error', $html);
    }

    private static function pageBroadcast()
    {
        Broadcast::ready();
        $old = ($_POST['action'] ?? '') === 'broadcast' && !self::$done ? $_POST : [];
        $a = Broadcast::audience($old);
        $sel = function ($list, $id) {
            return in_array((int) $id, $list, true) ? ' selected' : '';
        };
        $servers = '';
        foreach (Capsule::table('tblservers')->orderBy('name')->get(['id', 'name', 'hostname', 'disabled']) as $sv) {
            $servers .= '<option value="' . (int) $sv->id . '"' . $sel($a['servers'], $sv->id) . '>' . self::h($sv->name . ($sv->hostname ? ' (' . $sv->hostname . ')' : '') . ($sv->disabled ? ' · désactivé' : '')) . '</option>';
        }
        $groups = Capsule::table('tblproductgroups')->orderBy('order')->get(['id', 'name']);
        $groupOptions = '';
        $productOptions = '';
        foreach ($groups as $g) {
            $groupOptions .= '<option value="' . (int) $g->id . '"' . $sel($a['groups'], $g->id) . '>' . self::h($g->name) . '</option>';
            $products = Capsule::table('tblproducts')->where('gid', $g->id)->orderBy('order')->get(['id', 'name']);
            if (count($products)) {
                $productOptions .= '<optgroup label="' . self::h($g->name) . '">';
                foreach ($products as $p) {
                    $productOptions .= '<option value="' . (int) $p->id . '"' . $sel($a['products'], $p->id) . '>' . self::h($p->name) . '</option>';
                }
                $productOptions .= '</optgroup>';
            }
        }
        $statuses = '';
        foreach (Broadcast::SERVICE_STATUSES as $k => $label) {
            $statuses .= '<label style="font-weight:normal;margin-right:12px"><input type="checkbox" name="statuses[]" value="' . $k . '"' . (in_array($k, $a['statuses'], true) ? ' checked' : '') . '> ' . $label . '</label>';
        }
        $days = (int) ($old['days'] ?? 3);
        $daysOptions = '';
        foreach ([1 => '1 jour', 2 => '2 jours', 3 => '3 jours', 7 => '7 jours'] as $d => $label) {
            $daysOptions .= '<option value="' . $d . '"' . ($days === $d ? ' selected' : '') . '>' . $label . '</option>';
        }
        $vars = implode(' ', array_map(function ($v) {
            return '<span class="lwb-var">{' . $v . '}</span>';
        }, Broadcast::vars()));
        $placeholder = "{greeting} {first_name},\n\nNous avons mis à jour votre hébergement {domain} vers *PHP 8.3*, plus rapide et plus sûr. Aucune action n'est nécessaire de votre part.\n\nUn souci sur votre site ? Répondez simplement à ce message.\n\nL'équipe {company}";

        echo '<div class="lwb-card"><p style="margin:0">Un même message envoyé à un groupe de clients : mise à jour PHP, maintenance, changement de serveur, nouveauté… '
            . 'Chaque client reçoit un message personnel (prénom, domaine), avec le pied « Répondez STOP ». Les envois partent au compte-gouttes au passage du cron, '
            . 'après les notifications attendues et hors heures calmes, pour protéger votre numéro. Les clients désinscrits ou sans numéro sont exclus.</p></div>';

        $form = '<p>Titre (interne) : <input type="text" name="title" value="' . self::h($old['title'] ?? '') . '" maxlength="150" placeholder="Ex. Mise à jour PHP 8.3 – serveur 1" class="form-control" style="width:420px;max-width:100%;display:inline-block"></p>'
            . '<p><textarea name="message" rows="9" class="form-control" placeholder="' . self::h($placeholder) . '" required>' . self::h($old['message'] ?? '') . '</textarea>'
            . '<small>Variables : ' . $vars . ' · variantes <code>{Bonjour|Hello}</code> · <code>*gras*</code> <code>_italique_</code>. '
            . '<span class="lwb-var">{domain}</span> = premier domaine concerné, <span class="lwb-var">{domains}</span> = tous, <span class="lwb-var">{service}</span> = offre(s).</small></p>'
            . '<h4 style="margin-top:18px">Destinataires</h4>'
            . '<p><label style="font-weight:normal;margin-right:18px"><input type="radio" name="scope" value="clients"' . ($a['scope'] === 'clients' ? ' checked' : '') . '> Tous les clients actifs</label>'
            . '<label style="font-weight:normal"><input type="radio" name="scope" value="services"' . ($a['scope'] === 'services' ? ' checked' : '') . '> Clients ayant un service précis</label></p>'
            . '<div id="lwb-bc-services" class="row">'
            . '<div class="col-md-4"><label>Serveur</label><select name="servers[]" multiple size="6" class="form-control">' . $servers . '</select></div>'
            . '<div class="col-md-4"><label>Groupe de produits</label><select name="groups[]" multiple size="6" class="form-control">' . $groupOptions . '</select></div>'
            . '<div class="col-md-4"><label>Produit</label><select name="products[]" multiple size="6" class="form-control">' . $productOptions . '</select></div>'
            . '<div class="col-md-12" style="margin-top:8px">Statut du service : ' . $statuses
            . '<br><small class="text-muted">Ctrl/Cmd + clic pour choisir plusieurs éléments. Les critères se cumulent (serveur ET groupe ET produit) ; aucun choix = tous.</small></div></div>'
            . '<p style="margin-top:14px">Ne plus envoyer après : <select name="days" class="form-control" style="width:auto;display:inline-block">' . $daysOptions . '</select> '
            . '<small class="text-muted">les messages encore en file passé ce délai sont abandonnés (annonce périmée)</small></p>'
            . '<p>Votre numéro pour l\'aperçu : <input type="text" name="test_phone" value="' . self::h($old['test_phone'] ?? '') . '" placeholder="+243…" class="form-control" style="width:200px;display:inline-block"></p>'
            . '<input type="hidden" name="step" value="preview">'
            . '<button class="btn btn-default" onclick="this.form.step.value=\'preview\'">1. Compter et prévisualiser</button> '
            . '<button class="btn btn-info" onclick="this.form.step.value=\'test\'">2. M\'envoyer un aperçu</button> '
            . '<button class="btn btn-success" onclick="this.form.step.value=\'launch\';return confirm(\'Lancer l\\\'annonce ? Les messages partiront progressivement à tous les destinataires affichés par l\\\'aperçu.\')">3. Lancer l\'annonce</button>';
        echo '<div class="lwb-card">' . self::form('broadcast', 'broadcast', $form, 'id="lwb-bc"') . '</div>'
            . '<script>(function () { var f = document.getElementById("lwb-bc");'
            . 'function show() { document.getElementById("lwb-bc-services").style.display = f.querySelector("input[name=scope]:checked").value === "services" ? "" : "none"; }'
            . 'f.addEventListener("change", show); show(); })();</script>';

        $rows = Capsule::table(Broadcast::TABLE)->orderBy('id', 'desc')->limit(30)->get();
        echo '<h4>Annonces envoyées</h4><table class="datatable" width="100%"><tr><th>#</th><th>Date</th><th>Titre</th><th>Destinataires</th><th>Envoyés</th><th>Lus</th><th>En attente</th><th>Échecs / ignorés</th><th>Statut</th><th></th></tr>';
        if (!count($rows)) {
            echo '<tr><td colspan="10" class="text-muted">Aucune annonce pour le moment.</td></tr>';
        }
        $labels = ['active' => ['En cours', 'info'], 'done' => ['Terminée', 'success'], 'cancelled' => ['Annulée', 'default']];
        foreach ($rows as $b) {
            $p = Broadcast::progress($b->id);
            $st = $labels[$b->status] ?? [$b->status, 'default'];
            if ($b->status === 'active' && strtotime($b->expires_at) < time()) {
                $st = ['Expirée', 'warning'];
            }
            $audience = Broadcast::audience((array) json_decode((string) $b->audience, true));
            $actions = '<a href="' . self::h(self::url(['tab' => 'messages', 'bid' => $b->id])) . '" class="btn btn-default btn-xs">Détail</a>';
            if ($b->status === 'active' && $p['pending']) {
                $actions .= ' ' . self::form('broadcast_cancel', 'broadcast', '<input type="hidden" name="id" value="' . (int) $b->id . '"><button class="btn btn-danger btn-xs">Annuler</button>',
                    'style="display:inline" onsubmit="return confirm(\'Annuler les ' . $p['pending'] . ' message(s) encore en attente ?\')"');
            }
            echo '<tr><td>' . (int) $b->id . '</td><td style="white-space:nowrap">' . self::h(Time::show($b->created_at)) . '</td>'
                . '<td><strong>' . self::h($b->title) . '</strong><br><small class="text-muted">' . self::h(Broadcast::describe($audience)) . '</small>'
                . '<details><summary class="text-muted" style="cursor:pointer;font-size:11px">voir le texte</summary><pre class="lwb-msg">' . self::h(Events::dbDecode($b->message)) . '</pre></details></td>'
                . '<td>' . (int) $b->total . '</td><td>' . $p['sent'] . '</td><td>' . $p['read'] . '</td><td>' . $p['pending'] . '</td><td>' . $p['failed'] . ' / ' . $p['skipped'] . '</td>'
                . '<td><span class="label label-' . $st[1] . '">' . $st[0] . '</span></td><td style="white-space:nowrap">' . $actions . '</td></tr>';
        }
        echo '</table><p class="text-muted"><small>Conseil : réservez les annonces aux informations utiles (technique, sécurité, changement de service). '
            . 'Les envois promotionnels de masse sont la première cause de blocage d\'un numéro WhatsApp.</small></p>';
    }

    /** Éléments d'un client proposés dans le champ « ID de l'élément », par type d'objet. */
    private static function suggestions($clientId, $invoices)
    {
        $lists = ['invoice' => [], 'service' => [], 'domain' => [], 'client' => [$clientId => 'ce client'], 'order' => [], 'ticket' => []];
        foreach ($invoices as $inv) {
            $lists['invoice'][$inv->id] = self::invoiceLabel($inv);
        }
        $services = Capsule::table('tblhosting as h')->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')->where('h.userid', $clientId)
            ->orderBy('h.id', 'desc')->limit(20)->get(['h.id', 'h.domain', 'h.domainstatus', 'p.name']);
        foreach ($services as $s) {
            $lists['service'][$s->id] = trim($s->name . ' ' . $s->domain) . ' · ' . $s->domainstatus;
        }
        foreach (Capsule::table('tbldomains')->where('userid', $clientId)->orderBy('id', 'desc')->limit(20)->get(['id', 'domain', 'status', 'expirydate']) as $d) {
            $expiry = Time::date($d->expirydate);
            $lists['domain'][$d->id] = $d->domain . ' · ' . $d->status . ($expiry !== '' ? ' · expire le ' . $expiry : '');
        }
        foreach (Capsule::table('tblorders')->where('userid', $clientId)->orderBy('id', 'desc')->limit(10)->get(['id', 'ordernum', 'date', 'status']) as $o) {
            $lists['order'][$o->id] = 'n°' . $o->ordernum . ' du ' . Time::date($o->date) . ' · ' . $o->status;
        }
        foreach (Capsule::table('tbltickets')->where('userid', $clientId)->orderBy('id', 'desc')->limit(10)->get(['id', 'tid', 'title', 'status']) as $t) {
            $lists['ticket'][$t->id] = '#' . $t->tid . ' ' . $t->title . ' · ' . $t->status;
        }
        $html = '';
        foreach ($lists as $rel => $items) {
            $html .= '<datalist id="lwb-rel-' . $rel . '">';
            foreach ($items as $id => $label) {
                $html .= '<option value="' . (int) $id . '">' . self::h($label) . '</option>';
            }
            $html .= '</datalist>';
        }
        return $html;
    }

    private static function pageTemplates()
    {
        $rows = Capsule::table('mod_lwbwha_templates')->get()->keyBy('event');
        $gateway = Settings::isGateway();
        echo '<p>Les notifications client partent sur WhatsApp <strong>en plus</strong> de l\'e-mail WHMCS indiqué. Elles entrent dans la file d\'attente et partent au passage suivant du cron, hors heures calmes, '
            . 'après une dernière vérification (une facture payée entre-temps ne déclenche pas de relance). '
            . 'Mise en forme : <code>*gras*</code>, <code>_italique_</code> ; variantes tirées au hasard : <code>{Bonjour|Hello}</code>.</p>';
        $html = '';
        $group = null;
        foreach (Events::defaults() as $event => $def) {
            if ($def['group'] !== $group) {
                $group = $def['group'];
                $html .= '<h3 style="margin-top:25px">' . self::h($group) . '</h3>';
                if ($group === 'Équipe') {
                    $phones = Settings::teamPhones();
                    $html .= '<p class="text-muted">Alertes internes envoyées à : ' . ($phones ? '+' . self::h(implode(', +', $phones)) : '<strong>aucun numéro</strong> (renseignez « Numéros de l\'équipe » dans la configuration du module)') . '.</p>';
                } elseif ($group === 'Marketing') {
                    $html .= '<p class="text-muted">À activer avec prudence : les messages promotionnels sont la première cause de bannissement. Celui-ci suit votre e-mail promotionnel (clients ayant accepté le marketing).</p>';
                }
            }
            $t = $rows[$event] ?? null;
            $message = $t ? Events::dbDecode($t->message) : $def['message'];
            $trigger = !empty($def['team']) ? 'évènement WHMCS' : ($def['emails'] ? 'e-mail « ' . implode(' », « ', $def['emails']) . ' »' : 'tout e-mail de bienvenue produit/service');
            $html .= '<div class="panel panel-default"><div class="panel-heading"><label style="margin:0"><input type="checkbox" name="enabled[' . $event . ']" '
                . (($t && $t->enabled) ? 'checked' : '') . '> <strong>' . self::h($def['label']) . '</strong></label>'
                . ' <small class="text-muted">— ' . self::h($trigger) . '</small></div><div class="panel-body">'
                . '<textarea name="message[' . $event . ']" rows="7" class="form-control" style="font-family:inherit">' . self::h($message) . '</textarea><div style="margin-top:6px">'
                . implode('', array_map(function ($v) {
                    return '<span class="lwb-var">{' . $v . '}</span>';
                }, Events::vars($def['rel'], !empty($def['team'])))) . '</div>';
            if (isset($def['days'])) {
                $html .= '<div style="margin-top:8px">Seulement à J- <input type="text" name="days[' . $event . ']" value="' . self::h($t ? $t->days : $def['days']) . '" style="width:90px">'
                    . ' <small class="text-muted">jours avant l\'échéance, séparés par des virgules (vide = à chaque e-mail)</small></div>';
            }
            if ($def['rel'] === 'invoice' && empty($def['team'])) {
                $html .= '<div style="margin-top:6px"><label><input type="checkbox" name="attach_pdf[' . $event . ']" ' . (($t && $t->attach_pdf) ? 'checked' : '') . '> Joindre la facture en PDF</label></div>';
            }
            if (!$gateway) {
                $html .= '<div style="margin-top:8px">Sent.dm : ID du modèle / Cloud API : nom du modèle Meta : <input type="text" name="cloud_template[' . $event . ']" value="'
                    . self::h($t ? $t->cloud_template : '') . '" style="width:220px"> langue : <input type="text" name="cloud_lang[' . $event . ']" value="' . self::h($t ? $t->cloud_lang : 'fr') . '" style="width:60px"></div>';
            }
            $html .= '<div style="margin-top:6px"><label class="text-muted" style="font-weight:normal"><input type="checkbox" name="reset[' . $event . ']"> Rétablir le texte par défaut</label></div></div></div>';
        }
        echo self::form('save_templates', 'templates', $html . '<button class="btn btn-success btn-lg">Enregistrer les modèles</button>');
    }

    private static function pageMessages()
    {
        $status = (string) ($_GET['status'] ?? '');
        $event = (string) ($_GET['event'] ?? '');
        $q = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $bid = (int) ($_GET['bid'] ?? 0);
        $query = Capsule::table(Queue::TABLE);
        if ($bid) {
            $query->where('source', Broadcast::SOURCE)->where('rel_id', $bid);
            echo '<p>Messages de l\'annonce #' . $bid . ' · <a href="' . self::h(self::url(['tab' => 'messages'])) . '">tous les messages</a></p>';
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($event !== '') {
            $query->where('event', $event);
        }
        if ($q !== '') {
            $digits = preg_replace('/\D+/', '', $q);
            $query->where(function ($w) use ($q, $digits) {
                if (ctype_digit($q) && strlen($q) < 7) {
                    $w->where('client_id', (int) $q);
                } elseif ($digits !== '') {
                    $w->where('phone', 'like', '%' . $digits . '%');
                }
            });
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('id', 'desc')->offset(($page - 1) * 50)->limit(50)->get();
        $clicks = Capsule::table(Links::TABLE)->whereIn('message_id', $rows->pluck('id')->all())->select('message_id', Capsule::raw('sum(clicks) as n'))
            ->groupBy('message_id')->pluck('n', 'message_id')->all();
        $names = Capsule::table('tblclients')->whereIn('id', $rows->pluck('client_id')->unique()->all())->get(['id', 'firstname', 'lastname'])->keyBy('id');

        $options = '<option value="">Tous les statuts</option>';
        foreach (self::STATUS_LABELS as $key => $lbl) {
            $options .= '<option value="' . $key . '"' . ($status === $key ? ' selected' : '') . '>' . $lbl[0] . '</option>';
        }
        $events = '<option value="">Toutes les notifications</option>';
        foreach (Events::defaults() as $key => $def) {
            $events .= '<option value="' . $key . '"' . ($event === $key ? ' selected' : '') . '>' . self::h($def['label']) . '</option>';
        }
        $events .= '<option value="manual"' . ($event === 'manual' ? ' selected' : '') . '>Message manuel</option>'
            . '<option value="' . Broadcast::EVENT . '"' . ($event === Broadcast::EVENT ? ' selected' : '') . '>Annonce</option>';
        echo '<form method="get" class="form-inline" style="margin-bottom:12px"><input type="hidden" name="module" value="' . Settings::MODULE . '"><input type="hidden" name="tab" value="messages">'
            . '<select name="status" class="form-control">' . $options . '</select> <select name="event" class="form-control">' . $events . '</select> '
            . '<input type="text" name="q" value="' . self::h($q) . '" placeholder="ID client ou numéro" class="form-control"> <button class="btn btn-default">Filtrer</button></form>';

        $pending = Capsule::table(Queue::TABLE)->where('status', 'pending')->count();
        if ($pending) {
            echo self::form('queue_clear', 'messages', '<p>' . $pending . ' message(s) en attente. <button class="btn btn-default btn-xs">Vider la file</button></p>', 'onsubmit="return confirm(\'Retirer tous les messages en attente ?\')"');
        }
        echo '<table class="datatable" width="100%"><tr><th>#</th><th>Date</th><th>Client</th><th>Notification</th><th>Numéro</th><th>Statut</th><th>Lu</th><th>Clics</th><th></th></tr>';
        foreach ($rows as $r) {
            $lbl = self::STATUS_LABELS[$r->status] ?? [$r->status, 'default'];
            $client = $names[$r->client_id] ?? null;
            $delivery = ['sent' => '✓', 'delivered' => '✓✓', 'read' => '<span class="lwb-read" title="Lu">✓✓</span>', 'error' => '✗'][$r->delivery] ?? '';
            $actions = '';
            if ($r->status === 'pending') {
                $actions = self::form('msg_send_now', 'messages', '<input type="hidden" name="id" value="' . $r->id . '"><button class="btn btn-primary btn-xs">Envoyer</button>', 'style="display:inline"')
                    . ' ' . self::form('msg_cancel', 'messages', '<input type="hidden" name="id" value="' . $r->id . '"><button class="btn btn-default btn-xs">Annuler</button>', 'style="display:inline"');
            } elseif (in_array($r->status, ['failed', 'expired'], true)) {
                $actions = self::form('msg_retry', 'messages', '<input type="hidden" name="id" value="' . $r->id . '"><button class="btn btn-default btn-xs">Réessayer</button>', 'style="display:inline"');
            }
            $when = $r->status === 'sent' ? $r->sent_at : ($r->status === 'pending' ? $r->next_try : $r->created_at);
            echo '<tr><td>' . $r->id . '</td><td>' . self::h(Time::show($when)) . ($r->status === 'pending' ? '<br><small class="text-muted">prévu</small>' : '') . '</td>'
                . '<td>' . ($client ? '<a href="clientssummary.php?userid=' . (int) $r->client_id . '">' . self::h(trim($client->firstname . ' ' . $client->lastname)) . '</a>' : ($r->source === 'team' ? 'Équipe' : '–')) . '</td>'
                . '<td>' . self::h(self::label($r->event)) . ($r->source === 'test' ? ' <span class="label label-info">aperçu</span>' : '') . ($r->source === 'team' ? ' <span class="label label-default">équipe</span>' : '')
                . ($r->source === 'manual' && $r->event !== 'manual' ? ' <span class="label label-primary">manuel</span>' : '')
                . ($r->source === Broadcast::SOURCE ? ' <a href="' . self::h(self::url(['tab' => 'messages', 'bid' => $r->rel_id])) . '" class="label label-warning">#' . (int) $r->rel_id . '</a>' : '')
                . '<details><summary class="text-muted" style="cursor:pointer;font-size:11px">voir le texte</summary><pre class="lwb-msg">' . self::h(Events::dbDecode($r->message)) . '</pre></details></td>'
                . '<td>+' . self::h($r->phone) . '</td>'
                . '<td><span class="label label-' . $lbl[1] . '">' . $lbl[0] . '</span>' . ($r->error ? '<br><small class="text-muted">' . self::h(mb_substr(Events::dbDecode($r->error), 0, 140)) . '</small>' : '') . '</td>'
                . '<td>' . $delivery . '</td><td>' . (int) ($clicks[$r->id] ?? 0) . '</td><td>' . $actions . '</td></tr>';
        }
        echo '</table>';
        if ($total > 50) {
            echo '<p>';
            for ($i = 1; $i <= (int) ceil($total / 50) && $i <= 20; $i++) {
                echo $i === $page ? ' <strong>' . $i . '</strong> ' : ' <a href="' . self::h(self::url(array_filter(['tab' => 'messages', 'status' => $status, 'event' => $event, 'q' => $q, 'bid' => $bid ?: null, 'p' => $i]))) . '">' . $i . '</a> ';
            }
            echo '</p>';
        }
    }

    private static function pageStats()
    {
        $days = in_array((int) ($_GET['days'] ?? 30), [7, 30, 90], true) ? (int) $_GET['days'] : 30;
        $data = Stats::summary($days);
        echo '<p>Période : ';
        foreach ([7, 30, 90] as $d) {
            echo $d === $days ? '<strong>' . $d . ' jours</strong> ' : '<a href="' . self::h(self::url(['tab' => 'stats', 'days' => $d])) . '">' . $d . ' jours</a> ';
        }
        echo '</p><div class="lwb-card"><div class="lwb-kpi"><b>' . $data['total']['sent'] . '</b>messages envoyés</div>'
            . '<div class="lwb-kpi"><b>' . ($data['total']['sent'] ? round($data['total']['read'] * 100 / $data['total']['sent']) . ' %' : '–') . '</b>lus</div>'
            . '<div class="lwb-kpi"><b>' . $data['total']['clicked'] . '</b>liens ouverts</div>'
            . '<div class="lwb-kpi"><b>' . $data['paid']['count'] . '</b>factures payées dans les 7 jours suivant un WhatsApp (' . self::h($data['paid']['amount']) . ')</div></div>';
        echo '<table class="datatable" width="100%"><tr><th>Notification</th><th>Créés</th><th>Envoyés</th><th>Distribués</th><th>Lus</th><th>Clics</th><th>Ignorés</th><th>Expirés</th><th>Échecs</th><th>Désinscrits</th></tr>';
        foreach ($data['events'] as $event => $v) {
            echo '<tr><td>' . self::h(self::label($event)) . '</td><td>' . $v['created'] . '</td><td>' . $v['sent'] . '</td><td>' . $v['delivered'] . '</td><td>' . $v['read'] . '</td><td>'
                . $v['clicked'] . '</td><td>' . $v['skipped'] . '</td><td>' . $v['expired'] . '</td><td>' . $v['failed'] . '</td><td>' . $v['optout'] . '</td></tr>';
        }
        echo '</table><p class="text-muted"><small>« Ignorés » : messages devenus inutiles avant l\'envoi (facture payée, doublon fusionné…), c\'est normal et souhaitable. '
            . '« Lus » dépend des confirmations de lecture activées chez le client.</small></p>';
    }

    private static function pageOptouts()
    {
        $rows = Capsule::table('mod_lwbwha_optout')->orderBy('created_at', 'desc')->get();
        $sources = ['whatsapp' => 'a répondu STOP', 'admin' => 'ajouté par un administrateur', 'client' => 'profil client'];
        echo '<div class="lwb-card"><p>Ces numéros ne reçoivent plus de notifications automatiques. Un client peut se réinscrire en répondant <strong>START</strong>.</p>'
            . '<table class="datatable" width="100%"><tr><th>Numéro</th><th>Client</th><th>Origine</th><th>Date</th><th></th></tr>';
        foreach ($rows as $r) {
            echo '<tr><td>+' . self::h($r->phone) . '</td><td>' . ($r->client_id ? '<a href="clientssummary.php?userid=' . (int) $r->client_id . '">#' . (int) $r->client_id . '</a>' : '–') . '</td>'
                . '<td>' . self::h($sources[$r->source] ?? $r->source) . '</td><td>' . self::h(Time::show($r->created_at)) . '</td><td>'
                . self::form('optout_remove', 'optouts', '<input type="hidden" name="phone" value="' . self::h($r->phone) . '"><button class="btn btn-default btn-xs">Réactiver</button>', 'style="display:inline"') . '</td></tr>';
        }
        echo '</table>' . self::form('optout_add', 'optouts', '<p style="margin-top:10px">Ajouter un numéro : <input type="text" name="phone" placeholder="+243…" class="form-control" style="width:200px;display:inline-block"> <button class="btn btn-default">Désinscrire</button></p>') . '</div>';
        $fid = Phone::fieldId(Phone::FIELD_OPTOUT);
        if ($fid) {
            $clients = Capsule::table('tblcustomfieldsvalues as v')->join('tblclients as c', 'c.id', '=', 'v.relid')->where('v.fieldid', $fid)->where('v.value', 'on')
                ->get(['c.id', 'c.firstname', 'c.lastname']);
            echo '<div class="lwb-card"><h4>Clients ayant désactivé WhatsApp dans leur profil</h4>';
            if (!count($clients)) {
                echo '<p class="text-muted">Aucun.</p>';
            }
            foreach ($clients as $c) {
                echo '<a href="clientssummary.php?userid=' . (int) $c->id . '">' . self::h(trim($c->firstname . ' ' . $c->lastname)) . '</a><br>';
            }
            echo '<p class="text-muted"><small>Champ client « ' . self::h(Phone::FIELD_OPTOUT) . ' », modifiable par le client dans son profil.</small></p></div>';
        }
    }

    private static function pageTest()
    {
        $options = '';
        foreach (Events::defaults() as $event => $def) {
            $options .= '<option value="' . $event . '"' . (($_POST['event'] ?? '') === $event ? ' selected' : '') . '>' . self::h($def['group'] . ' — ' . $def['label']) . '</option>';
        }
        echo '<div class="lwb-card"><p>Envoie <strong>tout de suite</strong> une notification remplie avec de vraies données WHMCS (PDF et liens compris) vers <strong>votre</strong> numéro, '
            . 'préfixée « [APERÇU] ». Rien n\'est envoyé au client.</p>'
            . self::form('test', 'test',
                '<p>Notification : <select name="event" class="form-control" style="width:420px;display:inline-block">' . $options . '</select></p>'
                . '<p>ID de l\'élément : <input type="number" name="relid" value="' . ((int) ($_POST['relid'] ?? 0) ?: '') . '" class="form-control" style="width:140px;display:inline-block"> '
                . '<small class="text-muted">facture → ID facture · service → ID service · domaine → ID domaine · bienvenue/marketing → ID client · commande → ID commande · ticket → ID ticket</small></p>'
                . '<p>Votre numéro : <input type="text" name="test_phone" value="' . self::h((string) ($_POST['test_phone'] ?? '')) . '" placeholder="+243…" class="form-control" style="width:220px;display:inline-block"></p>'
                . '<button class="btn btn-primary">Envoyer l\'aperçu</button>')
            . '</div>';
    }

    private static function pageHelp()
    {
        $tips = [
            'Le numéro' => [
                'Utilisez un <strong>numéro dédié</strong> à l\'entreprise, actif depuis plusieurs semaines, avec de vraies conversations. Un numéro neuf qui écrit beaucoup le premier jour est banni très vite.',
                'Préférez un <strong>numéro local</strong> (+243 pour la RDC) sur une vraie carte SIM : les clients lui font confiance et le signalent moins. Les numéros virtuels (VoIP) sont plus surveillés.',
                'Installez <strong>WhatsApp Business</strong> (gratuit) sur le téléphone : logo, description, horaires, adresse et site web rendent le profil crédible.',
                'Dans WhatsApp Business, activez le <strong>message d\'absence</strong> (en dehors des heures d\'ouverture) et le <strong>message d\'accueil</strong> : ce sont des réponses natives, sans risque.',
                'Ouvrez WhatsApp sur le téléphone <strong>au moins une fois tous les 14 jours</strong>, sinon l\'appareil lié est déconnecté. Vous êtes prévenu par e-mail si cela arrive.',
            ],
            'Les messages' => [
                'Courts, personnels (prénom), utiles, avec <strong>un seul lien</strong>. Évitez les MAJUSCULES, les avalanches d\'emojis et les liens raccourcis externes (bit.ly…).',
                'Les <strong>liens courts suivis</strong> du module pointent vers votre propre domaine : plus propres, toujours valables, et ils comptent les clics.',
                'Gardez le pied « Répondez STOP » : un client qui peut se désinscrire ne vous signale pas comme spam. Quelques signalements suffisent à bloquer un numéro.',
                'Les variantes <code>{Bonjour|Hello}</code> évitent que tous les clients reçoivent un texte identique.',
                'Évitez le marketing de masse : réservez WhatsApp aux messages attendus (facture, paiement, activation, support).',
            ],
            'Les clients' => [
                'Demandez-leur d\'<strong>enregistrer votre numéro</strong> : ajoutez « Enregistrez notre WhatsApp : +… » dans vos e-mails et sur vos factures (Configuration &gt; Paramètres généraux &gt; Facturation, texte « Payer à »).',
                '<strong>Répondez vite</strong> aux messages reçus (onglet <strong>Boîte de réception</strong>) : les conversations à double sens améliorent la réputation du numéro (et vos ventes).',
                'Chaque client peut indiquer un autre <strong>numéro WhatsApp</strong> ou désactiver les notifications dans son profil (champs ajoutés par le module).',
                'Corrigez les numéros invalides : l\'onglet Messages indique les échecs « n\'a pas de compte WhatsApp ».',
            ],
            'Le rythme' => [
                'Laissez le <strong>rodage</strong> se terminer (7 jours), puis augmentez les quotas par paliers dans le fichier .env de la passerelle (MAX_PER_DAY 150 → 250 → 400).',
                'Le module respecte aussi les <strong>limites de WhatsApp lui-même</strong> (plafond de nouvelles discussions, restriction temporaire) : rien n\'est envoyé aux nouveaux contacts tant qu\'elles sont actives.',
                'Les heures calmes, l\'espacement par client et la fusion des doublons sont actifs par défaut : ne les désactivez pas.',
            ],
            'Surveillance' => [
                'Le widget « WhatsApp » du tableau de bord WHMCS montre l\'état en un coup d\'œil. Un e-mail vous prévient si WhatsApp reste déconnecté plus de 15 minutes.',
                'Surveillez le <strong>taux de lecture</strong> (Statistiques) : une chute brutale ou beaucoup d\'échecs signalent un problème de numéro ou de contenu.',
                'Si WhatsApp affiche un avertissement sur le téléphone : désactivez les modèles non essentiels pendant 48 h, puis reprenez avec des quotas plus bas.',
            ],
            'En cas de bannissement' => [
                'Gardez un <strong>numéro de secours</strong> prêt : reliez-le (QR code ou code de liaison). Le rodage et les contacts connus repartent de zéro automatiquement.',
                'En dernier recours, le module sait envoyer par l\'API officielle WhatsApp Cloud (Meta) : changez le fournisseur dans la configuration du module.',
            ],
        ];
        foreach ($tips as $title => $list) {
            echo '<div class="lwb-card"><h4>' . self::h($title) . '</h4><ul style="margin-bottom:0">';
            foreach ($list as $tip) {
                echo '<li style="margin-bottom:6px">' . $tip . '</li>';
            }
            echo '</ul></div>';
        }
    }

    // ------------------------------------------------------------------ fiche client & widget

    public static function clientPanel($userid)
    {
        $client = Capsule::table('tblclients')->where('id', (int) $userid)->first();
        if (!$client) {
            return '';
        }
        $who = Phone::forClient($client);
        $rows = Capsule::table(Queue::TABLE)->where('client_id', $client->id)->whereIn('source', ['auto', 'manual'])->orderBy('id', 'desc')->limit(5)->get();
        $base = 'addonmodules.php?module=' . Settings::MODULE;
        $html = '<div class="panel panel-default" style="margin-bottom:15px"><div class="panel-heading"><strong>WhatsApp</strong> · '
            . ($who['phone'] !== '' ? '+' . self::h($who['phone']) : '<span class="text-danger">aucun numéro</span>')
            . ($who['blocked'] !== '' && $who['phone'] !== '' ? ' · <span class="text-warning">' . self::h($who['blocked']) . '</span>' : '')
            . ' · <a href="' . $base . '&tab=send&userid=' . (int) $client->id . '">Envoyer un message</a> · <a href="' . $base . '&tab=messages&q=' . (int) $client->id . '">Historique</a>'
            . ' · <a href="' . $base . '&tab=inbox&userid=' . (int) $client->id . '">Messages reçus</a></div>';
        if (count($rows)) {
            $html .= '<table class="table table-condensed" style="margin:0;font-size:12px">';
            foreach ($rows as $r) {
                $lbl = self::STATUS_LABELS[$r->status] ?? [$r->status, 'default'];
                $delivery = ['delivered' => ' ✓✓', 'read' => ' <span class="lwb-read" style="color:#34B7F1">✓✓ lu</span>'][$r->delivery] ?? '';
                $html .= '<tr><td>' . self::h(Time::show($r->sent_at ?: $r->created_at)) . '</td><td>' . self::h(self::label($r->event)) . '</td><td><span class="label label-' . $lbl[1] . '">'
                    . $lbl[0] . '</span>' . $delivery . '</td></tr>';
            }
            $html .= '</table>';
        }
        $received = Inbox::ready() ? Capsule::table(Inbox::TABLE)->where('client_id', $client->id)->orderBy('received_at', 'desc')->orderBy('id', 'desc')->limit(5)->get() : [];
        if (count($received)) {
            $html .= '<div style="padding:8px 10px 4px;font-size:12px;border-top:1px solid #ddd"><strong>Derniers messages reçus</strong></div>'
                . '<table class="table table-condensed" style="margin:0;font-size:12px">';
            foreach ($received as $r) {
                $html .= '<tr><td style="white-space:nowrap">' . self::h(Time::show($r->received_at)) . '</td><td>' . self::h(Inbox::excerpt(Events::dbDecode($r->message), 140))
                    . ($r->replied_at ? ' <span class="label label-success">répondu</span>' : '')
                    . ($r->ticket_id ? ' <a href="supporttickets.php?action=view&id=' . (int) $r->ticket_id . '">ticket</a>' : '') . '</td></tr>';
            }
            $html .= '</table>';
        }
        return $html . '</div>';
    }

    /**
     * Encart WhatsApp de la page d'une facture : envoie tout de suite au client la
     * notification adaptée au statut de la facture. La page place cet encart dans un
     * formulaire : le bouton construit donc son propre formulaire, vers le module.
     */
    public static function invoicePanel($invoiceId)
    {
        $inv = Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->first();
        if (!$inv) {
            return '';
        }
        $flash = '';
        if ((int) ($_SESSION['lwbwha_flash']['invoice'] ?? 0) === (int) $inv->id) {
            $flash = (string) $_SESSION['lwbwha_flash']['html'];
            unset($_SESSION['lwbwha_flash']);
        }
        $who = Phone::forClient((int) $inv->userid);
        $choices = Events::forInvoice($inv->status, Time::daysUntil($inv->duedate), $inv->total);
        $base = 'addonmodules.php?module=' . Settings::MODULE;
        $html = $flash . '<div style="margin:10px 0;padding:8px 10px;border:1px solid #cfe3d4;border-radius:4px;background:#f6fbf7;text-align:left">'
            . '<strong><i class="fab fa-whatsapp" style="color:#25D366"></i> WhatsApp</strong> · '
            . ($who['phone'] !== '' ? '+' . self::h($who['phone']) : '<span class="text-danger">aucun numéro</span>')
            . ($who['blocked'] !== '' && $who['phone'] !== '' ? ' · <span class="text-warning">' . self::h($who['blocked']) . '</span>' : '')
            . ' · <a href="' . $base . '&tab=messages&q=' . (int) $inv->userid . '">Historique</a>';
        if (!$choices) {
            $html .= '<div class="text-muted" style="margin-top:4px"><small>Rien à envoyer pour une facture '
                . ((float) $inv->total <= 0 ? 'à 0' : self::h(self::INVOICE_STATUS[$inv->status] ?? $inv->status)) . '.</small></div>';
        } elseif ($who['phone'] !== '' && $who['blocked'] === '') {
            if (count($choices) > 1) {
                $pick = '<select class="form-control input-sm" style="display:inline-block;width:auto;max-width:100%;margin-right:4px">';
                foreach ($choices as $event) {
                    $pick .= '<option value="' . $event . '">' . self::h(Events::get($event)['label']) . '</option>';
                }
                $pick .= '</select>';
            } else {
                $pick = '<span style="margin-right:6px">' . self::h(Events::get($choices[0])['label']) . '</span>';
            }
            $quiet = Time::inQuietHours() ? 'Heures calmes en cours chez vos clients : le message part quand même tout de suite.' : '';
            $html .= '<div style="margin-top:6px">' . $pick
                . '<button type="button" class="btn btn-success btn-sm" onclick="lwbWhaInvoice(this)" data-url="' . self::h($base . '&tab=send') . '"'
                . ' data-token="' . self::h(generate_token('plain')) . '" data-invoice="' . (int) $inv->id . '" data-event="' . $choices[0] . '"'
                . ' data-label="' . self::h(Events::get($choices[0])['label']) . '" data-phone="' . self::h($who['phone']) . '" data-quiet="' . self::h($quiet) . '">'
                . '<i class="fab fa-whatsapp"></i> Envoyer sur WhatsApp</button></div>'
                . '<script>function lwbWhaInvoice(b) {'
                . 'var s = b.parentNode.querySelector("select"), o = s ? s.options[s.selectedIndex] : null, q = b.getAttribute("data-quiet");'
                . 'if (!confirm("Envoyer « " + (o ? o.text : b.getAttribute("data-label")) + " » sur WhatsApp au +" + b.getAttribute("data-phone")'
                . ' + " maintenant ?" + (q ? "\n\n" + q : ""))) { return; }'
                . 'b.disabled = true;'
                . 'var f = document.createElement("form"), d = {token: b.getAttribute("data-token"), action: "send_invoice",'
                . ' invoice_id: b.getAttribute("data-invoice"), event: o ? o.value : b.getAttribute("data-event")};'
                . 'f.method = "post"; f.setAttribute("action", b.getAttribute("data-url"));'
                . 'for (var k in d) { var i = document.createElement("input"); i.type = "hidden"; i.name = k; i.value = d[k]; f.appendChild(i); }'
                . 'document.body.appendChild(f); f.submit(); }</script>';
        }
        $rows = Capsule::table(Queue::TABLE)->where('rel_type', 'invoice')->where('rel_id', $inv->id)->whereIn('source', ['auto', 'manual'])
            ->orderBy('id', 'desc')->limit(3)->get();
        if (count($rows)) {
            $html .= '<div class="text-muted" style="margin-top:6px;font-size:11px">';
            foreach ($rows as $r) {
                $lbl = self::STATUS_LABELS[$r->status] ?? [$r->status, 'default'];
                $html .= self::h(Time::show($r->sent_at ?: $r->created_at, 'd/m H:i')) . ' · ' . self::h(self::label($r->event)) . ' · ' . $lbl[0]
                    . ($r->delivery === 'read' ? ' <span style="color:#34B7F1;font-weight:bold">✓✓ lu</span>' : ($r->delivery === 'delivered' ? ' ✓✓' : ''))
                    . ($r->source === 'manual' ? ' (manuel)' : '') . '<br>';
            }
            $html .= '</div>';
        }
        return $html . '</div>';
    }

    public static function widget()
    {
        $s = Gateway::status();
        $st = (string) ($s['status'] ?? 'unreachable');
        $t = Stats::today();
        $labels = ['open' => ['Connecté', 'success'], 'qr' => ['QR code à scanner', 'warning'], 'unreachable' => ['Passerelle injoignable', 'danger'],
            'banned' => ['Refusé par WhatsApp', 'danger'], 'logged_out' => ['Délié', 'danger'], 'closed' => ['Reconnexion…', 'warning'], 'connecting' => ['Connexion…', 'info']];
        $l = $labels[$st] ?? [$st, 'default'];
        return '<div class="widget-content-padded"><p><span class="label label-' . $l[1] . '">' . $l[0] . '</span>'
            . ($st === 'open' ? ' +' . self::h(Gateway::number()) : '') . '</p>'
            . '<p>' . $t['sent_today'] . ' envoyé(s) aujourd\'hui · ' . $t['pending'] . ' en attente'
            . ($t['read_rate'] !== null ? ' · ' . $t['read_rate'] . ' % lus (7 j)' : '')
            . ($t['received_today'] ? ' · <a href="addonmodules.php?module=' . Settings::MODULE . '&tab=inbox">' . $t['received_today'] . ' reçu(s)</a>' : '') . '</p>'
            . '<a href="addonmodules.php?module=' . Settings::MODULE . '" class="btn btn-default btn-sm">Ouvrir LWB WhatsApp</a></div>';
    }
}
