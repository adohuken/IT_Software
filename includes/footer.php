        </main> <!-- Fin del Main Content -->


    </div> <!-- Fin del Flex Container Principal -->

    <!-- ════════════════════════════════════════
         ASISTENTE DE BÚSQUEDA INTELIGENTE IT
    ════════════════════════════════════════ -->
    
    <!-- Botón Flotante -->
    <button id="ai-toggle-btn" onclick="toggleAI()" title="Asistente IT"
            class="fixed bottom-6 right-6 z-[200] h-14 w-14 rounded-full shadow-2xl shadow-cyan-500/40 flex items-center justify-center transition-all duration-300 group hover:scale-105 hover:shadow-cyan-500/50"
            style="background: linear-gradient(135deg, #06b6d4, #3b82f6);">
        <i id="ai-btn-icon" class="fa-solid fa-robot text-white text-xl group-hover:rotate-12 transition-transform"></i>
        <!-- Ping animado -->
        <span class="absolute top-0 right-0 h-3.5 w-3.5 rounded-full bg-emerald-400 border-2 border-[#0f172a] animate-ping opacity-75"></span>
        <span class="absolute top-0 right-0 h-3.5 w-3.5 rounded-full bg-emerald-500 border-2 border-[#0f172a]"></span>
    </button>

    <!-- Panel del Asistente (Rediseño Moderno/Premium) -->
    <div id="ai-panel" class="fixed bottom-24 right-6 z-[199] w-[370px] max-h-[560px] flex flex-col rounded-3xl border border-slate-800/80 shadow-2xl overflow-hidden transition-all duration-300 origin-bottom-right"
         style="display:none; background: #0f172a; backdrop-filter: blur(20px);">

        <!-- Header del panel -->
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-800/60 bg-slate-900/60">
            <div class="flex items-center gap-3">
                <div class="h-9 w-9 rounded-xl flex items-center justify-center text-white text-sm shrink-0" style="background: linear-gradient(135deg, #06b6d4, #3b82f6);">
                    <i class="fa-solid fa-robot"></i>
                </div>
                <div>
                    <h4 class="text-white font-extrabold text-xs tracking-wide uppercase">Asistente de Consulta</h4>
                    <p class="text-[9px] text-emerald-400 font-bold flex items-center gap-1.5 mt-0.5"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span> En línea</p>
                </div>
            </div>
            <button onclick="toggleAI()" class="h-8 w-8 rounded-lg hover:bg-white/10 flex items-center justify-center text-slate-400 hover:text-white transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Área de conversación -->
        <div id="ai-messages" class="flex-1 overflow-y-auto p-4 space-y-4" style="max-height: 380px;">
            <!-- Mensaje de bienvenida -->
            <div class="flex gap-2.5">
                <div class="h-7 w-7 rounded-full shrink-0 flex items-center justify-center text-xs text-white" style="background: linear-gradient(135deg, #06b6d4, #3b82f6);">
                    <i class="fa-solid fa-robot text-[10px]"></i>
                </div>
                <div class="bg-slate-800/90 border border-slate-700/50 rounded-2xl rounded-tl-sm px-4 py-3 text-xs text-slate-200 leading-relaxed max-w-[260px]">
                    ¡Hola! Soy tu Asistente IT. Puedo buscar colaboradores, equipos, números de serie (S/N) y licencias. ¿Qué deseas encontrar hoy?
                </div>
            </div>

            <!-- Sugerencias rápidas -->
            <div id="ai-suggestions" class="flex flex-wrap gap-1.5 pl-9">
                <button onclick="quickSearch('laptops')" class="px-2.5 py-1.5 text-[9px] font-bold uppercase tracking-wider text-cyan-400 bg-cyan-500/10 border border-cyan-500/20 rounded-xl hover:bg-cyan-500/25 transition">
                    Laptops
                </button>
                <button onclick="quickSearch('servidores')" class="px-2.5 py-1.5 text-[9px] font-bold uppercase tracking-wider text-indigo-400 bg-indigo-500/10 border border-indigo-500/20 rounded-xl hover:bg-indigo-500/25 transition">
                    Servidores
                </button>
                <button onclick="quickSearch('tareas')" class="px-2.5 py-1.5 text-[9px] font-bold uppercase tracking-wider text-amber-400 bg-amber-500/10 border border-amber-500/20 rounded-xl hover:bg-amber-500/25 transition">
                    Tareas IT
                </button>
            </div>
        </div>

        <!-- Input de búsqueda -->
        <div class="p-3.5 border-t border-slate-800 bg-slate-900/80">
            <form autocomplete="off" id="ai-form" onsubmit="sendQuery(event)" class="flex gap-2">
                <input type="text" id="ai-input"
                       placeholder="Busca marcas, modelos, S/N o colaboradores..."
                       autocomplete="off"
                       class="flex-1 bg-slate-800 border border-slate-700/80 rounded-xl px-4 py-2.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500 transition">
                <button type="submit" id="ai-send-btn"
                        class="h-10 w-10 rounded-xl flex items-center justify-center text-white transition shrink-0 hover:opacity-90"
                        style="background: linear-gradient(135deg, #06b6d4, #3b82f6);">
                    <i id="ai-send-icon" class="fa-solid fa-paper-plane text-xs"></i>
                </button>
            </form>
            <p class="text-[8px] text-slate-500 text-center mt-2 uppercase tracking-widest font-bold">Búsqueda directa e inteligente en tiempo real</p>
        </div>
    </div>

    <!-- SCRIPTS GENERALES -->
    <script>

        // ════════════════════════════════════════
        // LÓGICA DEL ASISTENTE IT
        // ════════════════════════════════════════
        const BASE_URL = '<?= defined("BASE_URL") ? BASE_URL : "/" ?>';
        let aiOpen = false;

        function toggleAI() {
            const panel = document.getElementById('ai-panel');
            const icon  = document.getElementById('ai-btn-icon');
            aiOpen = !aiOpen;
            if (aiOpen) {
                panel.style.display = 'flex';
                panel.style.flexDirection = 'column';
                setTimeout(() => {
                    panel.style.opacity = '1';
                    panel.style.transform = 'scale(1)';
                    document.getElementById('ai-input').focus();
                }, 10);
                icon.className = 'fa-solid fa-xmark text-white text-xl group-hover:scale-110 transition-transform';
            } else {
                panel.style.opacity = '0';
                panel.style.transform = 'scale(0.9)';
                setTimeout(() => { panel.style.display = 'none'; }, 200);
                icon.className = 'fa-solid fa-robot text-white text-xl group-hover:scale-110 transition-transform';
            }
        }

        function quickSearch(term) {
            document.getElementById('ai-input').value = term;
            sendQuery(new Event('submit'));
        }

        async function sendQuery(e) {
            e.preventDefault();
            const input = document.getElementById('ai-input');
            const query = input.value.trim();
            if (!query) return;

            // Ocultar sugerencias
            const sug = document.getElementById('ai-suggestions');
            if (sug) sug.style.display = 'none';

            // Añadir burbuja del usuario
            appendMessage('user', query);
            input.value = '';

            // Indicador de carga
            const loaderId = appendLoader();

            // Desactivar botón
            const btn  = document.getElementById('ai-send-btn');
            const icon = document.getElementById('ai-send-icon');
            btn.disabled = true;
            icon.className = 'fa-solid fa-spinner fa-spin text-xs';

            try {
                const res = await fetch(BASE_URL + 'api/chat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ query })
                });
                const data = await res.json();
                removeLoader(loaderId);

                if (data.error) {
                    appendMessage('bot', '⚠️ ' + data.error);
                } else {
                    appendMessage('bot', data.reply);
                    if (data.results && data.results.length > 0) {
                        appendResults(data.results);
                    }
                }
            } catch (err) {
                removeLoader(loaderId);
                appendMessage('bot', 'No pude conectarme con el servidor. Verifica que XAMPP esté activo.');
            } finally {
                btn.disabled = false;
                icon.className = 'fa-solid fa-paper-plane text-xs';
            }
        }

        function appendMessage(from, text) {
            const container = document.getElementById('ai-messages');
            const div = document.createElement('div');
            div.className = 'flex gap-2.5' + (from === 'user' ? ' justify-end' : '');

            if (from === 'bot') {
                div.innerHTML = `
                    <div class="h-7 w-7 rounded-full shrink-0 flex items-center justify-center text-xs text-white" style="background: linear-gradient(135deg, #06b6d4, #3b82f6);">
                        <i class="fa-solid fa-robot text-[10px]"></i>
                    </div>
                    <div class="bg-slate-800/90 border border-slate-700/50 rounded-2xl rounded-tl-sm px-4 py-3 text-xs text-slate-200 leading-relaxed max-w-[260px]">${text}</div>`;
            } else {
                div.innerHTML = `
                    <div class="bg-indigo-650 bg-indigo-600/90 border border-indigo-500/40 rounded-2xl rounded-tr-sm px-4 py-3 text-xs text-white leading-relaxed max-w-[260px]">${escapeHtml(text)}</div>`;
            }
            container.appendChild(div);
            container.scrollTop = container.scrollHeight;
        }

        function appendResults(results) {
            const container = document.getElementById('ai-messages');
            const colorMap = {
                emerald: 'text-emerald-400 bg-emerald-500/10 border-emerald-550/20 border-emerald-500/20 hover:bg-emerald-500/20',
                cyan:    'text-cyan-400 bg-cyan-500/10 border-cyan-550/20 border-cyan-500/20 hover:bg-cyan-500/20',
                blue:    'text-blue-400 bg-blue-500/10 border-blue-550/20 border-blue-500/20 hover:bg-blue-500/20',
                amber:   'text-amber-400 bg-amber-500/10 border-amber-550/20 border-amber-500/20 hover:bg-amber-500/20',
                fuchsia: 'text-fuchsia-400 bg-fuchsia-500/10 border-fuchsia-550/20 border-fuchsia-500/20 hover:bg-fuchsia-500/20',
            };

            const wrapper = document.createElement('div');
            wrapper.className = 'pl-9 space-y-1.5';
            results.forEach(r => {
                const c = colorMap[r.color] || colorMap['cyan'];
                const card = document.createElement('a');
                card.href = BASE_URL + r.link;
                card.className = `flex items-center gap-3 p-2.5 rounded-xl border ${c} transition cursor-pointer group`;
                card.innerHTML = `
                    <i class="fa-solid ${r.icon} text-xs shrink-0"></i>
                    <div class="overflow-hidden">
                        <div class="text-[11px] font-bold text-slate-100 truncate group-hover:underline">${escapeHtml(r.titulo)}</div>
                        <div class="text-[9px] text-slate-400 truncate">${escapeHtml(r.sub)}</div>
                    </div>
                    <span class="ml-auto text-[9px] font-bold uppercase px-1.5 py-0.5 rounded ${c} shrink-0 bg-opacity-20">${escapeHtml(r.estado)}</span>`;
                wrapper.appendChild(card);
            });

            container.appendChild(wrapper);
            container.scrollTop = container.scrollHeight;
        }

        function appendLoader() {
            const container = document.getElementById('ai-messages');
            const id = 'loader-' + Date.now();
            const div = document.createElement('div');
            div.id = id;
            div.className = 'flex gap-2.5';
            div.innerHTML = `
                <div class="h-7 w-7 rounded-full shrink-0 flex items-center justify-center text-xs text-white" style="background: linear-gradient(135deg, #06b6d4, #3b82f6);">
                    <i class="fa-solid fa-robot text-[10px]"></i>
                </div>
                <div class="bg-slate-800/90 border border-slate-700/50 rounded-2xl rounded-tl-sm px-4 py-3 flex items-center gap-1.5">
                    <span class="h-1.5 w-1.5 rounded-full bg-slate-400 animate-bounce" style="animation-delay:0ms"></span>
                    <span class="h-1.5 w-1.5 rounded-full bg-slate-400 animate-bounce" style="animation-delay:150ms"></span>
                    <span class="h-1.5 w-1.5 rounded-full bg-slate-400 animate-bounce" style="animation-delay:300ms"></span>
                </div>`;
            container.appendChild(div);
            container.scrollTop = container.scrollHeight;
            return id;
        }

        function removeLoader(id) {
            const el = document.getElementById(id);
            if (el) el.remove();
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // Inicializar animación del panel
        document.getElementById('ai-panel').style.opacity = '0';
        document.getElementById('ai-panel').style.transform = 'scale(0.9)';
        document.getElementById('ai-panel').style.transition = 'opacity 0.2s ease, transform 0.2s ease';

        // Global: Reset all forms and selects on page load to prevent browser caching old values
        window.addEventListener('pageshow', function() {
            document.querySelectorAll('form[autocomplete="off"]').forEach(function(f) { f.reset(); });
            document.querySelectorAll('select[autocomplete="off"]').forEach(function(s) {
                var defaultOpt = s.querySelector('option[selected]');
                if (defaultOpt) {
                    s.value = defaultOpt.value;
                } else {
                    s.selectedIndex = 0;
                }
            });
        });

        // Global: Inicializar TomSelect para selects buscables
        if (typeof TomSelect !== 'undefined') {
            document.querySelectorAll('.searchable-select').forEach((el) => {
                new TomSelect(el, {
                    create: false,
                    sortField: { field: 'text', direction: 'asc' },
                    placeholder: el.getAttribute('data-placeholder') || 'Buscar y seleccionar...'
                });
            });
        }
        // ════════════════════════════════════════
        // PREVENIR PROBLEMAS CON EL BOTÓN 'ATRÁS' (BFCache)
        // ════════════════════════════════════════
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                // Si la página se restauró desde el caché del navegador (al darle "Atrás"), forzar recarga
                window.location.reload();
            }
        });

    </script>
</body>
</html>
