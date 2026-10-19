/**
 * lwb-whmcs-wha — passerelle WhatsApp Web auto-hébergée pour WHMCS.
 *
 * Se connecte à WhatsApp comme un « appareil lié » (mécanisme de WhatsApp Web)
 * grâce à la bibliothèque open source Baileys, et expose une petite API HTTP
 * protégée par clé, appelée par le module WHMCS (modules/addons/lwb_whmcs_wha).
 *
 *   GET  /status     état, QR code, quotas, limites WhatsApp, version
 *   POST /send       {"phone":"243812345678","message":"...","document":{...}}
 *   POST /statuses   {"ids":["3EB0..."]}  -> distribué / lu
 *   GET  /optouts    clients ayant répondu STOP
 *   POST /optouts    {"phone":"...","action":"add"|"remove"}
 *   POST /pair       {"phone":"..."} -> code de liaison à 8 caractères
 *   POST /reconnect  relance la connexion WhatsApp
 *   POST /logout     délie l'appareil (nouveau QR code)
 *
 * Protections anti-bannissement :
 *   - file d'envoi unique, délai aléatoire, simulation « en train d'écrire »
 *   - quotas par heure / jour, réduits pendant le rodage d'un nouveau numéro
 *   - plafond de NOUVEAUX contacts par jour + respect des limites renvoyées par
 *     WhatsApp lui-même (plafond de nouvelles discussions, restriction temporaire)
 *   - vérification que le destinataire a WhatsApp (résultat mis en cache)
 *   - désinscription STOP / réinscription START, lecture des messages reçus
 *   - renvoi des messages à la demande du destinataire (getMessage) : évite le
 *     « En attente de ce message » côté client
 *   - reconnexion progressive, arrêt des tentatives en cas de bannissement
 */

import http from 'node:http';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import pino from 'pino';
import QRCode from 'qrcode';
import makeWASocket, {
    Browsers,
    DisconnectReason,
    fetchLatestBaileysVersion,
    isJidBroadcast,
    isJidGroup,
    isJidNewsletter,
    isJidStatusBroadcast,
    isPnUser,
    proto,
    useMultiFileAuthState,
} from 'baileys';

const VERSION = JSON.parse(fs.readFileSync(new URL('./package.json', import.meta.url), 'utf8')).version;

const env = (name, def) => (process.env[name] !== undefined && process.env[name] !== '' ? process.env[name] : def);
const int = (name, def) => {
    const v = parseInt(env(name, String(def)), 10);
    return Number.isFinite(v) ? v : def;
};
const words = (name, def) => env(name, def).split(',').map((w) => w.trim().toUpperCase()).filter(Boolean);

const PORT = int('PORT', 3000);
const HOST = env('HOST', '127.0.0.1');
const API_KEY = env('API_KEY', '');
const DATA_DIR = path.resolve(env('DATA_DIR', './data'));
const AUTH_DIR = path.join(DATA_DIR, 'auth');
const MIN_DELAY_MS = int('MIN_DELAY_MS', 8000);
const MAX_DELAY_MS = int('MAX_DELAY_MS', 20000);
const MAX_PER_HOUR = int('MAX_PER_HOUR', 30);
const MAX_PER_DAY = int('MAX_PER_DAY', 150);
const WARMUP_DAYS = int('WARMUP_DAYS', 7);
const WARMUP_MAX_PER_DAY = int('WARMUP_MAX_PER_DAY', 30);
const MAX_NEW_PER_DAY = int('MAX_NEW_PER_DAY', 20);
const WARMUP_MAX_NEW_PER_DAY = int('WARMUP_MAX_NEW_PER_DAY', 10);
const READ_INCOMING = env('READ_INCOMING', 'true') === 'true';
const OPTOUT_WORDS = words('OPTOUT_WORDS', 'STOP,ARRET,ARRÊT,DESABONNER,DÉSABONNER,UNSUBSCRIBE');
const OPTIN_WORDS = words('OPTIN_WORDS', 'START,REPRENDRE');
const OPTOUT_REPLY = env('OPTOUT_REPLY', 'Vous ne recevrez plus nos notifications WhatsApp. Répondez START pour les réactiver.');
const OPTIN_REPLY = env('OPTIN_REPLY', 'Notifications WhatsApp réactivées. Merci !');

const MINUTE = 60 * 1000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

if (API_KEY.length < 16) {
    console.error('API_KEY manquante ou trop courte (16 caractères minimum) : renseignez-la dans le fichier .env');
    process.exit(1);
}

fs.mkdirSync(DATA_DIR, { recursive: true });
const logger = pino({ level: env('LOG_LEVEL', 'warn') });
const log = (...args) => console.log(new Date().toISOString(), ...args);

// --- Instance unique -----------------------------------------------------

const PID_FILE = path.join(DATA_DIR, 'gateway.pid');

function otherInstanceRunning() {
    let pid;
    try {
        pid = parseInt(fs.readFileSync(PID_FILE, 'utf8'), 10);
    } catch {
        return false;
    }
    if (!pid || pid === process.pid) {
        return false;
    }
    try {
        process.kill(pid, 0);
    } catch {
        return false;
    }
    // Sous Linux, vérifie qu'il s'agit bien de la passerelle (PID recyclé sinon).
    try {
        return fs.readFileSync(`/proc/${pid}/cmdline`, 'utf8').includes('server.js');
    } catch {
        return true;
    }
}

if (otherInstanceRunning()) {
    log('Une autre instance de la passerelle tourne déjà : arrêt.');
    process.exit(0);
}

// --- Stockage JSON persistant (écritures groupées) -------------------------

class Store {
    constructor(file, def) {
        this.file = path.join(DATA_DIR, file);
        this.timer = null;
        try {
            this.data = JSON.parse(fs.readFileSync(this.file, 'utf8'));
        } catch {
            this.data = def;
        }
    }

    save() {
        if (!this.timer) {
            this.timer = setTimeout(() => this.flush(), 2000);
        }
    }

    flush() {
        clearTimeout(this.timer);
        this.timer = null;
        const tmp = this.file + '.tmp';
        fs.writeFileSync(tmp, JSON.stringify(this.data));
        fs.renameSync(tmp, this.file);
    }
}

// stats    : { linkedAt, number, sent: [ms], newSent: [ms] }
const stats = new Store('stats.json', { linkedAt: null, number: null, sent: [], newSent: [] });
stats.data.newSent = stats.data.newSent || [];
// optout   : { "243812345678": "2026-10-19T10:00:00.000Z" }
const optout = new Store('optout.json', {});
// contacts : { phone: { jid, exists, checked, lastOut, lastIn } }
const contacts = new Store('contacts.json', {});
// statuses : { messageId: [statut, ms] }  (2 serveur, 3 distribué, 4 lu, 5 écouté)
const statuses = new Store('statuses.json', {});
// msgstore : { messageId: [proto base64, ms] }  pour les renvois (getMessage)
const msgstore = new Store('msgstore.json', {});
const stores = [stats, optout, contacts, statuses, msgstore];

function storeMessage(id, message) {
    if (!id || !message) {
        return;
    }
    try {
        msgstore.data[id] = [Buffer.from(proto.Message.encode(message).finish()).toString('base64'), Date.now()];
        msgstore.save();
    } catch (err) {
        logger.warn({ err }, 'message non mémorisé');
    }
}

async function getStoredMessage(key) {
    const entry = key && key.id ? msgstore.data[key.id] : null;
    if (!entry) {
        return undefined;
    }
    try {
        return proto.Message.decode(Buffer.from(entry[0], 'base64'));
    } catch {
        return undefined;
    }
}

function prune() {
    const now = Date.now();
    for (const [id, [, t]] of Object.entries(statuses.data)) {
        if (now - t > 30 * DAY) delete statuses.data[id];
    }
    const kept = Object.entries(msgstore.data).filter(([, [, t]]) => now - t < 7 * DAY).sort((a, b) => b[1][1] - a[1][1]).slice(0, 3000);
    msgstore.data = Object.fromEntries(kept);
    for (const [phone, c] of Object.entries(contacts.data)) {
        if (!c.exists && !c.lastIn && now - (c.checked || 0) > 30 * DAY) delete contacts.data[phone];
    }
    stats.data.sent = stats.data.sent.filter((t) => now - t < DAY);
    stats.data.newSent = stats.data.newSent.filter((t) => now - t < DAY);
    stores.forEach((s) => s.save());
}
setInterval(prune, HOUR).unref();

// --- Quotas ---------------------------------------------------------------

function inWarmup() {
    return !stats.data.linkedAt || Date.now() - stats.data.linkedAt < WARMUP_DAYS * DAY;
}

function quota() {
    const now = Date.now();
    stats.data.sent = stats.data.sent.filter((t) => now - t < DAY);
    stats.data.newSent = stats.data.newSent.filter((t) => now - t < DAY);
    const lastHour = stats.data.sent.filter((t) => now - t < HOUR);
    const warmup = inWarmup();
    const perDay = warmup ? Math.min(WARMUP_MAX_PER_DAY, MAX_PER_DAY) : MAX_PER_DAY;
    const newPerDay = warmup ? Math.min(WARMUP_MAX_NEW_PER_DAY, MAX_NEW_PER_DAY) : MAX_NEW_PER_DAY;
    let retryAfter = 0;
    if (lastHour.length >= MAX_PER_HOUR) {
        retryAfter = Math.ceil((lastHour[0] + HOUR - now) / 1000);
    } else if (stats.data.sent.length >= perDay) {
        retryAfter = Math.ceil((stats.data.sent[0] + DAY - now) / 1000);
    }
    return {
        warmup,
        sent_last_hour: lastHour.length,
        sent_last_24h: stats.data.sent.length,
        new_last_24h: stats.data.newSent.length,
        max_per_hour: MAX_PER_HOUR,
        max_per_day: perDay,
        max_new_per_day: newPerDay,
        retry_after: Math.max(0, retryAfter),
    };
}

/** Raison de bloquer l'envoi à un contact jamais contacté, ou null. */
function newContactBlock() {
    const now = Date.now();
    const r = state.reachout;
    if (r && r.active) {
        const until = r.until ? Date.parse(r.until) : now + 6 * HOUR;
        return { message: 'WhatsApp limite temporairement les messages vers de nouveaux contacts', retryAfter: Math.max(600, Math.ceil((until - now) / 1000)) };
    }
    const cap = state.newChatCap;
    if (cap && cap.total > 0 && cap.used >= cap.total) {
        const until = cap.cycleEnd ? Date.parse(cap.cycleEnd) : now + 6 * HOUR;
        return { message: 'Plafond WhatsApp de nouvelles discussions atteint', retryAfter: Math.max(600, Math.ceil((until - now) / 1000)) };
    }
    const q = quota();
    if (q.new_last_24h >= q.max_new_per_day) {
        return { message: 'Quota quotidien de nouveaux contacts atteint (anti-bannissement)', retryAfter: Math.max(600, Math.ceil((stats.data.newSent[0] + DAY - now) / 1000)) };
    }
    return null;
}

// --- Connexion WhatsApp ----------------------------------------------------

const state = {
    status: 'connecting',
    qr: null,
    me: null,
    lastError: null,
    lastCode: null,
    connectedAt: null,
    reachout: null,
    newChatCap: null,
};
let sock = null;
let reconnectTimer = null;
let limitsTimer = null;
let failures = 0;

const ts = (v) => {
    const n = Number(v);
    if (!Number.isFinite(n) || n <= 0) return null;
    return new Date(n > 1e12 ? n : n * 1000).toISOString();
};

function summarizeCap(info) {
    if (!info) return null;
    return {
        total: Number(info.total_quota) || 0,
        used: Number(info.used_quota) || 0,
        cycleEnd: ts(info.cycle_end_timestamp),
        status: info.capping_status || null,
    };
}

function summarizeReachout(r) {
    if (!r) return null;
    return {
        active: !!r.isActive,
        until: r.timeEnforcementEnds ? new Date(r.timeEnforcementEnds).toISOString() : null,
        type: r.enforcementType || null,
    };
}

async function refreshLimits(s) {
    if (sock !== s || state.status !== 'open') return;
    try {
        state.reachout = summarizeReachout(await s.fetchAccountReachoutTimelock());
    } catch (err) {
        logger.debug({ err }, 'reachout indisponible');
    }
    try {
        state.newChatCap = summarizeCap(await s.fetchNewChatMessageCap());
    } catch (err) {
        logger.debug({ err }, 'plafond indisponible');
    }
}

function schedule(delay) {
    clearTimeout(reconnectTimer);
    reconnectTimer = setTimeout(() => connect().catch(onFatal), delay);
}

function backoff() {
    failures++;
    return Math.min(5 * MINUTE, 5000 * 2 ** Math.min(failures - 1, 6));
}

async function connect() {
    clearTimeout(reconnectTimer);
    const { state: auth, saveCreds } = await useMultiFileAuthState(AUTH_DIR);
    const { version } = await fetchLatestBaileysVersion().catch(() => ({ version: undefined }));

    const s = makeWASocket({
        auth,
        version,
        logger,
        // Se présente comme un WhatsApp Web classique sous Chrome.
        browser: Browsers.ubuntu('Chrome'),
        markOnlineOnConnect: false,
        syncFullHistory: false,
        // Pas d'historique : moins de mémoire, moins de trafic.
        shouldSyncHistoryMessage: () => false,
        shouldIgnoreJid: (jid) => !!(isJidGroup(jid) || isJidBroadcast(jid) || isJidNewsletter(jid) || isJidStatusBroadcast(jid)),
        // Renvoi des messages demandés par le téléphone du destinataire.
        getMessage: getStoredMessage,
    });
    sock = s;

    s.ev.on('creds.update', saveCreds);
    s.ev.on('connection.update', (u) => onConnection(s, u).catch((err) => logger.error({ err }, 'connection.update')));
    s.ev.on('messages.upsert', (m) => onIncoming(s, m).catch((err) => logger.error({ err }, 'messages.upsert')));
    s.ev.on('messages.update', onStatusUpdate);
    s.ev.on('message-capping.update', (info) => {
        state.newChatCap = summarizeCap(info);
    });
}

async function onConnection(s, u) {
    if (sock !== s) return;
    if (u.reachoutTimeLock) {
        state.reachout = summarizeReachout(u.reachoutTimeLock);
    }
    if (u.qr) {
        state.status = 'qr';
        state.qr = await QRCode.toDataURL(u.qr, { margin: 1, width: 300 });
        log('Nouveau QR code disponible : scannez-le depuis WHMCS (Addons > LWB WhatsApp).');
    }
    if (u.connection === 'open') {
        failures = 0;
        state.status = 'open';
        state.qr = null;
        state.lastError = null;
        state.lastCode = null;
        state.connectedAt = new Date().toISOString();
        state.me = s.user ? { id: s.user.id, name: s.user.name || '' } : null;
        const number = state.me ? state.me.id.split(':')[0].split('@')[0] : null;
        if (number && stats.data.number && stats.data.number !== number) {
            // Nouveau numéro : l'historique des contacts ne s'applique plus.
            log('Nouveau numéro WhatsApp détecté : remise à zéro du rodage et des contacts connus.');
            contacts.data = {};
            stats.data.linkedAt = null;
            stats.data.newSent = [];
            contacts.save();
        }
        stats.data.number = number;
        if (!stats.data.linkedAt) {
            stats.data.linkedAt = Date.now();
        }
        stats.save();
        log('Connecté à WhatsApp :', number);
        clearInterval(limitsTimer);
        limitsTimer = setInterval(() => refreshLimits(s), HOUR);
        setTimeout(() => refreshLimits(s), 5000);
    }
    if (u.connection === 'close') {
        clearInterval(limitsTimer);
        const code = u.lastDisconnect?.error?.output?.statusCode;
        state.lastCode = code ?? null;
        state.lastError = u.lastDisconnect?.error?.message || null;
        state.me = null;
        state.qr = null;
        if (code === DisconnectReason.loggedOut) {
            state.status = 'logged_out';
            log('Appareil délié (401) : réinitialisation de la session, nouveau QR code à scanner.');
            fs.rmSync(AUTH_DIR, { recursive: true, force: true });
            stats.data.linkedAt = null;
            stats.save();
            schedule(3000);
        } else if (code === DisconnectReason.forbidden) {
            state.status = 'banned';
            log('Accès refusé par WhatsApp (403) : numéro probablement banni. Nouvelle tentative dans 30 min.');
            schedule(30 * MINUTE);
        } else if (code === DisconnectReason.connectionReplaced) {
            state.status = 'replaced';
            log('Session ouverte ailleurs (440) : nouvelle tentative dans 2 min.');
            schedule(2 * MINUTE);
        } else if (code === DisconnectReason.restartRequired) {
            state.status = 'connecting';
            schedule(500);
        } else {
            state.status = 'closed';
            const delay = backoff();
            log(`Connexion fermée (${code ?? '?'}), reconnexion dans ${Math.round(delay / 1000)} s.`);
            schedule(delay);
        }
    }
}

function onFatal(err) {
    state.status = 'closed';
    state.lastError = String(err && err.message ? err.message : err);
    logger.error({ err }, 'connexion impossible');
    schedule(backoff());
}

function phoneOf(key) {
    const pn = [key.remoteJid, key.remoteJidAlt].find((j) => j && isPnUser(j));
    return pn ? pn.split('@')[0].split(':')[0] : null;
}

async function onIncoming(s, { messages, type }) {
    if (sock !== s || type !== 'notify') return;
    for (const msg of messages) {
        if (!msg.message || msg.key.fromMe) continue;
        const jid = msg.key.remoteJid || '';
        if (READ_INCOMING) {
            await s.readMessages([msg.key]).catch(() => {});
        }
        const phone = phoneOf(msg.key);
        if (!phone) continue;
        const c = contacts.data[phone] || { exists: true, jid: `${phone}@s.whatsapp.net`, checked: Date.now() };
        c.lastIn = Date.now();
        contacts.data[phone] = c;
        contacts.save();
        const text = (msg.message.conversation || msg.message.extendedTextMessage?.text || '').trim().toUpperCase();
        if (OPTOUT_WORDS.includes(text)) {
            optout.data[phone] = new Date().toISOString();
            optout.save();
            log('Désinscription :', phone);
            await s.sendMessage(jid, { text: OPTOUT_REPLY }).catch(() => {});
        } else if (OPTIN_WORDS.includes(text) && optout.data[phone]) {
            delete optout.data[phone];
            optout.save();
            log('Réinscription :', phone);
            await s.sendMessage(jid, { text: OPTIN_REPLY }).catch(() => {});
        }
    }
}

function onStatusUpdate(updates) {
    let changed = false;
    for (const { key, update } of updates) {
        if (!key.fromMe || update.status == null || !key.id) continue;
        const previous = statuses.data[key.id];
        if (!previous || update.status > previous[0]) {
            statuses.data[key.id] = [update.status, Date.now()];
            changed = true;
        }
    }
    if (changed) statuses.save();
}

// --- File d'envoi ------------------------------------------------------------

class SendError extends Error {
    constructor(httpCode, message, extra = {}) {
        super(message);
        this.httpCode = httpCode;
        this.extra = extra;
    }
}

let queue = Promise.resolve();
let pending = 0;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const jitter = () => MIN_DELAY_MS + Math.floor(Math.random() * Math.max(0, MAX_DELAY_MS - MIN_DELAY_MS));

function enqueueSend(phone, message, document) {
    pending++;
    const job = queue.then(() => doSend(phone, message, document));
    // Le délai n'est appliqué qu'après un envoi réel.
    queue = job.then(() => sleep(jitter()), () => {}).finally(() => pending--);
    return job;
}

async function lookup(phone) {
    const cached = contacts.data[phone];
    if (cached && cached.checked && Date.now() - cached.checked < (cached.exists ? 30 : 7) * DAY) {
        return cached;
    }
    const list = (await sock.onWhatsApp(phone)) || [];
    const found = list.find((r) => r && r.exists);
    const entry = {
        ...(cached || {}),
        exists: !!found,
        jid: found ? found.jid : `${phone}@s.whatsapp.net`,
        checked: Date.now(),
    };
    contacts.data[phone] = entry;
    contacts.save();
    return entry;
}

async function doSend(phone, message, document) {
    if (state.status !== 'open' || !sock) {
        throw new SendError(503, 'WhatsApp non connecté (statut : ' + state.status + ')');
    }
    if (optout.data[phone]) {
        throw new SendError(410, 'Le client ' + phone + ' a demandé à ne plus recevoir de messages (STOP)', { reason: 'optout' });
    }
    const q = quota();
    if (q.retry_after > 0) {
        throw new SendError(429, 'Quota anti-bannissement atteint, réessayez dans ' + q.retry_after + ' s', { retry_after: q.retry_after, reason: 'quota' });
    }
    const contact = await lookup(phone);
    if (!contact.exists) {
        throw new SendError(410, 'Le numéro ' + phone + " n'a pas de compte WhatsApp", { reason: 'not_on_whatsapp' });
    }
    const isNew = !contact.lastOut && !contact.lastIn;
    if (isNew) {
        const block = newContactBlock();
        if (block) {
            throw new SendError(429, block.message, { retry_after: block.retryAfter, reason: 'new_contact_limit' });
        }
    }
    const jid = contact.jid;
    // Simule une frappe humaine avant l'envoi.
    await sock.presenceSubscribe(jid).catch(() => {});
    await sleep(800 + Math.floor(Math.random() * 1200));
    await sock.sendPresenceUpdate('composing', jid).catch(() => {});
    await sleep(Math.min(7000, 1500 + message.length * 40));
    await sock.sendPresenceUpdate('paused', jid).catch(() => {});

    let sent;
    if (document) {
        // Un seul message : le document (ex. facture PDF) avec le texte en légende.
        const file = {
            document: Buffer.from(document.base64, 'base64'),
            mimetype: document.mimetype || 'application/pdf',
            fileName: document.filename || 'document.pdf',
        };
        if (message.length <= 1000) {
            sent = await sock.sendMessage(jid, { ...file, caption: message });
        } else {
            sent = await sock.sendMessage(jid, { text: message });
            storeMessage(sent?.key?.id, sent?.message);
            await sleep(1500 + Math.floor(Math.random() * 1500));
            sent = await sock.sendMessage(jid, file);
        }
    } else {
        sent = await sock.sendMessage(jid, { text: message });
    }
    const id = sent?.key?.id || null;
    storeMessage(id, sent?.message);
    if (id) {
        statuses.data[id] = [2, Date.now()];
        statuses.save();
    }
    const now = Date.now();
    stats.data.sent.push(now);
    if (isNew) stats.data.newSent.push(now);
    stats.save();
    contact.lastOut = now;
    contacts.data[phone] = contact;
    contacts.save();
    return { id, jid, new_contact: isNew };
}

// --- HTTP --------------------------------------------------------------------

const STATUS_NAMES = { 0: 'error', 1: 'pending', 2: 'sent', 3: 'delivered', 4: 'read', 5: 'read' };

function authorized(req) {
    const given = Buffer.from(String(req.headers['x-api-key'] || ''));
    const expected = Buffer.from(API_KEY);
    return given.length === expected.length && crypto.timingSafeEqual(given, expected);
}

function reply(res, code, data) {
    res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(JSON.stringify(data));
}

function readJson(req) {
    return new Promise((resolve, reject) => {
        const chunks = [];
        let size = 0;
        req.on('data', (c) => {
            size += c.length;
            if (size > 12 * 1024 * 1024) {
                reject(new SendError(413, 'Corps trop volumineux'));
                req.destroy();
                return;
            }
            chunks.push(c);
        });
        req.on('end', () => {
            try {
                const body = Buffer.concat(chunks).toString('utf8');
                resolve(body ? JSON.parse(body) : {});
            } catch {
                reject(new SendError(400, 'JSON invalide'));
            }
        });
        req.on('error', reject);
    });
}

const digitsOf = (v) => String(v || '').replace(/\D+/g, '');

const routes = {
    'GET /status': async () => ({
        ok: true,
        version: VERSION,
        uptime: Math.round(process.uptime()),
        memory_mb: Math.round(process.memoryUsage().rss / 1048576),
        ...state,
        queue: pending,
        quota: quota(),
        optout_count: Object.keys(optout.data).length,
        contacts_known: Object.values(contacts.data).filter((c) => c.lastOut || c.lastIn).length,
    }),

    'POST /send': async (body) => {
        const phone = digitsOf(body.phone);
        const message = String(body.message || '');
        if (phone.length < 8 || !message.trim()) {
            throw new SendError(400, 'Paramètres "phone" et "message" requis');
        }
        if (body.document && (typeof body.document.base64 !== 'string' || !body.document.base64)) {
            throw new SendError(400, 'Document invalide (champ "base64" requis)');
        }
        if (pending >= 20) {
            throw new SendError(429, "File d'attente pleine", { retry_after: 60, reason: 'busy' });
        }
        return { ok: true, ...(await enqueueSend(phone, message, body.document || null)) };
    },

    'POST /statuses': async (body) => {
        const out = {};
        for (const id of Array.isArray(body.ids) ? body.ids.slice(0, 500) : []) {
            const s = statuses.data[id];
            if (s) out[id] = { status: STATUS_NAMES[s[0]] || 'sent', at: new Date(s[1]).toISOString() };
        }
        return { ok: true, statuses: out };
    },

    'GET /optouts': async () => ({ ok: true, optouts: optout.data }),

    'POST /optouts': async (body) => {
        const phone = digitsOf(body.phone);
        if (phone.length < 8) throw new SendError(400, 'Numéro invalide');
        if (body.action === 'remove') {
            delete optout.data[phone];
        } else {
            optout.data[phone] = optout.data[phone] || new Date().toISOString();
        }
        optout.save();
        return { ok: true };
    },

    'POST /pair': async (body) => {
        const phone = digitsOf(body.phone);
        if (phone.length < 8) throw new SendError(400, 'Numéro invalide');
        if (!sock || state.status === 'open' || sock.authState?.creds?.registered) {
            throw new SendError(409, 'Déjà lié : déliez d\'abord l\'appareil pour en lier un autre.');
        }
        const code = await sock.requestPairingCode(phone);
        return { ok: true, code: String(code).replace(/^(.{4})(.{4})$/, '$1-$2') };
    },

    'POST /reconnect': async () => {
        failures = 0;
        if (sock) sock.end(new Error('Reconnexion demandée'));
        else schedule(0);
        return { ok: true };
    },

    'POST /logout': async () => {
        if (sock && state.status === 'open') {
            await sock.logout().catch(() => {});
        } else {
            fs.rmSync(AUTH_DIR, { recursive: true, force: true });
            sock?.end(undefined);
        }
        return { ok: true };
    },
};

const server = http.createServer(async (req, res) => {
    if (!authorized(req)) {
        return reply(res, 401, { ok: false, error: 'Clé API invalide' });
    }
    const route = routes[`${req.method} ${new URL(req.url, 'http://localhost').pathname}`];
    if (!route) {
        return reply(res, 404, { ok: false, error: 'Route inconnue' });
    }
    try {
        const body = req.method === 'POST' ? await readJson(req) : {};
        return reply(res, 200, await route(body));
    } catch (err) {
        if (err instanceof SendError) {
            return reply(res, err.httpCode, { ok: false, error: err.message, ...err.extra });
        }
        logger.error({ err }, 'requête en échec');
        return reply(res, 500, { ok: false, error: err.message || String(err) });
    }
});

server.on('error', (err) => {
    if (err.code === 'EADDRINUSE') {
        log(`Port ${PORT} déjà utilisé : une autre passerelle tourne sans doute déjà. Arrêt.`);
        process.exit(0);
    }
    throw err;
});

function shutdown(signal) {
    log(`Arrêt (${signal}).`);
    for (const s of stores) {
        try {
            s.flush();
        } catch {}
    }
    try {
        if (parseInt(fs.readFileSync(PID_FILE, 'utf8'), 10) === process.pid) fs.rmSync(PID_FILE, { force: true });
    } catch {}
    try {
        sock?.end(undefined);
    } catch {}
    process.exit(0);
}
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
process.on('unhandledRejection', (err) => logger.error({ err }, 'promesse rejetée'));

server.listen(PORT, HOST, () => {
    // Écrit seulement une fois le port obtenu : une instance en double ne l'écrase pas.
    fs.writeFileSync(PID_FILE, String(process.pid));
    log(`Passerelle lwb-whmcs-wha ${VERSION} en écoute sur http://${HOST}:${PORT}`);
    connect().catch(onFatal);
});
