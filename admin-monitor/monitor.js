// --- ERRORI FATTURE IN CLOUD ---
async function loadFicErrorLog() {
	try {
		const r = await fetch(BASE + '/api.php?action=fic_error_log');
		const rows = await r.json();
		const tbody = document.getElementById('fic-error-log-body');
		if (!rows.length) {
			tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:16px;color:#64748b;">Nessun errore recente</td></tr>';
			return;
		}
		tbody.innerHTML = rows.map(e =>
			`<tr>
				<td>${escHtml(e.creato_il || '')}</td>
				<td>${e.fattura_id ? `<a href="/modules/fatture/dettaglio_fattura.php?id=${e.fattura_id}" target="_blank">#${e.fattura_id}</a>` : '-'}</td>
				<td>${escHtml(e.utente_nome || '-')}</td>
				<td>${escHtml(e.messaggio || '')}</td>
			</tr>`
		).join('');
	} catch(e) {
		document.getElementById('fic-error-log-body').innerHTML = '<tr><td colspan="4" style="text-align:center;padding:16px;color:#f87171;">Errore caricamento</td></tr>';
		console.error('loadFicErrorLog', e);
	}
}

// Carica errori FIC all'avvio
if (document.getElementById('fic-error-log-body')) loadFicErrorLog();

// Imposta BASE e CAN_KICK come variabili globali
const BASE = document.currentScript && document.currentScript.getAttribute('data-base')
	? document.currentScript.getAttribute('data-base')
	: (window.BASE || '/admin-monitor');
const CAN_KICK = typeof window.CAN_KICK !== 'undefined' ? window.CAN_KICK : true;

// Tabs
document.querySelectorAll('.tab').forEach(t => {
	t.addEventListener('click', () => {
		document.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
		document.querySelectorAll('.tab-content').forEach(x => x.classList.remove('active'));
		t.classList.add('active');
		document.getElementById('tab-' + t.dataset.tab).classList.add('active');
	});
});

function escHtml(s) {
	const d = document.createElement('div');
	d.textContent = s;
	return d.innerHTML;
}

function timeAgo(dt) {
	const diff = Math.floor((Date.now() - new Date(dt + ' UTC').getTime()) / 1000);
	if (diff < 60) return diff + 's fa';
	if (diff < 3600) return Math.floor(diff/60) + 'min fa';
	if (diff < 86400) return Math.floor(diff/3600) + 'h fa';
	return Math.floor(diff/86400) + 'g fa';
}

function actionBadge(a) {
	const map = { login: 'badge-green', logout: 'badge-red', pageview: 'badge-blue' };
	return '<span class="badge ' + (map[a] || 'badge-gray') + '">' + escHtml(a) + '</span>';
}

function shortPage(url) {
	try {
		const u = new URL(url, window.location.origin);
		return u.pathname + (u.search || '');
	} catch(e) { return url; }
}

// --- FATTURE NON INVIATE ---
async function loadFattureNonInviate() {
	try {
		const r = await fetch(BASE + '/api.php?action=fatture_non_inviate');
		const rows = await r.json();
		const tbody = document.getElementById('fatture-non-inviate-body');
		if (!rows.length) {
			tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:16px;color:#64748b;">Nessuna fattura da inviare</td></tr>';
			return;
		}
		tbody.innerHTML = rows.map(e =>
			`<tr>
				<td>${escHtml(e.numero || '')}</td>
				<td>${escHtml(e.data || '')}</td>
				<td>${escHtml(e.ragione_sociale || '')}</td>
				<td>${escHtml(e.mail_to || e.email || '')}</td>
				<td>${escHtml(e.mail_subject || '')}</td>
				<td><span class="badge badge-yellow">${escHtml(e.mail_status || 'non inviata')}</span></td>
				<td>
					<button class="btn btn-primary" onclick="apriModalInviaMail(${e.id}, '${escHtml(e.mail_to || e.email || '')}', '${escHtml(e.mail_subject || '')}', '')">Invia/Rinvia</button>
					<button class="btn btn-primary" onclick="inviaFatturaFIC(${e.id}, this)">Invia a FIC</button>
				</td>
			</tr>`
		).join('');

	// Invio Fattura Elettronica (FIC)
	async function inviaFatturaFIC(id, btn) {
		if (!confirm('Vuoi inviare questa fattura a Fatture in Cloud?')) return;
		btn.disabled = true;
		btn.textContent = '...';
		try {
			const r = await fetch(BASE + '/api.php?action=invia_fic', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ id })
			});
			const d = await r.json();
			if (d.ok) {
				btn.textContent = 'Inviata!';
				loadFattureNonInviate();
			} else {
				btn.textContent = 'Errore';
				alert(d.error || 'Errore invio FIC');
				btn.disabled = false;
				btn.textContent = 'Invia a FIC';
			}
		} catch(e) {
			btn.textContent = 'Errore';
			alert('Errore di rete');
			btn.disabled = false;
			btn.textContent = 'Invia a FIC';
		}
	}
	} catch(e) {
		document.getElementById('fatture-non-inviate-body').innerHTML = '<tr><td colspan="7" style="text-align:center;padding:16px;color:#f87171;">Errore caricamento</td></tr>';
		console.error('loadFattureNonInviate', e);
	}
}

function apriModalInviaMail(id, to, subject, body) {
	document.getElementById('fattura-id').value = id;
	document.getElementById('fattura-mail-to').value = to;
	document.getElementById('fattura-mail-subject').value = subject;
	document.getElementById('fattura-mail-body').value = body || 'Gentile cliente, in allegato trova la sua fattura.';
	document.getElementById('invia-mail-status').textContent = '';
	document.getElementById('modal-invia-mail').style.display = 'flex';
}
function closeModalInviaMail() {
	document.getElementById('modal-invia-mail').style.display = 'none';
}

document.getElementById('form-invia-mail-fattura').addEventListener('submit', async function(ev) {
	ev.preventDefault();
	const id = document.getElementById('fattura-id').value;
	const to = document.getElementById('fattura-mail-to').value;
	const subject = document.getElementById('fattura-mail-subject').value;
	const body = document.getElementById('fattura-mail-body').value;
	const status = document.getElementById('invia-mail-status');
	status.textContent = 'Invio...';
	try {
		const r = await fetch(BASE + '/api.php?action=invia_mail_fattura', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ id, to, subject, body })
		});
		const d = await r.json();
		if (d.ok) {
			status.textContent = 'Email inviata!';
			setTimeout(() => { closeModalInviaMail(); loadFattureNonInviate(); }, 1200);
		} else {
			status.textContent = d.error || 'Errore invio';
		}
	} catch(e) {
		status.textContent = 'Errore di rete';
	}
});

// Carica la lista all'avvio
loadFattureNonInviate();

// --- INBOX (IMAP) ---
async function loadInbox() {
	try {
		const r = await fetch(BASE + '/api.php?action=inbox');
		const rows = await r.json();
		const tbody = document.getElementById('inbox-body');
		if (!rows.length) {
			tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:16px;color:#64748b;">Nessuna email trovata</td></tr>';
			return;
		}
		tbody.innerHTML = rows.map(e =>
			`<tr><td>${escHtml(e.date)}</td><td>${escHtml(e.from)}</td><td>${escHtml(e.subject)}</td></tr>`
		).join('');
	} catch(e) {
		document.getElementById('inbox-body').innerHTML = '<tr><td colspan="3" style="text-align:center;padding:16px;color:#f87171;">Errore caricamento</td></tr>';
		console.error('loadInbox', e);
	}
}

// Invio manuale email
document.getElementById('send-mail-form').addEventListener('submit', async function(ev) {
	ev.preventDefault();
	const to = document.getElementById('mail-to').value;
	const subject = document.getElementById('mail-subject').value;
	const body = document.getElementById('mail-body').value;
	const status = document.getElementById('send-mail-status');
	status.textContent = 'Invio...';
	try {
		const r = await fetch(BASE + '/api.php?action=send_mail', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ to, subject, body })
		});
		const d = await r.json();
		if (d.ok) {
			status.textContent = 'Email inviata!';
			document.getElementById('send-mail-form').reset();
		} else {
			status.textContent = d.error || 'Errore invio';
		}
	} catch(e) {
		status.textContent = 'Errore di rete';
	}
});

// --- KPI, SESSIONS, ACTIVITY, INTEGRATIONS ---
async function loadStats() {
	try {
		const r = await fetch(BASE + '/api.php?action=stats');
		const d = await r.json();
		document.getElementById('kpi-sessions').textContent = d.active_sessions;
		document.getElementById('kpi-logins').textContent = d.today_logins;
		document.getElementById('kpi-views').textContent = d.today_pageviews;
		document.getElementById('kpi-users').textContent = d.total_users;
	} catch(e) { console.error(e); }
}

async function loadSessions() {
	try {
		const mins = document.getElementById('session-minutes').value;
		const r = await fetch(BASE + '/api.php?action=sessions&minutes=' + mins);
		const rows = await r.json();
		const tbody = document.getElementById('sessions-body');
		if (!rows.length) {
			tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#64748b;">Nessuna sessione attiva</td></tr>';
			return;
		}
		tbody.innerHTML = rows.map(s => `<tr>
			<td><strong>${escHtml(s.user_nome)}</strong></td>
			<td>${escHtml(s.user_email)}</td>
			<td class="page-path" title="${escHtml(s.last_page)}">${escHtml(shortPage(s.last_page))}</td>
			<td>${escHtml(s.ip)}</td>
			<td><span class="time-ago">${timeAgo(s.last_seen_at)}</span></td>
			<td>${CAN_KICK ? '<button class="btn btn-danger" onclick="kickSession(\'' + escHtml(s.session_id) + '\', this)">Disconnetti</button>' : ''}</td>
		</tr>`).join('');
	} catch(e) { console.error(e); }
}

async function loadActivity() {
	try {
		const lim = document.getElementById('activity-limit').value;
		const r = await fetch(BASE + '/api.php?action=activity&limit=' + lim);
		const rows = await r.json();
		const tbody = document.getElementById('activity-body');
		if (!rows.length) {
			tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#64748b;">Nessuna attivit&agrave;</td></tr>';
			return;
		}
		tbody.innerHTML = rows.map(a => `<tr>
			<td><span class="time-ago">${escHtml(a.creato_il)}</span></td>
			<td>${escHtml(a.user_nome)}</td>
			<td><span class="badge badge-gray">${escHtml(a.user_ruolo)}</span></td>
			<td>${actionBadge(a.azione)}</td>
			<td class="page-path" title="${escHtml(a.pagina)}">${escHtml(shortPage(a.pagina))}</td>
			<td>${escHtml(a.ip)}</td>
		</tr>`).join('');
	} catch(e) { console.error(e); }
}

async function kickSession(sid, btn) {
	if (!confirm('Disconnettere questo utente?')) return;
	btn.disabled = true;
	btn.textContent = '...';
	try {
		const fd = new FormData();
		fd.append('session_id', sid);
		const r = await fetch(BASE + '/api.php?action=kick', { method: 'POST', body: fd });
		const d = await r.json();
		if (d.ok) {
			btn.closest('tr').style.opacity = '0.3';
			btn.textContent = 'Disconnesso';
			loadStats();
		} else {
			alert(d.error || 'Errore');
			btn.disabled = false;
			btn.textContent = 'Disconnetti';
		}
	} catch(e) {
		alert('Errore di rete');
		btn.disabled = false;
		btn.textContent = 'Disconnetti';
	}
}

function fmtEuro(cents) {
	return (cents / 100).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
}

async function loadIntegrations() {
	try {
		const r = await fetch(BASE + '/api.php?action=integrations');
		const d = await r.json();

		// STRIPE
		const s = d.stripe || {};
		document.getElementById('st-total').textContent = s.pagamenti_totali ?? '--';
		document.getElementById('st-ok').textContent = s.pagamenti_succeeded ?? '--';
		document.getElementById('st-pending').textContent = s.pagamenti_pending ?? '--';
		document.getElementById('st-failed').textContent = s.pagamenti_failed ?? '--';
		document.getElementById('st-refunded').textContent = s.pagamenti_refunded ?? '--';
		document.getElementById('st-volume').textContent = s.volume_totale ? fmtEuro(s.volume_totale) : '--';
		document.getElementById('st-clients').textContent = s.clienti_stripe ?? '--';
		document.getElementById('st-today').textContent = s.pagamenti_oggi ?? '--';
		if (s.last_sync) {
			const cls = s.last_sync.status === 'ok' ? 'on' : 'off';
			document.getElementById('stripe-sync-info').innerHTML =
				`<span class="status-dot ${cls}"></span> <strong>${escHtml(s.last_sync.status)}</strong> &mdash; ${escHtml(s.last_sync.finished_at || '?')} &mdash; Importati: ${s.last_sync.imported ?? 0}, Errori: ${s.last_sync.errors ?? 0}`;
		} else {
			document.getElementById('stripe-sync-info').textContent = 'Nessun sync registrato';
		}

		// FIC
		const f = d.fic || {};
		document.getElementById('fic-total').textContent = f.fatture_totali ?? '--';
		document.getElementById('fic-ok').textContent = f.fatture_con_fic ?? '--';
		const pct = f.fatture_totali ? Math.round((f.fatture_con_fic / f.fatture_totali) * 100) : 0;
		document.getElementById('fic-ok-pct').textContent = pct + '% del totale';
		document.getElementById('fic-missing').textContent = f.fatture_senza_fic ?? '--';
		document.getElementById('fic-st-ok').textContent = f.fic_status_ok ?? '--';
		document.getElementById('fic-st-pending').textContent = f.fic_status_pending ?? '--';
		document.getElementById('fic-st-error').textContent = f.fic_status_error ?? '--';
		document.getElementById('fic-st-null').textContent = f.fic_status_null ?? '--';
		const autoOn = f.auto_send_enabled === 1;
		document.getElementById('fic-autosend').innerHTML = `<span class="status-dot ${autoOn ? 'on' : 'off'}"></span>${autoOn ? 'ON' : 'OFF'}`;
		document.getElementById('fic-autosend-mode').textContent = 'Modalità: ' + (f.auto_send_mode || '?');

		// EMAIL
		const m = d.mail || {};
		document.getElementById('mail-sent').textContent = m.mail_inviate ?? '--';
		document.getElementById('mail-pending').textContent = m.mail_pending ?? '--';
		document.getElementById('mail-error').textContent = m.mail_errore ?? '--';
		document.getElementById('mail-none').textContent = m.mail_non_inviate ?? '--';
		if (m.ultima_mail) {
			document.getElementById('mail-last').innerHTML = `#${escHtml(m.ultima_mail.numero || '?')}<br><span class="time-ago">${escHtml(m.ultima_mail.mail_sent_at || '')}</span>`;
		} else {
			document.getElementById('mail-last').textContent = 'Nessuna';
		}

		// Carica log email inviate
		loadMailLog();

		// FATTURE LOG
		const fl = d.fatture_log || [];
		const flBody = document.getElementById('fatture-log-body');
		if (!fl.length) {
			flBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:16px;color:#64748b;">Nessuna operazione</td></tr>';
		} else {
			flBody.innerHTML = fl.map(l => {
				const eCls = l.esito === 'ok' ? 'badge-green' : l.esito === 'error' ? 'badge-red' : 'badge-blue';
				return `<tr>
					<td><span class="time-ago">${escHtml(l.creato_il || '')}</span></td>
					<td>${escHtml(l.utente_nome || '-')}</td>
					<td><span class="badge badge-blue">${escHtml(l.azione || '')}</span></td>
					<td><span class="badge ${eCls}">${escHtml(l.esito || '')}</span></td>
					<td>${escHtml(l.messaggio || '')}</td>
				</tr>`;
			}).join('');
		}

		// CRON LOG
		const cl = d.cron_log || [];
		const clBody = document.getElementById('cron-log-body');
		if (!cl.length) {
			clBody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:16px;color:#64748b;">Nessun cron registrato</td></tr>';
		} else {
			clBody.innerHTML = cl.map(c => {
				const sCls = c.status === 'ok' ? 'badge-green' : 'badge-red';
				return `<tr>
					<td><span class="badge badge-gray">${escHtml(c.job_name || '')}</span></td>
					<td><span class="badge ${sCls}">${escHtml(c.status || '')}</span></td>
					<td><span class="time-ago">${escHtml(c.started_at || '')}</span></td>
					<td><span class="time-ago">${escHtml(c.finished_at || '')}</span></td>
					<td>${c.imported ?? '-'}</td>
					<td>${c.invoices_created ?? '-'}</td>
					<td>${c.clients_created ?? '-'}</td>
					<td>${c.errors ?? '0'}</td>
				</tr>`;
			}).join('');
		}
	} catch(e) { console.error('loadIntegrations', e); }
}

// Carica lo storico delle email inviate
async function loadMailLog() {
	try {
		const r = await fetch(BASE + '/api.php?action=mail_log');
		const rows = await r.json();
		const tbody = document.getElementById('mail-log-body');
		if (!rows.length) {
			tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:16px;color:#64748b;">Nessuna email trovata</td></tr>';
			return;
		}
		tbody.innerHTML = rows.map(e => {
			const statoCls = e.mail_status === 'sent' ? 'badge-green' : (e.mail_status === 'pending' ? 'badge-yellow' : (e.mail_status === 'error' ? 'badge-red' : 'badge-gray'));
			const esito = e.mail_status === 'sent' ? 'OK' : (e.mail_status === 'pending' ? 'In attesa' : (e.mail_status === 'error' ? 'Errore' : ''));
			const canRetry = e.mail_status !== 'sent';
			return `<tr>
				<td><span class="time-ago">${escHtml(e.mail_sent_at || '')}</span></td>
				<td>${escHtml(e.mail_to || '')}</td>
				<td>${escHtml(e.mail_subject || '')}</td>
				<td><span class="badge ${statoCls}">${escHtml(e.mail_status || '')}</span></td>
				<td>${escHtml(esito)}</td>
				<td>${canRetry ? `<button class="btn btn-primary" onclick="retryMail(${e.id}, this)">Reinvia</button>` : ''}</td>
			</tr>`;
		}).join('');
	} catch(e) {
		document.getElementById('mail-log-body').innerHTML = '<tr><td colspan="6" style="text-align:center;padding:16px;color:#f87171;">Errore caricamento</td></tr>';
		console.error('loadMailLog', e);
	}
}

// Funzione per reinviare una mail non inviata
async function retryMail(id, btn) {
	if (!confirm('Vuoi reinviare questa email?')) return;
	btn.disabled = true;
	btn.textContent = '...';
	try {
		const r = await fetch(BASE + '/api.php?action=retry_mail', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ id })
		});
		const d = await r.json();
		if (d.ok) {
			btn.textContent = 'Inviata';
			btn.classList.remove('btn-primary');
			btn.classList.add('btn-success');
			loadMailLog();
		} else {
			alert(d.error || 'Errore');
			btn.disabled = false;
			btn.textContent = 'Reinvia';
		}
	} catch(e) {
		alert('Errore di rete');
		btn.disabled = false;
		btn.textContent = 'Reinvia';
	}
}

// Auto-refresh
loadStats();
loadSessions();
loadActivity();
loadIntegrations();
setInterval(loadStats, 15000);
setInterval(loadSessions, 15000);
setInterval(loadIntegrations, 30000);

document.getElementById('session-minutes').addEventListener('change', loadSessions);
document.getElementById('activity-limit').addEventListener('change', loadActivity);
