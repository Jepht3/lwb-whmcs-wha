<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * File d'attente et journal des messages (une seule table).
 *
 * Cycle de vie : pending -> sending -> sent | failed | skipped | expired | optout
 *
 * Règles anti-bannissement et qualité appliquées ici :
 *   - envoi progressif au cron, par priorité, hors heures calmes ;
 *   - espacement minimal et plafond quotidien par client ;
 *   - fusion : la confirmation de commande remplace « nouvelle facture » et
 *     « bienvenue » quand ils arrivent ensemble ;
 *   - revalidation juste avant l'envoi (facture payée entre-temps, service
 *     réactivé, domaine renouvelé…) et durée de validité par notification ;
 *   - une passerelle déconnectée met la file en pause sans perdre de messages.
 */
class Queue
{
    const TABLE = 'mod_lwbwha_messages';

    public static function template($event)
    {
        $t = Capsule::table('mod_lwbwha_templates')->where('event', $event)->first();
        if ($t) {
            $t->message = Events::dbDecode($t->message);
        }
        return $t;
    }

    /** Texte final : rendu + pied de désinscription (sauf alertes équipe). */
    public static function compose($message, array $vars, array $def)
    {
        list($text) = Events::render($message, $vars);
        if (empty($def['team'])) {
            $footer = trim(Settings::get('optout_footer', 'Répondez STOP pour ne plus recevoir ces messages.'));
            if ($footer !== '') {
                $text .= "\n\n" . $footer;
            }
        }
        return $text;
    }

    /** Notification client (déclenchée par un e-mail WHMCS). Ne lève jamais d'exception. */
    public static function notify($event, $rel, $relId)
    {
        try {
            $def = Events::get($event);
            $tpl = self::template($event);
            if (!$def || !$tpl || !$tpl->enabled || !empty($def['team'])) {
                return;
            }
            $ctx = Context::build($rel, $relId);
            if (!$ctx['client_id']) {
                return;
            }
            $days = trim((string) $tpl->days);
            if ($days !== '' && isset($ctx['vars']['days_left'])) {
                $allowed = array_map('intval', array_filter(array_map('trim', explode(',', $days)), 'strlen'));
                if (!in_array((int) $ctx['vars']['days_left'], $allowed, true)) {
                    return;
                }
            }
            $who = Phone::forClient($ctx['client']);
            self::enqueue([
                'client_id' => $ctx['client_id'],
                'event' => $event,
                'rel_type' => $rel,
                'rel_id' => (int) $relId,
                'phone' => $who['phone'],
                'message' => self::compose($tpl->message, $ctx['vars'], $def),
                'attachment' => ($rel === 'invoice' && $tpl->attach_pdf) ? 'invoice:' . (int) $relId : null,
                'priority' => $def['priority'],
                'source' => 'auto',
                'status' => $who['blocked'] !== '' ? 'optout' : 'pending',
                'error' => $who['blocked'] !== '' ? 'Non envoyé : ' . $who['blocked'] : null,
            ]);
        } catch (\Throwable $e) {
            logActivity('LWB WhatsApp (' . $event . ') : ' . $e->getMessage());
        }
    }

    /**
     * Ajoute un message (avec dédoublonnage sur 24 h et fusion des doublons
     * fonctionnels). Retourne l'identifiant, ou 0 si c'est un doublon.
     */
    public static function enqueue(array $row)
    {
        $row += ['client_id' => 0, 'rel_type' => '', 'rel_id' => 0, 'attachment' => null, 'priority' => 5, 'source' => 'auto', 'status' => 'pending', 'error' => null];
        $hash = sha1($row['event'] . '|' . $row['rel_type'] . '|' . $row['rel_id'] . '|' . $row['phone']);
        if ($row['source'] !== 'test' && $row['source'] !== 'manual') {
            $duplicate = Capsule::table(self::TABLE)->where('hash', $hash)->whereIn('status', ['pending', 'sending', 'sent'])
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))->exists();
            if ($duplicate) {
                return 0;
            }
            if ($row['status'] === 'pending') {
                $reason = self::supersede($row);
                if ($reason !== null) {
                    $row['status'] = 'skipped';
                    $row['error'] = $reason;
                }
            }
        }
        $now = date('Y-m-d H:i:s');
        return (int) Capsule::table(self::TABLE)->insertGetId([
            'client_id' => (int) $row['client_id'],
            'event' => $row['event'],
            'rel_type' => $row['rel_type'],
            'rel_id' => (int) $row['rel_id'],
            'phone' => (string) $row['phone'],
            'message' => Events::dbEncode($row['message']),
            'attachment' => $row['attachment'],
            'priority' => (int) $row['priority'],
            'hash' => $hash,
            'source' => $row['source'],
            'status' => $row['status'],
            'attempts' => 0,
            'next_try' => $now,
            'created_at' => $now,
            'error' => $row['error'] === null ? null : Events::dbEncode($row['error']),
        ]);
    }

    /** Fusion des messages redondants. Retourne la raison d'ignorer le nouveau, ou null. */
    private static function supersede(array $row)
    {
        $recent = date('Y-m-d H:i:s', time() - 6 * 3600);
        $active = ['pending', 'sending', 'sent'];
        $skip = function ($query, $reason) {
            $query->where('status', 'pending')->update(['status' => 'skipped', 'error' => $reason]);
        };
        switch ($row['event']) {
            case 'order_confirmation':
                $skip(Capsule::table(self::TABLE)->where('client_id', $row['client_id'])->where('event', 'client_signup'), 'Remplacé par la confirmation de commande');
                $invoiceId = (int) Capsule::table('tblorders')->where('id', $row['rel_id'])->value('invoiceid');
                if ($invoiceId) {
                    $skip(Capsule::table(self::TABLE)->where('event', 'invoice_created')->where('rel_type', 'invoice')->where('rel_id', $invoiceId),
                        'Remplacé par la confirmation de commande (lien de paiement inclus)');
                }
                return null;
            case 'invoice_created':
                $orders = Capsule::table('tblorders')->where('invoiceid', $row['rel_id'])->pluck('id')->all();
                if ($orders && Capsule::table(self::TABLE)->where('event', 'order_confirmation')->where('rel_type', 'order')
                    ->whereIn('rel_id', $orders)->whereIn('status', $active)->where('created_at', '>=', $recent)->exists()) {
                    return 'Déjà couvert par la confirmation de commande';
                }
                return null;
            case 'client_signup':
                if (Capsule::table(self::TABLE)->where('client_id', $row['client_id'])->where('event', 'order_confirmation')
                    ->whereIn('status', $active)->where('created_at', '>=', $recent)->exists()) {
                    return 'Déjà couvert par la confirmation de commande';
                }
                return null;
            case 'invoice_paid':
                $skip(Capsule::table(self::TABLE)->where('rel_type', 'invoice')->where('rel_id', $row['rel_id'])
                    ->whereIn('event', ['invoice_created', 'invoice_reminder', 'invoice_overdue_1', 'invoice_overdue_2', 'invoice_overdue_3']), 'Facture payée entre-temps');
                return null;
            case 'service_unsuspended':
                $skip(Capsule::table(self::TABLE)->where('rel_type', 'service')->where('rel_id', $row['rel_id'])->where('event', 'service_suspended'), 'Service réactivé entre-temps');
                return null;
        }
        return null;
    }

    /** Envoi progressif de la file (cron WHMCS). */
    public static function process()
    {
        if (Settings::isGateway() && !Gateway::isOpen()) {
            return;
        }
        if (Time::inQuietHours()) {
            return;
        }
        Capsule::table(self::TABLE)->where('status', 'sending')->where('next_try', '<', date('Y-m-d H:i:s', time() - 900))
            ->update(['status' => 'pending']);
        @set_time_limit(0);
        $batch = Settings::int('batch_size', 5, 1, 20);
        $rows = Capsule::table(self::TABLE)->where('status', 'pending')->where('next_try', '<=', date('Y-m-d H:i:s'))
            ->orderBy('priority', 'desc')->orderBy('id')->limit($batch * 4)->get();
        $sent = 0;
        foreach ($rows as $row) {
            if ($sent >= $batch) {
                break;
            }
            $result = self::sendRow($row);
            if ($result === 'sent') {
                $sent++;
            } elseif ($result === 'stop') {
                break;
            }
        }
    }

    /**
     * Envoie une ligne de la file.
     *
     * @param bool $force envoi demandé par l'administrateur : ignore l'espacement
     *                    par client et la durée de validité (pas la revalidation)
     * @return string sent | skip | later | stop | failed
     */
    public static function sendRow($row, $force = false)
    {
        $taken = Capsule::table(self::TABLE)->where('id', $row->id)->where('status', 'pending')
            ->update(['status' => 'sending', 'next_try' => date('Y-m-d H:i:s')]);
        if (!$taken) {
            return 'skip';
        }
        try {
            $def = Events::get($row->event);
            if ($def && !$force && $row->source === 'auto' && strtotime($row->created_at) < time() - $def['ttl'] * 3600) {
                self::finish($row->id, 'expired', 'Délai de validité dépassé (' . $def['ttl'] . ' h)');
                return 'skip';
            }
            if (!$force && $row->source === 'auto') {
                $wait = self::pacing($row->phone);
                if ($wait !== null) {
                    Capsule::table(self::TABLE)->where('id', $row->id)->update(['status' => 'pending', 'next_try' => $wait]);
                    return 'later';
                }
            }
            $text = Events::dbDecode($row->message);
            $tpl = null;
            $ordered = [];
            $named = [];
            if ($def && $row->rel_type !== '' && $row->rel_id) {
                $ctx = Context::build($row->rel_type, $row->rel_id, ['message_id' => $row->id, 'team' => !empty($def['team'])]);
                // Un aperçu peut porter sur n'importe quel élément ; les envois réels sont revalidés.
                $reason = $row->source === 'test' ? null : Context::irrelevant($row->event, $ctx);
                if ($reason !== null) {
                    self::finish($row->id, 'skipped', 'Plus d\'actualité : ' . $reason);
                    return 'skip';
                }
                $tpl = self::template($row->event);
                if ($tpl && $row->source !== 'manual') {
                    $text = self::compose($tpl->message, $ctx['vars'], $def);
                    list(, $ordered) = Events::render($tpl->message, $ctx['vars']);
                    preg_match_all('/\{(\w+)\}/', $tpl->message, $m);
                    foreach (array_unique($m[1]) as $key) {
                        $named[$key] = isset($ctx['vars'][$key]) ? (string) $ctx['vars'][$key] : '';
                    }
                }
            }
            $document = null;
            if ($row->attachment && preg_match('/^invoice:(\d+)$/', $row->attachment, $m)) {
                $document = Sender::invoicePdf($m[1]);
            }
            $res = Sender::send($row->phone, $row->source === 'test' ? "[APERÇU]\n" . $text : $text, $document, $tpl, $ordered, $named);
            return self::result($row, $res, $text);
        } catch (\Throwable $e) {
            self::finish($row->id, 'failed', 'Erreur interne : ' . $e->getMessage());
            logActivity('LWB WhatsApp : message #' . $row->id . ' : ' . $e->getMessage());
            return 'failed';
        }
    }

    private static function result($row, array $res, $text)
    {
        $code = (int) ($res['code'] ?? 0);
        $reason = (string) ($res['data']['reason'] ?? '');
        if ($res['ok']) {
            Capsule::table(self::TABLE)->where('id', $row->id)->update([
                'status' => 'sent',
                'sent_at' => date('Y-m-d H:i:s'),
                'wa_id' => $res['data']['id'] ?? null,
                'delivery' => Settings::isGateway() ? 'sent' : null,
                'message' => Events::dbEncode($text),
                'error' => null,
            ]);
            return 'sent';
        }
        if ($code === 410 || $code === 400 || $code === 413) {
            if ($reason === 'optout') {
                Capsule::table('mod_lwbwha_optout')->updateOrInsert(['phone' => $row->phone], ['client_id' => (int) $row->client_id, 'source' => 'whatsapp', 'created_at' => date('Y-m-d H:i:s')]);
                self::finish($row->id, 'optout', $res['error']);
            } else {
                self::finish($row->id, 'failed', $res['error']);
            }
            return 'failed';
        }
        if ($code === 429) {
            $wait = max(60, (int) ($res['data']['retry_after'] ?? 600));
            Capsule::table(self::TABLE)->where('id', $row->id)->update([
                'status' => 'pending',
                'next_try' => date('Y-m-d H:i:s', time() + $wait),
                'error' => Events::dbEncode($res['error']),
            ]);
            // Une limite « nouveaux contacts » n'empêche pas d'écrire aux contacts connus.
            return $reason === 'new_contact_limit' ? 'later' : 'stop';
        }
        if ($code === 503 || $code === 0) {
            // Passerelle déconnectée : pause, sans compter de tentative.
            Capsule::table(self::TABLE)->where('id', $row->id)->update([
                'status' => 'pending',
                'next_try' => date('Y-m-d H:i:s', time() + 600),
                'error' => Events::dbEncode($res['error']),
            ]);
            return 'stop';
        }
        $attempts = (int) $row->attempts + 1;
        if ($attempts >= 5) {
            self::finish($row->id, 'failed', $res['error']);
        } else {
            Capsule::table(self::TABLE)->where('id', $row->id)->update([
                'status' => 'pending',
                'attempts' => $attempts,
                'next_try' => date('Y-m-d H:i:s', time() + 900 * $attempts),
                'error' => Events::dbEncode($res['error']),
            ]);
        }
        return 'failed';
    }

    private static function finish($id, $status, $error = null)
    {
        Capsule::table(self::TABLE)->where('id', $id)->update([
            'status' => $status,
            'error' => $error === null ? null : Events::dbEncode(mb_substr((string) $error, 0, 1000)),
        ]);
    }

    /** Prochaine heure d'envoi possible pour ce numéro (espacement, plafond du jour), ou null. */
    private static function pacing($phone)
    {
        $gap = Settings::int('min_gap', 20, 0, 1440);
        $last = Capsule::table(self::TABLE)->where('phone', $phone)->where('status', 'sent')->max('sent_at');
        if ($gap > 0 && $last && strtotime($last) > time() - $gap * 60) {
            return date('Y-m-d H:i:s', strtotime($last) + $gap * 60);
        }
        $cap = Settings::int('max_per_client_day', 3, 1, 50);
        $dayStart = Time::server(Time::now()->setTime(0, 0));
        $count = Capsule::table(self::TABLE)->where('phone', $phone)->where('status', 'sent')->where('sent_at', '>=', $dayStart)->count();
        if ($count >= $cap) {
            return Time::server(Time::now()->modify('+1 day')->setTime(9, 0));
        }
        return null;
    }

    /** Envoi immédiat d'un message libre (onglet Envoyer). */
    public static function sendManual($clientId, $phone, $text, $attachInvoice = false)
    {
        $attachment = null;
        if ($clientId && $attachInvoice) {
            $inv = Capsule::table('tblinvoices')->where('userid', $clientId)->where('status', 'Unpaid')->orderBy('id', 'desc')->value('id');
            $attachment = $inv ? 'invoice:' . $inv : null;
        }
        $id = self::enqueue([
            'client_id' => $clientId, 'event' => 'manual', 'phone' => $phone, 'message' => $text,
            'attachment' => $attachment, 'priority' => 9, 'source' => 'manual',
        ]);
        return self::sendNow($id);
    }

    /** Aperçu réel d'une notification envoyé au numéro de l'administrateur. */
    public static function sendTest($event, $relId, $phone)
    {
        $def = Events::get($event);
        $tpl = self::template($event);
        if (!$def || !$tpl) {
            return ['ok' => false, 'error' => 'Notification inconnue', 'text' => ''];
        }
        $ctx = Context::build($def['rel'], $relId, ['team' => !empty($def['team'])]);
        if (!$ctx['obj']) {
            return ['ok' => false, 'error' => 'Élément introuvable : vérifiez l\'ID saisi.', 'text' => ''];
        }
        $id = self::enqueue([
            'client_id' => $ctx['client_id'], 'event' => $event, 'rel_type' => $def['rel'], 'rel_id' => (int) $relId,
            'phone' => $phone, 'message' => self::compose($tpl->message, $ctx['vars'], $def),
            'attachment' => ($def['rel'] === 'invoice' && $tpl->attach_pdf) ? 'invoice:' . (int) $relId : null,
            'priority' => 9, 'source' => 'test',
        ]);
        return self::sendNow($id);
    }

    /** Envoi immédiat d'une ligne (bouton « Envoyer maintenant », envois manuels). */
    public static function sendNow($id)
    {
        Capsule::table(self::TABLE)->where('id', $id)->whereIn('status', ['pending', 'failed', 'expired'])
            ->update(['status' => 'pending', 'next_try' => date('Y-m-d H:i:s')]);
        $row = Capsule::table(self::TABLE)->where('id', $id)->first();
        if (!$row) {
            return ['ok' => false, 'error' => 'Message introuvable', 'text' => ''];
        }
        $result = self::sendRow($row, true);
        $row = Capsule::table(self::TABLE)->where('id', $id)->first();
        return [
            'ok' => $result === 'sent',
            'error' => $result === 'sent' ? '' : Events::dbDecode((string) $row->error),
            'text' => Events::dbDecode((string) $row->message),
            'status' => $row->status,
        ];
    }

    /** Statuts de distribution / lecture remontés par la passerelle. */
    public static function syncStatuses()
    {
        if (!Settings::isGateway() || !Gateway::isOpen()) {
            return;
        }
        $ids = Capsule::table(self::TABLE)->where('status', 'sent')->whereNotNull('wa_id')
            ->where('sent_at', '>=', date('Y-m-d H:i:s', time() - 7 * 86400))
            ->where(function ($q) {
                $q->whereNull('delivery')->orWhere('delivery', '!=', 'read');
            })->orderBy('id', 'desc')->limit(300)->pluck('wa_id')->all();
        if (!$ids) {
            return;
        }
        $res = Gateway::request('POST', '/statuses', ['ids' => array_values($ids)], 15);
        foreach ((array) ($res['data']['statuses'] ?? []) as $waId => $info) {
            Capsule::table(self::TABLE)->where('wa_id', $waId)->update([
                'delivery' => (string) $info['status'],
                'delivery_at' => date('Y-m-d H:i:s', strtotime((string) $info['at'])),
            ]);
        }
    }

    /** Désinscriptions STOP / START reçues par la passerelle. */
    public static function syncOptouts()
    {
        if (!Settings::isGateway()) {
            return;
        }
        $res = Gateway::request('GET', '/optouts', null, 10);
        if (!$res['ok']) {
            return;
        }
        $remote = (array) ($res['data']['optouts'] ?? []);
        foreach (Capsule::table('mod_lwbwha_optout')->where('source', 'whatsapp')->pluck('phone') as $phone) {
            if (!isset($remote[$phone])) {
                Capsule::table('mod_lwbwha_optout')->where('phone', $phone)->delete();
            }
        }
        foreach ($remote as $phone => $date) {
            if (!Capsule::table('mod_lwbwha_optout')->where('phone', $phone)->exists()) {
                Capsule::table('mod_lwbwha_optout')->insert([
                    'phone' => $phone,
                    'client_id' => self::clientByPhone($phone),
                    'source' => 'whatsapp',
                    'created_at' => date('Y-m-d H:i:s', strtotime((string) $date) ?: time()),
                ]);
            }
        }
    }

    public static function clientByPhone($phone)
    {
        $id = (int) Capsule::table(self::TABLE)->where('phone', $phone)->where('client_id', '>', 0)->orderBy('id', 'desc')->value('client_id');
        if ($id) {
            return $id;
        }
        foreach (Capsule::table('tblclients')->where('phonenumber', 'like', '%' . substr($phone, -6))->get(['id', 'phonenumber', 'country']) as $c) {
            if (Phone::normalize($c->phonenumber, $c->country) === $phone) {
                return (int) $c->id;
            }
        }
        return 0;
    }

    /** Nettoyage quotidien de l'historique. */
    public static function purge()
    {
        if (!State::every('last_purge', 86400)) {
            return;
        }
        $limit = date('Y-m-d H:i:s', time() - Settings::int('retention_days', 180, 30, 3650) * 86400);
        Capsule::table(self::TABLE)->where('created_at', '<', $limit)->where('status', '!=', 'pending')->delete();
        Capsule::table(Links::TABLE)->where('created_at', '<', $limit)->delete();
    }
}
