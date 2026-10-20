(() => {
    const select = document.getElementById('campaign-template');
    if (!select) return;
    select.addEventListener('change', async () => {
        if (!select.value) return;
        try {
            const response = await fetch(`/api/template.php?id=${encodeURIComponent(select.value)}`, { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) throw new Error('Vorlage nicht verfügbar');
            const template = await response.json();
            document.querySelector('[name="subject"]').value = template.subject;
            document.querySelector('[name="html_content"]').value = template.html_content;
            document.querySelector('[name="text_content"]').value = template.text_content;
        } catch {
            window.alert('Vorlage konnte nicht geladen werden.');
        }
    });
})();
