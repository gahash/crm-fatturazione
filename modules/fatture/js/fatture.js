document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('#fattura-form');
    if (!form) return;

    function normalizeEmail(value) {
        const email = String(value || '').trim();
        if (email === '') return '';
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) ? email : '';
    }

    function parseAddressParts(value) {
        const raw = String(value || '').trim();
        if (raw === '') return { postalCode: '', city: '', province: '' };

        let postalCode = '';
        let city = '';
        let province = '';

        const zip = raw.match(/\b(\d{5})\b/);
        if (zip) postalCode = zip[1];

        const prov = raw.match(/(?:\(|\b)([A-Za-z]{2})(?:\)|\b)\s*$/);
        if (prov) province = prov[1].toUpperCase();

        if (postalCode) {
            const cityProv = raw.match(new RegExp('\\b' + postalCode + '\\s+(.+?)\\s*\\(?([A-Za-z]{2})\\)?(?:\\s*,|$)', 'i'));
            if (cityProv) {
                city = String(cityProv[1] || '').trim();
                if (!province) province = String(cityProv[2] || '').toUpperCase();
            }
        }

        if (!city && postalCode) {
            const cityAlt = raw.match(new RegExp('\\b' + postalCode + '\\b\\s*,?\\s*([^,]+?)(?:\\s*,\\s*[A-Za-z]{2})?(?:\\s*,|$)', 'i'));
            if (cityAlt) city = String(cityAlt[1] || '').trim();
        }

        return {
            postalCode: /^\d{5}$/.test(postalCode) ? postalCode : '',
            city,
            province: /^[A-Z]{2}$/.test(province) ? province : '',
        };
    }

    function isValidCityName(value) {
        const city = String(value || '').trim();
        if (city === '') return false;
        return /^[A-Za-zÀ-ÖØ-öø-ÿ'\-\. ]+$/u.test(city);
    }

    function validateEInvoiceAddress(data) {
        const vat = String(data.get('cliente_piva') || '').trim();
        const sdi = String(data.get('cliente_codice_sdi') || '').trim();
        const pec = normalizeEmail(String(data.get('cliente_pec') || ''));
        const isEInvoiceCandidate = vat !== '' && (sdi !== '' || pec !== '');
        if (!isEInvoiceCandidate) return null;

        const address = String(data.get('cliente_indirizzo') || '').trim();
        if (address === '') {
            return 'Indirizzo cliente obbligatorio per e-fattura (formato: Via Roma 10, 20100 Milano MI).';
        }

        const parts = parseAddressParts(address);
        const missing = [];
        if (!parts.postalCode) missing.push('CAP valido (5 cifre)');
        if (!isValidCityName(parts.city)) missing.push('Comune valido');
        if (!parts.province) missing.push('Provincia valida (2 lettere)');

        if (missing.length > 0) {
            return 'Indirizzo cliente non valido per e-fattura: manca ' + missing.join(' + ') + '. Usa formato: Via Roma 10, 20100 Milano MI.';
        }
        return null;
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const data = new FormData(form);
        const validationError = validateEInvoiceAddress(data);
        if (validationError) {
            alert(validationError);
            return;
        }
        const endpoint = form.getAttribute('action') || 'salva_fattura.php';
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalBtnText = submitBtn ? submitBtn.textContent : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Salvataggio...';
        }

        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                body: data,
                headers: {
                    'Accept': 'application/json'
                }
            });

            const rawBody = await res.text();
            let payload = null;
            try {
                payload = JSON.parse(rawBody);
            } catch (parseErr) {
                payload = null;
            }

            const message = payload && typeof payload.message === 'string'
                ? payload.message
                : (rawBody || 'Errore nel salvataggio.');

            if (!res.ok || (payload && payload.ok === false)) {
                throw new Error(message);
            }

            const modal = form.closest('.modal');
            if (modal) {
                modal.classList.remove('active');
                modal.classList.remove('show');
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }

            if (payload && payload.message) {
                alert(payload.message);
            }
            window.location.reload();
        } catch (err) {
            alert(err.message || 'Errore nel salvataggio.');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalBtnText;
            }
        }
    });
});
