<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Rezzy Voice</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: system-ui, -apple-system, Segoe UI, sans-serif;
            margin: 0;
            background: #f8fafc;
            color: #0f172a;
        }

        .app {
            display: flex;
            flex-direction: column;
            height: 100vh;
            max-width: 520px;
            margin: 0 auto;
            background: #fff;
            box-shadow: 0 0 0 1px #e2e8f0;
        }

        header {
            padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        header h1 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
        }

        header button {
            border: 0;
            background: transparent;
            color: #64748b;
            font-size: 13px;
            cursor: pointer;
        }

        header button:hover {
            color: #0f172a;
        }

        #chat {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .msg {
            max-width: 80%;
            padding: 10px 14px;
            border-radius: 16px;
            font-size: 15px;
            line-height: 1.35;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .msg.user {
            align-self: flex-end;
            background: #2563eb;
            color: #fff;
            border-bottom-right-radius: 4px;
        }

        .msg.bot {
            align-self: flex-start;
            background: #f1f5f9;
            color: #0f172a;
            border-bottom-left-radius: 4px;
        }

        .msg.error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fee2e2;
            align-self: stretch;
            max-width: 100%;
            text-align: center;
            font-size: 13px;
        }

        .msg.bot.typing {
            color: #64748b;
            font-style: italic;
        }

        .meta {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 2px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        footer {
            border-top: 1px solid #e2e8f0;
            padding: 12px 16px;
        }

        #mic {
            width: 100%;
            padding: 14px;
            font-size: 15px;
            font-weight: 600;
            border: 0;
            border-radius: 999px;
            background: #2563eb;
            color: #fff;
            cursor: pointer;
            transition: all 0.2s;
        }

        #mic.recording {
            background: #dc2626;
            animation: pulse 1.1s infinite;
        }

        #mic[disabled] {
            background: #94a3b8;
            cursor: not-allowed;
        }

        #err {
            color: #b91c1c;
            font-size: 13px;
            margin-top: 8px;
            text-align: center;
            min-height: 16px;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: .7;
            }
        }

        .hint {
            color: #64748b;
            font-size: 13px;
            text-align: center;
            padding: 24px 16px;
        }

        .shops {
            align-self: stretch;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .shop {
            display: flex;
            gap: 10px;
            padding: 10px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            align-items: center;
        }

        .shop img {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            object-fit: cover;
            background: #e2e8f0;
            flex-shrink: 0;
        }

        .shop .info {
            flex: 1;
            min-width: 0;
        }

        .shop .name {
            font-weight: 600;
            font-size: 14px;
            color: #0f172a;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .shop .sub {
            font-size: 12px;
            color: #64748b;
        }

        .shop .dist {
            font-size: 12px;
            font-weight: 600;
            color: #2563eb;
        }

        .settings-link {
            font-weight: bold;
            text-decoration: underline;
            color: #2563eb;
            cursor: pointer;
            display: block;
            margin-top: 4px;
        }
    </style>
</head>

<body>
    <div class="app">
        <header>
            <h1>Rezzy Voice</h1>
            <button id="reset" type="button">New chat</button>
        </header>

        <div id="chat">
            <div class="hint" id="hint">Tap the mic and say something like <em>"find a barber near me"</em> or
                <em>"open my bookings"</em>.
            </div>
        </div>

        <footer>
            <button id="mic" type="button">🎤 Tap to speak</button>
            <div id="err"></div>
        </footer>
    </div>


    <script>
        const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        const mic = document.getElementById('mic');
        const resetBtn = document.getElementById('reset');
        const chat = document.getElementById('chat');
        const hint = document.getElementById('hint');
        const errEl = document.getElementById('err');
        const csrf = document.querySelector('meta[name="csrf-token"]').content;

        let userId = localStorage.getItem('rezzy_user_id');
        if (!userId) {
            userId = 'guest-' + Math.random().toString(36).slice(2, 10);
            localStorage.setItem('rezzy_user_id', userId);
        }

        let coords = null;

        /**
         * Get user location (with caching)
         */
        function getCoords() {
            if (coords) return Promise.resolve(coords);

            return new Promise((resolve, reject) => {
                if (!navigator.geolocation) {
                    return reject('NOT_SUPPORTED');
                }

                navigator.geolocation.getCurrentPosition(
                    (pos) => {
                        coords = {
                            lat: pos.coords.latitude,
                            lon: pos.coords.longitude
                        };
                        resolve(coords);
                    },
                    (err) => {
                        if (err.code === 1) reject('PERMISSION_DENIED');
                        else reject('POSITION_UNAVAILABLE');
                    }, {
                        timeout: 8000,
                        maximumAge: 60000,
                        enableHighAccuracy: true
                    }
                );
            });
        }

        /**
         * Ask location early (better UX)
         */
        window.addEventListener('load', async () => {
            try {
                await getCoords();
            } catch (e) {
                console.log('Location not granted yet');
            }
        });

        /**
         * Fetch shops
         */
        async function fetchShops(query) {
            try {
                const location = await getCoords();

                const url = new URL('/api/shops/nearby', location.origin);
                url.searchParams.set('lat', location.lat);
                url.searchParams.set('lon', location.lon);
                url.searchParams.set('radius_km', '10');
                url.searchParams.set('per_page', '5');
                if (query) url.searchParams.set('search', query);

                const res = await fetch(url.toString(), {
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                const data = await res.json();

                return {
                    shops: data.data || []
                };

            } catch (error) {
                let msg = 'Location required to show nearby shops.';
                if (error === 'PERMISSION_DENIED') {
                    msg = 'Location blocked. Click 🔒 icon → Allow → refresh.';
                }
                return {
                    error: msg
                };
            }
        }

        /**
         * UI helpers
         */
        function renderShops(shops) {
            const wrap = document.createElement('div');
            wrap.className = 'shops';

            if (!shops.length) {
                const empty = document.createElement('div');
                empty.className = 'msg bot';
                empty.textContent = 'No nearby shops found.';
                wrap.appendChild(empty);
            } else {
                shops.forEach((s) => {
                    const card = document.createElement('div');
                    card.className = 'shop';

                    const img = document.createElement('img');
                    img.src = s.logo || s.hero_image || '';
                    img.onerror = () => img.style.display = 'none';

                    const info = document.createElement('div');
                    info.className = 'info';

                    const name = document.createElement('div');
                    name.className = 'name';
                    name.textContent = s.name || 'Shop';

                    const sub = document.createElement('div');
                    sub.className = 'sub';
                    sub.textContent = s.address || s.city || '';

                    const dist = document.createElement('div');
                    dist.className = 'dist';
                    dist.textContent = s.distance || '';

                    info.appendChild(name);
                    info.appendChild(sub);
                    card.appendChild(img);
                    card.appendChild(info);
                    card.appendChild(dist);

                    wrap.appendChild(card);
                });
            }

            chat.appendChild(wrap);
            chat.scrollTop = chat.scrollHeight;
        }

        function addMessage(role, text, extra = '', isError = false) {
            if (document.getElementById('hint')) document.getElementById('hint').remove();

            const el = document.createElement('div');
            el.className = 'msg ' + role + (isError ? ' error' : '');
            el.textContent = text;
            chat.appendChild(el);

            if (extra) {
                const m = document.createElement('div');
                m.className = 'meta';
                m.style.alignSelf = role === 'user' ? 'flex-end' : 'flex-start';
                m.textContent = extra;
                chat.appendChild(m);
            }

            chat.scrollTop = chat.scrollHeight;
            return el;
        }

        /**
         * Reset chat
         */
        resetBtn.addEventListener('click', () => {
            localStorage.removeItem('rezzy_conv_id');
            chat.innerHTML = '';
            const h = document.createElement('div');
            h.className = 'hint';
            h.id = 'hint';
            h.innerHTML = 'New chat started. Tap the mic to speak.';
            chat.appendChild(h);
        });

        /**
         * Speech Recognition
         */
        if (!SR) {
            mic.disabled = true;
            errEl.textContent = 'Speech recognition not supported.';
        } else {

            const recognition = new SR();
            recognition.lang = 'en-US';
            recognition.interimResults = false;

            let listening = false;

            mic.addEventListener('click', () => {
                errEl.textContent = '';
                if (listening) {
                    recognition.stop();
                    return;
                }
                recognition.start();
            });

            recognition.onstart = () => {
                listening = true;
                mic.textContent = '● Listening… tap to stop';
                mic.classList.add('recording');
            };

            recognition.onend = () => {
                listening = false;
                mic.textContent = '🎤 Tap to speak';
                mic.classList.remove('recording');
            };

            recognition.onerror = (e) => {
                errEl.textContent = 'Mic error: ' + e.error;
            };

            recognition.onresult = async (event) => {
                const text = event.results[0][0].transcript;
                addMessage('user', text);

                const typing = addMessage('bot', '…');
                typing.classList.add('typing');

                try {
                    // 🔥 GET LOCATION FIRST
                    let locationData;
                    try {
                        locationData = await getCoords();
                    } catch (error) {
                        let msg = 'Location required.';
                        if (error === 'PERMISSION_DENIED') {
                            msg = 'Enable location from browser 🔒 and retry.';
                        }
                        typing.classList.remove('typing');
                        typing.textContent = msg;
                        return;
                    }

                    // 🔥 SEND TO BACKEND WITH LAT/LON
                    const res = await fetch('/api/voice/intent', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({
                            text,
                            user_id: userId,
                            conversation_id: localStorage.getItem('rezzy_conv_id') || null,
                            lat: locationData.lat,
                            lon: locationData.lon,
                        }),
                    });

                    const data = await res.json();

                    if (data.conversation_id) {
                        localStorage.setItem('rezzy_conv_id', data.conversation_id);
                    }

                    typing.classList.remove('typing');
                    typing.textContent = data.reply || data.message || '…';

                    // Action badge
                    if (data.action && data.action !== 'none') {
                        const badgeText =
                            data.action === 'search' ?
                            'search · ' + (data.query || '') :
                            'open · ' + (data.screen || '');

                        const m = document.createElement('div');
                        m.className = 'meta';
                        m.textContent = badgeText;
                        chat.appendChild(m);
                    }

                    // Speak reply
                    if (data.reply && 'speechSynthesis' in window) {
                        speechSynthesis.cancel();
                        speechSynthesis.speak(new SpeechSynthesisUtterance(data.reply));
                    }

                    // Fetch shops
                    if (data.action === 'search') {
                        const result = await fetchShops(data.query);
                        if (result.error) {
                            addMessage('bot', result.error, '', true);
                        } else {
                            renderShops(result.shops);
                        }
                    }

                } catch (err) {
                    typing.classList.remove('typing');
                    typing.textContent = 'Something went wrong.';
                    errEl.textContent = 'Error: ' + err.message;
                }
            };
        }
    </script>
</body>

</html>
