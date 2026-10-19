<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Envoi effectif d'un message selon le fournisseur choisi.
 * La passerelle auto-hébergée est le mode principal ; WhatsApp Cloud API,
 * Sent.dm et une passerelle HTTP générique restent disponibles en secours
 * (par exemple si le numéro venait à être banni).
 */
class Sender
{
    /**
     * @param array|null $template ['cloud_template' => …, 'cloud_lang' => …] pour Cloud API / Sent.dm
     * @param array      $ordered  valeurs des variables dans l'ordre (Cloud API)
     * @param array      $named    valeurs nommées (Sent.dm)
     * @return array ['ok', 'code', 'error', 'response', 'data']
     */
    public static function send($phone, $text, array $document = null, $template = null, array $ordered = [], array $named = [])
    {
        $provider = Settings::get('provider', 'gateway');
        if ($provider === 'gateway') {
            $payload = ['phone' => $phone, 'message' => $text];
            if ($document) {
                $payload['document'] = $document;
            }
            return Gateway::request('POST', '/send', $payload);
        }
        if ($provider === 'cloud') {
            if ($template && !empty($template->cloud_template)) {
                $components = [];
                if ($ordered) {
                    $components[] = ['type' => 'body', 'parameters' => array_map(function ($p) {
                        return ['type' => 'text', 'text' => $p === '' ? '-' : $p];
                    }, $ordered)];
                }
                return self::cloud([
                    'messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'template',
                    'template' => ['name' => $template->cloud_template, 'language' => ['code' => $template->cloud_lang ?: 'fr'], 'components' => $components],
                ]);
            }
            // Texte libre : uniquement dans la fenêtre de 24 h après un message du client.
            return self::cloud(['messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'text', 'text' => ['body' => $text]]);
        }
        if ($provider === 'sent') {
            $id = $template ? (string) $template->cloud_template : '';
            if ($id === '') {
                return self::fail('Sent.dm exige un ID de modèle (onglet Modèles)');
            }
            $body = ['to' => ['+' . ltrim($phone, '+')], 'template' => ['id' => $id] + ($named ? ['parameters' => $named] : []), 'channel' => ['whatsapp']];
            if (Settings::bool('sent_sandbox')) {
                $body['sandbox'] = true;
            }
            return self::http('https://api.sent.dm/v3/messages', json_encode($body), ['x-api-key: ' . Settings::get('sent_api_key'), 'Content-Type: application/json']);
        }
        // Passerelle HTTP générique (Evolution API, UltraMsg…).
        $body = str_replace(
            ['{phone}', '{message}'],
            [$phone, trim(json_encode($text, JSON_UNESCAPED_UNICODE), '"')],
            Settings::get('custom_body', '{"number":"{phone}","text":"{message}"}')
        );
        $headers = ['Content-Type: application/json'];
        foreach (preg_split('/\r?\n/', Settings::get('custom_headers')) as $h) {
            if (trim($h) !== '') {
                $headers[] = trim($h);
            }
        }
        return self::http(Settings::get('custom_url'), $body, $headers);
    }

    private static function cloud(array $payload)
    {
        $url = 'https://graph.facebook.com/v21.0/' . rawurlencode(Settings::get('cloud_phone_id')) . '/messages';
        return self::http($url, json_encode($payload), ['Authorization: Bearer ' . Settings::get('cloud_token'), 'Content-Type: application/json']);
    }

    private static function fail($error)
    {
        return ['ok' => false, 'code' => 400, 'error' => $error, 'response' => '', 'data' => []];
    }

    private static function http($url, $body, array $headers)
    {
        if ($url === '') {
            return self::fail('URL non configurée');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ok = $err === '' && $code >= 200 && $code < 300;
        return ['ok' => $ok, 'code' => $err !== '' ? 0 : $code, 'error' => $ok ? '' : ($err ?: 'HTTP ' . $code . ' ' . $resp), 'response' => (string) $resp, 'data' => json_decode((string) $resp, true) ?: []];
    }

    /** Facture PDF générée au moment de l'envoi (état à jour). */
    public static function invoicePdf($invoiceId)
    {
        try {
            if (!function_exists('pdfInvoice')) {
                require_once ROOTDIR . '/includes/invoicefunctions.php';
            }
            $pdf = pdfInvoice((int) $invoiceId);
            if (!$pdf) {
                return null;
            }
            $num = Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->value('invoicenum') ?: $invoiceId;
            return [
                'base64' => base64_encode($pdf),
                'filename' => 'Facture-' . preg_replace('/[^\w-]+/', '-', (string) $num) . '.pdf',
                'mimetype' => 'application/pdf',
            ];
        } catch (\Throwable $e) {
            logActivity('LWB WhatsApp : PDF de la facture #' . (int) $invoiceId . ' impossible : ' . $e->getMessage());
            return null;
        }
    }
}
