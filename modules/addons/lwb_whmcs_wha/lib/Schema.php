<?php

namespace LwbWha;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/** Installation / mise à jour des tables, champs personnalisés, migration depuis whatsapp_notify. */
class Schema
{
    public static function install()
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(Queue::TABLE)) {
            $schema->create(Queue::TABLE, function ($t) {
                $t->increments('id');
                $t->integer('client_id')->default(0)->index();
                $t->string('event', 50);
                $t->string('rel_type', 20)->default('');
                $t->integer('rel_id')->default(0);
                $t->string('phone', 30)->index();
                $t->text('message');
                $t->string('attachment', 50)->nullable();
                $t->tinyInteger('priority')->default(5);
                $t->string('hash', 40)->index();
                $t->string('source', 10)->default('auto');
                $t->string('status', 20)->default('pending');
                $t->integer('attempts')->default(0);
                $t->dateTime('next_try');
                $t->dateTime('created_at');
                $t->dateTime('sent_at')->nullable();
                $t->string('wa_id', 64)->nullable()->index();
                $t->string('delivery', 12)->nullable();
                $t->dateTime('delivery_at')->nullable();
                $t->text('error')->nullable();
                $t->index(['status', 'next_try']);
                $t->index(['rel_type', 'rel_id']);
            });
        }
        if (!$schema->hasTable('mod_lwbwha_templates')) {
            $schema->create('mod_lwbwha_templates', function ($t) {
                $t->string('event', 50)->primary();
                $t->boolean('enabled')->default(0);
                $t->text('message');
                $t->string('days', 50)->default('');
                $t->boolean('attach_pdf')->default(0);
                $t->string('cloud_template', 100)->default('');
                $t->string('cloud_lang', 10)->default('fr');
            });
        }
        if (!$schema->hasTable(Links::TABLE)) {
            $schema->create(Links::TABLE, function ($t) {
                $t->string('code', 16)->primary();
                $t->integer('message_id')->default(0)->index();
                $t->string('kind', 10);
                $t->integer('rel_id')->default(0);
                $t->text('target')->nullable();
                $t->integer('clicks')->default(0);
                $t->dateTime('first_click')->nullable();
                $t->dateTime('last_click')->nullable();
                $t->dateTime('created_at');
            });
        }
        if (!$schema->hasTable('mod_lwbwha_optout')) {
            $schema->create('mod_lwbwha_optout', function ($t) {
                $t->string('phone', 30)->primary();
                $t->integer('client_id')->default(0);
                $t->string('source', 10)->default('whatsapp');
                $t->dateTime('created_at');
            });
        }
        if (!$schema->hasTable(State::TABLE)) {
            $schema->create(State::TABLE, function ($t) {
                $t->string('k', 50)->primary();
                $t->text('v')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        self::seedTemplates();
        self::customFields();
        self::migrateFromWhatsappNotify();
    }

    /** Ajoute les modèles manquants avec leurs valeurs par défaut (les textes modifiés sont conservés). */
    public static function seedTemplates()
    {
        foreach (Events::defaults() as $event => $def) {
            if (!Capsule::table('mod_lwbwha_templates')->where('event', $event)->exists()) {
                Capsule::table('mod_lwbwha_templates')->insert([
                    'event' => $event,
                    'enabled' => empty($def['enabled']) ? 0 : 1,
                    'message' => Events::dbEncode($def['message']),
                    'days' => $def['days'] ?? '',
                    'attach_pdf' => empty($def['pdf']) ? 0 : 1,
                    'cloud_template' => '',
                    'cloud_lang' => 'fr',
                ]);
            }
        }
    }

    /** Champs du profil client : numéro WhatsApp (si différent) et refus des notifications. */
    public static function customFields()
    {
        $fields = [
            [Phone::FIELD_NUMBER, 'text', 'Si différent de votre téléphone, au format international (ex. +243 812 345 678)'],
            [Phone::FIELD_OPTOUT, 'tickbox', 'Cochez pour ne plus recevoir nos notifications sur WhatsApp'],
        ];
        $columns = Capsule::schema()->getColumnListing('tblcustomfields');
        foreach ($fields as $i => list($name, $type, $description)) {
            if (Capsule::table('tblcustomfields')->where('type', 'client')->where('fieldname', $name)->exists()) {
                continue;
            }
            $row = [
                'type' => 'client', 'relid' => 0, 'fieldname' => $name, 'fieldtype' => $type,
                'description' => $description, 'fieldoptions' => '', 'regexpr' => '', 'adminonly' => '',
                'required' => '', 'showorder' => '', 'showinvoice' => '', 'sortorder' => 90 + $i,
            ];
            if (in_array('created_at', $columns, true)) {
                $row['created_at'] = $row['updated_at'] = date('Y-m-d H:i:s');
            }
            Capsule::table('tblcustomfields')->insert(array_intersect_key($row, array_flip($columns)));
        }
    }

    /** Reprise des modèles, du journal et des réglages de l'ancien module whatsapp_notify. */
    public static function migrateFromWhatsappNotify()
    {
        $schema = Capsule::schema();
        if (State::get('migrated_whatsapp_notify') || !$schema->hasTable('mod_whatsapp_templates')) {
            return;
        }
        foreach (Capsule::table('mod_whatsapp_templates')->get() as $old) {
            if (Events::get($old->event)) {
                $update = ['enabled' => (int) $old->enabled];
                if (isset($old->days)) {
                    $update['days'] = (string) $old->days;
                }
                if (isset($old->attach_pdf)) {
                    $update['attach_pdf'] = (int) $old->attach_pdf;
                }
                Capsule::table('mod_lwbwha_templates')->where('event', $old->event)->update($update);
            }
        }
        if ($schema->hasTable('mod_whatsapp_logs')) {
            foreach (Capsule::table('mod_whatsapp_logs')->orderBy('id')->get() as $log) {
                Capsule::table(Queue::TABLE)->insert([
                    'client_id' => (int) $log->client_id,
                    'event' => $log->event,
                    'phone' => (string) $log->phone,
                    'message' => (string) $log->message,
                    'hash' => sha1('legacy|' . $log->id),
                    'source' => $log->event === 'manual' ? 'manual' : 'auto',
                    'status' => in_array($log->status, ['sent', 'failed', 'optout'], true) ? $log->status : 'failed',
                    'next_try' => $log->created_at,
                    'created_at' => $log->created_at,
                    'sent_at' => $log->status === 'sent' ? $log->created_at : null,
                    'error' => $log->status === 'sent' ? null : $log->response,
                ]);
            }
        }
        $old = Capsule::table('tbladdonmodules')->where('module', 'whatsapp_notify')->pluck('value', 'setting')->all();
        if ($old && !Capsule::table('tbladdonmodules')->where('module', Settings::MODULE)->where('setting', 'provider')->exists()) {
            foreach (['provider', 'gateway_url', 'gateway_key', 'gateway_dir', 'gateway_node', 'optout_footer', 'quiet_hours', 'batch_size',
                'default_country_code', 'sent_api_key', 'sent_sandbox', 'cloud_token', 'cloud_phone_id', 'custom_url', 'custom_headers', 'custom_body'] as $key) {
                if (array_key_exists($key, $old)) {
                    Capsule::table('tbladdonmodules')->updateOrInsert(['module' => Settings::MODULE, 'setting' => $key], ['value' => $old[$key]]);
                }
            }
            // Les anciennes heures calmes étaient exprimées à l'heure du serveur.
            Capsule::table('tbladdonmodules')->updateOrInsert(['module' => Settings::MODULE, 'setting' => 'timezone'], ['value' => date_default_timezone_get()]);
            Settings::all(true);
        }
        State::set('migrated_whatsapp_notify', date('Y-m-d H:i:s'));
    }
}
