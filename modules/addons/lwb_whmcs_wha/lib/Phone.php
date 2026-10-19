<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/** Numéros de téléphone : normalisation, numéro WhatsApp du client, consentement. */
class Phone
{
    const FIELD_NUMBER = 'Numéro WhatsApp';
    const FIELD_OPTOUT = 'Pas de notifications WhatsApp';

    /**
     * Normalise un numéro WHMCS ("+243.812 345 678", "0812345678", "00243…")
     * en chiffres internationaux (243812345678). Chaîne vide si inexploitable.
     */
    public static function normalize($raw, $country = '', $defaultCode = null)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        $international = strpos($raw, '+') === 0 || strpos($raw, '00') === 0;
        $digits = preg_replace('/\D+/', '', $raw);
        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }
        if ($international) {
            return strlen($digits) >= 8 ? $digits : '';
        }
        $code = self::callingCode($country);
        if ($code === '') {
            $code = preg_replace('/\D+/', '', $defaultCode === null ? Settings::get('default_country_code', '243') : $defaultCode);
        }
        $local = ltrim($digits, '0');
        if (strlen($local) < 6) {
            return '';
        }
        // Numéro déjà saisi avec l'indicatif mais sans « + ».
        if ($code !== '' && strpos($digits, $code) === 0 && strlen($digits) >= strlen($code) + 8) {
            return $digits;
        }
        $full = $code . $local;
        return strlen($full) >= 8 ? $full : '';
    }

    /** Indicatif d'un pays (code ISO) d'après les données pays de WHMCS. */
    public static function callingCode($country)
    {
        static $codes;
        $country = strtoupper(trim((string) $country));
        if ($country === '' || !defined('ROOTDIR')) {
            return '';
        }
        if ($codes === null) {
            $codes = [];
            foreach (['countries.json', 'dist.countries.json'] as $file) {
                $path = ROOTDIR . '/resources/country/' . $file;
                if (is_file($path)) {
                    $data = json_decode((string) file_get_contents($path), true);
                    foreach ((array) $data as $iso => $info) {
                        if (!isset($codes[$iso]) && !empty($info['callingCode'])) {
                            $codes[$iso] = (string) $info['callingCode'];
                        }
                    }
                }
            }
        }
        return isset($codes[$country]) ? preg_replace('/\D+/', '', $codes[$country]) : '';
    }

    /** Identifiant d'un champ personnalisé client par son nom (0 si absent). */
    public static function fieldId($name)
    {
        static $ids = [];
        if (!array_key_exists($name, $ids)) {
            $ids[$name] = (int) Capsule::table('tblcustomfields')->where('type', 'client')->where('fieldname', $name)->value('id');
        }
        return $ids[$name];
    }

    private static function fieldValue($name, $clientId)
    {
        $id = self::fieldId($name);
        return $id ? trim((string) Capsule::table('tblcustomfieldsvalues')->where('fieldid', $id)->where('relid', $clientId)->value('value')) : '';
    }

    /**
     * Numéro WhatsApp d'un client et consentement.
     *
     * @return array ['phone' => '243…', 'blocked' => raison ou '']
     */
    public static function forClient($client)
    {
        if (!is_object($client)) {
            $client = Capsule::table('tblclients')->where('id', (int) $client)->first();
        }
        if (!$client) {
            return ['phone' => '', 'blocked' => 'client introuvable'];
        }
        $custom = self::fieldValue(self::FIELD_NUMBER, $client->id);
        $phone = self::normalize($custom !== '' ? $custom : $client->phonenumber, $client->country);
        $blocked = '';
        if ($phone === '') {
            $blocked = 'aucun numéro de téléphone';
        } elseif (in_array(strtolower(self::fieldValue(self::FIELD_OPTOUT, $client->id)), ['on', 'yes', '1'], true)) {
            $blocked = 'le client a désactivé WhatsApp dans son profil';
        } elseif (self::isOptedOut($phone)) {
            $blocked = 'le client a répondu STOP';
        }
        return ['phone' => $phone, 'blocked' => $blocked];
    }

    public static function isOptedOut($phone)
    {
        return $phone !== '' && Capsule::table('mod_lwbwha_optout')->where('phone', $phone)->exists();
    }
}
