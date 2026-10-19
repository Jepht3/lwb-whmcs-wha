<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Alertes internes sur le WhatsApp de l'équipe : paiement reçu, nouvelle
 * commande, nouveau ticket, réponse d'un client, demande de résiliation.
 * Les membres de l'équipe enregistrent le numéro et répondent : ces échanges
 * sont aussi bons pour la réputation du numéro.
 */
class Team
{
    public static function notify($event, $rel, $relId)
    {
        try {
            $phones = Settings::teamPhones();
            $def = Events::get($event);
            $tpl = Queue::template($event);
            if (!$phones || !$def || empty($def['team']) || !$tpl || !$tpl->enabled) {
                return;
            }
            $ctx = Context::build($rel, $relId, ['team' => true]);
            if (!$ctx['obj']) {
                return;
            }
            $text = Queue::compose($tpl->message, $ctx['vars'], $def);
            foreach ($phones as $phone) {
                Queue::enqueue([
                    'client_id' => $ctx['client_id'],
                    'event' => $event,
                    'rel_type' => $rel,
                    'rel_id' => (int) $relId,
                    'phone' => $phone,
                    'message' => $text,
                    'priority' => $def['priority'],
                    'source' => 'team',
                ]);
            }
        } catch (\Throwable $e) {
            logActivity('LWB WhatsApp (alerte équipe ' . $event . ') : ' . $e->getMessage());
        }
    }
}
