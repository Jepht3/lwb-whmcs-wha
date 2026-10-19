<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/** Réglages du module (tbladdonmodules), avec valeurs par défaut. */
class Settings
{
    const MODULE = 'lwb_whmcs_wha';
    const VERSION = '3.2.0';

    private static $cache;

    public static function all($refresh = false)
    {
        if ($refresh || self::$cache === null) {
            self::$cache = Capsule::table('tbladdonmodules')->where('module', self::MODULE)->pluck('value', 'setting')->all();
        }
        return self::$cache;
    }

    /** Remplace les réglages (tests, aperçu). */
    public static function inject(array $settings)
    {
        self::$cache = $settings;
    }

    /** Valeur texte ; la valeur par défaut ne s'applique que si le réglage n'a jamais été enregistré. */
    public static function get($key, $default = '')
    {
        $all = self::all();
        return array_key_exists($key, $all) && $all[$key] !== null ? (string) $all[$key] : $default;
    }

    public static function bool($key, $default = false)
    {
        $all = self::all();
        if (!array_key_exists($key, $all) || $all[$key] === null) {
            return $default;
        }
        return in_array(strtolower(trim((string) $all[$key])), ['on', 'yes', '1', 'true'], true);
    }

    public static function int($key, $default, $min, $max)
    {
        $value = trim(self::get($key, (string) $default));
        $value = $value === '' || !is_numeric($value) ? $default : (int) $value;
        return max($min, min($max, $value));
    }

    public static function isGateway()
    {
        return self::get('provider', 'gateway') === 'gateway';
    }

    /** Numéros de l'équipe pour les alertes internes. */
    public static function teamPhones()
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', self::get('team_phones')) as $raw) {
            $phone = Phone::normalize($raw);
            if ($phone !== '') {
                $out[$phone] = $phone;
            }
        }
        return array_values($out);
    }
}
