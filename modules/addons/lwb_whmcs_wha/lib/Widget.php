<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Widget « WhatsApp » du tableau de bord d'administration WHMCS. */
class Widget extends \WHMCS\Module\AbstractWidget
{
    protected $title = 'WhatsApp';
    protected $description = 'État de la connexion WhatsApp et des envois (LWB WhatsApp)';
    protected $weight = 150;
    protected $columns = 1;
    protected $cache = false;
    protected $requiredPermission = '';

    public function getData()
    {
        return [];
    }

    public function generateOutput($data)
    {
        try {
            return Admin::widget();
        } catch (\Throwable $e) {
            return '<div class="widget-content-padded">LWB WhatsApp : ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}
