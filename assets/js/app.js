document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.querySelector(button.dataset.passwordToggle);
        const icon = button.querySelector('i');
        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        icon.classList.toggle('bi-eye', showing);
        icon.classList.toggle('bi-eye-slash', !showing);
        button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    });
});

const requestModule = document.querySelector('.request-module');
const themeToggle = document.querySelector('[data-theme-toggle]');
if (document.body) {
    const savedTheme = window.localStorage.getItem('request-theme');
    if (savedTheme === 'dark') {
        requestModule.dataset.theme = 'dark';
    }

    const aiChat = document.querySelector('[data-ai-chat]');
    const aiChatOpen = document.querySelector('[data-ai-chat-open]');
    const aiChatClose = document.querySelector('[data-ai-chat-close]');
    const aiChatBackdrop = document.querySelector('[data-ai-chat-backdrop]');
    const aiChatForm = document.querySelector('[data-ai-chat-form]');
    const aiChatMessages = document.querySelector('[data-ai-chat-messages]');
    if (aiChat && aiChatOpen && aiChatClose && aiChatBackdrop && aiChatForm && aiChatMessages) {
        const addAiMessage = (message, type) => {
            const item = document.createElement('div');
            item.className = `ai-chat-message ai-chat-${type}`;
            item.textContent = message;
            aiChatMessages.appendChild(item);
            aiChatMessages.scrollTop = aiChatMessages.scrollHeight;
            return item;
        };
        aiChatOpen.addEventListener('click', () => {
            aiChat.classList.add('is-open');
            aiChatBackdrop.classList.add('is-open');
            aiChat.setAttribute('aria-hidden', 'false');
            aiChatBackdrop.setAttribute('aria-hidden', 'false');
            document.body.classList.add('ai-chat-active');
            aiChatForm.querySelector('input[name="message"]').focus();
        });
        const closeAiChat = () => {
            aiChat.classList.remove('is-open');
            aiChatBackdrop.classList.remove('is-open');
            aiChat.setAttribute('aria-hidden', 'true');
            aiChatBackdrop.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('ai-chat-active');
        };
        aiChatClose.addEventListener('click', closeAiChat);
        aiChatBackdrop.addEventListener('click', closeAiChat);
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && aiChat.classList.contains('is-open')) {
                closeAiChat();
            }
        });
        aiChatForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const input = aiChatForm.querySelector('input[name="message"]');
            const message = input.value.trim();
            if (!message) {
                return;
            }
            addAiMessage(message, 'user');
            input.value = '';
            const waiting = addAiMessage('Thinking...', 'bot');
            const formData = new FormData(aiChatForm);
            formData.set('message', message);
            try {
                const response = await fetch('ai_chat.php', { method: 'POST', body: formData, headers: { Accept: 'application/json' } });
                const result = await response.json();
                waiting.textContent = result.message || 'I could not prepare a response.';
                if (!result.ok) {
                    waiting.classList.add('ai-chat-error');
                }
            } catch (error) {
                waiting.textContent = 'The assistant is temporarily unavailable. Please try again.';
                waiting.classList.add('ai-chat-error');
            }
            aiChatMessages.scrollTop = aiChatMessages.scrollHeight;
        });
    }
}

if (requestModule && themeToggle) {
    const updateThemeButton = () => {
        const dark = requestModule.dataset.theme === 'dark';
        themeToggle.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
        themeToggle.innerHTML = `<i class="bi ${dark ? 'bi-sun' : 'bi-moon-stars'}"></i>`;
    };
    updateThemeButton();
    themeToggle.addEventListener('click', () => {
        const dark = requestModule.dataset.theme !== 'dark';
        requestModule.dataset.theme = dark ? 'dark' : 'light';
        window.localStorage.setItem('request-theme', dark ? 'dark' : 'light');
        updateThemeButton();
    });
}

const staffTimer = document.querySelector('[data-staff-timer]');
if (staffTimer) {
    const clockIn = new Date(staffTimer.dataset.clockIn).getTime();
    const updateStaffTimer = () => {
        const elapsed = Math.max(0, Math.floor((Date.now() - clockIn) / 1000));
        const hours = String(Math.floor(elapsed / 3600)).padStart(2, '0');
        const minutes = String(Math.floor((elapsed % 3600) / 60)).padStart(2, '0');
        const seconds = String(elapsed % 60).padStart(2, '0');
        staffTimer.textContent = `${hours}:${minutes}:${seconds}`;
    };
    updateStaffTimer();
    window.setInterval(updateStaffTimer, 1000);
}

document.querySelectorAll('[data-copy-number]').forEach((button) => {
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(button.dataset.copyNumber);
            const original = button.innerHTML;
            button.textContent = 'Copied';
            window.setTimeout(() => {
                button.innerHTML = original;
            }, 1600);
        } catch (error) {
            console.error('Unable to copy tracking number.', error);
        }
    });
});

const profileTabs = document.querySelectorAll('[data-profile-tab]');
const profilePanels = document.querySelectorAll('[data-profile-panel]');
if (profileTabs.length && profilePanels.length) {
    profileTabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const selectedTab = tab.dataset.profileTab;
            profileTabs.forEach((item) => {
                const selected = item === tab;
                item.classList.toggle('active', selected);
                item.setAttribute('aria-selected', selected ? 'true' : 'false');
            });
            profilePanels.forEach((panel) => {
                panel.hidden = panel.dataset.profilePanel !== selectedTab;
            });
        });
    });
}

const profilePhotoInput = document.querySelector('#profile_image');
const profilePhotoTrigger = document.querySelector('[data-profile-photo-trigger]');
if (profilePhotoInput && profilePhotoTrigger) {
    profilePhotoTrigger.addEventListener('click', () => {
        profilePhotoInput.click();
    });
    profilePhotoInput.addEventListener('change', () => {
        const file = profilePhotoInput.files[0];
        if (!file) {
            return;
        }
        if (!file.type.match(/^image\/(jpeg|png)$/)) {
            profilePhotoInput.value = '';
            return;
        }
        const previewUrl = URL.createObjectURL(file);
        let preview = profilePhotoTrigger.querySelector('[data-profile-photo-preview]');
        const placeholder = profilePhotoTrigger.querySelector('[data-profile-photo-placeholder]');
        if (!preview) {
            preview = document.createElement('img');
            preview.dataset.profilePhotoPreview = '';
            preview.alt = 'Profile photo preview';
            profilePhotoTrigger.prepend(preview);
        }
        preview.src = previewUrl;
        if (placeholder) {
            placeholder.remove();
        }
    });
}

const passwordModal = document.querySelector('[data-password-modal]');
if (passwordModal) {
    document.querySelectorAll('[data-password-modal-open]').forEach((button) => {
        button.addEventListener('click', () => {
            passwordModal.classList.add('is-open');
            passwordModal.setAttribute('aria-hidden', 'false');
            const input = passwordModal.querySelector('input[type="password"]');
            if (input) {
                input.focus();
            }
        });
    });
    document.querySelectorAll('[data-password-modal-close]').forEach((button) => {
        button.addEventListener('click', () => {
            passwordModal.classList.remove('is-open');
            passwordModal.setAttribute('aria-hidden', 'true');
        });
    });
    passwordModal.addEventListener('click', (event) => {
        if (event.target === passwordModal) {
            passwordModal.classList.remove('is-open');
            passwordModal.setAttribute('aria-hidden', 'true');
        }
    });
}

const blotterMapElement = document.querySelector('#blotter-map');
if (blotterMapElement && typeof L !== 'undefined') {
    const mapCenter = [13.9911, 121.1710];
    const blotterMap = L.map(blotterMapElement).setView(mapCenter, 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(blotterMap);

    const locationInput = document.querySelector('#location');
    const latitudeInput = document.querySelector('#latitude');
    const longitudeInput = document.querySelector('#longitude');
    let marker;
    let geocodeRequest = 0;

    const setLocation = (latitude, longitude, shouldReverseGeocode = true) => {
        if (!marker) {
            marker = L.marker([latitude, longitude], { draggable: true }).addTo(blotterMap);
            marker.on('dragend', async () => {
                const position = marker.getLatLng();
                latitudeInput.value = position.lat.toFixed(7);
                longitudeInput.value = position.lng.toFixed(7);
                await reverseGeocode(position.lat, position.lng);
            });
        } else {
            marker.setLatLng([latitude, longitude]);
        }
        latitudeInput.value = Number(latitude).toFixed(7);
        longitudeInput.value = Number(longitude).toFixed(7);
        blotterMap.setView([latitude, longitude], Math.max(blotterMap.getZoom(), 16));
        if (shouldReverseGeocode) {
            reverseGeocode(latitude, longitude);
        }
    };

    const reverseGeocode = async (latitude, longitude) => {
        const requestId = ++geocodeRequest;
        try {
            const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(latitude)}&lon=${encodeURIComponent(longitude)}&zoom=18&addressdetails=1`, {
                headers: { Accept: 'application/json' }
            });
            if (!response.ok) {
                throw new Error(`Reverse geocoding failed with status ${response.status}.`);
            }
            const result = await response.json();
            if (requestId === geocodeRequest && result.display_name) {
                locationInput.value = result.display_name;
            }
        } catch (error) {
            console.error('Unable to identify the selected map location.', error);
        }
    };

    const geocodeLocation = async () => {
        const query = locationInput.value.trim();
        if (!query) {
            return;
        }
        const requestId = ++geocodeRequest;
        try {
            const response = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=ph&q=${encodeURIComponent(`${query}, Lipa City, Batangas, Philippines`)}`, {
                headers: { Accept: 'application/json' }
            });
            if (!response.ok) {
                throw new Error(`Geocoding failed with status ${response.status}.`);
            }
            const results = await response.json();
            if (requestId !== geocodeRequest || !results[0]) {
                return;
            }
            setLocation(Number(results[0].lat), Number(results[0].lon), false);
            locationInput.value = results[0].display_name || query;
        } catch (error) {
            console.error('Unable to find the typed incident location.', error);
        }
    };

    blotterMap.on('click', (event) => setLocation(event.latlng.lat, event.latlng.lng));
    locationInput.addEventListener('change', geocodeLocation);
    locationInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            geocodeLocation();
        }
    });
}
