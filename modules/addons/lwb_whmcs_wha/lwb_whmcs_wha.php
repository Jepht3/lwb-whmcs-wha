<?php
/**
 * lwb-whmcs-wha — notifications WhatsApp pour WHMCS, sans API officielle.
 *
 * Envoie sur WhatsApp, en plus des e-mails WHMCS : factures (PDF + lien de
 * paiement), relances, confirmations de paiement, activations, domaines,
 * réponses aux tickets, et des alertes internes pour l'équipe. Passe par une
 * passerelle WhatsApp Web auto-hébergée (dossier gateway/ du dépôt).
 *
 * @see https://github.com/Jepht3/lwb-whmcs-wha
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/bootstrap.php';

function lwb_whmcs_wha_config()
{
    return [
        'name' => 'LWB WhatsApp (lwb-whmcs-wha)',
        'description' => 'Notifications WhatsApp sans API officielle : factures avec PDF et lien de paiement, relances, paiements, activations, domaines, tickets, alertes équipe, boîte de réception. Anti-bannissement intégré.',
        'version' => \LwbWha\Settings::VERSION,
        'author' => 'LE WEB MAX',
        'language' => 'french',
        'fields' => [
            'provider' => [
                'FriendlyName' => 'Fournisseur',
                'Type' => 'dropdown',
                'Options' => [
                    'gateway' => 'Passerelle WhatsApp Web auto-hébergée (recommandé)',
                    'cloud' => 'Secours : WhatsApp Cloud API (Meta, officiel)',
                    'sent' => 'Secours : Sent.dm',
                    'custom' => 'Secours : passerelle HTTP générique',
                ],
                'Default' => 'gateway',
            ],
            'gateway_url' => [
                'FriendlyName' => 'Passerelle : URL',
                'Type' => 'text',
                'Size' => '40',
                'Default' => 'http://127.0.0.1:3000',
                'Description' => 'Adresse du serveur Node.js (dossier gateway/)',
            ],
            'gateway_key' => [
                'FriendlyName' => 'Passerelle : clé API',
                // Texte et non « password » : le module lit ses réglages directement en base.
                'Type' => 'text',
                'Size' => '60',
                'Description' => 'Même valeur que API_KEY dans le fichier .env de la passerelle',
            ],
            'gateway_dir' => [
                'FriendlyName' => 'Passerelle : dossier (même serveur)',
                'Type' => 'text',
                'Size' => '60',
                'Description' => 'Ex. /home/utilisateur/whatsapp-gateway — avec le binaire Node.js, le cron WHMCS redémarre la passerelle si elle s\'arrête',
            ],
            'gateway_node' => [
                'FriendlyName' => 'Passerelle : binaire Node.js',
                'Type' => 'text',
                'Size' => '60',
                'Description' => 'Ex. /usr/bin/node ou /opt/alt/alt-nodejs22/root/usr/bin/node',
            ],
            'timezone' => [
                'FriendlyName' => 'Fuseau horaire des clients',
                'Type' => 'text',
                'Size' => '25',
                'Default' => 'Africa/Kinshasa',
                'Description' => 'Pour les heures calmes et les salutations (Bonjour / Bonsoir)',
            ],
            'quiet_hours' => [
                'FriendlyName' => 'Anti-ban : heures calmes',
                'Type' => 'text',
                'Size' => '8',
                'Default' => '21-8',
                'Description' => 'Aucun envoi automatique entre ces heures (fuseau ci-dessus). Vide = jamais',
            ],
            'batch_size' => [
                'FriendlyName' => 'Anti-ban : messages par cron',
                'Type' => 'text',
                'Size' => '4',
                'Default' => '5',
                'Description' => 'Maximum envoyé à chaque passage du cron WHMCS (1 à 20)',
            ],
            'min_gap' => [
                'FriendlyName' => 'Anti-ban : espacement par client (min)',
                'Type' => 'text',
                'Size' => '4',
                'Default' => '20',
                'Description' => 'Délai minimal entre deux messages au même client',
            ],
            'max_per_client_day' => [
                'FriendlyName' => 'Anti-ban : messages max par client et par jour',
                'Type' => 'text',
                'Size' => '4',
                'Default' => '3',
            ],
            'broadcast_per_run' => [
                'FriendlyName' => 'Annonces : messages par cron',
                'Type' => 'text',
                'Size' => '4',
                'Default' => '3',
                'Description' => 'Messages d\'annonce envoyés à chaque passage du cron (1 à 10), espacés de 15 à 35 secondes',
            ],
            'optout_footer' => [
                'FriendlyName' => 'Anti-ban : pied de message',
                'Type' => 'text',
                'Size' => '60',
                'Default' => 'Répondez STOP pour ne plus recevoir ces messages.',
                'Description' => 'Ajouté aux notifications client. Fortement conseillé',
            ],
            'short_links' => [
                'FriendlyName' => 'Liens courts suivis',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Liens sur votre domaine, toujours valables, avec comptage des clics',
            ],
            'payment_methods' => [
                'FriendlyName' => 'Moyens de paiement (texte)',
                'Type' => 'text',
                'Size' => '60',
                'Default' => 'Mobile Money (M-Pesa, Orange Money, Airtel Money) ou carte bancaire',
                'Description' => 'Variable {payment_methods} des modèles',
            ],
            'team_phones' => [
                'FriendlyName' => 'Numéros de l\'équipe',
                'Type' => 'text',
                'Size' => '60',
                'Description' => 'Alertes internes (paiement, commande, ticket, résiliation). Séparés par des virgules, ex. +243812345678',
            ],
            'inbox_tickets' => [
                'FriendlyName' => 'Boîte de réception : tickets',
                'Type' => 'yesno',
                'Description' => 'Ajoute les messages WhatsApp d\'un client à son ticket ouvert, ou lui ouvre un ticket (sans e-mail au client)',
            ],
            'inbox_department' => [
                'FriendlyName' => 'Boîte de réception : département',
                'Type' => 'dropdown',
                'Options' => \LwbWha\Inbox::departments(),
                'Description' => 'Département des tickets ouverts depuis WhatsApp',
            ],
            'alert_email' => [
                'FriendlyName' => 'Alerte e-mail si déconnecté',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Prévient les administrateurs si WhatsApp reste déconnecté plus de 15 minutes',
            ],
            'client_button' => [
                'FriendlyName' => 'Bouton WhatsApp dans l\'espace client',
                'Type' => 'yesno',
                'Description' => 'Bouton flottant « Écrivez-nous sur WhatsApp » vers le numéro connecté',
            ],
            'default_country_code' => [
                'FriendlyName' => 'Indicatif par défaut',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '243',
                'Description' => 'Pour les numéros saisis sans indicatif (sans +)',
            ],
            'retention_days' => [
                'FriendlyName' => 'Conservation de l\'historique (jours)',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '180',
            ],
            'cloud_token' => [
                'FriendlyName' => 'Secours Cloud API : jeton',
                'Type' => 'password',
                'Size' => '60',
            ],
            'cloud_phone_id' => [
                'FriendlyName' => 'Secours Cloud API : Phone Number ID',
                'Type' => 'text',
                'Size' => '30',
            ],
            'sent_api_key' => [
                'FriendlyName' => 'Secours Sent.dm : clé API',
                'Type' => 'password',
                'Size' => '60',
            ],
            'sent_sandbox' => [
                'FriendlyName' => 'Secours Sent.dm : sandbox',
                'Type' => 'yesno',
            ],
            'custom_url' => [
                'FriendlyName' => 'Secours HTTP : URL',
                'Type' => 'text',
                'Size' => '60',
            ],
            'custom_headers' => [
                'FriendlyName' => 'Secours HTTP : en-têtes',
                'Type' => 'textarea',
                'Rows' => '2',
                'Description' => 'Un par ligne, ex. apikey: XXXX',
            ],
            'custom_body' => [
                'FriendlyName' => 'Secours HTTP : corps JSON',
                'Type' => 'textarea',
                'Rows' => '2',
                'Default' => '{"number":"{phone}","text":"{message}"}',
            ],
        ],
    ];
}

function lwb_whmcs_wha_activate()
{
    try {
        \LwbWha\Schema::install();
        return ['status' => 'success', 'description' => 'LWB WhatsApp activé. Ouvrez Addons > LWB WhatsApp pour relier votre numéro.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Erreur : ' . $e->getMessage()];
    }
}

function lwb_whmcs_wha_deactivate()
{
    // Les tables sont conservées (modèles, historique, désinscriptions).
    return ['status' => 'success', 'description' => 'Module désactivé (données conservées).'];
}

function lwb_whmcs_wha_upgrade($vars)
{
    \LwbWha\Schema::install();
}

function lwb_whmcs_wha_output($vars)
{
    \LwbWha\Admin::render($vars);
}

/** Liens courts publics : index.php?m=lwb_whmcs_wha&c=CODE */
function lwb_whmcs_wha_clientarea($vars)
{
    \LwbWha\Links::redirect();
}
