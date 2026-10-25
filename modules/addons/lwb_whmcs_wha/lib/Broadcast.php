<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Annonces : un même message (mise à jour PHP, maintenance, nouveauté…) envoyé à
 * un groupe de clients choisi par l'administrateur.
 *
 * Chaque destinataire devient une ligne de la file (source « broadcast »), qui
 * part au cron, hors heures calmes, au compte-gouttes : quelques messages par
 * passage, espacés de plusieurs secondes, après les notifications attendues
 * (priorité basse). Le texte est recomposé au moment de l'envoi (salutation à
 * l'heure, liens suivis) ; une annonce annulée ou expirée ne part plus.
 */
class Broadcast
{
    const TABLE = 'mod_lwbwha_broadcasts';
    const EVENT = 'broadcast';
    const SOURCE = 'broadcast';

    /** Statuts de service WHMCS proposés pour le ciblage. */
    const SERVICE_STATUSES = ['Active' => 'Actif', 'Suspended' => 'Suspendu', 'Pending' => 'En attente'];

    public static function ready()
    {
        static $ok = null;
        if ($ok === null) {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::TABLE)) {
                $schema->create(self::TABLE, function ($t) {
                    $t->increments('id');
                    $t->string('title', 150);
                    $t->text('message');
                    $t->text('audience');
                    $t->integer('total')->default(0);
                    $t->string('status', 12)->default('active');
                    $t->integer('admin_id')->default(0);
                    $t->dateTime('created_at');
                    $t->dateTime('expires_at');
                });
            }
            $ok = true;
        }
        return $ok;
    }

    /** Variables propres aux annonces, en plus de celles du client. */
    public static function vars()
    {
        return ['greeting', 'first_name', 'client_name', 'company', 'client_area', 'domain', 'domains', 'service'];
    }

    /**
     * Filtres de ciblage nettoyés.
     *
     * scope : clients (tous les clients actifs) | services (clients ayant un service correspondant)
     */
    public static function audience(array $in)
    {
        $ints = function ($v) {
            return array_values(array_unique(array_filter(array_map('intval', (array) $v))));
        };
        $statuses = array_values(array_intersect(array_keys(self::SERVICE_STATUSES), (array) ($in['statuses'] ?? [])));
        return [
            'scope' => ($in['scope'] ?? '') === 'services' ? 'services' : 'clients',
            'servers' => $ints($in['servers'] ?? []),
            'groups' => $ints($in['groups'] ?? []),
            'products' => $ints($in['products'] ?? []),
            'statuses' => $statuses ?: ['Active'],
        ];
    }

    /** Description lisible du ciblage. */
    public static function describe(array $a)
    {
        if ($a['scope'] === 'clients') {
            return 'Tous les clients actifs';
        }
        $parts = [];
        if ($a['servers']) {
            $parts[] = 'serveur ' . implode(', ', Capsule::table('tblservers')->whereIn('id', $a['servers'])->pluck('name')->all());
        }
        if ($a['groups']) {
            $parts[] = 'groupe ' . implode(', ', Capsule::table('tblproductgroups')->whereIn('id', $a['groups'])->pluck('name')->all());
        }
        if ($a['products']) {
            $parts[] = 'produit ' . implode(', ', Capsule::table('tblproducts')->whereIn('id', $a['products'])->pluck('name')->all());
        }
        $statuses = implode(', ', array_map(function ($s) {
            return mb_strtolower(self::SERVICE_STATUSES[$s]);
        }, $a['statuses']));
        return 'Clients avec un service ' . $statuses . ($parts ? ' (' . implode(' · ', $parts) . ')' : ' (tous les produits)');
    }

    /**
     * Clients ciblés, avec leurs domaines et services correspondants.
     *
     * @return array client_id => ['domains' => [...], 'services' => [...]]
     */
    public static function targets(array $a)
    {
        $out = [];
        if ($a['scope'] === 'clients') {
            foreach (Capsule::table('tblclients')->where('status', 'Active')->orderBy('id')->pluck('id') as $id) {
                $out[(int) $id] = ['domains' => [], 'services' => []];
            }
            $rows = Capsule::table('tblhosting as h')->join('tblproducts as p', 'p.id', '=', 'h.packageid')
                ->whereIn('h.userid', array_keys($out) ?: [0])->where('h.domainstatus', 'Active')->orderBy('h.id')
                ->get(['h.userid', 'h.domain', 'p.name']);
        } else {
            $q = Capsule::table('tblhosting as h')->join('tblproducts as p', 'p.id', '=', 'h.packageid')
                ->join('tblclients as c', 'c.id', '=', 'h.userid')->where('c.status', '!=', 'Closed')
                ->whereIn('h.domainstatus', $a['statuses']);
            if ($a['servers']) {
                $q->whereIn('h.server', $a['servers']);
            }
            if ($a['groups']) {
                $q->whereIn('p.gid', $a['groups']);
            }
            if ($a['products']) {
                $q->whereIn('h.packageid', $a['products']);
            }
            $rows = $q->orderBy('h.userid')->orderBy('h.id')->get(['h.userid', 'h.domain', 'p.name']);
            foreach ($rows as $r) {
                $out[(int) $r->userid] = ['domains' => [], 'services' => []];
            }
        }
        foreach ($rows as $r) {
            $id = (int) $r->userid;
            $domain = strtolower(trim((string) $r->domain));
            if ($domain !== '' && !in_array($domain, $out[$id]['domains'], true)) {
                $out[$id]['domains'][] = $domain;
            }
            if (!in_array($r->name, $out[$id]['services'], true)) {
                $out[$id]['services'][] = $r->name;
            }
        }
        return $out;
    }

    /**
     * Destinataires : un message par numéro WhatsApp, désinscrits et clients sans
     * numéro mis à part.
     *
     * @return array ['ok' => [client_id => phone], 'blocked' => [client_id => raison], 'targets' => targets()]
     */
    public static function recipients(array $a)
    {
        $targets = self::targets($a);
        $ok = [];
        $blocked = [];
        $phones = [];
        $clients = Capsule::table('tblclients')->whereIn('id', array_keys($targets) ?: [0])->get()->keyBy('id');
        foreach ($targets as $id => $t) {
            $client = $clients[$id] ?? null;
            $who = Phone::forClient($client ?: $id);
            if ($who['blocked'] !== '') {
                $blocked[$id] = $who['blocked'];
            } elseif (isset($phones[$who['phone']])) {
                $blocked[$id] = 'même numéro que le client #' . $phones[$who['phone']];
            } else {
                $phones[$who['phone']] = $id;
                $ok[$id] = $who['phone'];
            }
        }
        return ['ok' => $ok, 'blocked' => $blocked, 'targets' => $targets];
    }

    /** Variables d'un destinataire. */
    public static function clientVars($clientId, array $target, $messageId = 0)
    {
        $ctx = Context::build('client', (int) $clientId, ['message_id' => (int) $messageId]);
        $domains = $target['domains'] ?? [];
        return $ctx['vars'] + [
            'domain' => $domains ? $domains[0] : '',
            'domains' => implode(', ', $domains),
            'service' => implode(', ', $target['services'] ?? []),
        ];
    }

    /** Texte final d'un destinataire (avec le pied « STOP »). */
    public static function text($message, array $vars)
    {
        return Queue::compose($message, $vars, []);
    }

    /**
     * Crée l'annonce et met les messages en file.
     *
     * @return array ['ok', 'error', 'id', 'queued', 'blocked']
     */
    public static function create($title, $message, array $audience, $days = 3)
    {
        self::ready();
        $title = trim((string) $title);
        $message = trim((string) $message);
        if ($message === '') {
            return ['ok' => false, 'error' => 'Le message est vide.'];
        }
        $r = self::recipients($audience);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => 'Aucun destinataire joignable pour ce ciblage.'];
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) Capsule::table(self::TABLE)->insertGetId([
            'title' => mb_substr($title !== '' ? $title : mb_substr(strtok($message, "\n"), 0, 60), 0, 150),
            'message' => Events::dbEncode($message),
            'audience' => json_encode($audience),
            'total' => count($r['ok']),
            'status' => 'active',
            'admin_id' => (int) ($_SESSION['adminid'] ?? 0),
            'created_at' => $now,
            'expires_at' => date('Y-m-d H:i:s', time() + max(1, min(14, (int) $days)) * 86400),
        ]);
        foreach ($r['ok'] as $clientId => $phone) {
            Queue::enqueue([
                'client_id' => $clientId, 'event' => self::EVENT, 'rel_type' => self::EVENT, 'rel_id' => $id, 'phone' => $phone,
                // Texte provisoire (aperçu dans l'onglet Messages) : recomposé à l'envoi.
                'message' => self::text($message, self::clientVars($clientId, $r['targets'][$clientId])),
                'priority' => 1, 'source' => self::SOURCE,
            ]);
        }
        return ['ok' => true, 'error' => '', 'id' => $id, 'queued' => count($r['ok']), 'blocked' => count($r['blocked'])];
    }

    /**
     * Texte à envoyer pour une ligne de la file, ou raison de ne plus l'envoyer.
     *
     * @return array [texte|null, raison|null]
     */
    public static function prepare($row)
    {
        $b = self::ready() ? Capsule::table(self::TABLE)->where('id', (int) $row->rel_id)->first() : null;
        if (!$b) {
            return [null, 'annonce supprimée'];
        }
        if ($b->status === 'cancelled') {
            return [null, 'annonce annulée'];
        }
        if (strtotime($b->expires_at) < time()) {
            return [null, 'annonce expirée le ' . Time::show($b->expires_at)];
        }
        $client = Capsule::table('tblclients')->where('id', (int) $row->client_id)->first();
        if ($client && $client->status === 'Closed') {
            return [null, 'compte client fermé'];
        }
        $audience = self::audience((array) json_decode((string) $b->audience, true));
        $targets = self::targets($audience);
        $target = $targets[(int) $row->client_id] ?? ['domains' => [], 'services' => []];
        return [self::text(Events::dbDecode($b->message), self::clientVars($row->client_id, $target, $row->id)), null];
    }

    /** Annule une annonce : ses messages en attente ne partiront pas. */
    public static function cancel($id)
    {
        self::ready();
        Capsule::table(self::TABLE)->where('id', (int) $id)->update(['status' => 'cancelled']);
        return Capsule::table(Queue::TABLE)->where('source', self::SOURCE)->where('rel_id', (int) $id)->whereIn('status', ['pending'])
            ->update(['status' => 'skipped', 'error' => 'Annonce annulée par un administrateur']);
    }

    /** Avancement d'une annonce. */
    public static function progress($id)
    {
        $rows = Capsule::table(Queue::TABLE)->where('source', self::SOURCE)->where('rel_id', (int) $id)
            ->select('status', 'delivery', Capsule::raw('count(*) as n'))->groupBy('status', 'delivery')->get();
        $p = ['pending' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($rows as $r) {
            $n = (int) $r->n;
            if (in_array($r->status, ['pending', 'sending'], true)) {
                $p['pending'] += $n;
            } elseif ($r->status === 'sent') {
                $p['sent'] += $n;
                if ($r->delivery === 'read') {
                    $p['read'] += $n;
                    $p['delivered'] += $n;
                } elseif ($r->delivery === 'delivered') {
                    $p['delivered'] += $n;
                }
            } elseif (in_array($r->status, ['failed', 'optout'], true)) {
                $p['failed'] += $n;
            } else {
                $p['skipped'] += $n;
            }
        }
        return $p;
    }

    /** Clôture les annonces terminées (plus aucun message en attente). */
    public static function close()
    {
        if (!self::ready()) {
            return;
        }
        foreach (Capsule::table(self::TABLE)->where('status', 'active')->pluck('id') as $id) {
            if (!Capsule::table(Queue::TABLE)->where('source', self::SOURCE)->where('rel_id', $id)->whereIn('status', ['pending', 'sending'])->exists()) {
                Capsule::table(self::TABLE)->where('id', $id)->update(['status' => 'done']);
            }
        }
    }
}
