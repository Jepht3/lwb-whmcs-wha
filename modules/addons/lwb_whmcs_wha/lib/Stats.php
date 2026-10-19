<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/** Statistiques : envois, lecture, clics, factures payées après un WhatsApp. */
class Stats
{
    const PAYMENT_EVENTS = ['invoice_created', 'invoice_reminder', 'invoice_overdue_1', 'invoice_overdue_2', 'invoice_overdue_3', 'service_suspended'];

    public static function summary($days = 30)
    {
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $rows = Capsule::table(Queue::TABLE)->where('created_at', '>=', $since)
            ->select('event', 'status', 'delivery', Capsule::raw('count(*) as n'))
            ->groupBy('event', 'status', 'delivery')->get();
        $out = [];
        $total = ['created' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'skipped' => 0, 'expired' => 0, 'optout' => 0, 'pending' => 0, 'clicked' => 0];
        foreach ($rows as $r) {
            $e = $r->event;
            if (!isset($out[$e])) {
                $out[$e] = array_fill_keys(array_keys($total), 0);
            }
            $n = (int) $r->n;
            $out[$e]['created'] += $n;
            $status = $r->status === 'sending' ? 'pending' : $r->status;
            if (isset($out[$e][$status])) {
                $out[$e][$status] += $n;
            }
            if ($r->status === 'sent' && in_array($r->delivery, ['delivered', 'read'], true)) {
                $out[$e]['delivered'] += $n;
            }
            if ($r->status === 'sent' && $r->delivery === 'read') {
                $out[$e]['read'] += $n;
            }
        }
        $clicks = Capsule::table(Links::TABLE . ' as l')->join(Queue::TABLE . ' as m', 'm.id', '=', 'l.message_id')
            ->where('m.created_at', '>=', $since)->where('l.clicks', '>', 0)
            ->select('m.event', Capsule::raw('count(distinct m.id) as n'))->groupBy('m.event')->pluck('n', 'event')->all();
        foreach ($clicks as $e => $n) {
            if (isset($out[$e])) {
                $out[$e]['clicked'] = (int) $n;
            }
        }
        foreach ($out as $e => $v) {
            foreach ($v as $k => $n) {
                $total[$k] += $n;
            }
        }
        return ['events' => $out, 'total' => $total, 'paid' => self::paidAfter($since)];
    }

    /** Factures payées dans les 7 jours suivant un WhatsApp de facture/relance. */
    public static function paidAfter($since)
    {
        $rows = Capsule::table(Queue::TABLE . ' as m')->join('tblinvoices as i', 'i.id', '=', 'm.rel_id')
            ->where('m.rel_type', 'invoice')->whereIn('m.event', self::PAYMENT_EVENTS)->where('m.status', 'sent')
            ->where('m.sent_at', '>=', $since)->where('i.status', 'Paid')
            ->whereRaw('i.datepaid >= m.sent_at')->whereRaw('i.datepaid <= DATE_ADD(m.sent_at, INTERVAL 7 DAY)')
            ->select('i.id', 'i.total', 'i.userid')->distinct()->get();
        $sum = 0.0;
        foreach ($rows as $r) {
            $sum += (float) $r->total;
        }
        $clientId = $rows->count() ? (int) $rows[0]->userid : 0;
        return ['count' => $rows->count(), 'amount' => Context::money($sum, $clientId)];
    }

    /** Chiffres du jour pour le tableau de bord et le widget. */
    public static function today()
    {
        $dayStart = Time::server(Time::now()->setTime(0, 0));
        $week = date('Y-m-d H:i:s', time() - 7 * 86400);
        // Seuls les messages suivis par la passerelle (identifiant WhatsApp connu) comptent.
        $sentWeek = Capsule::table(Queue::TABLE)->where('status', 'sent')->whereIn('source', ['auto', 'manual'])
            ->whereNotNull('wa_id')->where('sent_at', '>=', $week);
        $sent7 = (clone $sentWeek)->count();
        $read7 = (clone $sentWeek)->where('delivery', 'read')->count();
        return [
            'sent_today' => Capsule::table(Queue::TABLE)->where('status', 'sent')->where('sent_at', '>=', $dayStart)->count(),
            'pending' => Capsule::table(Queue::TABLE)->whereIn('status', ['pending', 'sending'])->count(),
            'failed_today' => Capsule::table(Queue::TABLE)->where('status', 'failed')->where('created_at', '>=', $dayStart)->count(),
            'read_rate' => $sent7 ? round($read7 * 100 / $sent7) : null,
            'next' => Capsule::table(Queue::TABLE)->where('status', 'pending')->min('next_try'),
            'received_today' => Inbox::ready() ? Capsule::table(Inbox::TABLE)->where('received_at', '>=', $dayStart)->count() : 0,
        ];
    }
}
