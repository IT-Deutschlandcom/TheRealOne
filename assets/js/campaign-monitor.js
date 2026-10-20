(() => {
    const root = document.getElementById('campaign-monitor');
    if (!root) return;
    const id = encodeURIComponent(root.dataset.campaignId);
    const statusNode = document.getElementById('monitor-status');
    const progress = document.getElementById('monitor-progress');
    const events = document.getElementById('monitor-events');
    const labels = { queued: 'Ausstehend', processing: 'In Bearbeitung', accepted: 'SMTP angenommen', failed: 'Fehlgeschlagen', unknown: 'Annahme unklar', cancelled: 'Abgebrochen' };
    const load = async () => {
        try {
            const response = await fetch(`/api/campaign-status.php?id=${id}`, { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) throw new Error('Status nicht verfügbar');
            const data = await response.json();
            statusNode.textContent = `Status: ${data.campaign.status} · Intervall: ${data.campaign.send_interval_seconds} Sekunden`;
            for (const [key, value] of Object.entries(data.stats)) {
                const node = root.querySelector(`[data-stat="${key}"]`);
                if (node) node.textContent = String(value);
            }
            progress.style.width = `${data.stats.progress}%`;
            progress.textContent = `${data.stats.progress}%`;
            progress.parentElement.setAttribute('aria-valuenow', String(data.stats.progress));
            events.replaceChildren();
            if (!data.events.length) {
                const row = document.createElement('tr');
                const cell = document.createElement('td');
                cell.colSpan = 4;
                cell.textContent = 'Noch keine Versandereignisse.';
                row.append(cell);
                events.append(row);
            }
            for (const event of data.events) {
                const row = document.createElement('tr');
                [event.recipient, labels[event.status] || event.status, event.smtp_code || '–', event.time || '–'].forEach((value) => {
                    const cell = document.createElement('td');
                    cell.textContent = String(value);
                    row.append(cell);
                });
                events.append(row);
            }
        } catch {
            statusNode.textContent = 'Live-Status konnte nicht geladen werden. Erneuter Versuch folgt.';
        }
    };
    load();
    window.setInterval(load, 2000);
})();
