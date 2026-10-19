<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Liens courts suivis, sur votre propre domaine WHMCS :
 *   https://votre-whmcs/index.php?m=lwb_whmcs_wha&c=Ab3xK9pQ2z
 *
 * - plus courts et plus propres qu'un lien de paiement signé (moins « spam ») ;
 * - toujours valables : le lien de paiement est recalculé au moment du clic
 *   (une facture déjà payée renvoie vers la facture au lieu du paiement) ;
 * - chaque clic est compté (statistiques : qui a ouvert le lien de paiement).
 */
class Links
{
    const TABLE = 'mod_lwbwha_links';

    public static function enabled()
    {
        return Settings::bool('short_links', true);
    }

    public static function url($code)
    {
        return Context::systemUrl() . 'index.php?m=' . Settings::MODULE . '&c=' . $code;
    }

    /** Crée un lien court (ou renvoie l'URL d'origine si la fonction est désactivée). */
    public static function make($kind, $relId, $target, $messageId = 0)
    {
        if (!self::enabled() || !$messageId) {
            return $target;
        }
        $existing = Capsule::table(self::TABLE)->where('message_id', $messageId)->where('kind', $kind)->where('rel_id', (int) $relId)
            ->where('target', $kind === 'pay' ? null : $target)->value('code');
        if ($existing) {
            return self::url($existing);
        }
        for ($i = 0; $i < 5; $i++) {
            $code = self::code();
            try {
                Capsule::table(self::TABLE)->insert([
                    'code' => $code,
                    'message_id' => (int) $messageId,
                    'kind' => $kind,
                    'rel_id' => (int) $relId,
                    'target' => $kind === 'pay' ? null : $target,
                    'clicks' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                return self::url($code);
            } catch (\Exception $e) {
                // Collision de code (très improbable) : on réessaie.
            }
        }
        return $target;
    }

    private static function code($length = 10)
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    /** Destination d'un code (compte le clic). */
    public static function resolve($code)
    {
        $fallback = Context::systemUrl() . 'clientarea.php';
        if (!preg_match('/^[A-Za-z0-9]{6,16}$/', (string) $code)) {
            return $fallback;
        }
        $link = Capsule::table(self::TABLE)->where('code', $code)->first();
        if (!$link) {
            return $fallback;
        }
        $now = date('Y-m-d H:i:s');
        Capsule::table(self::TABLE)->where('code', $code)->update([
            'clicks' => Capsule::raw('clicks + 1'),
            'first_click' => $link->first_click ?: $now,
            'last_click' => $now,
        ]);
        if ($link->kind === 'pay') {
            $status = Capsule::table('tblinvoices')->where('id', $link->rel_id)->value('status');
            return $status === 'Unpaid' ? Context::payUrl($link->rel_id) : Context::systemUrl() . 'viewinvoice.php?id=' . (int) $link->rel_id;
        }
        $target = (string) $link->target;
        // Uniquement des destinations sur ce WHMCS.
        return strpos($target, Context::systemUrl()) === 0 ? $target : $fallback;
    }

    /** Point d'entrée public : index.php?m=lwb_whmcs_wha&c=CODE */
    public static function redirect()
    {
        $url = self::resolve($_GET['c'] ?? '');
        header('Cache-Control: no-store');
        header('Location: ' . $url, true, 302);
        exit;
    }
}
