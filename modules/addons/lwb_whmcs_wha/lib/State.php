<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/** Petit stockage clé / valeur pour l'état d'exécution (alertes, synchronisations…). */
class State
{
    const TABLE = 'mod_lwbwha_state';

    public static function get($key, $default = null)
    {
        $value = Capsule::table(self::TABLE)->where('k', $key)->value('v');
        return $value === null ? $default : $value;
    }

    public static function set($key, $value)
    {
        Capsule::table(self::TABLE)->updateOrInsert(['k' => $key], ['v' => (string) $value, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public static function delete($key)
    {
        Capsule::table(self::TABLE)->where('k', $key)->delete();
    }

    /** Vrai au plus une fois par période (secondes) pour une clé donnée. */
    public static function every($key, $seconds)
    {
        $last = (int) self::get($key, 0);
        if (time() - $last < $seconds) {
            return false;
        }
        self::set($key, time());
        return true;
    }
}
