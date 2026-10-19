<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Client HTTP de la passerelle Node.js, et maintien en vie sur le même serveur. */
class Gateway
{
    private static $status;

    /**
     * @return array ['ok' => bool, 'code' => int HTTP (0 = injoignable), 'data' => array, 'error' => string, 'response' => string]
     */
    public static function request($method, $path, array $payload = null, $timeout = 120)
    {
        $url = rtrim(Settings::get('gateway_url', 'http://127.0.0.1:3000'), '/');
        if ($url === '') {
            return ['ok' => false, 'code' => 0, 'data' => [], 'error' => 'URL de la passerelle non configurée', 'response' => ''];
        }
        $ch = curl_init($url . $path);
        $opts = [
            CURLOPT_HTTPHEADER => ['x-api-key: ' . Settings::get('gateway_key'), 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload ?: new \stdClass(), JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string) $resp, true) ?: [];
        $ok = $err === '' && !empty($data['ok']);
        return [
            'ok' => $ok,
            'code' => $err !== '' ? 0 : $code,
            'data' => $data,
            'error' => $ok ? '' : ($err ?: ($data['error'] ?? ('Réponse invalide (HTTP ' . $code . ')'))),
            'response' => (string) $resp,
        ];
    }

    /** État de la passerelle (mis en cache pendant la requête PHP). */
    public static function status($refresh = false)
    {
        if ($refresh || self::$status === null) {
            $res = self::request('GET', '/status', null, 10);
            self::$status = $res['ok'] ? $res['data'] : ['status' => 'unreachable', 'error' => $res['error'], 'code' => $res['code']];
        }
        return self::$status;
    }

    public static function isOpen()
    {
        return (self::status()['status'] ?? '') === 'open';
    }

    /** Numéro WhatsApp connecté (chiffres), ou ''. */
    public static function number()
    {
        $id = (string) (self::status()['me']['id'] ?? '');
        return $id === '' ? '' : preg_replace('/[:@].*$/', '', $id);
    }

    /**
     * Passerelle sur le même serveur : la (re)démarre si elle ne répond plus.
     * Appelé à chaque cron WHMCS, ce qui la maintient en vie sans accès SSH.
     */
    public static function keepAlive()
    {
        $dir = rtrim(Settings::get('gateway_dir'), '/');
        $node = trim(Settings::get('gateway_node'));
        if (!Settings::isGateway() || $dir === '' || $node === '' || !is_file($dir . '/server.js') || !function_exists('proc_open')) {
            return;
        }
        $status = self::status();
        // Injoignable uniquement (une clé invalide n'est pas une panne).
        if (($status['status'] ?? '') !== 'unreachable' || (int) ($status['code'] ?? 0) !== 0) {
            return;
        }
        $logFile = $dir . '/gateway.log';
        if (is_file($logFile) && filesize($logFile) > 2 * 1024 * 1024) {
            @rename($logFile, $logFile . '.1');
        }
        // umask 077 : journal et session lisibles par le seul propriétaire.
        $cmd = 'umask 077 && cd ' . escapeshellarg($dir) . ' && nohup setsid ' . escapeshellarg($node)
            . ' --env-file-if-exists=.env server.js >> gateway.log 2>&1 < /dev/null &';
        $p = proc_open(['/bin/sh', '-c', $cmd], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (is_resource($p)) {
            proc_close($p);
            $restarts = (int) State::get('gateway_restarts', 0) + 1;
            State::set('gateway_restarts', $restarts);
            State::set('gateway_last_restart', date('Y-m-d H:i:s'));
            logActivity('LWB WhatsApp : passerelle redémarrée automatiquement.');
        }
    }

    /** Dernières lignes du journal de la passerelle (dépannage). */
    public static function logTail($lines = 30)
    {
        $file = rtrim(Settings::get('gateway_dir'), '/') . '/gateway.log';
        if (!is_file($file) || !is_readable($file)) {
            return '';
        }
        $size = filesize($file);
        $fh = fopen($file, 'r');
        fseek($fh, max(0, $size - 8192));
        $tail = stream_get_contents($fh);
        fclose($fh);
        return implode("\n", array_slice(explode("\n", trim((string) $tail)), -$lines));
    }
}
