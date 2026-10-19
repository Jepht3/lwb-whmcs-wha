<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Variables d'un message, calculées à partir de l'objet WHMCS lié (facture,
 * service, domaine, client, commande, ticket) avec les liens de votre WHMCS.
 * Recalculé au moment de l'envoi : montants, remise et liens sont toujours à jour.
 */
class Context
{
    public static function systemUrl()
    {
        static $url;
        if ($url === null) {
            $url = rtrim((string) Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value'), '/') . '/';
        }
        return $url;
    }

    public static function config($setting)
    {
        return (string) Capsule::table('tblconfiguration')->where('setting', $setting)->value('value');
    }

    /** URL de l'administration WHMCS (dossier personnalisé pris en compte). */
    public static function adminUrl($path = '')
    {
        static $folder;
        if ($folder === null) {
            try {
                $folder = (string) \App::get_admin_folder_name();
            } catch (\Throwable $e) {
                $folder = '';
            }
            $folder = trim($folder, '/') ?: 'admin';
        }
        return self::systemUrl() . $folder . '/' . ltrim($path, '/');
    }

    /** Lien de paiement sans connexion (FlexPay) si disponible, sinon la facture. */
    public static function payUrl($invoiceId)
    {
        try {
            $lib = ROOTDIR . '/modules/gateways/flexpay/lib/Gateway.php';
            if (is_file($lib)) {
                require_once $lib;
                if (class_exists('\FlexPayWhmcs\Gateway') && \FlexPayWhmcs\Gateway::isActive()) {
                    return \FlexPayWhmcs\Gateway::guestPayUrl((int) $invoiceId);
                }
            }
        } catch (\Throwable $e) {
            // Repli sur le lien standard.
        }
        return self::systemUrl() . 'viewinvoice.php?id=' . (int) $invoiceId;
    }

    public static function money($amount, $clientId)
    {
        static $currencies = [];
        $clientId = (int) $clientId;
        if (!array_key_exists($clientId, $currencies)) {
            $cur = Capsule::table('tblclients')->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
                ->where('tblclients.id', $clientId)->first(['tblcurrencies.prefix', 'tblcurrencies.suffix']);
            $currencies[$clientId] = $cur ?: Capsule::table('tblcurrencies')->where('default', 1)->first(['prefix', 'suffix']);
        }
        $cur = $currencies[$clientId];
        return trim(($cur ? $cur->prefix : '') . number_format((float) $amount, 2, '.', ' ') . ($cur ? $cur->suffix : ''));
    }

    private static function gatewayName($module)
    {
        $name = Capsule::table('tblpaymentgateways')->where('gateway', $module)->where('setting', 'name')->value('value');
        return $name ?: (string) $module;
    }

    /** Dernière facture impayée d'un client, liée à un service si possible. */
    private static function unpaidInvoiceId($clientId, $serviceId = 0)
    {
        $base = Capsule::table('tblinvoices as i')->where('i.userid', $clientId)->where('i.status', 'Unpaid')->where('i.total', '>', 0);
        if ($serviceId) {
            $id = (clone $base)->join('tblinvoiceitems as it', 'it.invoiceid', '=', 'i.id')
                ->where('it.type', 'Hosting')->where('it.relid', $serviceId)->orderBy('i.id', 'desc')->value('i.id');
            if ($id) {
                return (int) $id;
            }
        }
        return (int) $base->orderBy('i.id', 'desc')->value('i.id');
    }

    /**
     * @param string $rel   invoice | service | domain | client | order | ticket
     * @param array  $opts  message_id => crée des liens courts suivis ; team => liens d'administration
     * @return array ['client' => objet|null, 'client_id' => int, 'obj' => objet|null, 'vars' => [...]]
     */
    public static function build($rel, $relId, array $opts = [])
    {
        $relId = (int) $relId;
        $messageId = (int) ($opts['message_id'] ?? 0);
        $link = function ($kind, $id, $target) use ($messageId) {
            return Links::make($kind, $id, $target, $messageId);
        };
        $base = self::systemUrl();
        $vars = [];
        $obj = null;
        $clientId = 0;
        $admin = '';

        switch ($rel) {
            case 'invoice':
                $obj = Capsule::table('tblinvoices')->where('id', $relId)->first();
                if (!$obj) {
                    break;
                }
                $clientId = (int) $obj->userid;
                $paid = (float) Capsule::table('tblaccounts')->where('invoiceid', $obj->id)->sum('amountin')
                    - (float) Capsule::table('tblaccounts')->where('invoiceid', $obj->id)->sum('amountout');
                $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $obj->id)->where('amount', '>', 0)
                    ->orderBy('id')->limit(3)->pluck('description')->map(function ($d) {
                        return '• ' . strtok(trim((string) $d), "\n");
                    })->all();
                $days = Time::daysUntil($obj->duedate);
                $invoiceLink = $base . 'viewinvoice.php?id=' . $obj->id;
                $vars = [
                    'invoice_id' => $obj->invoicenum !== '' ? $obj->invoicenum : $obj->id,
                    'total' => self::money($obj->total, $clientId),
                    'balance' => self::money(max(0, $obj->total - $paid), $clientId),
                    'due_date' => Time::date($obj->duedate),
                    'days_left' => $days,
                    'due_when' => Time::when($days, $obj->duedate),
                    'suspend_when' => self::suspendWhen($obj->duedate),
                    'invoice_link' => $link('url', $obj->id, $invoiceLink),
                    'pay_link' => $obj->status === 'Unpaid' ? $link('pay', $obj->id, self::payUrl($obj->id)) : $link('url', $obj->id, $invoiceLink),
                    'early_discount' => self::earlyDiscount($obj, $clientId),
                    'items' => implode("\n", $items),
                    'payment_method' => self::gatewayName($obj->paymentmethod),
                ];
                $admin = 'invoices.php?action=edit&id=' . $obj->id;
                break;

            case 'service':
                $obj = Capsule::table('tblhosting')->where('id', $relId)->first();
                if (!$obj) {
                    break;
                }
                $clientId = (int) $obj->userid;
                $unpaid = self::unpaidInvoiceId($clientId, $obj->id);
                $cancel = Capsule::table('tblcancelrequests')->where('relid', $obj->id)->orderBy('id', 'desc')->first();
                $vars = [
                    'service' => (string) Capsule::table('tblproducts')->where('id', $obj->packageid)->value('name'),
                    'domain' => (string) $obj->domain,
                    'domain_label' => $obj->domain ? '(' . $obj->domain . ') ' : '',
                    'service_link' => $link('url', $obj->id, $base . 'clientarea.php?action=productdetails&id=' . $obj->id),
                    'next_due_date' => Time::date($obj->nextduedate),
                    'amount' => self::money($obj->amount, $clientId),
                    'pay_link' => $unpaid ? $link('pay', $unpaid, self::payUrl($unpaid)) : $link('url', 0, $base . 'clientarea.php?action=invoices'),
                    'cancel_type' => $cancel ? ($cancel->type === 'Immediate' ? 'immédiate' : 'en fin de période') : '',
                    'cancel_reason' => $cancel ? trim((string) $cancel->reason) : '',
                ];
                $admin = 'clientsservices.php?userid=' . $clientId . '&id=' . $obj->id;
                break;

            case 'domain':
                $obj = Capsule::table('tbldomains')->where('id', $relId)->first();
                if (!$obj) {
                    break;
                }
                $clientId = (int) $obj->userid;
                $expiry = ($obj->expirydate && $obj->expirydate !== '0000-00-00') ? $obj->expirydate : $obj->nextduedate;
                $days = Time::daysUntil($expiry);
                $vars = [
                    'domain' => (string) $obj->domain,
                    'expiry_date' => Time::date($expiry),
                    'days_left' => $days,
                    'due_when' => Time::when($days, $expiry),
                    'renew_link' => $link('url', $obj->id, $base . 'clientarea.php?action=domaindetails&id=' . $obj->id),
                ];
                $admin = 'clientsdomains.php?userid=' . $clientId . '&id=' . $obj->id;
                break;

            case 'client':
                $obj = Capsule::table('tblclients')->where('id', $relId)->first();
                $clientId = $obj ? (int) $obj->id : 0;
                $vars = [
                    'early_percent' => defined('LWM_EARLY_PERCENT') ? LWM_EARLY_PERCENT : 5,
                    'early_days' => defined('LWM_EARLY_DAYS') ? LWM_EARLY_DAYS : 20,
                ];
                $admin = 'clientssummary.php?userid=' . $clientId;
                break;

            case 'order':
                $obj = Capsule::table('tblorders')->where('id', $relId)->first();
                if (!$obj) {
                    break;
                }
                $clientId = (int) $obj->userid;
                $items = Capsule::table('tblhosting as h')->join('tblproducts as p', 'p.id', '=', 'h.packageid')
                    ->where('h.orderid', $obj->id)->limit(4)->get(['p.name', 'h.domain'])->map(function ($r) {
                        return '• ' . $r->name . ($r->domain ? ' (' . $r->domain . ')' : '');
                    })->all();
                foreach (Capsule::table('tbldomains')->where('orderid', $obj->id)->limit(4)->pluck('domain') as $d) {
                    $items[] = '• Domaine ' . $d;
                }
                $payment = '';
                $payLink = $link('url', 0, $base . 'clientarea.php?action=invoices');
                $inv = $obj->invoiceid ? Capsule::table('tblinvoices')->where('id', $obj->invoiceid)->first() : null;
                if ($inv && $inv->status === 'Unpaid' && $inv->total > 0) {
                    $payLink = $link('pay', $inv->id, self::payUrl($inv->id));
                    $payment = 'Montant : *' . self::money($inv->total, $clientId) . "*.\n\n"
                        . "💳 Réglez votre facture ici pour une activation rapide :\n" . $payLink;
                }
                $client = Capsule::table('tblclients')->where('id', $clientId)->first();
                $isNew = $client && $client->datecreated && Time::daysUntil($client->datecreated) >= -2;
                $vars = [
                    'order_number' => $obj->ordernum ?: $obj->id,
                    'order_total' => self::money($obj->amount, $clientId),
                    'order_items' => implode("\n", array_slice($items, 0, 5)),
                    'order_payment' => $payment,
                    'pay_link' => $payLink,
                    'welcome_line' => $isNew ? "\nBienvenue chez " . self::config('CompanyName')
                        . " 🙌 Enregistrez ce numéro : vous recevrez ici vos factures, et vous pouvez nous écrire à tout moment.\n" : '',
                ];
                $admin = 'orders.php?action=view&id=' . $obj->id;
                break;

            case 'ticket':
                $obj = Capsule::table('tbltickets')->where('id', $relId)->first();
                if (!$obj) {
                    break;
                }
                $clientId = (int) $obj->userid;
                $vars = [
                    'ticket_id' => $obj->tid,
                    'subject' => $obj->title,
                    'department' => (string) Capsule::table('tblticketdepartments')->where('id', $obj->did)->value('name'),
                    'ticket_link' => $link('url', $obj->id, $base . 'viewticket.php?tid=' . rawurlencode($obj->tid) . '&c=' . rawurlencode($obj->c)),
                ];
                $admin = 'supporttickets.php?action=view&id=' . $obj->id;
                break;
        }

        $client = $clientId ? Capsule::table('tblclients')->where('id', $clientId)->first() : null;
        $vars += [
            'greeting' => Time::greeting(),
            'first_name' => $client ? trim($client->firstname) : '',
            'client_name' => $client ? trim($client->firstname . ' ' . $client->lastname) . ($client->companyname ? ' (' . $client->companyname . ')' : '') : '',
            'company' => self::config('CompanyName'),
            'client_area' => $link('url', 0, $base . 'clientarea.php'),
            'payment_methods' => Settings::get('payment_methods', 'Mobile Money (M-Pesa, Orange Money, Airtel Money) ou carte bancaire'),
        ];
        if (!empty($opts['team'])) {
            $vars['admin_link'] = self::adminUrl($admin);
        }
        return ['client' => $client, 'client_id' => $clientId, 'obj' => $obj, 'vars' => $vars];
    }

    /**
     * Raison de ne plus envoyer une notification (situation changée depuis la
     * mise en file), ou null si elle est toujours pertinente.
     */
    public static function irrelevant($event, array $ctx)
    {
        $def = Events::get($event);
        $obj = $ctx['obj'];
        if (!$def) {
            return 'notification inconnue';
        }
        if (!$obj) {
            return 'élément introuvable (supprimé ?)';
        }
        if (empty($def['team']) && $ctx['client'] && $ctx['client']->status === 'Closed') {
            return 'compte client fermé';
        }
        switch ($event) {
            case 'invoice_created':
            case 'invoice_reminder':
            case 'invoice_overdue_1':
            case 'invoice_overdue_2':
            case 'invoice_overdue_3':
                if ($obj->status !== 'Unpaid') {
                    return 'facture déjà réglée ou annulée';
                }
                if ((float) $obj->total <= 0) {
                    return 'facture à 0';
                }
                break;
            case 'invoice_paid':
                if ($obj->status !== 'Paid') {
                    return 'facture plus marquée payée';
                }
                break;
            case 'service_suspended':
                if ($obj->domainstatus !== 'Suspended') {
                    return 'service plus suspendu';
                }
                break;
            case 'service_unsuspended':
            case 'service_welcome':
                if ($obj->domainstatus !== 'Active') {
                    return 'service pas actif';
                }
                break;
            case 'domain_renewal_notice':
            case 'domain_expired':
                if (in_array($obj->status, ['Cancelled', 'Transferred Away', 'Fraud'], true)) {
                    return 'domaine plus géré';
                }
                if ($ctx['vars']['days_left'] > ($event === 'domain_expired' ? 0 : 120)) {
                    return 'domaine déjà renouvelé';
                }
                break;
            case 'order_confirmation':
                if (in_array($obj->status, ['Cancelled', 'Fraud'], true)) {
                    return 'commande annulée';
                }
                break;
        }
        return null;
    }

    /** « demain », « dans 3 jours »… d'après la suspension automatique de WHMCS. */
    private static function suspendWhen($duedate)
    {
        if (self::config('AutoSuspension') === 'on') {
            $date = date('Y-m-d', strtotime(substr((string) $duedate, 0, 10) . ' +' . (int) self::config('AutoSuspensionDays') . ' days'));
            $days = Time::daysUntil($date);
            if ($days >= 1) {
                return Time::when($days, $date);
            }
        }
        return 'très prochainement';
    }

    /**
     * Remise pour paiement anticipé (hook lewebmax_early_payment.php), mise en
     * avant tant qu'elle est valable. Vide si ce hook n'est pas installé.
     */
    private static function earlyDiscount($inv, $clientId)
    {
        try {
            if ($inv->status !== 'Unpaid' || !Capsule::schema()->hasTable('mod_lwm_early_discounts')) {
                return '';
            }
            $d = Capsule::table('mod_lwm_early_discounts')->where('invoice_id', $inv->id)->whereNull('removed_at')
                ->where('deadline', '>=', Time::today())->first();
            if (!$d) {
                return '';
            }
            $percent = defined('LWM_EARLY_PERCENT') ? ' (-' . LWM_EARLY_PERCENT . ' %)' : '';
            return "\n🎁 *Bonus paiement anticipé* : réglez " . Time::when(Time::daysUntil($d->deadline), $d->deadline) . ' au plus tard et économisez *'
                . self::money($d->amount, $clientId) . '*' . $percent . ", déjà déduits du montant !\n";
        } catch (\Throwable $e) {
            return '';
        }
    }
}
