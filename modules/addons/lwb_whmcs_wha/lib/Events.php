<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Catalogue des notifications WhatsApp.
 *
 * Les notifications client suivent les e-mails envoyés par WHMCS (hook
 * EmailPreSend), y compris ceux envoyés par vos propres hooks via SendEmail.
 * Les alertes équipe suivent des évènements WHMCS (paiement, commande, ticket…).
 *
 *   group    : rubrique de l'onglet Modèles
 *   emails   : noms exacts des modèles d'e-mail WHMCS déclencheurs
 *   match    : règle supplémentaire (type de modèle + mots du nom)
 *   rel      : objet lié : invoice | service | domain | client | order | ticket
 *   priority : 1 (faible) à 9 (urgent) — ordre d'envoi dans la file
 *   ttl      : durée de validité en heures (au-delà, le message est abandonné)
 *   days     : jours avant l'échéance autorisés (rappels)
 *   pdf      : joindre la facture PDF par défaut
 *   enabled  : actif par défaut
 *   team     : alerte interne envoyée aux numéros de l'équipe
 */
class Events
{
    const SIGN = "\n\nL'équipe {company} 💙";

    public static function defaults()
    {
        static $defs;
        if ($defs !== null) {
            return $defs;
        }
        $sign = self::SIGN;
        $defs = [
            // ------------------------------------------------------------ Factures
            'invoice_created' => [
                'group' => 'Factures', 'label' => 'Nouvelle facture (+ PDF)',
                'emails' => ['Invoice Created'], 'rel' => 'invoice',
                'priority' => 5, 'ttl' => 72, 'pdf' => true, 'enabled' => true,
                'message' => "{greeting} {first_name} 👋\n\n"
                    . "Votre facture *n°{invoice_id}* de *{total}* est disponible (échéance {due_when}).\n"
                    . "{early_discount}\n"
                    . "💳 Payez en 1 minute par {payment_methods}, sans vous connecter :\n{pay_link}\n\n"
                    . "Merci de votre confiance, c'est un plaisir de vous accompagner !" . $sign,
            ],
            'invoice_reminder' => [
                'group' => 'Factures', 'label' => 'Rappel avant échéance',
                'emails' => ['Invoice Payment Reminder'], 'rel' => 'invoice',
                'priority' => 3, 'ttl' => 24, 'days' => '7,1', 'enabled' => true,
                'message' => "{greeting} {first_name} 😊\n\n"
                    . "Petit rappel amical : votre facture *n°{invoice_id}* de *{balance}* arrive à échéance *{due_when}*.\n"
                    . "{early_discount}\n"
                    . "👉 Réglez-la en quelques secondes par {payment_methods} :\n{pay_link}\n\n"
                    . "Vos services continuent ainsi sans interruption. Déjà réglée ? Merci, ignorez simplement ce message !" . $sign,
            ],
            'invoice_overdue_1' => [
                'group' => 'Factures', 'label' => 'Facture en retard (J+1)',
                'emails' => ['First Invoice Overdue Notice'], 'rel' => 'invoice',
                'priority' => 4, 'ttl' => 24, 'enabled' => true,
                'message' => "{greeting} {first_name},\n\n"
                    . "Sauf erreur de notre part, la facture *n°{invoice_id}* (*{balance}*) est arrivée à échéance {due_when} et reste à régler.\n\n"
                    . "Un oubli, ça arrive à tout le monde 🙂 Vous pouvez la payer maintenant ici :\n{pay_link}\n\n"
                    . "Besoin d'un délai ou d'aide pour payer ? Répondez simplement à ce message." . $sign,
            ],
            'invoice_overdue_2' => [
                'group' => 'Factures', 'label' => 'Facture en retard, relances suivantes',
                'emails' => ['Second Invoice Overdue Notice'], 'rel' => 'invoice',
                'priority' => 3, 'ttl' => 24, 'enabled' => false,
                'message' => "{greeting} {first_name},\n\n"
                    . "Votre facture *n°{invoice_id}* de *{balance}* est toujours en attente de paiement.\n"
                    . "Pour garder vos services actifs, réglez-la dès que possible :\n{pay_link}\n\n"
                    . "Nous restons disponibles pour toute question." . $sign,
            ],
            'invoice_overdue_3' => [
                'group' => 'Factures', 'label' => 'Dernier rappel avant suspension',
                'emails' => ['Third Invoice Overdue Notice'], 'rel' => 'invoice',
                'priority' => 6, 'ttl' => 18, 'enabled' => true,
                'message' => "{greeting} {first_name} ⚠️\n\n"
                    . "Dernier rappel : sans règlement de la facture *n°{invoice_id}* (*{balance}*), vos services seront suspendus *{suspend_when}*.\n\n"
                    . "Évitez toute coupure de votre site et de vos e-mails en payant maintenant :\n{pay_link}\n\n"
                    . "Une difficulté ? Écrivez-nous ici, on trouvera une solution ensemble 🤝" . $sign,
            ],
            'invoice_paid' => [
                'group' => 'Factures', 'label' => 'Paiement reçu, merci (+ reçu PDF)',
                'emails' => ['Invoice Payment Confirmation'], 'rel' => 'invoice',
                'priority' => 8, 'ttl' => 72, 'pdf' => true, 'enabled' => true,
                'message' => "Merci {first_name} ! 🎉\n\n"
                    . "Nous avons bien reçu votre paiement de *{total}* pour la facture *n°{invoice_id}*. Votre reçu est joint à ce message.\n\n"
                    . "🎁 Le saviez-vous ? En recommandant {company} à vos proches, vous profitez d'avantages exclusifs. Tous les détails dans votre espace client :\n{client_area}" . $sign,
            ],
            // ------------------------------------------------------------ Services
            'service_welcome' => [
                'group' => 'Services', 'label' => 'Service activé (e-mails de bienvenue)',
                'emails' => [], 'match' => ['type' => 'product', 'name' => ['welcome', 'bienvenue']], 'rel' => 'service',
                'priority' => 7, 'ttl' => 72, 'enabled' => true,
                'message' => "Bienvenue {first_name} ! 🚀\n\n"
                    . "Votre service *{service}* {domain_label}est maintenant *actif*.\n"
                    . "Vos accès vous ont été envoyés par e-mail. Tous les détails sont ici :\n{service_link}\n\n"
                    . "Une question pour bien démarrer ? Notre équipe est là pour vous aider, répondez simplement à ce message 😊" . $sign,
            ],
            'service_suspended' => [
                'group' => 'Services', 'label' => 'Service suspendu',
                'emails' => ['Service Suspension Notification'], 'rel' => 'service',
                'priority' => 6, 'ttl' => 24, 'enabled' => true,
                'message' => "{greeting} {first_name},\n\n"
                    . "Votre service *{service}* {domain_label}a été suspendu suite à une facture impayée.\n\n"
                    . "Bonne nouvelle : il sera réactivé *automatiquement* dès votre paiement 👇\n{pay_link}\n\n"
                    . "Besoin d'aide ? Répondez à ce message." . $sign,
            ],
            'service_unsuspended' => [
                'group' => 'Services', 'label' => 'Service réactivé',
                'emails' => ['Service Unsuspension Notification'], 'rel' => 'service',
                'priority' => 7, 'ttl' => 24, 'enabled' => true,
                'message' => "Bonne nouvelle {first_name} ✅\n\n"
                    . "Votre service *{service}* {domain_label}est de nouveau *actif*. Merci pour votre règlement !\n\n"
                    . "Gérez votre service ici : {service_link}" . $sign,
            ],
            // ------------------------------------------------------------ Domaines
            'domain_renewal_notice' => [
                'group' => 'Domaines', 'label' => 'Domaine bientôt expiré',
                'emails' => ['Upcoming Domain Renewal Notice'], 'rel' => 'domain',
                'priority' => 3, 'ttl' => 48, 'enabled' => true,
                'message' => "{greeting} {first_name} 🌐\n\n"
                    . "Votre nom de domaine *{domain}* expire *{due_when}*.\n"
                    . "Renouvelez-le à temps pour garder votre site et vos e-mails en ligne, et ne pas risquer de le perdre :\n{renew_link}" . $sign,
            ],
            'domain_expired' => [
                'group' => 'Domaines', 'label' => 'Domaine expiré',
                'emails' => ['Expired Domain Notice'], 'rel' => 'domain',
                'priority' => 5, 'ttl' => 48, 'enabled' => true,
                'message' => "{greeting} {first_name} ⚠️\n\n"
                    . "Votre nom de domaine *{domain}* a expiré {due_when} : votre site et vos e-mails risquent de ne plus fonctionner.\n"
                    . "Il est encore temps de le récupérer, renouvelez-le ici :\n{renew_link}" . $sign,
            ],
            'domain_registered' => [
                'group' => 'Domaines', 'label' => 'Domaine enregistré / transféré',
                'emails' => ['Domain Registration Confirmation', 'Domain Transfer Completed'], 'rel' => 'domain',
                'priority' => 6, 'ttl' => 72, 'enabled' => true,
                'message' => "Félicitations {first_name} ! 🎉\n\n"
                    . "Le nom de domaine *{domain}* est désormais à vous (valable jusqu'au {expiry_date}).\n"
                    . "Et maintenant ? Un site professionnel ou des adresses e-mail à votre nom ? On s'en occupe pour vous 😉\n{client_area}" . $sign,
            ],
            'domain_renewed' => [
                'group' => 'Domaines', 'label' => 'Domaine renouvelé',
                'emails' => ['Domain Renewal Confirmation'], 'rel' => 'domain',
                'priority' => 6, 'ttl' => 72, 'enabled' => true,
                'message' => "Merci {first_name} ✅\n\n"
                    . "Votre nom de domaine *{domain}* est renouvelé jusqu'au *{expiry_date}*. Vous êtes tranquille !" . $sign,
            ],
            // ------------------------------------------------------------ Clients & commandes
            'client_signup' => [
                'group' => 'Clients & commandes', 'label' => 'Bienvenue nouveau client',
                'emails' => ['Client Signup Email'], 'rel' => 'client',
                'priority' => 4, 'ttl' => 24, 'enabled' => true,
                'message' => "Bienvenue chez {company}, {first_name} ! 🙌\n\n"
                    . "Votre espace client est prêt : factures, services, domaines et support au même endroit.\n{client_area}\n\n"
                    . "Enregistrez ce numéro : vous recevrez ici vos factures et informations importantes, et vous pouvez nous écrire à tout moment." . $sign,
            ],
            'order_confirmation' => [
                'group' => 'Clients & commandes', 'label' => 'Commande reçue (+ lien de paiement)',
                'emails' => ['Order Confirmation'], 'rel' => 'order',
                'priority' => 7, 'ttl' => 24, 'enabled' => true,
                'message' => "Merci pour votre commande {first_name} ! 🛒\n"
                    . "{welcome_line}\n"
                    . "Commande *n°{order_number}* bien reçue. {order_payment}\n\n"
                    . "Nous activons votre service dès réception du paiement. À très vite !" . $sign,
            ],
            // ------------------------------------------------------------ Support
            'ticket_reply' => [
                'group' => 'Support', 'label' => 'Réponse à un ticket',
                'emails' => ['Support Ticket Reply'], 'rel' => 'ticket',
                'priority' => 8, 'ttl' => 24, 'enabled' => true,
                'message' => "{greeting} {first_name} 💬\n\n"
                    . "Notre équipe a répondu à votre demande *#{ticket_id}* « {subject} ».\n"
                    . "Lire la réponse : {ticket_link}" . $sign,
            ],
            'ticket_opened' => [
                'group' => 'Support', 'label' => 'Ticket bien reçu',
                'emails' => ['Support Ticket Opened'], 'rel' => 'ticket',
                'priority' => 6, 'ttl' => 12, 'enabled' => false,
                'message' => "{greeting} {first_name},\n\n"
                    . "Nous avons bien reçu votre demande *#{ticket_id}* « {subject} ». Notre équipe vous répond au plus vite 🙏\n"
                    . "Suivre la demande : {ticket_link}" . $sign,
            ],
            // ------------------------------------------------------------ Marketing
            'promo' => [
                'group' => 'Marketing', 'label' => 'Avantages clients (suit l\'e-mail promotionnel)',
                'emails' => ['LWM Promotion Avantages Clients'], 'rel' => 'client',
                'priority' => 1, 'ttl' => 72, 'enabled' => false,
                'message' => "{greeting} {first_name} 🎁\n\n"
                    . "Merci d'être client chez {company} ! Deux avantages à connaître :\n"
                    . "• *-{early_percent} %* sur vos renouvellements payés au moins {early_days} jours avant l'échéance ;\n"
                    . "• des avantages exclusifs quand vous nous recommandez à vos proches.\n\n"
                    . "Tous les détails dans votre espace client :\n{client_area}" . $sign,
            ],
            // ------------------------------------------------------------ Équipe
            'team_paid' => [
                'group' => 'Équipe', 'label' => 'Alerte équipe : paiement reçu',
                'emails' => [], 'rel' => 'invoice', 'team' => true,
                'priority' => 9, 'ttl' => 12, 'enabled' => true,
                'message' => "💰 *Paiement reçu* : {total}\n👤 {client_name}\n🧾 Facture n°{invoice_id} · {payment_method}\n{admin_link}",
            ],
            'team_order' => [
                'group' => 'Équipe', 'label' => 'Alerte équipe : nouvelle commande',
                'emails' => [], 'rel' => 'order', 'team' => true,
                'priority' => 9, 'ttl' => 12, 'enabled' => true,
                'message' => "🛒 *Nouvelle commande* n°{order_number} : {order_total}\n👤 {client_name}\n{order_items}\n{admin_link}",
            ],
            'team_ticket_new' => [
                'group' => 'Équipe', 'label' => 'Alerte équipe : nouveau ticket',
                'emails' => [], 'rel' => 'ticket', 'team' => true,
                'priority' => 9, 'ttl' => 12, 'enabled' => true,
                'message' => "🎫 *Nouveau ticket* #{ticket_id} ({department})\n👤 {client_name}\n« {subject} »\n{admin_link}",
            ],
            'team_ticket_reply' => [
                'group' => 'Équipe', 'label' => 'Alerte équipe : réponse d\'un client',
                'emails' => [], 'rel' => 'ticket', 'team' => true,
                'priority' => 9, 'ttl' => 12, 'enabled' => true,
                'message' => "💬 *Réponse client* sur #{ticket_id}\n👤 {client_name}\n« {subject} »\n{admin_link}",
            ],
            'team_cancel' => [
                'group' => 'Équipe', 'label' => 'Alerte équipe : demande de résiliation',
                'emails' => [], 'rel' => 'service', 'team' => true,
                'priority' => 9, 'ttl' => 12, 'enabled' => true,
                'message' => "⚠️ *Demande de résiliation* ({cancel_type})\n👤 {client_name}\n📦 {service} {domain_label}\nMotif : {cancel_reason}\n{admin_link}",
            ],
        ];
        return $defs;
    }

    public static function get($event)
    {
        $defs = self::defaults();
        return $defs[$event] ?? null;
    }

    /** Variables disponibles pour un type d'objet (affichées dans l'onglet Modèles). */
    public static function vars($rel, $team = false)
    {
        $common = ['greeting', 'first_name', 'client_name', 'company', 'client_area', 'payment_methods'];
        $map = [
            'invoice' => ['invoice_id', 'total', 'balance', 'due_date', 'due_when', 'days_left', 'suspend_when', 'pay_link', 'invoice_link', 'early_discount', 'items', 'payment_method'],
            'service' => ['service', 'domain', 'domain_label', 'service_link', 'next_due_date', 'amount', 'pay_link'],
            'domain' => ['domain', 'expiry_date', 'due_when', 'days_left', 'renew_link'],
            'client' => ['early_percent', 'early_days'],
            'order' => ['order_number', 'order_total', 'order_items', 'order_payment', 'welcome_line', 'pay_link'],
            'ticket' => ['ticket_id', 'subject', 'department', 'ticket_link'],
        ];
        $vars = array_merge($common, $map[$rel] ?? []);
        if ($team) {
            $vars[] = 'admin_link';
            if ($rel === 'service') {
                $vars = array_merge($vars, ['cancel_type', 'cancel_reason']);
            }
        }
        return $vars;
    }

    /** Notification client correspondant à un modèle d'e-mail WHMCS, ou null. */
    public static function forEmail($templateName, $templateType)
    {
        foreach (self::defaults() as $key => $def) {
            if (in_array($templateName, $def['emails'], true)) {
                return $key;
            }
        }
        foreach (self::defaults() as $key => $def) {
            if (empty($def['match']) || $def['match']['type'] !== $templateType) {
                continue;
            }
            foreach ($def['match']['name'] as $needle) {
                if (stripos($templateName, $needle) !== false) {
                    return $key;
                }
            }
        }
        return null;
    }

    /**
     * Remplace les {variables}. Gère le spintax {Bonjour|Salut|Hello} : une
     * variante est tirée au hasard pour que deux clients ne reçoivent pas
     * exactement le même texte. Les lignes vidées par une variable optionnelle
     * ne laissent pas de trou. Retourne [texte, valeurs dans l'ordre].
     */
    public static function render($message, array $vars)
    {
        $message = preg_replace_callback('/\{([^{}|]*\|[^{}]*)\}/u', function ($m) {
            $options = explode('|', $m[1]);
            return $options[array_rand($options)];
        }, (string) $message);
        $ordered = [];
        $text = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vars, &$ordered) {
            $val = isset($vars[$m[1]]) ? (string) $vars[$m[1]] : '';
            $ordered[] = $val;
            return $val;
        }, $message);
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return [trim($text), $ordered];
    }

    /**
     * Les bases WHMCS en utf8 (3 octets) ne stockent pas les emojis : ils sont
     * enregistrés sous forme d'entités (&#x1F44B;) et restitués à la lecture.
     */
    public static function dbEncode($text)
    {
        return preg_replace_callback('/[\x{10000}-\x{10FFFF}]/u', function ($m) {
            return '&#x' . strtoupper(dechex(mb_ord($m[0], 'UTF-8'))) . ';';
        }, (string) $text);
    }

    public static function dbDecode($text)
    {
        return preg_replace_callback('/&#x([0-9A-Fa-f]{5,6});/', function ($m) {
            return mb_chr(hexdec($m[1]), 'UTF-8');
        }, (string) $text);
    }
}
