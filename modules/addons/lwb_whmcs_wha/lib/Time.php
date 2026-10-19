<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Heure « métier » (fuseau des clients, ex. Africa/Kinshasa) : heures calmes,
 * salutation, échéances en langage naturel. La base WHMCS reste à l'heure du serveur.
 */
class Time
{
    public static function tz()
    {
        try {
            return new \DateTimeZone(Settings::get('timezone', 'Africa/Kinshasa') ?: 'Africa/Kinshasa');
        } catch (\Exception $e) {
            return new \DateTimeZone(date_default_timezone_get());
        }
    }

    public static function now()
    {
        return new \DateTimeImmutable('now', self::tz());
    }

    public static function hour()
    {
        return (int) self::now()->format('G');
    }

    /** Date du jour dans le fuseau métier (Y-m-d). */
    public static function today()
    {
        return self::now()->format('Y-m-d');
    }

    /** Heure du serveur (format base WHMCS) correspondant à un instant. */
    public static function server(\DateTimeInterface $at)
    {
        return (new \DateTime('@' . $at->getTimestamp()))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /** Affiche une date de la base (heure serveur) dans le fuseau métier. */
    public static function show($serverDateTime, $format = 'd/m/Y H:i')
    {
        if (!$serverDateTime || strpos((string) $serverDateTime, '0000') === 0) {
            return '';
        }
        try {
            return (new \DateTime((string) $serverDateTime))->setTimezone(self::tz())->format($format);
        } catch (\Exception $e) {
            return (string) $serverDateTime;
        }
    }

    /** Plage « 21-8 » : vrai si l'heure donnée (ou actuelle) est dans la plage. */
    public static function inQuietHours($range = null, $hour = null)
    {
        $range = $range === null ? Settings::get('quiet_hours', '21-8') : $range;
        if (!preg_match('/^\s*(\d{1,2})\s*-\s*(\d{1,2})\s*$/', (string) $range, $m)) {
            return false;
        }
        $from = (int) $m[1] % 24;
        $to = (int) $m[2] % 24;
        $h = $hour === null ? self::hour() : (int) $hour;
        if ($from === $to) {
            return false;
        }
        return $from < $to ? ($h >= $from && $h < $to) : ($h >= $from || $h < $to);
    }

    /** Heure de fin des heures calmes, ex. "8 h". */
    public static function quietEnd()
    {
        return preg_match('/-\s*(\d{1,2})\s*$/', Settings::get('quiet_hours', '21-8'), $m) ? ((int) $m[1] % 24) . ' h' : '';
    }

    /** Salutation selon l'heure locale du client. */
    public static function greeting($hour = null)
    {
        $h = $hour === null ? self::hour() : (int) $hour;
        if ($h >= 17 || $h < 4) {
            return 'Bonsoir';
        }
        $options = ['Bonjour', 'Bonjour', 'Hello'];
        return $options[array_rand($options)];
    }

    /** Nombre de jours entre aujourd'hui (fuseau métier) et une date Y-m-d. */
    public static function daysUntil($ymd, $today = null)
    {
        $ymd = substr((string) $ymd, 0, 10);
        if (!$ymd || $ymd === '0000-00-00') {
            return 0;
        }
        $from = new \DateTimeImmutable($today ?: self::today());
        $to = new \DateTimeImmutable($ymd);
        return (int) $from->diff($to)->format('%r%a');
    }

    public static function date($ymd)
    {
        $ymd = substr((string) $ymd, 0, 10);
        return ($ymd && $ymd !== '0000-00-00') ? date('d/m/Y', strtotime($ymd)) : '';
    }

    /** « aujourd'hui », « demain », « dans 3 jours », « hier », « il y a 3 jours », « le 18/11/2026 ». */
    public static function when($daysLeft, $ymd)
    {
        $d = (int) $daysLeft;
        if ($d === 0) {
            return "aujourd'hui";
        }
        if ($d === 1) {
            return 'demain';
        }
        if ($d === -1) {
            return 'hier';
        }
        if ($d > 1 && $d <= 7) {
            return 'dans ' . $d . ' jours';
        }
        if ($d < -1 && $d >= -7) {
            return 'il y a ' . abs($d) . ' jours';
        }
        return 'le ' . self::date($ymd);
    }
}
