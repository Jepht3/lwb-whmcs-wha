<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Boîte de réception : messages WhatsApp reçus des clients.
 *
 * La passerelle garde les messages reçus 30 jours. À chaque cron (et à
 * l'ouverture de l'onglet), WHMCS rapatrie les nouveaux dans mod_lwbwha_inbox,
 * rattachés au client par son numéro. En option, les messages d'un client sont
 * ajoutés à son ticket ouvert, ou ouvrent un ticket dans le département choisi.
 */
class Inbox
{
    const TABLE = 'mod_lwbwha_inbox';
    const PAGE = 200;

    private static $ready;

    /** Vrai si la table existe (module à jour). */
    public static function ready()
    {
        if (self::$ready === null) {
            self::$ready = Capsule::schema()->hasTable(self::TABLE);
        }
        return self::$ready;
    }

    /**
     * Rapatrie les messages reçus depuis le passage précédent. Ne lève jamais d'exception.
     *
     * @return int nombre de nouveaux messages
     */
    public static function sync()
    {
        try {
            if (!Settings::isGateway()) {
                return 0;
            }
            if (!self::ready()) {
                Schema::install();
                self::$ready = null;
                if (!self::ready()) {
                    return 0;
                }
            }
            $since = (int) State::get('inbox_since', 0);
            $added = [];
            for ($page = 0; $page < 5; $page++) {
                $res = Gateway::request('GET', '/inbox?since=' . $since . '&limit=' . self::PAGE, null, 15);
                $list = $res['ok'] ? (array) ($res['data']['messages'] ?? []) : [];
                foreach ($list as $raw) {
                    // Un message inexploitable ne bloque pas les suivants.
                    $since = max($since, is_array($raw) ? (int) ($raw['received'] ?? 0) : 0);
                    $m = self::fromGateway($raw);
                    $id = $m ? self::store($m) : 0;
                    if ($id) {
                        $added[] = $id;
                    }
                }
                if ($list) {
                    State::set('inbox_since', $since);
                }
                if (!$list || empty($res['data']['more'])) {
                    break;
                }
            }
            if ($added && Settings::bool('inbox_tickets', false)) {
                self::toTickets($added);
            }
            return count($added);
        } catch (\Throwable $e) {
            logActivity('LWB WhatsApp (boîte de réception) : ' . $e->getMessage());
            return 0;
        }
    }

    /** Message transmis par la passerelle, contrôlé ; null s'il est inexploitable. */
    public static function fromGateway($m)
    {
        if (!is_array($m)) {
            return null;
        }
        $str = function ($key) use ($m) {
            return isset($m[$key]) && is_scalar($m[$key]) ? (string) $m[$key] : '';
        };
        $id = $str('id');
        $phone = preg_replace('/\D+/', '', $str('phone'));
        $text = trim(str_replace(["\r\n", "\r"], "\n", $str('text')));
        if (!preg_match('/^[\w.:-]{4,64}$/', $id) || strlen($phone) < 8 || strlen($phone) > 20 || $text === '') {
            return null;
        }
        $at = (int) $str('at');
        return [
            'wa_id' => $id,
            'phone' => $phone,
            'name' => mb_substr(trim($str('name')), 0, 25),
            'text' => mb_substr($text, 0, 4000),
            'quoted' => preg_match('/^[\w.:-]{4,64}$/', $str('quoted')) ? $str('quoted') : '',
            'at' => $at > 0 ? intdiv($at, 1000) : time(),
        ];
    }

    /** Enregistre un message ; retourne son identifiant, ou 0 s'il est déjà connu. */
    private static function store(array $m)
    {
        if (Capsule::table(self::TABLE)->where('wa_id', $m['wa_id'])->exists()) {
            return 0;
        }
        // Les numéros de l'équipe (réponses aux alertes) ne sont rattachés à aucun client.
        $team = in_array($m['phone'], Settings::teamPhones(), true);
        $replyTo = $m['quoted'] === '' ? 0 : (int) Capsule::table(Queue::TABLE)->where('wa_id', $m['quoted'])->value('id');
        try {
            return (int) Capsule::table(self::TABLE)->insertGetId([
                'wa_id' => $m['wa_id'],
                'client_id' => $team ? 0 : Queue::clientByPhone($m['phone']),
                'phone' => $m['phone'],
                'name' => Events::dbEncode($m['name']),
                'message' => Events::dbEncode($m['text']),
                'reply_to' => $replyTo,
                'ticket_id' => 0,
                'received_at' => date('Y-m-d H:i:s', $m['at']),
            ]);
        } catch (\Exception $e) {
            // Déjà enregistré par un passage simultané (cron et onglet), sinon vraie erreur.
            if (!Capsule::table(self::TABLE)->where('wa_id', $m['wa_id'])->exists()) {
                logActivity('LWB WhatsApp : message reçu ' . $m['wa_id'] . ' non enregistré : ' . $e->getMessage());
            }
            return 0;
        }
    }

    // ------------------------------------------------------------------ tickets

    /** Ajoute les nouveaux messages des clients à leur ticket ouvert, ou ouvre un ticket. */
    private static function toTickets(array $ids)
    {
        $rows = Capsule::table(self::TABLE)->whereIn('id', $ids)->where('client_id', '>', 0)->orderBy('id')->get()->all();
        $rows = array_map(function ($r) {
            return (array) $r;
        }, $rows);
        // Plusieurs messages d'un client reçus ensemble : une seule réponse au ticket.
        foreach (self::group($rows) as $clientId => $list) {
            try {
                self::toTicket((int) $clientId, $list);
            } catch (\Throwable $e) {
                logActivity('LWB WhatsApp : ticket du client #' . (int) $clientId . ' impossible : ' . $e->getMessage());
            }
        }
    }

    private static function toTicket($clientId, array $list)
    {
        $parts = [];
        foreach ($list as $r) {
            $parts[] = ['text' => Events::dbDecode($r['message']), 'time' => Time::show($r['received_at'], 'H:i')];
        }
        $last = end($list);
        $message = self::ticketText($parts, $last['phone'], Time::show($last['received_at'], 'd/m/Y'));
        $ticketId = self::openTicket($clientId, array_filter(array_column($list, 'reply_to')));
        if ($ticketId) {
            $res = localAPI('AddTicketReply', ['ticketid' => $ticketId, 'clientid' => $clientId, 'message' => $message, 'markdown' => false]);
        } else {
            $dept = self::department();
            if (!$dept) {
                logActivity('LWB WhatsApp : aucun département de tickets pour les messages WhatsApp reçus.');
                return;
            }
            // Pas d'e-mail « ticket ouvert » au client : il a simplement écrit sur WhatsApp.
            $res = localAPI('OpenTicket', [
                'deptid' => $dept,
                'clientid' => $clientId,
                'subject' => self::subject($parts[0]['text']),
                'message' => $message,
                'priority' => 'Medium',
                'markdown' => false,
                'noemail' => true,
            ]);
            $ticketId = (int) ($res['id'] ?? 0);
        }
        if (($res['result'] ?? '') !== 'success' || !$ticketId) {
            logActivity('LWB WhatsApp : ticket du client #' . $clientId . ' : ' . ($res['message'] ?? 'erreur inconnue'));
            return;
        }
        Capsule::table(self::TABLE)->whereIn('id', array_column($list, 'id'))->update(['ticket_id' => $ticketId]);
    }

    /** Ticket actif du client : celui de la notification citée, sinon le plus récent ; 0 si aucun. */
    private static function openTicket($clientId, array $replyTo)
    {
        $active = self::activeStatuses();
        $tickets = function () use ($clientId, $active) {
            return Capsule::table('tbltickets')->where('userid', $clientId)->whereIn('status', $active);
        };
        if ($replyTo) {
            $quoted = Capsule::table(Queue::TABLE)->whereIn('id', $replyTo)->where('rel_type', 'ticket')->pluck('rel_id')->all();
            if ($quoted) {
                $id = (int) $tickets()->whereIn('id', $quoted)->orderBy('lastreply', 'desc')->value('id');
                if ($id) {
                    return $id;
                }
            }
        }
        return (int) $tickets()->orderBy('lastreply', 'desc')->orderBy('id', 'desc')->value('id');
    }

    /** Statuts de ticket considérés comme ouverts (Open, Answered, Customer-Reply…). */
    private static function activeStatuses()
    {
        try {
            $list = Capsule::table('tblticketstatuses')->where('showactive', 1)->pluck('title')->all();
        } catch (\Throwable $e) {
            $list = [];
        }
        return $list ?: ['Open', 'Answered', 'Customer-Reply', 'On Hold', 'In Progress'];
    }

    /** Département des tickets ouverts depuis WhatsApp (réglage, sinon le premier). */
    public static function department()
    {
        $id = (int) preg_replace('/\D+/', '', Settings::get('inbox_department'));
        if ($id && Capsule::table('tblticketdepartments')->where('id', $id)->exists()) {
            return $id;
        }
        return (int) Capsule::table('tblticketdepartments')->orderBy('order')->orderBy('id')->value('id');
    }

    /** Départements proposés dans la configuration du module. */
    public static function departments()
    {
        try {
            $list = Capsule::table('tblticketdepartments')->orderBy('order')->orderBy('id')->pluck('name', 'id')->all();
        } catch (\Throwable $e) {
            $list = [];
        }
        return $list ?: ['' => '(aucun département)'];
    }

    // ------------------------------------------------------------------ administration

    /** Après une réponse depuis WHMCS : les messages de ce numéro sont traités. */
    public static function markReplied($phone)
    {
        if ($phone !== '' && self::ready()) {
            Capsule::table(self::TABLE)->where('phone', $phone)->whereNull('replied_at')->update(['replied_at' => date('Y-m-d H:i:s')]);
        }
    }

    /** Messages de clients reçus ces dernières 24 h sans réponse depuis WHMCS. */
    public static function unanswered()
    {
        if (!self::ready()) {
            return 0;
        }
        $query = Capsule::table(self::TABLE)->whereNull('replied_at')->where('received_at', '>=', date('Y-m-d H:i:s', time() - 86400));
        $team = Settings::teamPhones();
        if ($team) {
            $query->whereNotIn('phone', $team);
        }
        return $query->count();
    }

    // ------------------------------------------------------------------ texte

    /** Messages groupés par client, dans l'ordre d'arrivée. */
    public static function group(array $rows)
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['client_id']][] = $r;
        }
        return $out;
    }

    /**
     * Texte d'un ticket : messages reçus (heure si plusieurs), puis leur origine.
     *
     * @param array $messages [['text' => …, 'time' => '14:32'], …]
     */
    public static function ticketText(array $messages, $phone, $date)
    {
        $lines = [];
        foreach ($messages as $m) {
            $text = self::plain($m['text']);
            $lines[] = count($messages) > 1 ? '[' . $m['time'] . '] ' . $text : $text;
        }
        return implode("\n\n", $lines) . "\n\n— " . (count($messages) > 1 ? 'Messages' : 'Message') . ' WhatsApp du +' . $phone . ', le ' . $date
            . (count($messages) === 1 ? ' à ' . $messages[0]['time'] : '') . '.';
    }

    /** Sujet d'un ticket ouvert depuis WhatsApp. */
    public static function subject($text)
    {
        return 'WhatsApp : ' . self::excerpt(strtok(self::plain($text), "\n"), 60);
    }

    /**
     * Texte sûr pour un ticket WHMCS : sans emojis (base utf8 de 3 octets) ni
     * chevrons (aucune balise HTML possible, quel que soit l'affichage).
     */
    public static function plain($text)
    {
        $text = preg_replace('/[\x{10000}-\x{10FFFF}\x{FE0E}\x{FE0F}\x{200D}\x{20E3}]/u', '', (string) $text);
        $text = strtr($text, ['<' => '‹', '>' => '›']);
        $text = preg_replace(["/[ \t]{2,}/", "/[ \t]+\n/", "/\n{3,}/"], [' ', "\n", "\n\n"], $text);
        $text = trim($text);
        return $text === '' ? '(emoji)' : $text;
    }

    /** Extrait sur une ligne, coupé à $length caractères. */
    public static function excerpt($text, $length)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)) . '…' : $text;
    }
}
