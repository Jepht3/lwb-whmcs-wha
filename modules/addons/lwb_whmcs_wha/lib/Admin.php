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

    const TABS = [
        'dashboard' => 'Tableau de bord',
        'send' => 'Envoyer',
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

    private static function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private static function url($params = [])
    {
        return self::$link . ($params ? '&' . http_build_query($params) : '');
    }

    private static function form($action, $tab, $inner, $attrs = '')
    {
        return '<form method="post" action="' . self::h(self::url(['tab' => $tab])) . '" ' . $attrs . '>'
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
        return ['manual' => 'Message manuel'][$event] ?? $event;
    }

    public static function render(array $vars)
    {
        self::$link = $vars['modulelink'];
        self::$token = generate_token('plain');
        $tab = isset(self::TABS[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'dashboard';
        $flash = $_SERVER['REQUEST_METHOD'] === 'POST' ? self::handle() : '';
        Settings::all(true);

        echo '<style>.lwb-card{border:1px solid #e3e6ea;border-radius:6px;padding:14px 16px;margin-bottom:14px;background:#fff}'
            . '.lwb-card h4{margin-top:0}.lwb-kpi{display:inline-block;min-width:150px;margin:0 18px 10px 0}.lwb-kpi b{display:block;font-size:22px}'
            . '.lwb-var{display:inline-block;background:#f3f5f7;border-radius:3px;padding:1px 5px;margin:2px;font-family:monospace;font-size:11px}'
            . '.lwb-read{color:#34B7F1;font-weight:bold}.lwb-msg{white-space:pre-wrap;max-width:520px;font-size:12px;margin:4px 0 0}'
            . '.lwb-pair{font-size:30px;letter-spacing:4px;font-family:monospace;font-weight:bold}</style>';
        echo $flash;
        echo '<ul class="nav nav-tabs admin-tabs" style="margin-bottom:15px">';
        foreach (self::TABS as $key => $title) {
            echo '<li class="' . ($tab === $key ? 'active' : '') . '"><a href="' . self::h(self::url(['tab' => $key])) . '">' . $title . '</a></li>';
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
                $text = trim((string) ($_POST['message'] ?? ''));
                $phone = Phone::normalize((string) ($_POST['phone'] ?? ''));
                if ($text === '') {
                    return self::box('error', 'Le message est vide.');
                }
                if ($clientId) {
                    $client = Capsule::table('tblclients')->where('id', $clientId)->first();
                    if (!$client) {
                        return self::box('error', 'Client #' . $clientId . ' introuvable.');
                    }
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
                $res = Queue::sendManual($clientId, $phone, $text, !empty($_POST['attach_invoice']));
                return $res['ok'] ? self::box('success', 'Message envoyé au +' . self::h($phone) . '.')
                    : self::box('error', 'Échec : ' . self::h($res['error']));

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
            . '<div class="lwb-kpi"><b>' . $paid['count'] . '</b>factures payées après un WhatsApp (30 j, ' . self::h($paid['amount']) . ')</div>';
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

    private static function pageSend()
    {
        $clientId = (int) ($_GET['userid'] ?? $_POST['client_id'] ?? 0);
        $info = '';
        if ($clientId) {
            $client = Capsule::table('tblclients')->where('id', $clientId)->first();
            if ($client) {
                $who = Phone::forClient($client);
                $info = '<p>Client : <strong>' . self::h(trim($client->firstname . ' ' . $client->lastname)) . '</strong> · '
                    . ($who['phone'] !== '' ? 'WhatsApp : +' . self::h($who['phone']) : '<span class="text-danger">aucun numéro</span>')
                    . ($who['blocked'] !== '' && $who['phone'] !== '' ? ' · <span class="text-warning">' . self::h($who['blocked']) . '</span>' : '') . '</p>';
            }
        }
        echo '<div class="lwb-card">' . $info
            . self::form('send_manual', 'send',
                '<p>ID client : <input type="number" name="client_id" value="' . ($clientId ?: '') . '" class="form-control" style="width:140px;display:inline-block">'
                . ' &nbsp;ou numéro : <input type="text" name="phone" placeholder="+243…" class="form-control" style="width:200px;display:inline-block"></p>'
                . '<p><textarea name="message" rows="6" class="form-control" placeholder="{greeting} {first_name}, …"></textarea>'
                . '<small>Avec un ID client : ' . implode(' ', array_map(function ($v) {
                    return '<span class="lwb-var">{' . $v . '}</span>';
                }, ['greeting', 'first_name', 'client_name', 'company', 'client_area'])) . ' et les variantes {Bonjour|Hello}.</small></p>'
                . '<p><label><input type="checkbox" name="attach_invoice" value="1"> Joindre la dernière facture impayée du client (PDF)</label></p>'
                . '<button class="btn btn-primary">Envoyer maintenant</button>')
            . '</div><p class="text-muted">Les messages manuels partent tout de suite (hors file d\'attente) mais respectent les quotas anti-bannissement de la passerelle.</p>';
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
        $query = Capsule::table(Queue::TABLE);
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
        $events .= '<option value="manual"' . ($event === 'manual' ? ' selected' : '') . '>Message manuel</option>';
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
                . '<details><summary class="text-muted" style="cursor:pointer;font-size:11px">voir le texte</summary><pre class="lwb-msg">' . self::h(Events::dbDecode($r->message)) . '</pre></details></td>'
                . '<td>+' . self::h($r->phone) . '</td>'
                . '<td><span class="label label-' . $lbl[1] . '">' . $lbl[0] . '</span>' . ($r->error ? '<br><small class="text-muted">' . self::h(mb_substr(Events::dbDecode($r->error), 0, 140)) . '</small>' : '') . '</td>'
                . '<td>' . $delivery . '</td><td>' . (int) ($clicks[$r->id] ?? 0) . '</td><td>' . $actions . '</td></tr>';
        }
        echo '</table>';
        if ($total > 50) {
            echo '<p>';
            for ($i = 1; $i <= (int) ceil($total / 50) && $i <= 20; $i++) {
                echo $i === $page ? ' <strong>' . $i . '</strong> ' : ' <a href="' . self::h(self::url(['tab' => 'messages', 'status' => $status, 'event' => $event, 'q' => $q, 'p' => $i])) . '">' . $i . '</a> ';
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
                '<strong>Répondez vite</strong> aux messages reçus : les conversations à double sens améliorent la réputation du numéro (et vos ventes).',
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
            . ' · <a href="' . $base . '&tab=send&userid=' . (int) $client->id . '">Envoyer un message</a> · <a href="' . $base . '&tab=messages&q=' . (int) $client->id . '">Historique</a></div>';
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
            . ($t['read_rate'] !== null ? ' · ' . $t['read_rate'] . ' % lus (7 j)' : '') . '</p>'
            . '<a href="addonmodules.php?module=' . Settings::MODULE . '" class="btn btn-default btn-sm">Ouvrir LWB WhatsApp</a></div>';
    }
}
