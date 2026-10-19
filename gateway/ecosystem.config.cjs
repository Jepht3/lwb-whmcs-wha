// Démarrage permanent avec PM2 (serveur avec accès SSH) :
//   pm2 start ecosystem.config.cjs && pm2 save && pm2 startup
// Sans SSH (hébergement cPanel), renseignez plutôt le dossier et le binaire Node.js
// dans la configuration du module WHMCS : le cron WHMCS redémarre la passerelle.
module.exports = {
    apps: [{
        name: 'lwb-whmcs-wha-gateway',
        script: 'server.js',
        node_args: '--env-file-if-exists=.env',
        cwd: __dirname,
        autorestart: true,
        max_restarts: 50,
        restart_delay: 10000,
        max_memory_restart: '400M',
        kill_timeout: 5000,
    }],
};
